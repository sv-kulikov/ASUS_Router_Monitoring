<?php

// Huge "Thank you!" to https://github.com/dunderrrrrr/Asus-RT-AC68U-Hooks
// The whole idea of this class is based on that code.

namespace Sv\Network\VmsRtbw;

/**
 * Class Hooks provides methods to execute API commands on an Asus router.
 */
class Hooks
{
    private string $deviceAddr;
    private string $login;
    private string $password;

    public function __construct(string $deviceAddr, string $login, string $password)
    {
        $this->deviceAddr = $deviceAddr;
        $this->login = $login;
        $this->password = $password;
    }


    private function getToken(): string
    {
        $payload = "login_authorization=" . base64_encode($this->login . ':' . $this->password);
        $headers = array(
            // Do not change! This is magic.
            // It seems like the router thinks that it was accessed from a mobile utility. 
            "user-agent: asusrouter-Android-DUTUtil-1.0.0.201"
        );
        $curlObject = curl_init();
        curl_setopt($curlObject, CURLOPT_URL, "http://" . $this->deviceAddr . "/login.cgi");
        curl_setopt($curlObject, CURLOPT_POST, true);
        curl_setopt($curlObject, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($curlObject, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curlObject, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($curlObject);
        curl_close($curlObject);
        $response_data = json_decode($response, true);
        return $response_data['asus_token'];
    }

    public function execApiCommands(array $apiCommands): array
    {
        $results = array();
        foreach ($apiCommands as $apiCommand) {
            $payload = "hook=" . $apiCommand;
            $headers = array(
                "user-agent: asusrouter-Android-DUTUtil-1.0.0.201",
                "cookie: asus_token=" . self::getToken()
            );
            $curlObject = curl_init();
            curl_setopt($curlObject, CURLOPT_URL, "http://" . $this->deviceAddr . "/appGet.cgi");
            curl_setopt($curlObject, CURLOPT_POST, true);
            curl_setopt($curlObject, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($curlObject, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($curlObject, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($curlObject);
            $statusCode = curl_getinfo($curlObject, CURLINFO_HTTP_CODE);
            curl_close($curlObject);
            $results[$apiCommand] = array('statusCode' => $statusCode, 'response' => $response);
        }
        return $results;
    }

}