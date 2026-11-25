<?php

use Carbure\Lib\ApiResolver;
use Carbure\Lib\ApiRoute;
use Carbure\Resources\Changes;
use Carbure\Resources\User;
use PHPUnit\Framework\TestCase;

class ApiResolverTest extends TestCase
{
    public function testLoginIsPublic()
    {
        $route = ApiResolver::findRoute('/user/login', 'POST');

        $this->assertSame(User::class, $route['class']);
        $this->assertSame('login', $route['method']);
        $this->assertTrue($route['apiRoute']->public);
    }

    public function testSyncIsAStream()
    {
        $route = ApiResolver::findRoute('/bank/sync', 'GET');

        $this->assertSame('getSync', $route['method']);
        $this->assertTrue($route['apiRoute']->stream);
    }

    public function testUnknownRoute()
    {
        $this->assertNull(ApiResolver::findRoute('/does/not/exist', 'GET'));
        $this->assertNull(ApiResolver::findRoute('/user/login', 'GET'));
    }

    public function testClearRouteCacheRebuildsTheRoutes()
    {
        ApiResolver::clearRouteCache();
        $this->assertNotNull(ApiResolver::findRoute('/user/me', 'GET'));
    }

    public function testOnlyExpectedRoutesArePublic()
    {
        $public = [];
        foreach (glob(__DIR__ . '/../../src/resources/*.php') as $file) {
            $class = new ReflectionClass(ApiResolver::RESOURCES_NAMESPACE . basename($file, '.php'));
            foreach ($class->getMethods() as $method) {
                foreach ($method->getAttributes(ApiRoute::class) as $attribute) {
                    $route = $attribute->newInstance();
                    if ($route->public) {
                        $public[] = $route->method . ' ' . $route->path;
                    }
                }
            }
        }
        sort($public);

        // Any new public route must be a deliberate decision
        // The MCP routes authenticate with an API token themselves; the OAuth routes are
        // the metadata, the registration, the authorization (sent to the portal) and the tokens;
        // the second step of the login takes the challenge given by the first one (password)
        $this->assertSame([
            'GET /.well-known/oauth-authorization-server', 'GET /.well-known/oauth-protected-resource',
            'GET /.well-known/oauth-protected-resource/api/mcp', 'GET /bank/sync', 'GET /health', 'GET /mcp',
            'GET /oauth/authorize', 'POST /mcp', 'POST /oauth/register', 'POST /oauth/token', 'POST /user/login',
            'POST /user/login/mfa',
        ], $public);
    }

