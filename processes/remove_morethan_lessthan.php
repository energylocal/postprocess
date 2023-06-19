<?php

use JetBrains\PhpStorm\ArrayShape;

class PostProcess_remove_morethan_lessthan extends PostProcess_common
{
    public function description() {
        return array(
            "name"=>"remove_morethan_lessthan",
            "group"=>"Limits",
            "description"=>"Remove values more than or less than a certain value",
            "settings"=>array(
                "feedid"=>array("type"=>"feed", "engine"=>5, "short"=>"Select feed to remove nan values:"),
                "morethan"=>array("type"=>"value", "short"=>"Remove values above this limit:"),
                "lessthan"=>array("type"=>"value", "short"=>"Remote values below this limit:")
            )
        );
    }

    public function process($processitem)
    {
        $result = $this->validate($processitem);
        if (!$result["success"]) return $result;
        
        $dir = $this->dir;
        $id = $processitem->feedid;
        $morethan = $processitem->morethan;
        $lessthan = $processitem->lessthan;
    
        if (!$fh = @fopen($dir.$id.".dat", 'c+')) {
            return array("success"=>false, "message"=>"could not open input feed");
        }
        if (!$npoints = floor(filesize($dir.$id.".dat") / 4.0)) {
            return array("success"=>false, "message"=>"feed is empty");
        }
        $fpos = 0;
        $dplefttoread = $npoints;
        $blocksize = 100000;
        
        $fix_count = 0;
        
        $stime = microtime(true);
        while ($dplefttoread>0)
        {
            fseek($fh,$fpos*4);
            $values = unpack("f*",fread($fh,4*$blocksize));
            $count = count($values);
            if ($count==0) break;
            for ($i=1; $i<=$count; $i++)
            {
                $dpos = $fpos + ($i-1);
                
                if (!is_nan($values[$i])) {
                    if ($values[$i]>$morethan) {
                        fseek($fh,$dpos*4);
                        fwrite($fh,pack("f",NAN));
                        $fix_count ++;
                    }
                    else if ($values[$i]<$lessthan) {
                        fseek($fh,$dpos*4);
                        fwrite($fh,pack("f",NAN));
                        $fix_count ++;
                    }
                }
            }
            $dplefttoread -= $count;
            $fpos += $count;
        }
        fclose($fh);
        echo "nanfix: ".$fix_count." datapoints, ".round(($fix_count/$npoints)*100)."%\n";
        echo "time: ".(microtime(true)-$stime)."\n";
        return array("success"=>true);
    }
}