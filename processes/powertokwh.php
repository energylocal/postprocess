<?php

class PostProcess_powertokwh extends PostProcess_common
{
    public function description() {
        return array(
            "name"=>"Power to kWh",
            "group"=>"Power & Energy",
            "description"=>"Create a cumulative kWh feed from a power feed",
            "order"=>3,
            "settings"=>array(
                "input"=>array("type"=>"feed", "engine"=>5, "short"=>"Select input power feed (W):"),
                "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Create or select output kWh feed:"),
                "max_power"=>array("type"=>"value", "default"=>1000000, "short"=>"Enter max power limit (W):"),
                "min_power"=>array("type"=>"value", "default"=>-1000000, "short"=>"Enter min power limit (W):")
            )
        );
    }

    public function process($params)
    {
        $result = $this->validate($params);
        if (!$result["success"]) return $result;

        $input_meta = getmeta($this->dir,$params->input);
        $output_meta = getmeta($this->dir,$params->output);

        // Check that output feed is empty or has same start time and interval
        if ($output_meta->npoints>0) {
            if ($input_meta->start_time != $output_meta->start_time) {
                return array("success"=>false, "message"=>"start time mismatch");
            }
            if ($input_meta->interval != $output_meta->interval) {
                return array("success"=>false, "message"=>"interval mismatch");
            }
        } else {
            // Copies over start_time to output meta file
            createmeta($this->dir,$params->output,$input_meta);
        }

        // If recent mode, check that input feed has more points than output feed
        if ($params->process_mode=='recent' && $output_meta->npoints >= $input_meta->npoints) {
            return array("success"=>true, "message"=>"output feed already up to date");
        }
        
        if (!$if = @fopen($this->dir.$params->input.".dat", 'rb')) {
            return array("success"=>false, "message"=>"could not open input feed");
        }
        
        if (!$of = @fopen($this->dir.$params->output.".dat", 'c+')) {
            return array("success"=>false, "message"=>"could not open output feed");
        }
        
        $buffer = "";
        $wh = 0;
        $power = 0;
        $start_pos = 0;

        if ($params->process_mode=='from' && $output_meta->npoints>0) {
            $start_pos = floor(($params->process_start - $input_meta->start_time) / $input_meta->interval);
            if ($start_pos<0) $start_pos = 0;
            if ($start_pos>$input_meta->npoints) {
                return array("success"=>false, "message"=>"start time is after end of input feed");
            }
        }

        if ($params->process_mode=='recent' && $output_meta->npoints>0) {
            $start_pos = $output_meta->npoints;
        }

        if ($start_pos>0) {
            fseek($if,$start_pos*4);
            fseek($of,($start_pos-1)*4);
            $tmp = unpack("f",fread($of,4));
            $wh = $tmp[1]*1000.0;
        }
        
        $filtered_count = 0;
        
        for ($n=$start_pos; $n<$input_meta->npoints; $n++) {
            $tmp = unpack("f",fread($if,4));
            
            if (!is_nan($tmp[1])) $power = 1*$tmp[1];
            
            // filter spurious power values +-1MW
            if ($power>=$params->min_power && $power<$params->max_power) {
                $wh += ($power * $input_meta->interval) / 3600.0;
                $buffer .= pack("f",$wh*0.001);
            } else {
                $filtered_count++;
            }
        }
        
        fwrite($of,$buffer);
        
        if ($filtered_count>0) {
            print "Filtered count: $filtered_count\n";
        }
        
        print "bytes written: ".strlen($buffer)."\n";
        fclose($of);
        fclose($if);
        
        $time = $input_meta->start_time + ($input_meta->npoints * $input_meta->interval);
        $value = $wh * 0.001;
        
        print "last time value: ".$time." ".$value."\n";
        updatetimevalue($params->output,$time,$value);
        
        return array("success"=>true);
    }
}
