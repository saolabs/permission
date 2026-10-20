<?php

namespace Saola\Permission\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Saola\Core\Routing\Permission;

interface PermissionSubject
{
    /** Read by PermissionManager to resolve (and cache) the subject's role ids. */
    public function roles(): MorphToMany;

    public function hasPermissionTo(string $context, array $modulePath, Permission $permission): bool;
}
