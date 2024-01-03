<?php

namespace Sv\Network\VmsRtbw;

use DateTime;
use Exception;

class Config
{
    public static function getConfigData(string $xmlConfigFileName) : array
    {
        $xml = simplexml_load_file($xmlConfigFileName);
        $json = json_encode($xml);
        $array = json_decode($json,TRUE);
        try {
            $array['globalStartDateTime'] = new DateTime(date('Y-m-d H:i:s'));
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage();
        }
        return $array;
    }
}