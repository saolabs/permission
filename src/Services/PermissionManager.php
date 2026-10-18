<?php

namespace Saola\Permission\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use Saola\Permission\Contracts\PermissionSubject;
use Saola\Permission\Data\PermissionRequirement;

class PermissionManager
{
    /** @param list<PermissionRequirement> $requirements ALL semantics across roles. */
    public function allowsAll(?Authenticatable $subject, array $requirements): bool
    {
        if (! $subject instanceof PermissionSubject) {
            return false;
        }
        foreach ($requirements as $requirement) {
            if (! $requirement instanceof PermissionRequirement) {
                throw new InvalidArgumentException('Expected scoped PermissionRequirement objects.');
            }
            if (! $subject->hasPermissionTo($requirement->context, $requirement->modulePath, $requirement->operation)) {
                return false;
            }
        }

        return true;
    }
}
