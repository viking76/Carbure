<?php

/**
 * ApiRoute.php
 *
 * Attribute to define API routes
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib;

use Attribute;
use Carbure\Resources\Changes;
use LogicException;

#[Attribute(Attribute::TARGET_METHOD)]
class ApiRoute {
    public string $path;
    public string $method;
    public bool $public;
    public bool $stream;
    public bool $raw = false;
    public bool $admin = false;
    public bool $quiet = false;
    /** @var list<string>|null */
    public ?array $changes = null;

    /**
     * Declare an API route on a public static method.
     *
     * @param string $path   The route path, without the /api prefix (e.g. /user/login)
     * @param string $method The HTTP method (GET, POST, PUT, DELETE)
     * @param bool   $public True if the route does not require a JWT
     * @param bool   $stream True if the route answers with Server-Sent Events
     * @param bool   $raw    True if the method returns the response body itself
     *                       (already encoded JSON, or '' for an empty body)
     * @param bool   $admin  True if the route is reserved to the administrators: checked by
     *                       Webservice before the method is called (403 otherwise)
     * @param list<string>|null $changes The kinds of data the route writes (Changes::KINDS), bumped
     *                       by Webservice after a successful call; [] for a write that changes
     *                       none of them. Required on every route but GET (ApiResolverTest)
     * @param bool   $quiet  True if the successful calls are logged at the DEBUG level only
     *                       (GET /health, polled by the healthchecks): its errors keep their level
     * @throws LogicException If the route is both public and reserved to the administrators,
     *                        or declares an unknown kind of data
     */
    public function __construct(
        string $path,
        string $method = 'GET',
        bool $public = false,
        bool $stream = false,
        bool $raw = false,
        bool $admin = false,
        ?array $changes = null,
        bool $quiet = false
    ) {
        if ($public && $admin) {
            throw new LogicException("The route $method $path cannot be both public and reserved to the administrators");
        }
        $unknown = array_diff($changes ?? [], Changes::KINDS);
        if ($unknown) {
            throw new LogicException("The route $method $path declares unknown kinds of data: " . implode(', ', $unknown));
        }
        $this->path = $path;
        $this->method = $method;
        $this->public = $public;
        $this->stream = $stream;
        $this->raw = $raw;
        $this->admin = $admin;
        $this->changes = $changes;
        $this->quiet = $quiet;
    }

    /**
     * Access level of the route: public (no JWT), user (any authenticated user) or admin.
     *
     * @return string public, user or admin
     */
    public function access(): string
    {
        if ($this->public) {
            return 'public';
        }
        return $this->admin ? 'admin' : 'user';
    }
}
