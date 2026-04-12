<?php

declare(strict_types=1);

namespace WPApps\Runtime\Core\Gateway;

use WP_Error;

class PermissionEnforcer
{
    /**
     * Map of REST route patterns to required scopes.
     */
    private const ROUTE_SCOPES = [
        'GET:/apps/v1/posts' => 'posts:read',
        'POST:/apps/v1/posts' => 'posts:write',
        'GET:/apps/v1/posts/(?P<id>\d+)' => 'posts:read',
        'PUT:/apps/v1/posts/(?P<id>\d+)' => 'posts:write',
        'DELETE:/apps/v1/posts/(?P<id>\d+)' => 'posts:delete',
        'GET:/apps/v1/posts/(?P<id>\d+)/meta' => 'postmeta:read',
        'PUT:/apps/v1/posts/(?P<id>\d+)/meta/(?P<key>.+)' => 'postmeta:write',
        'DELETE:/apps/v1/posts/(?P<id>\d+)/meta/(?P<key>.+)' => 'postmeta:write',
        'GET:/apps/v1/users' => 'users:read:basic',
        'GET:/apps/v1/users/(?P<id>\d+)' => 'users:read:basic',
        'GET:/apps/v1/media' => 'media:read',
        'POST:/apps/v1/media' => 'media:write',
    ];

    /**
     * Scope hierarchy: broader scopes include narrower ones.
     */
    private const SCOPE_HIERARCHY = [
        'posts:write' => ['posts:read'],
        'posts:delete' => ['posts:write', 'posts:read'],
        'postmeta:write' => ['postmeta:read'],
        'users:write' => ['users:read:full', 'users:read:basic'],
        'users:read:full' => ['users:read:basic'],
        'media:write' => ['media:read'],
        'comments:write' => ['comments:read'],
        'taxonomies:write' => ['taxonomies:read'],
        'menus:write' => ['menus:read'],
        'site:write' => ['site:read'],
    ];

    /**
     * Check if the given scopes satisfy a required scope.
     */
    public function hasScope(array $grantedScopes, string $requiredScope): bool
    {
        // Direct match
        if (in_array($requiredScope, $grantedScopes, true)) {
            return true;
        }

        // Check hierarchy: does any granted scope imply the required one?
        foreach ($grantedScopes as $granted) {
            $implies = self::SCOPE_HIERARCHY[$granted] ?? [];
            if (in_array($requiredScope, $implies, true)) {
                return true;
            }
        }

        // Check wildcard scopes (e.g., options:read:my_seo_* matches options:read)
        foreach ($grantedScopes as $granted) {
            if ($this->wildcardMatch($granted, $requiredScope)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine the required scope for a given route and method.
     */
    public function getRequiredScope(string $method, string $route): ?string
    {
        $key = "{$method}:{$route}";

        if (isset(self::ROUTE_SCOPES[$key])) {
            return self::ROUTE_SCOPES[$key];
        }

        // Try pattern matching
        foreach (self::ROUTE_SCOPES as $pattern => $scope) {
            [$patternMethod, $patternRoute] = explode(':', $pattern, 2);
            if ($patternMethod !== $method) {
                continue;
            }

            $regex = '#^' . $patternRoute . '$#';
            if (preg_match($regex, $route)) {
                return $scope;
            }
        }

        return null;
    }

}
