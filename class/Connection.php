<?php

namespace Sv\Network\VmsRtbw;

use phpseclib3\Net\SSH2;
use Exception;

/**
 * Class Connection provides methods to establish an SSH connection.
 */
class Connection
{
    /**
     * Establishes an SSH connection to a router.
     *
     * @param string $routerIp IP address of the router.
     * @param string $routerLogin Login username for the router.
     * @param string $routerPassword Password for the router.
     * @param int $routerPort Port number for the SSH connection.
     * @return SSH2 SSH client instance.
     * @throws Exception If connection or login to the router fails.
     */
    public function getConnection(string $routerIp, string $routerLogin, string $routerPassword, int $routerPort): SSH2
    {
        // Initialize the SSH client
        $sshClient = new SSH2($routerIp, $routerPort);

        // Attempt to log in with provided credentials
        if (!$sshClient->login($routerLogin, $routerPassword)) {
            throw new Exception("Failed to login to router at IP: $routerIp, Port: $routerPort.");
        }

        return $sshClient;
    }
}