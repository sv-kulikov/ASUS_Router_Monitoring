<?php

namespace Sv\Network\VmsRtbw;

use DateTime;
use Exception;
use SimpleXMLElement;

/**
 * Class Config provides methods to read the application configuration.
 */
class Config
{
    /**
     * @param string $xmlConfigFileName Path to the XML configuration file.
     * @return array Configuration data.
     */
    public static function getConfigData(string $xmlConfigFileName): array
    {
        try {
            if (!file_exists($xmlConfigFileName)) {
                throw new Exception("File not found: $xmlConfigFileName");
            }

            $xml = simplexml_load_file($xmlConfigFileName, SimpleXMLElement::class, options: LIBXML_NOERROR | LIBXML_NOWARNING);
            if ($xml === false) {
                throw new Exception("Failed to load XML file: $xmlConfigFileName");
            }

            $array = json_decode(json_encode($xml), true);
            $array['globalStartDateTime'] = new DateTime();

            return $array;
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage();
            return [];
        }
    }
}
