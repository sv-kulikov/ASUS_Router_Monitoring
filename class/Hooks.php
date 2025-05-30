<?php

/**
 * Huge "Thank you!" to https://github.com/dunderrrrrr/Asus-RT-AC68U-Hooks
 * The whole idea of this class is based on that code.
 *
 * Another useful resource 1: https://itnext.io/monitor-your-asus-router-in-python-171693465fc1
 * Another useful resource 2: https://github.com/lmeulen/AsusRouterMonitor
 *
 * List of interesting hooks:
 * netdev(appobj)         - miscellaneous network information
 * get_uptime()           - uptime and last boot time
 * get_uptime_secs()      - uptime
 * get_memory_usage()     - memory usage statistics
 * get_cpu_usage()        - CPU usage statistics
 * get_settings()         - router settings
 * get_clients_fullinfo() - all info of all connected clients
 * get_clients_info()     - info on all clients
 * get_client_info(cid)   - info of specified client (MAC address)
 * get_traffic_total()    - total network usage since last boot
 * get_traffic()          - current network usage and total usage
 * get_status_wan()       - WAN status info
 * is_wan_online()        - WAN connected true / false
 * get_lan_ip_adress()    - router IP address for LAN
 * get_lan_netmask()      - network mask for LAN
 * get_lan_gateway()      - gateway address for LAN
 * get_dhcp_list()        - list of DHCP leases
 * get_online_clients()   - list of online clients (MAC address)
 * get_clients_info()     - info on all clients
 * get_client_info(cid)   - info of specified client (MAC address)
 */

namespace Sv\Network\VmsRtbw;

use Exception;

/**
 * Class Hooks provides methods to execute API commands on an Asus router.
 *
 * This class facilitates interaction with Asus routers using API hooks.
 */
class Hooks
{
    /**
     * @var string The IP address or hostname of the Asus router / repeater.
     */
    private string $deviceAddr;

    /**
     * @var string The username for the router / repeater.
     */
    private string $login;

    /**
     * @var string The password for the router / repeater.
     */
    private string $password;

    /**
     * Hooks constructor.
     *
     * @param string $deviceAddr The IP address or hostname of the Asus router.
     * @param string $login The login username for the router.
     * @param string $password The login password for the router.
     */
    public function __construct(string $deviceAddr, string $login, string $password)
    {
        $this->deviceAddr = $deviceAddr;
        $this->login = $login;
        $this->password = $password;
    }

    /**
     * Retrieves the authentication token required for API calls.
     *
     * @return string The authentication token.
     * @throws Exception If the token retrieval fails.
     */
    private function getToken(): string
    {
        $payload = "login_authorization=" . base64_encode($this->login . ':' . $this->password);
        $headers = [
            "user-agent: asusrouter-Android-DUTUtil-1.0.0.201"
        ];

        $curlObject = curl_init();
        curl_setopt($curlObject, CURLOPT_URL, "http://" . $this->deviceAddr . "/login.cgi");
        curl_setopt($curlObject, CURLOPT_POST, true);
        curl_setopt($curlObject, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($curlObject, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curlObject, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($curlObject);
        $statusCode = curl_getinfo($curlObject, CURLINFO_HTTP_CODE);
        curl_close($curlObject);

        if ($statusCode !== 200 || $response === false) {
            throw new Exception("Failed to retrieve authentication token from router.");
        }

        $responseData = json_decode($response, true);
        if (!isset($responseData['asus_token'])) {
            throw new Exception("Invalid response: Token not found.");
        }

        return $responseData['asus_token'];
    }

    /**
     * Executes API commands on the router.
     *
     * @param array $apiCommands List of API commands to execute.
     * @return array An associative array containing the status code and response for each command.
     * @throws Exception If any API call fails.
     */
    public function execApiCommands(array $apiCommands): array
    {
        $results = [];
        $token = $this->getToken(); // Retrieve token once to avoid repeated calls

        foreach ($apiCommands as $apiCommand) {
            $payload = "hook=" . $apiCommand;
            $headers = [
                "user-agent: asusrouter-Android-DUTUtil-1.0.0.201",
                "cookie: asus_token=" . $token
            ];

            $curlObject = curl_init();
            curl_setopt($curlObject, CURLOPT_URL, "http://" . $this->deviceAddr . "/appGet.cgi");
            curl_setopt($curlObject, CURLOPT_POST, true);
            curl_setopt($curlObject, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($curlObject, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($curlObject, CURLOPT_RETURNTRANSFER, true);

            $response = curl_exec($curlObject);
            $statusCode = curl_getinfo($curlObject, CURLINFO_HTTP_CODE);
            curl_close($curlObject);

            if ($response === false || $statusCode !== 200) {
                $results[$apiCommand] = [
                    'statusCode' => $statusCode,
                    'response' => null,
                    'error' => "Failed to execute command: $apiCommand"
                ];
            } else {
                $results[$apiCommand] = [
                    'statusCode' => $statusCode,
                    'response' => $response
                ];
            }
        }

        return $results;
    }
}