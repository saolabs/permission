<?php

namespace Saola\Permission\Contracts;

use Saola\Core\Routing\Permission;

interface PermissionSubject
{
    public function hasPermissionTo(string $context, array $modulePath, Permission $permission): bool;
}
