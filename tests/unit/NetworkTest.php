<?php

use Carbure\Lib\Network;
use PHPUnit\Framework\TestCase;

class NetworkTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP'], $_SERVER['HTTP_FORWARDED']);
    }

    public function testLocalAddresses()
    {
        foreach (['127.0.0.1', '192.168.1.20', '10.0.0.5', '172.17.0.1', '::1', 'fd00::1', '192.168.1.1, 10.0.0.2'] as $addresses) {
            $this->assertTrue(Network::isLocal($addresses), $addresses);
        }
    }

    public function testInternetOrUnknownAddresses()
    {
        // One public address is enough: a reverse proxy of the NAS forwards a client from Internet
        foreach (['8.8.8.8', '2001:4860::8888', '192.168.1.1,8.8.8.8', '', ' , ', 'not-an-ip'] as $addresses) {
            $this->assertFalse(Network::isLocal($addresses), $addresses);
        }
    }

    public function testCurrentRequest()
    {
        $_SERVER['REMOTE_ADDR'] = '172.18.0.3';
        $this->assertTrue(Network::isLocalRequest());

        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.7';
        $this->assertFalse(Network::isLocalRequest());

        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        $_SERVER['HTTP_FORWARDED'] = 'for="[2606:4700:4700::1111]";proto=https';
        $this->assertFalse(Network::isLocalRequest());
    }
}
