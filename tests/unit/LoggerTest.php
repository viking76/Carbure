<?php

use Carbure\Lib\Config;
use Carbure\Lib\Logger;
use PHPUnit\Framework\TestCase;

class LoggerTest extends TestCase
{
    /**
     * Last entry of the log of the tests containing a text
     */
    private function lastEntry($text)
    {
        $file = Config::get('data_dir') . '/logs/carbure_test_' . date('Ymd') . '.log';
        $lines = array_filter(file($file), fn($line) => str_contains($line, $text));
        return json_decode(end($lines), true);
    }

    public function testEntriesAreJsonLines()
    {
        $message = "Unit test message " . uniqid();
        Logger::warn("$message\nwith a second line", ['key' => 'value']);
        Logger::flush();

        // One line, even with a newline in the message
        $entry = $this->lastEntry($message);
        $this->assertSame('WARNING', $entry['level']);
        $this->assertSame(Logger::getUID(), $entry['uid']);
        $this->assertSame("$message\nwith a second line", $entry['message']);
        $this->assertSame(['key' => 'value'], $entry['context']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}[+-]\d{2}:\d{2}$/', $entry['time']);
        $this->assertStringContainsString('LoggerTest', $entry['caller']);
    }

    public function testClientIp()
    {
        $server = $_SERVER;
        try {
            // Outside of an HTTP request
            unset($_SERVER['REMOTE_ADDR']);
            $this->assertNull(Logger::clientIp());

            // A client from Internet: its forwarded headers can be forged
            $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
            $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
            $this->assertSame('203.0.113.7', Logger::clientIp());

            // Behind the proxy of the NAS: the last public address of X-Forwarded-For
            $_SERVER['REMOTE_ADDR'] = '172.17.0.1';
            $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1, 203.0.113.9, 192.168.1.2';
            $this->assertSame('203.0.113.9', Logger::clientIp());

            // On the local network
            $_SERVER['HTTP_X_FORWARDED_FOR'] = '192.168.1.20';
            $this->assertSame('192.168.1.20', Logger::clientIp());

            // X-Real-IP without X-Forwarded-For, then no header at all
            unset($_SERVER['HTTP_X_FORWARDED_FOR']);
            $_SERVER['HTTP_X_REAL_IP'] = '203.0.113.10';
            $this->assertSame('203.0.113.10', Logger::clientIp());
            unset($_SERVER['HTTP_X_REAL_IP']);
            $this->assertSame('172.17.0.1', Logger::clientIp());
        } finally {
            $_SERVER = $server;
        }
    }

    public function testTokensOfTheUrlAreMasked()
    {
        $this->assertSame('/api/bank/sync?token=***&account=1', Logger::maskUrl('/api/bank/sync?token=secret&account=1'));
        $this->assertSame('/oauth/authorize?code=***', Logger::maskUrl('/oauth/authorize?code=abc'));
    }

    public function testBufferIsFlushedWhenFull()
    {
        $file = Config::get('data_dir') . '/logs/carbure_test_' . date('Ymd') . '.log';
        $message = 'Buffered message ' . uniqid();

        Logger::setBufferSize(1);
        try {
            Logger::info($message);
            // Written without an explicit flush
            $this->assertStringContainsString($message, file_get_contents($file));
        } finally {
            Logger::setBufferSize(50);
        }
    }

    public function testMessagesBelowTheLevelAreIgnored()
    {
        $minLevel = new ReflectionProperty(Logger::class, 'minLevel');
        $file = Config::get('data_dir') . '/logs/carbure_test_' . date('Ymd') . '.log';
        $message = 'Ignored message ' . uniqid();

        Config::set('log_level', 'error');
        $minLevel->setValue(null, null);
        try {
            Logger::warn($message);
            Logger::flush();
            $this->assertStringNotContainsString($message, file_get_contents($file));
        } finally {
            Config::set('log_level', 'debug');
            $minLevel->setValue(null, null);
        }
    }

    public function testPurgeDeletesTheFilesOlderThanTheRetention()
    {
        $dataDir = Config::get('data_dir');
        $dir = carbure_test_tmp('carbure-logs-' . uniqid());
        mkdir("$dir/logs", 0777, true);
        $old = 'carbure_' . date('Ymd', strtotime('-40 days')) . '.log';
        $oldScoped = 'carbure_e2e_' . date('Ymd', strtotime('-31 days')) . '.log';
        $recent = 'carbure_' . date('Ymd', strtotime('-29 days')) . '.log';
        $other = 'notes.log';
        foreach ([$old, $oldScoped, $recent, $other] as $name) {
            touch("$dir/logs/$name");
        }

        Config::set('data_dir', $dir);
        try {
            // 0 or absent: kept forever
            Config::set('log_retention_days', '0');
            $this->assertSame(0, Logger::retentionDays());
            $this->assertSame([], Logger::purge());

            Config::set('log_retention_days', '30');
            $this->assertSame(30, Logger::retentionDays());
            $deleted = Logger::purge();
            sort($deleted);
            $this->assertSame([$old, $oldScoped], $deleted);
            $this->assertFileExists("$dir/logs/$recent");
            $this->assertFileExists("$dir/logs/$other");
        } finally {
            Config::set('data_dir', $dataDir);
            Config::set('log_retention_days', '');
            array_map('unlink', glob("$dir/logs/*"));
            @rmdir("$dir/logs");
            @rmdir($dir);
        }
    }

    public function testTheRoutineEntriesOfAQuietRequestAreDebug()
    {
        $code = http_response_code();
        try {
            // A route that a user calls: INFO
            Logger::setQuiet(false);
            $message = 'Routine message ' . uniqid();
            Logger::routine($message);
            Logger::flush();
            $this->assertSame('INFO', $this->lastEntry($message)['level']);

            // A quiet request (GET /health): the call and the access line of a success are DEBUG
            Logger::setQuiet(true);
            $message = 'Quiet message ' . uniqid();
            Logger::routine($message);
            $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true) - 0.25;
            Logger::access();
            Logger::flush();
            $this->assertSame('DEBUG', $this->lastEntry($message)['level']);
            $this->assertSame('DEBUG', $this->lastEntry('"duration_ms"')['level']);

            // Its errors keep their level
            Logger::warn($message . ' warning');
            Logger::flush();
            $this->assertSame('WARNING', $this->lastEntry("$message warning")['level']);
            http_response_code(500);
            Logger::access();
            Logger::flush();
            $this->assertSame('ERROR', $this->lastEntry('"duration_ms"')['level']);
            $this->assertSame(500, $this->lastEntry('"duration_ms"')['context']['status']);
        } finally {
            Logger::setQuiet(false);
            http_response_code($code ?: 200);
        }
    }

    public function testEmailsAreMasked()
    {
        $this->assertSame('j***@example.org', Logger::maskEmail('jane.doe@example.org'));
        $this->assertSame('***', Logger::maskEmail('not-an-email'));
        $this->assertSame('***', Logger::maskEmail('@example.org'));
    }
}
