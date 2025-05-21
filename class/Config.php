<?php

namespace Sv\Network\VmsRtbw;

use DateTime;
use Exception;
use SimpleXMLElement;

/**
 * Class Config provides methods to read and manage the application configuration.
 */
class Config
{
    /**
     * @var array Configuration data parsed from the XML file.
     */
    private array $configData = [];

    /**
     * Reads configuration data from an XML file.
     *
     * @param string $xmlConfigFileName Path to the XML configuration file.
     * @return void
     * @throws Exception If the file does not exist or cannot be parsed.
     */
    public function readConfigData(string $xmlConfigFileName): void
    {
        try {
            // Check if the configuration file exists
            if (!file_exists($xmlConfigFileName)) {
                throw new Exception("File not found: $xmlConfigFileName");
            }

            // Load the XML file
            $xml = simplexml_load_file(
                $xmlConfigFileName,
                SimpleXMLElement::class,
                LIBXML_NOERROR | LIBXML_NOWARNING
            );

            if ($xml === false) {
                throw new Exception("Failed to load XML file: $xmlConfigFileName");
            }

            // Convert the XML to an associative array
            $array = json_decode(json_encode($xml), true);

            // Add additional global configuration details
            $array['globalStartDateTime'] = new DateTime();
            $array['isAdmin'] = self::isAdmin();

            $this->configData = $array;
        } catch (Exception $e) {
            // Handle errors and set default empty configuration
            $this->configData = [];
            $localLogger = new Logger();
            $localLogger->logException($e);
        }
    }

    /**
     * Checks if the script is running with administrative privileges.
     *
     * @return bool True if the script is running as admin/root, false otherwise.
     */
    private static function isAdmin(): bool
    {
        $os = strtolower(PHP_OS);

        if (str_contains($os, 'win')) {
            // Windows-specific admin check
            $output = shell_exec('whoami /priv');
            return str_contains($output, 'SeTakeOwnershipPrivilege');
        } elseif (str_contains($os, 'linux') || str_contains($os, 'darwin')) {
            // Linux and macOS root check
            $output = shell_exec('id -u');
            return trim($output) === '0';
        }

        return false;
    }

    /**
     * Retrieves the entire configuration data.
     *
     * @return array The configuration data as an associative array.
     */
    public function getConfigData(): array
    {
        return $this->configData;
    }

    /**
     * Updates a top-level configuration parameter.
     *
     * @param string $parameter The parameter name.
     * @param string|int|float|DateTime $value The new value to set.
     * @return void
     */
    public function updateParameter(string $parameter, string|int|float|DateTime $value): void
    {
        $this->configData[$parameter] = $value;
    }

    /**
     * Updates a nested configuration parameter.
     *
     * @param string $parameterNameLevel1 The top-level parameter name.
     * @param string $parameterNameLevel2 The nested parameter name.
     * @param string $value The new value to set.
     * @return void
     */
    public function updateNestedParameter(string $parameterNameLevel1, string $parameterNameLevel2, string $value): void
    {
        $this->configData[$parameterNameLevel1][$parameterNameLevel2] = $value;
    }

    /**
     * Retrieves a top-level configuration parameter.
     *
     * @param string $parameter The parameter name.
     * @return string|int|bool|null|DateTime The parameter value or null if not found.
     */
    public function getParameter(string $parameter): string|int|bool|null|DateTime
    {
        return $this->configData[$parameter] ?? null;
    }

    /**
     * Retrieves a nested configuration parameter.
     *
     * @param string $parameterNameLevel1 The top-level parameter name.
     * @param string $parameterNameLevel2 The nested parameter name.
     * @return string|int|bool|null The nested parameter value or null if not found.
     */
    public function getNestedParameter(string $parameterNameLevel1, string $parameterNameLevel2): string|int|bool|null
    {
        return $this->configData[$parameterNameLevel1][$parameterNameLevel2] ?? null;
    }
}
