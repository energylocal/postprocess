<?php

// no direct access
defined('EMONCMS_EXEC') or die('Restricted access');

function postprocess_controller()
{
    global $linked_modules_dir, $session, $route, $mysqli, $redis, $settings;

    $result = false;
    $route->format = "text";

    $log = new EmonLogger(__FILE__);

    include "Modules/feed/feed_model.php";
    $feed = new Feed($mysqli, $redis, $settings['feed']);

    include "Modules/postprocess/postprocess_model.php";
    $postprocess = new PostProcess($mysqli, $feed);

    // Load available processes descriptions
    $processes = $postprocess->get_processes("$linked_modules_dir/postprocess");

    // -------------------------------------------------------------------------
    // VIEW
    // -------------------------------------------------------------------------
    if ($route->action == "" && $session['write']) {
        $result = view("Modules/postprocess/view.php", array("processes" => $processes));
        $route->format = "html";
        return array('content' => $result);
    }

    if ($route->action == "processes" && $session['write']) {
        $route->format = "json";
        return array('content' => $processes);
    }

    // -------------------------------------------------------------------------
    // PROCESS LIST
    // -------------------------------------------------------------------------
    if ($route->action == "list" && $session['write']) {

        $userid = $session['userid'];

        $processlist = $postprocess->get($userid);

        if ($processlist == null) $processlist = array();
        $processlist_long = array();
        $processlist_valid = array();

        for ($i = 0; $i < count($processlist); $i++) {
            $valid = true;

            $item = json_decode(json_encode($processlist[$i]));

            $process = $item->process;
            if (isset($processes[$process])) {
                foreach ($processes[$process]['settings'] as $key => $option) {
                    if ($option['type'] == "feed" || $option['type'] == "newfeed") {
                        $id = $processlist[$i]->$key;
                        if ($feed->exist((int)$id)) {
                            $f = $feed->get($id);
                            if ($f['userid'] != $session['userid']) return false;
                            if ($meta = $feed->get_meta($id)) {
                                $f['start_time'] = $meta->start_time;
                                $f['interval'] = $meta->interval;
                                $f['npoints'] = $meta->npoints;
                                $f['id'] = (int) $f['id'];
                                $timevalue = $feed->get_timevalue($id);
                                $f['time'] = $timevalue["time"];
                            } else {
                                // $valid = false;
                                // $log->error("Invalid meta: ".json_encode($meta));
                            }
                            $item->$key = $f;
                        } else {
                            $valid = false;
                            $log->error("Feed $id does not exist");
                        }
                    }

                    if ($option['type'] == "formula") {
                        $formula = $processlist[$i]->$key;
                        $f = array();
                        $f['expression'] = $formula;
                        //we catch feed numbers in the formula
                        $feed_ids = array();
                        while (preg_match("/(f\d+)/", $formula, $b)) {
                            $feed_ids[] = substr($b[0], 1, strlen($b[0]) - 1);
                            $formula = str_replace($b[0], "", $formula);
                        }
                        $all_intervals = array();
                        $all_start_times = array();
                        $all_ending_times = array();
                        //we check feeds existence and stores all usefull metas
                        foreach ($feed_ids as $id) {
                            if ($feed->exist((int)$id)) {
                                $m = $feed->get_meta($id);
                                $all_intervals[] = $m->interval;
                                $all_start_times[] = $m->start_time;
                                $timevalue = $feed->get_timevalue($id);
                                $all_ending_times[] = $timevalue["time"];
                            } else {
                                $valid = false;
                                $log->error("Feed $id does not exist");
                            }
                        }
                        if ($valid) {
                            $f['interval'] = max($all_intervals);
                            $f['start_time'] = max($all_start_times);
                            $f['time'] = min($all_ending_times);

                            $item->$key = $f;
                        }
                    }
                }
            } else {
                $valid = false;
                $log->error("$process does not exist");
            }

            if ($valid) {
                $processlist_long[] = $item;
                $processlist_valid[] = $processlist[$i];
            }
        }

        $postprocess->set($userid, $processlist_valid);

        $result = $processlist_long;

        $route->format = "json";
    }

    // -------------------------------------------------------------------------
    // CREATE OR UPDATE PROCESS
    // -------------------------------------------------------------------------
    if (($route->action == "create" || $route->action == "edit") && $session['write']) {
        $route->format = "json";

        // this is the process name
        $process = get('process', true);
        // if we are editing, we need the process id
        if ($route->action == "edit")
            $processid = (int) get('processid', true);
        // process parameters in the post body
        $params = json_decode(file_get_contents('php://input'));
        
        // validate parameters, check valid feeds etc
        $result = $postprocess->validate_params($session['userid'],$process,$params);
        if (!$result['success']) return $result;

        // process_mode and process_start are not included in the process description
        // so we need to add them here if they are not set
        if (!isset($params->process_mode))
            $params->process_mode = "recent";
        if (!isset($params->process_start))
            $params->process_start = 0;

        $params->process = $process;

        // If we got this far the input parameters are valid.
        if (!$processlist = $postprocess->get($session['userid'])) {
            $processlist = array();
        }
        if ($route->action == "edit") {
            if (isset($processlist[$processid])) {
                $processlist[$processid] = $params;
            }
        } else {
            $processlist[] = $params;
        }
        $postprocess->set($session['userid'], $processlist);
        $redis->lpush("postprocessqueue", json_encode($params));

        // -----------------------------------------------------------------
        // Run postprocessor script using the emonpi service-runner
        // -----------------------------------------------------------------
        $update_script = "$linked_modules_dir/postprocess/postprocess.sh";
        $update_logfile = $settings['log']['location'] . "/postprocess.log";
        $redis->rpush("service-runner", "$update_script>$update_logfile");
        $result = "service-runner trigger sent";
        // -----------------------------------------------------------------
        return array('success' => true, 'message' => "process created");
    }

    if ($route->action == "run") {
        $route->format = "json";
        $processid = (int) get('processid', true);
        if (!$processlist = $postprocess->get($session['userid'])) {
            return array('success' => false, 'message' => "no processes");
        }
        if (!isset($processlist[$processid])) {
            return array('success' => false, 'message' => "process does not exist");
        }
        $redis->lpush("postprocessqueue", json_encode($processlist[$processid]));
        // -----------------------------------------------------------------
        // Run postprocessor script using the emonpi service-runner
        // -----------------------------------------------------------------
        $update_script = "$linked_modules_dir/postprocess/postprocess.sh";
        $update_logfile = $settings['log']['location'] . "/postprocess.log";
        $redis->rpush("service-runner", "$update_script>$update_logfile");
        $result = "service-runner trigger sent";
        // -----------------------------------------------------------------
        return array('success' => true, 'message' => "process added to queue");
    }

    if ($route->action == "remove" && $session['write']) {
        $route->format = "json";
        $processid = (int) get('processid', true);
        $processlist = $postprocess->get($session['userid']);
        if (isset($processlist[$processid])) {
            array_splice($processlist, $processid, 1);
        } else {
            return array("success" => false, "message" => "process does not exist");
        }
        $postprocess->set($session['userid'], $processlist);
        return array("success" => true, "message" => "process removed");
    }

    if ($route->action == 'logpath') {
        return $settings['log']['location'] . "/postprocess.log";
    }

    if ($route->action == 'getlog') {
        $route->format = "text";
        $log_filename = $settings['log']['location'] . "/postprocess.log";
        if (file_exists($log_filename)) {
            ob_start();
            passthru("tail -30 $log_filename");
            $result = trim(ob_get_clean());
        } else $result = "no logging yet available";
    }

    return array('content' => $result, 'fullwidth' => false);
}
