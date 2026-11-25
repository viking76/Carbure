<?php

use Carbure\Lib\ApiResolver;
use Carbure\Lib\ApiRoute;

/**
 * Access control of the routes through Webservice::exec(): the administrator routes
 * (admin: true in their ApiRoute attribute) and the conditional checks of User::update.
 */
class AccessTest extends DatabaseTestCase
{
    /**
     * The routes declared with admin: true ("METHOD /path").
     */
    private function adminRoutes()
    {
        $routes = [];
        foreach (glob(__DIR__ . '/../../src/resources/*.php') as $file) {
            $class = new ReflectionClass(ApiResolver::RESOURCES_NAMESPACE . basename($file, '.php'));
            foreach ($class->getMethods() as $method) {
                foreach ($method->getAttributes(ApiRoute::class) as $attribute) {
                    $route = $attribute->newInstance();
                    if ($route->admin) {
                        $routes[] = $route->method . ' ' . $route->path;
                    }
                }
            }
        }
        return $routes;
    }

    public function testEveryAdministratorRouteRefusesAUser()
    {
        $routes = $this->adminRoutes();
        $this->assertCount(56, $routes);

        $this->loginAs(self::USER);
        $this->assertAdminOnly($routes);
    }

    public function testAdministratorRouteWithoutTokenIsUnauthorized()
    {
        $this->expectException(Error::class);
        $this->expectExceptionCode(401);
        $this->callRoute('GET', '/system/config');
    }

    public function testAdministratorPassesTheCheck()
    {
        $this->loginAs(self::ADMIN);
        $this->assertCount(5, json_decode($this->callRoute('GET', '/category'), true));
        $this->assertSame('true', $this->callRoute('DELETE', '/category/keyword', ['id' => 1]));
        $this->assertArrayHasKey('sections', json_decode($this->callRoute('GET', '/system/config'), true));
    }

    public function testUserRouteStaysOpenToAUser()
    {
        $this->loginAs(self::USER);
        $this->assertSame('user', json_decode($this->callRoute('GET', '/user/me'), true)['username']);
        $this->assertIsArray(json_decode($this->callRoute('GET', '/user/notifications'), true));
    }

    public function testConditionalChecksOfTheUserUpdate()
    {
        // His own profile: allowed to a user
        $this->loginAs(self::USER);
        $this->assertSame('Me', json_decode($this->callRoute('PUT', '/user', ['id' => self::USER, 'firstname' => 'Me']), true)['firstname']);

        // Another user, or his own role: administrators only
        foreach ([['id' => self::ADMIN, 'firstname' => 'X'], ['id' => self::USER, 'is_admin' => 'true']] as $i => $params) {
            try {
                $this->callRoute('PUT', '/user', $params);
                $this->fail("Case $i accepted");
            } catch (Error $e) {
                $this->assertSame([403, 'Forbidden - Administrator only'], [$e->getCode(), $e->getMessage()], "Case $i");
            }
        }

        // An administrator updates another user
        $this->loginAs(self::ADMIN);
        $this->assertSame('X', json_decode($this->callRoute('PUT', '/user', ['id' => self::USER, 'firstname' => 'X']), true)['firstname']);
    }
}