    /**
     * Access level of every route: public (no JWT), user (any authenticated user) or admin
     * (declared with admin: true, checked by Webservice). Any new route, or any change of
     * access level, must be a deliberate decision recorded here.
     */
    private const ACCESS = [
        'DELETE /bank' => 'admin',
        'DELETE /bank/backend' => 'admin',
        'DELETE /category' => 'admin',
        'DELETE /category/keyword' => 'admin',
        'DELETE /device' => 'user',
        'DELETE /insight' => 'admin',
        'DELETE /insight/alert' => 'user',
        'DELETE /tag' => 'user',
        'DELETE /token' => 'user',
        'DELETE /transaction' => 'user',
        'DELETE /user' => 'admin',
        'DELETE /user/browser' => 'user',
        'DELETE /user/mfa' => 'user',
        'DELETE /user/mfa/browser' => 'user',
        'GET /.well-known/oauth-authorization-server' => 'public',
        'GET /.well-known/oauth-protected-resource' => 'public',
        'GET /.well-known/oauth-protected-resource/api/mcp' => 'public',
        'GET /backup' => 'admin',
        'GET /backup/export' => 'admin',
        'GET /bank' => 'user',
        'GET /bank/accounts' => 'admin',
        'GET /bank/backends' => 'admin',
        'GET /bank/discover' => 'admin',
        'GET /bank/discover/interactive' => 'admin',
        'GET /bank/module' => 'admin',
        'GET /bank/modules' => 'admin',
        'GET /bank/sync' => 'public',
        'GET /bank/woob' => 'admin',
        'GET /bank/woob/update' => 'admin',
        'GET /budget' => 'user',
        'GET /budget/copy' => 'user',
        'GET /budget/flow' => 'user',
        'GET /budget/flow/transactions' => 'user',
        'GET /budget/insights' => 'user',
        'GET /budget/insights/history' => 'user',
        'GET /budget/trends' => 'user',
        'GET /budget/trends/categories' => 'user',
        'GET /budget/trends/charts' => 'user',
        'GET /budget/trends/series' => 'user',
        'GET /category' => 'user',
        'GET /category/keyword' => 'user',
        'GET /category/keyword/count' => 'admin',
        'GET /category/keyword/settings' => 'user',
        'GET /changes' => 'user',
        'GET /device' => 'user',
        'GET /device/link' => 'user',
        'GET /health' => 'public',
        'GET /insight' => 'admin',
        'GET /insight/alert' => 'user',
        'GET /insight/disabled' => 'user',
        'GET /mcp' => 'public',
        'GET /mcp/settings' => 'user',
        'GET /oauth/authorize' => 'public',
        'GET /oauth/client' => 'user',
        'GET /purge' => 'admin',
        'GET /system/config' => 'admin',
        'GET /system/logs' => 'admin',
        'GET /system/logs/entries' => 'admin',
        'GET /system/logs/retention' => 'admin',
        'GET /system/push' => 'admin',
        'GET /system/push-status' => 'admin',
        'GET /system/release' => 'admin',
        'GET /system/schema' => 'admin',
        'GET /system/sync' => 'admin',
        'GET /tag' => 'user',
        'GET /tag/summary' => 'user',
        'GET /token' => 'user',
        'GET /transaction' => 'user',
        'GET /transaction/periods' => 'user',
        'GET /transaction/recurring' => 'user',
        'GET /transaction/search' => 'user',
        'GET /user' => 'user',
        'GET /user/browser' => 'user',
        'GET /user/me' => 'user',
        'GET /user/mfa' => 'user',
        'GET /user/notifications' => 'user',
        'POST /backup/import' => 'admin',
        'POST /bank' => 'admin',
        'POST /bank/backend' => 'admin',
        'POST /bank/manual' => 'admin',
        'POST /budget' => 'user',
        'POST /budget/copy' => 'user',
        'POST /category' => 'admin',
        'POST /category/keyword' => 'admin',
        'POST /category/keyword/apply' => 'admin',
        'POST /device' => 'user',
        'POST /device/push' => 'user',
        'POST /insight' => 'admin',
        'POST /insight/check' => 'admin',
        'POST /mcp' => 'public',
        'POST /oauth/approve' => 'user',
        'POST /oauth/register' => 'public',
        'POST /oauth/token' => 'public',
        'POST /purge' => 'admin',
        'POST /system/jwt-secret' => 'admin',
        'POST /system/migrate' => 'admin',
        'POST /system/push-register' => 'admin',
        'POST /system/push-test' => 'admin',
        'POST /system/smtp-test' => 'admin',
        'POST /system/sync-token' => 'admin',
        'POST /tag' => 'user',
        'POST /token' => 'user',
        'POST /transaction' => 'user',
        'POST /transaction/import' => 'user',
        'POST /transaction/import/preview' => 'user',
        'POST /user' => 'admin',
        'POST /user/login' => 'public',
        'POST /user/login/mfa' => 'public',
        'POST /user/mfa/enable' => 'user',
        'POST /user/mfa/recovery-codes' => 'user',
        'POST /user/mfa/reset' => 'admin',
        'POST /user/mfa/setup' => 'user',
        'POST /user/unlock' => 'admin',
        'PUT /bank' => 'admin',
        'PUT /bank/backend' => 'admin',
        'PUT /budget/trends/charts' => 'admin',
        'PUT /category' => 'admin',
        'PUT /category/keyword' => 'admin',
        'PUT /category/keyword/settings' => 'admin',
        'PUT /insight' => 'admin',
        'PUT /insight/alert' => 'user',
        'PUT /insight/enabled' => 'user',
        'PUT /insight/order' => 'user',
        'PUT /mcp/settings' => 'admin',
        'PUT /system/config' => 'admin',
        'PUT /tag' => 'user',
        'PUT /transaction' => 'user',
        'PUT /transaction/categories' => 'user',
        'PUT /transaction/category' => 'user',
        'PUT /transaction/comment' => 'user',
        'PUT /transaction/date' => 'user',
        'PUT /transaction/pointed' => 'user',
        'PUT /transaction/split' => 'user',
        'PUT /transaction/tags' => 'user',
        'PUT /user' => 'user',
        'PUT /user/notifications' => 'user',
    ];

    public function testAccessLevelOfEveryRoute()
    {
        $access = [];
        foreach (glob(__DIR__ . '/../../src/resources/*.php') as $file) {
            $class = new ReflectionClass(ApiResolver::RESOURCES_NAMESPACE . basename($file, '.php'));
            foreach ($class->getMethods() as $method) {
                foreach ($method->getAttributes(ApiRoute::class) as $attribute) {
                    $route = $attribute->newInstance();
                    $access[$route->method . ' ' . $route->path] = $route->access();
                }
            }
        }
        ksort($access);

        $missing = array_diff_key($access, self::ACCESS);
        $this->assertSame([], array_keys($missing), 'Routes without an access level in ApiResolverTest::ACCESS:'
            . ' add each one with public, user or admin (admin: true in its ApiRoute attribute)');
        $removed = array_diff_key(self::ACCESS, $access);
        $this->assertSame([], array_keys($removed), 'Routes of ApiResolverTest::ACCESS that no longer exist'
            . ' (the iOS app may still call them)');
        $this->assertSame(self::ACCESS, $access, 'The access level of a route changed: make it deliberate');
    }

