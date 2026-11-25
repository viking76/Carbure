<?php

use Carbure\Lib\ApiError;
use Carbure\Lib\Config;
use Carbure\Lib\Logger;
use Carbure\Lib\Webservice;
use PHPUnit\Framework\TestCase;

/**
 * Fake resource used to test the service dispatch.
 */
final class EchoResource
{
    public static function echo($first, $second = 'default')
    {
        return ['first' => $first, 'second' => $second];
    }
}

class WebserviceTest extends TestCase
{
    protected function tearDown(): void
    {
        $_GET = [];
    }

    public function testErrorOutputsJson()
    {
        ob_start();
        Webservice::error(404, new Error("Route not found"));
        $output = json_decode(ob_get_clean(), true);

        $this->assertSame("Route not found", $output['error']);
        $this->assertSame(404, $output['code']);
        $this->assertSame(Logger::getUID(), $output['uid']);
        // A plain error: no stable code, the format of the older versions
        $this->assertSame(['error', 'code', 'uid'], array_keys($output));
    }

    public function testErrorOutputsTheStableCodeAndTheValuesOfAnApiError()
    {
        ob_start();
        Webservice::error(423, new ApiError("Account locked after too many failed logins, until 2026-10-26 18:30:00: ask an administrator to unlock it",
            423, 'account_locked_until', ['until' => '2026-10-26 18:30:00']));
        $output = json_decode(ob_get_clean(), true);

        // Fields added: the message and the HTTP status stay as before
        $this->assertSame([
            'error' => "Account locked after too many failed logins, until 2026-10-26 18:30:00: ask an administrator to unlock it",
            'code' => 423,
            'uid' => Logger::getUID(),
            'error_code' => 'account_locked_until',
            'params' => ['until' => '2026-10-26 18:30:00'],
        ], $output);

        // Without values: no params
        ob_start();
        Webservice::error(409, new ApiError("This keyword already exists", 409, 'keyword_exists'));
        $output = json_decode(ob_get_clean(), true);
        $this->assertSame(['This keyword already exists', 409, 'keyword_exists'], [$output['error'], $output['code'], $output['error_code']]);
        $this->assertArrayNotHasKey('params', $output);
    }

    public function testAnApiErrorIsAnErrorWithItsHttpStatus()
    {
        try {
            throw new ApiError("This keyword already exists", 409, 'keyword_exists');
        } catch (Error $e) {
            // Still an Error: caught like the others, its code is the HTTP status
            $this->assertSame(409, $e->getCode());
            $this->assertSame(['error_code' => 'keyword_exists'], ApiError::fields($e));
        }
        $this->assertSame([], ApiError::fields(new Error("Route not found", 404)));
        $this->assertSame([], ApiError::fields(new Exception("Database down")));
    }

    public function testTheLoggedTraceHasNoArgument()
    {
        // Even when PHP keeps the arguments of the traces (built-in default)
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        try {
            try {
                (fn(string $password) => throw new Error("Bank refused"))('s3cret-bank-password');
            } catch (Error $error) {
                $this->assertSame('s3cret-bank-password', $error->getTrace()[0]['args'][0] ?? null);
                foreach (Webservice::traceWithoutArgs($error) as $call) {
                    $this->assertArrayNotHasKey('args', $call);
                }
                ob_start();
                Webservice::error(502, $error);
                ob_end_clean();
                Logger::flush();
            }
            $log = file_get_contents(Config::get('data_dir') . '/logs/carbure_test_' . date('Ymd') . '.log');
            $this->assertStringContainsString('Bank refused', $log);
            $this->assertStringNotContainsString('s3cret-bank-password', $log);
        } finally {
            ini_set('zend.exception_ignore_args', (string)$ignoreArgs);
        }
    }

    public function testInvalidUtf8IsAnErrorNotAnEmptyAnswer()
    {
        // "Épargne" read in latin1 (database connection in the wrong charset)
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('cannot be encoded in JSON');
        Webservice::callService('EchoResource', 'echo', ['first' => "\xC9pargne"]);
    }

