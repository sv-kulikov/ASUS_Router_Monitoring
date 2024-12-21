<?php

// Huge "Thank you!" to https://github.com/dunderrrrrr/Asus-RT-AC68U-Hooks
// The whole idea of this class is based on that code.

namespace Sv\Network\VmsRtbw;

/**
 * Class Hooks provides methods to execute API commands on an Asus router.
 *
 * This class facilitates interaction with Asus routers using API hooks.
 */
class Hooks
{
    private string $deviceAddr;
    private string $login;
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
     * @throws \Exception If the token retrieval fails.
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
            throw new \Exception("Failed to retrieve authentication token from router.");
        }

        $responseData = json_decode($response, true);
        if (!isset($responseData['asus_token'])) {
            throw new \Exception("Invalid response: Token not found.");
        }

        return $responseData['asus_token'];
    }

    /**
     * Executes API commands on the router.
     *
     * @param array $apiCommands List of API commands to execute.
     * @return array An associative array containing the status code and response for each command.
     * @throws \Exception If any API call fails.
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