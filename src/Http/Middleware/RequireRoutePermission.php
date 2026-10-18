<?php

namespace Saola\Permission\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use LogicException;
use Saola\Permission\Data\PermissionRequirement;
use Saola\Permission\Services\PermissionRegistry;

class RequireRoutePermission
{
    public function __construct(private PermissionRegistry $registry, private RequirePermission $middleware) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $route = $request->route();
        if (! $route instanceof Route) {
            throw new LogicException('Route permission middleware requires a matched route.');
        }
        $row = $this->registry->forRoute($route->getName(), $request->method());
        $permissions = $row['required_permissions'];

        return $permissions === [] ? $next($request) : $this->middleware->authorize(
            $request, $next, array_map(fn (array $row) => PermissionRequirement::fromArray($row), $permissions)
        );
    }
}
