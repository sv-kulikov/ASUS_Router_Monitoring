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
            $array['isAdmin'] = self::isAdmin();

            return $array;
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage();
            return [];
        }
    }

    /**
     * @return bool True if the script is running with admin privileges, false otherwise.
     */
    private static function isAdmin(): bool
    {
        $os = strtolower(PHP_OS);

        if (str_contains($os, 'win')) {

            // Windows-specific check
            $output = shell_exec('whoami /priv');
            return str_contains($output, 'SeTakeOwnershipPrivilege');
        } elseif (str_contains($os, 'linux') || str_contains($os, 'darwin')) {
            // Linux and macOS check
            // Using 'id -u' command to check if the user ID is 0 (root user)
            $output = shell_exec('id -u');
            return trim($output) === '0';
        }

        return false;
    }

}
