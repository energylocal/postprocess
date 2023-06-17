<?php

class PostProcess_addfeeds extends PostProcess_common
{
    public function description() {
        return array(
            "name"=>"addfeeds",
            "group"=>"Feeds",
            "description"=>"Add two feeds together",
            "settings"=>array(
                "feedA"=>array("type"=>"feed", "engine"=>5, "short"=>"Select feed A:"),
                "feedB"=>array("type"=>"feed", "engine"=>5, "short"=>"Select feed B:"),
                "output"=>array("type"=>"newfeed", "engine"=>5, "short"=>"Enter output feed name:", "nameappend"=>"")
            )
        );
    }

    public function process($processitem)
    {
        $result = $this->validate($processitem);
        if (!$result["success"]) return $result;
                
        $dir = $this->dir;
        $feedA = $processitem->feedA;
        $feedB = $processitem->feedB;
        $output = $processitem->output;

        $feedA_meta = getmeta($dir,$feedA);
        $feedB_meta = getmeta($dir,$feedB);
        
        if ($feedA_meta->interval != $feedB_meta->interval) {
            print "NOTICE: interval of feeds do not match, feedA:$feedA_meta->interval, feedB:$feedB_meta->interval\n";
        }
        
        print "FeedA start_time=$feedA_meta->start_time interval=$feedA_meta->interval\n";
        print "FeedB start_time=$feedB_meta->start_time interval=$feedB_meta->interval\n";
        
        if ($feedA_meta->interval==$feedB_meta->interval) $out_interval = $feedA_meta->interval;
        if ($feedA_meta->interval>$feedB_meta->interval) $out_interval = $feedA_meta->interval;
        if ($feedA_meta->interval<$feedB_meta->interval) $out_interval = $feedB_meta->interval;
        
        $out_start_time = 0;
        if ($feedA_meta->start_time==$feedB_meta->start_time) $out_start_time = (int) $feedA_meta->start_time;
        if ($feedA_meta->start_time<$feedB_meta->start_time) $out_start_time = (int) $feedA_meta->start_time;
        if ($feedA_meta->start_time>$feedB_meta->start_time) $out_start_time = (int) $feedB_meta->start_time;
        
        $out_start_time = floor($out_start_time / $out_interval) * $out_interval;
        
        $out_meta = new stdClass();
        $out_meta->start_time = $out_start_time;
        $out_meta->interval = $out_interval;
        
        print "OUT start_time=$out_start_time interval=$out_interval\n";
        
        createmeta($dir,$output,$out_meta);
        
        $output_meta = getmeta($dir,$output);

        if (!$feedA_fh = @fopen($dir.$feedA.".dat", 'rb')) {
            return array("success"=>false,"error"=>"could not open $dir $feedA.dat");
        }
        
        if (!$feedB_fh = @fopen($dir.$feedB.".dat", 'rb')) {
            return array("success"=>false,"error"=>"could not open $dir $feedB.dat");
        }
        
        if (!$output_fh = @fopen($dir.$output.".dat", 'ab')) {
            return array("success"=>false,"error"=>"could not open $dir $output.dat");
        }
        
        // Work out start and end time of merged feeds:
        $feedA_end_time = $feedA_meta->start_time + ($feedA_meta->interval * $feedA_meta->npoints);
        $feedB_end_time = $feedB_meta->start_time + ($feedB_meta->interval * $feedB_meta->npoints);
        
        $start_time = $output_meta->start_time + ($output_meta->npoints * $output_meta->interval);
        $end_time = $feedA_end_time;
        if ($feedB_end_time>$feedA_end_time) $end_time = $feedB_end_time;
        
        $interval = $output_meta->interval;
        
        $buffer = "";
        for ($time=$start_time; $time<$end_time; $time+=$interval) 
        {
            $posA = floor(($time - $feedA_meta->start_time) / $feedA_meta->interval);
            $posB = floor(($time - $feedB_meta->start_time) / $feedB_meta->interval);
        
            $valueA = NAN;
            $valueB = NAN;
        
            if ($posA>=0 && $posA<$feedA_meta->npoints) {
                fseek($feedA_fh,$posA*4);
                $feedA_tmp = unpack("f",fread($feedA_fh,4));
                $valueA = $feedA_tmp[1];
            }

            if ($posB>=0 && $posB<$feedB_meta->npoints) {
                fseek($feedB_fh,$posB*4);
                $feedB_tmp = unpack("f",fread($feedB_fh,4));
                $valueB = $feedB_tmp[1];
            }
            
            $outval = NAN;
            if (!is_nan($valueA)) $outval = $valueA;
            if (!is_nan($valueB)) $outval = $valueB;
            if (!is_nan($valueA) && !is_nan($valueB)) $outval = $valueB + $valueA;
            
            $buffer .= pack("f",$outval*1.0);
        }
            
        fwrite($output_fh,$buffer);
        
        $byteswritten = strlen($buffer);
        print "bytes written: ".$byteswritten."\n";
        fclose($output_fh);
        fclose($feedA_fh);
        fclose($feedB_fh);
        
        if ($byteswritten>0) {
            print "last time value: ".$time." ".$outval."\n";
            updatetimevalue($output,$time,$outval);
        }
        return array("success"=>true);
    }
}