    public function testCallServiceUsesNamedParameters()
    {
        $response = json_decode(Webservice::callService('EchoResource', 'echo', ['second' => 'b', 'first' => 'a']), true);

        $this->assertSame(['first' => 'a', 'second' => 'b'], $response);
    }

    public function testCallServiceMergesQueryParameters()
    {
        $_GET = ['first' => 'from-query'];
        $response = json_decode(Webservice::callService('EchoResource', 'echo', []), true);

        $this->assertSame('from-query', $response['first']);
        $this->assertSame('default', $response['second']);
    }

    public function testCallServiceRejectsUnknownParameter()
    {
        $this->expectException(Error::class);
        Webservice::callService('EchoResource', 'echo', ['first' => 'a', 'unknown' => 'x']);
    }

    public function testNumbersKeepIdentifiersIntact()
    {
        $normalized = Webservice::normalizeNumbers([
            'amount' => '-42.50', 'id' => '12', 'zero' => '0', 'progress' => '113',
            'account_number' => '00087654321',
            'bnp' => '2741588268268091098895144775404880395391938022422272465153',
            'exponent' => '1e5', 'label' => 'CB 100% BIO', 'null' => null, 'int' => 7,
            'nested' => [['account_number' => '0123']],
        ]);

        $this->assertSame(-42.5, $normalized['amount']);
        $this->assertSame(12, $normalized['id']);
        $this->assertSame(0, $normalized['zero']);
        $this->assertSame('00087654321', $normalized['account_number']);
        $this->assertSame('2741588268268091098895144775404880395391938022422272465153', $normalized['bnp']);
        $this->assertSame('1e5', $normalized['exponent']);
        $this->assertSame('CB 100% BIO', $normalized['label']);
        $this->assertNull($normalized['null']);
        $this->assertSame(7, $normalized['int']);
        $this->assertSame('0123', $normalized['nested'][0]['account_number']);
    }

    public function testVersionsOfTheDevicesStayTexts()
    {
        $normalized = Webservice::normalizeNumbers([['id' => '3', 'model' => '8', 'os_version' => '15', 'app_version' => '1.10']]);
        $this->assertSame([['id' => 3, 'model' => '8', 'os_version' => '15', 'app_version' => '1.10']], $normalized);
    }

    public function testSensitiveValuesAreMasked()
    {
        $method = new ReflectionMethod(Webservice::class, 'maskSensitive');
        $masked = $method->invoke(null, [
            'username' => 'bob',
            'password' => 'secret',
            'device' => ['name' => 'iPhone', 'token' => 'abcdef'],
            'values' => ['smtp_host' => 'smtp.example.com', 'smtp_password' => 'mail-secret'],
            'datasets' => ['transactions' => [['label' => 'CB SUPERMARCHE']]],
        ]);

        $this->assertSame('bob', $masked['username']);
        $this->assertSame('***', $masked['password']);
        $this->assertSame('iPhone', $masked['device']['name']);
        $this->assertSame('***', $masked['device']['token']);
        // The SMTP password changed in Administration > Settings
        $this->assertSame('smtp.example.com', $masked['values']['smtp_host']);
        $this->assertSame('***', $masked['values']['smtp_password']);
        // The rows of an imported backup (household data)
        $this->assertSame('***', $masked['datasets']);
    }

    public function testPayload()
    {
        $this->assertSame([], Webservice::getPayload(''));
        $this->assertSame(['a' => 1], Webservice::getPayload('{"a":1}'));
        $this->assertSame([], Webservice::getPayload('null'));

        $this->expectException(Error::class);
        Webservice::getPayload('{invalid');
    }

    public function testExecUnknownRoute()
    {
        $_SERVER['REQUEST_URI'] = '/api/does/not/exist?x=1';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->expectException(Error::class);
        $this->expectExceptionCode(404);
        Webservice::exec();
    }

    public function testExecProtectedRouteWithoutToken()
    {
        $_SERVER['REQUEST_URI'] = '/api/transaction';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->expectException(Error::class);
        $this->expectExceptionCode(401);
        Webservice::exec();
    }

