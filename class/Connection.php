<?php

namespace Sv\Network\VmsRtbw;
use phpseclib3\Net\SSH2;

class Connection
{
    public function getConnection(string $routerIp, string $routerLogin, string $routerPassword, string $routerPort) : SSH2
    {
        $sshClient = new SSH2($routerIp, $routerPort);
        if (!$sshClient->login($routerLogin, $routerPassword)) {
            exit("Login to router failed.\n");
        }
        return $sshClient;
    }

}