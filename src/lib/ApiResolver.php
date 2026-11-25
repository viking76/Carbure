<?php

/**
 * ApiResolver.php
 *
 * API route resolver with APCu caching.
 * Scans resources for ApiRoute attributes and resolves HTTP requests to controller methods.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib;

use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

final class ApiResolver {

    /**
     * Cache for registered API routes (in-memory), by "METHOD:path" (class: fully
     * qualified name of the resource class)
     * @var array<string, array{class: string, method: string, apiRoute: ApiRoute}>|null
     */
    private static ?array $routeCache = null;
    
    /**
     * APCu cache key, versioned with the format of the cached routes: v2 carries the
     * admin flag of ApiRoute, so a route cached by an older version (without the flag,
     * read as false) cannot open an administrator route to every user; v3 carries the
     * fully qualified class names (namespaces): the global names cached by an older
     * version would not be found; v4 carries the kinds of data changed by each route;
     * v5 carries the quiet flag (calls logged at the DEBUG level)
     */
    private const APCU_CACHE_KEY = 'carbure_routes_cache_v5';

    /**
     * Namespace of the resource classes (src/resources)
     */
    public const RESOURCES_NAMESPACE = 'Carbure\\Resources\\';
    
    /**
     * Check if APCu is available
     * @return bool
     */
    private static function isApcuAvailable(): bool
    {
        return extension_loaded('apcu') && ini_get('apc.enabled');
    }

    /**
     * Find the route matching the request path and HTTP method
     * @param string $requestPath The request path without /api prefix (e.g., /user/login)
     * @param string $httpMethod The HTTP method (GET, POST, PUT, DELETE)
     * @return array{class: string, method: string, apiRoute: ApiRoute}|null The route or null if not found
     */
    public static function findRoute(string $requestPath, string $httpMethod): ?array
    {
        // Initialize route cache on first call
        if (self::$routeCache === null) {
            self::loadRouteCache();
        }

        // Look up route in cache
        $routeKey = $httpMethod . ':' . $requestPath;
        
        if (isset(self::$routeCache[$routeKey])) {
            return self::$routeCache[$routeKey];
        }
        
        return null;
    }

    /**
     * Load all routes from resource files and cache them.
     *
     * @return void
     */
    private static function loadRouteCache(): void
    {
        // Try to load from APCu if available
        if (self::isApcuAvailable() && self::loadFromApcu()) {
            Logger::debug("Routes loaded from APCu cache (" . count(self::$routeCache ?? []) . " routes)");
            return;
        }
        
        // Build from scratch (APCu not available or cache miss)
        self::buildRouteCache();
    }
    
    /**
     * Build route cache from resource files.
     *
     * @return void
     */
    private static function buildRouteCache(): void
    {
        Logger::info("Building route cache from resource files...");
        self::$routeCache = [];
        
        // Load all resource files
        $resourcesDir = Config::get('install_dir') . '/src/resources';
        $resourceFiles = glob($resourcesDir . '/*.php') ?: [];
        
        foreach ($resourceFiles as $file) {
            // Loaded by the class loader (class name = file name)
            $className = self::RESOURCES_NAMESPACE . basename($file, '.php');
            if (!class_exists($className)) {
                continue;
            }
            
            // Get all methods of the class
            try {
                $reflection = new ReflectionClass($className);
                $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC);
                
                foreach ($methods as $method) {
                    // Check if method has ApiRoute attribute
                    $attributes = $method->getAttributes(ApiRoute::class);
                    
                    if (!empty($attributes)) {
                        $apiRoute = $attributes[0]->newInstance();
                        
                        // Store route in cache with key: "METHOD:path"
                        $routeKey = $apiRoute->method . ':' . $apiRoute->path;
                        self::$routeCache[$routeKey] = [
                            'class' => $className,
                            'method' => $method->getName(),
                            'apiRoute' => $apiRoute
                        ];
                        
                        Logger::debug("Registered route: $routeKey -> $className::" . $method->getName());
                    }
                }
            } catch (ReflectionException $e) {
                Logger::warn("Cannot reflect class $className: " . $e->getMessage());
            }
        }
        
        Logger::info("Route cache built with " . count(self::$routeCache) . " routes");
        
        // Save to APCu if available
        if (self::isApcuAvailable()) {
            self::saveToApcu();
        }
    }
    
    /**
     * Compute a signature of the resource files and of the ApiRoute attribute (names and
     * modification times), so that the cache is rebuilt as soon as a resource or the
     * attribute is added or modified.
     *
     * @return string The signature
     */
    private static function getResourcesSignature(): string
    {
        $signature = '';
        $files = glob(Config::get('install_dir') . '/src/resources/*.php') ?: [];
        foreach ([...$files, __DIR__ . '/ApiRoute.php'] as $file) {
            $signature .= basename($file) . ':' . filemtime($file) . ';';
        }
        return md5($signature);
    }

    /**
     * Load routes from APCu cache
     * @return bool True if cache was loaded successfully, false otherwise
     */
    private static function loadFromApcu(): bool
    {
        $success = false;
        $cache = apcu_fetch(self::APCU_CACHE_KEY, $success);

        if (!$success || !is_array($cache) || ($cache['signature'] ?? null) !== self::getResourcesSignature()) {
            return false;
        }

        self::$routeCache = $cache['routes'];
        return true;
    }

    /**
     * Save routes to APCu cache.
     *
     * @return void
     */
    private static function saveToApcu(): void
    {
        $cache = [
            'signature' => self::getResourcesSignature(),
            'routes' => self::$routeCache
        ];

        if (apcu_store(self::APCU_CACHE_KEY, $cache, 3600)) {
            Logger::debug("Route cache saved to APCu");
        } else {
            Logger::warn("Failed to save route cache to APCu");
        }
    }

    /**
     * Clear the APCu route cache (useful for development).
     *
     * @return void
     */
    public static function clearRouteCache(): void
    {
        // Clear APCu cache
        if (self::isApcuAvailable()) {
            apcu_delete(self::APCU_CACHE_KEY);
            Logger::info("APCu route cache cleared");
        }
        
        // Clear in-memory cache
        self::$routeCache = null;
    }
}