    public function testExecSyncWithoutTokenIsRefused()
    {
        $_SERVER['REQUEST_URI'] = '/api/bank/sync';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $level = ob_get_level();

        try {
            Webservice::exec();
            $this->fail('An error was expected');
        } catch (Error $e) {
            $this->assertSame(401, $e->getCode());
        } finally {
            // The stream headers close the output buffers: restore PHPUnit's
            while (ob_get_level() < $level) {
                ob_start();
            }
        }
    }

    public function testServerSentEvents()
    {
        ob_start();
        Webservice::sendProgress('Syncing');
        Webservice::sendHeartbeat();
        $this->assertSame("data: Syncing\n\n: heartbeat\n\n", ob_get_clean());
    }

    public function testApiEntryPoint()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        // Unknown route: JSON error
        $_SERVER['REQUEST_URI'] = '/api/does/not/exist';
        ob_start();
        include __DIR__ . '/../../src/api.php';
        $output = json_decode(ob_get_clean(), true);
        $this->assertSame(404, $output['code']);

        // Static files (with a dot) are not handled by the API
        $_SERVER['REQUEST_URI'] = '/portal/app.js';
        ob_start();
        include __DIR__ . '/../../src/api.php';
        $this->assertSame('', ob_get_clean());
    }

    public function testGetHeader()
    {
        $_SERVER['HTTP_X_SYNC_TOKEN'] = 'abc';
        $this->assertSame('abc', Webservice::getHeader('X-Sync-Token'));
        unset($_SERVER['HTTP_X_SYNC_TOKEN']);

        $this->assertNull(Webservice::getHeader('X-Sync-Token'));
    }

    public function testTheCallIsLoggedWithTheNamesOfItsParametersOnly()
    {
        $minLevel = new ReflectionProperty(Logger::class, 'minLevel');
        $value = 'household-label-' . uniqid();
        Config::set('log_level', 'info');
        $minLevel->setValue(null, null);
        try {
            Webservice::callService('EchoResource', 'echo', ['first' => $value]);
            Logger::flush();
        } finally {
            Config::set('log_level', 'debug');
            $minLevel->setValue(null, null);
        }
        $log = file_get_contents(Config::get('data_dir') . '/logs/carbure_test_' . date('Ymd') . '.log');
        $this->assertStringNotContainsString($value, $log);
        $this->assertStringContainsString('"message":"Calling : EchoResource.echo","context":{"params":["first"]}', $log);
    }

    public function testTheHealthCheckIsLoggedAtTheDebugLevel()
    {
        $minLevel = new ReflectionProperty(Logger::class, 'minLevel');
        $file = Config::get('data_dir') . '/logs/carbure_test_' . date('Ymd') . '.log';
        Logger::flush();
        clearstatcache();
        $size = is_file($file) ? filesize($file) : 0;
        $_SERVER['REQUEST_URI'] = '/api/health';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        // At the INFO level: no entry for the call (the database may be missing here: only
        // its error would be written)
        Config::set('log_level', 'info');
        $minLevel->setValue(null, null);
        try {
            try {
                Webservice::exec();
            } catch (Throwable) {
                // No database in the unit tests
            }
            Logger::flush();
        } finally {
            Config::set('log_level', 'debug');
            $minLevel->setValue(null, null);
            Logger::setQuiet(false);
        }
        clearstatcache();
        $written = is_file($file) ? (string)file_get_contents($file, false, null, $size) : '';
        $this->assertStringNotContainsString('Calling : System.health', $written);

        // At the DEBUG level: the call is written, at the DEBUG level
        try {
            try {
                Webservice::exec();
            } catch (Throwable) {
                // No database in the unit tests
            }
            Logger::flush();
        } finally {
            Logger::setQuiet(false);
        }
        $lines = array_values(array_filter(file($file), fn($line) => str_contains($line, '"message":"Calling : System.health"')));
        $this->assertNotEmpty($lines);
        $this->assertSame('DEBUG', json_decode(end($lines), true)['level']);
    }
}
