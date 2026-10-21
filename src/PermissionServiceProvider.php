<?php

namespace Saola\Permission;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Saola\Permission\Data\PermissionRequirement;
use Saola\Permission\Http\Middleware\RequirePermission;
use Saola\Permission\Http\Middleware\RequireRoutePermission;
use Saola\Permission\Services\PermissionManager;
use Saola\Permission\Services\PermissionRegistry;
use Saola\Permission\Services\RouteAccess;
use Saola\Core\Saola;

class PermissionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/permission.php', 'permission');
        // Route metadata is static after boot; the manager keeps per-request memos only.
        $this->app->singleton(PermissionRegistry::class);
        $this->app->scoped(PermissionManager::class);
        $this->app->singleton(RouteAccess::class);
    }

    public function boot(Router $router, Gate $gate): void
    {
        $router->aliasMiddleware('saola.permission', RequirePermission::class);
        $router->aliasMiddleware('saola.permission.route', RequireRoutePermission::class);

        // Dữ liệu cho canRoute() phía JS (dist/client.js): route nào người dùng được vào.
        Saola::share('permission', fn (Request $request) => app(RouteAccess::class)->split($request->user()));

        if (config('permission.gate')) {
            $gate->define('saola.permission', fn ($subject, PermissionRequirement $requirement) => app(PermissionManager::class)->allowsAll($subject, [$requirement]));
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/permission.php' => config_path('permission.php')], 'saola-permission-config');
            $this->publishesMigrations([
                __DIR__.'/../database/migrations/create_saola_permission_tables.php.stub' => database_path('migrations/0000_00_00_000000_create_saola_permission_tables.php'),
            ], 'saola-permission-migrations');
            $this->commands([Console\SyncPermissions::class]);
        }
    }
}