    public function testEveryWriteRouteDeclaresTheDataItChanges()
    {
        $undeclared = [];
        $reads = [];
        foreach (glob(__DIR__ . '/../../src/resources/*.php') as $file) {
            $class = new ReflectionClass(ApiResolver::RESOURCES_NAMESPACE . basename($file, '.php'));
            foreach ($class->getMethods() as $method) {
                foreach ($method->getAttributes(ApiRoute::class) as $attribute) {
                    $route = $attribute->newInstance();
                    if ($route->method !== 'GET' && $route->changes === null) {
                        $undeclared[] = $route->method . ' ' . $route->path;
                    } elseif ($route->method === 'GET' && $route->changes !== null) {
                        $reads[] = $route->path;
                    }
                }
            }
        }

        $this->assertSame([], $undeclared, 'Declare the kinds of data each write route changes (Changes::KINDS),'
            . ' changes: [] for none: the apps reload only the kinds that moved');
        // A GET that writes (the synchronization) bumps the kinds itself, when it changed them
        $this->assertSame([], $reads, 'A GET route declares no changes');
    }

    public function testCachedRoutesCarryTheChanges()
    {
        ApiResolver::clearRouteCache();
        $this->assertSame(['budgets'], ApiResolver::findRoute('/budget', 'POST')['apiRoute']->changes);
        $this->assertSame(Changes::KINDS, ApiResolver::findRoute('/backup/import', 'POST')['apiRoute']->changes);
        $this->assertSame([], ApiResolver::findRoute('/user', 'PUT')['apiRoute']->changes);
        $this->assertNull(ApiResolver::findRoute('/changes', 'GET')['apiRoute']->changes);
    }

    public function testOnlyTheHealthCheckIsQuiet()
    {
        $quiet = [];
        foreach (glob(__DIR__ . '/../../src/resources/*.php') as $file) {
            $class = new ReflectionClass(ApiResolver::RESOURCES_NAMESPACE . basename($file, '.php'));
            foreach ($class->getMethods() as $method) {
                foreach ($method->getAttributes(ApiRoute::class) as $attribute) {
                    $route = $attribute->newInstance();
                    if ($route->quiet) {
                        $quiet[] = $route->method . ' ' . $route->path;
                    }
                }
            }
        }
        // Logged at the DEBUG level only: a route that a user calls must stay visible
        $this->assertSame(['GET /health'], $quiet);

        // Carried by the cached routes
        ApiResolver::clearRouteCache();
        $this->assertTrue(ApiResolver::findRoute('/health', 'GET')['apiRoute']->quiet);
        $this->assertFalse(ApiResolver::findRoute('/user/me', 'GET')['apiRoute']->quiet);
    }

    public function testAnUnknownKindOfDataIsRefused()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The route POST /x declares unknown kinds of data: budget');
        new ApiRoute('/x', method: 'POST', changes: ['budget']);
    }

    public function testCachedRoutesCarryTheAdminFlag()
    {
        ApiResolver::clearRouteCache();
        $this->assertTrue(ApiResolver::findRoute('/system/config', 'PUT')['apiRoute']->admin);
        $this->assertTrue(ApiResolver::findRoute('/bank/woob/update', 'GET')['apiRoute']->admin);
        $this->assertFalse(ApiResolver::findRoute('/user', 'PUT')['apiRoute']->admin);
        $this->assertSame('admin', ApiResolver::findRoute('/user', 'POST')['apiRoute']->access());
        $this->assertSame('user', ApiResolver::findRoute('/user/me', 'GET')['apiRoute']->access());
        $this->assertSame('public', ApiResolver::findRoute('/user/login', 'POST')['apiRoute']->access());
    }

    public function testAPublicRouteCannotBeReservedToTheAdministrators()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The route GET /x cannot be both public and reserved to the administrators');
        new ApiRoute('/x', public: true, admin: true);
    }

    public function testRouteParamsAcceptWhatTheClientsSend()
    {
        $problems = [];
        foreach (glob(__DIR__ . '/../../src/resources/*.php') as $file) {
            $class = new ReflectionClass(ApiResolver::RESOURCES_NAMESPACE . basename($file, '.php'));
            foreach ($class->getMethods() as $method) {
                if (!$method->getAttributes(ApiRoute::class)) {
                    continue;
                }
                foreach ($method->getParameters() as $param) {
                    $type = $param->getType();
                    $name = $class->getName() . '::' . $method->getName() . '($' . $param->getName() . ')';
                    if ($type === null) {
                        $problems[] = "$name has no type";
                    } elseif (in_array((string)$type, ['bool', '?bool'], true)) {
                        // From the query string, "false" would be coerced to true
                        $problems[] = "$name is a bare bool";
                    } elseif ($param->isDefaultValueAvailable() && $param->getDefaultValue() === null && !$type->allowsNull()) {
                        $problems[] = "$name defaults to null but does not accept it";
                    }
                }
            }
        }

        $this->assertSame([], $problems);
    }
}
