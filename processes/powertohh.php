<?php

function powertohh($dir,$processitem)
{
    if (!isset($processitem->input)) return false;
    if (!isset($processitem->output)) return false;
    if (!isset($processitem->recalc)) $processitem->recalc = 0;
    
    $input = $processitem->input;
    $output = $processitem->output;
    // --------------------------------------------------
    
    if (!file_exists($dir.$input.".meta")) {
        print "input file $input.meta does not exist\n";
        return false;
    }

    if (!file_exists($dir.$output.".meta")) {
        print "output file $output.meta does not exist\n";
        return false;
    }

    $im = getmeta($dir,$input);
    print "input meta: ".json_encode($im)."\n";
    
    $om = getmeta($dir,$output);
    print "output meta: ".json_encode($om)."\n";
    
    // Set start time to im start time rounded to interval
    if ($om->start_time==0) {
        $om->start_time = floor($im->start_time/$om->interval)*$om->interval;
        print "set output meta start time: ".$om->start_time."\n";
        createmeta($dir,$output,$om);
    }

    $i_end_time = $im->start_time + ($im->npoints * $im->interval);
    $o_end_time = $om->start_time + ($om->npoints * $om->interval);
    
    $min_number_dp = round(0.33 * ($om->interval / $im->interval));

    // Only process new data
    /* if ($o_end_time >= $i_end_time) {
        print "output feed already up to date\n";
        return false;
    }*/

    if (!$if = @fopen($dir.$input.".dat", 'rb')) {
        echo "ERROR: could not open $dir $input.dat\n";
        return false;
    }
    
    if (!$of = @fopen($dir.$output.".dat", 'c+')) {
        echo "ERROR: could not open $dir $output.dat\n";
        return false;
    }
    
    $buffer = "";
    
    // Process from end of last halfhour minus 3 days
    $time_hh = $o_end_time - $processitem->recalc;
    if ($time_hh<$om->start_time) $time_hh=$om->start_time;
    
    $start_pos = floor(($time_hh - $im->start_time) / $im->interval);
    if ($start_pos<0) $start_pos = 0;
    fseek($if,$start_pos*4);
    
    $out_pos = floor(($time_hh - $om->start_time) / $om->interval);
    if ($out_pos<0) $out_pos = 0;
    fseek($of,$out_pos*4);
    
    $wh = 0;
    $power = 0;
    $valid_count = 0;
    for ($n=$start_pos; $n<$im->npoints; $n++) {

        $time = $im->start_time + ($n * $im->interval);
        $last_time_hh = $time_hh;
        $time_hh = floor($time/$om->interval)*$om->interval;
        
        $tmp = unpack("f",fread($if,4));
        if (!is_nan($tmp[1])) {
            $power = 1*$tmp[1];
            $valid_count ++;
        }
                
        // Import only
        if ($power<0.0) $power = 0.0;
        // filter spurious power values +-1MW
        if ($power<1000000.0) {
            
            $wh += ($power * $im->interval) / 3600.0;
            if ($time_hh!=$last_time_hh) {
                // At least 1/3rd of dps present
                if ($valid_count>=$min_number_dp) {
                    $buffer .= pack("f",$wh*0.001);
                } else {
                    $buffer .= pack("f",NAN);
                }
                $wh = 0;
                $valid_count = 0;
            }
        }
    }
    
    fwrite($of,$buffer);
    
    print "bytes written: ".strlen($buffer)."\n";
    fclose($of);
    fclose($if);
    return true;
}
