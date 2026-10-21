<?php

use Saola\Permission\Services\RouteAccess;

if (! function_exists('canRoute')) {
    /**
     * Người dùng hiện tại có được vào route tên `$name` không. Bản JS cùng tên
     * nằm trong dist/client.js (App.Helper.canRoute), nên gọi được trong `.sao`.
     */
    function canRoute(string $name): bool
    {
        return app(RouteAccess::class)->can($name, auth()->user());
    }
}
