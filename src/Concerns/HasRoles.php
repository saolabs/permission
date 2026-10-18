<?php

namespace Saola\Permission\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Saola\Core\Routing\Permission;
use Saola\Permission\Data\PermissionRequirement;
use Saola\Permission\Models\Role;

trait HasRoles
{
    protected static function bootHasRoles(): void
    {
        static::deleted(function ($subject) {
            $soft = method_exists($subject, 'isSoftDeleteMode') ? $subject->isSoftDeleteMode()
                : in_array(SoftDeletes::class, class_uses_recursive($subject), true);
            if (! $soft || $subject->isForceDeleting()) {
                $subject->roles()->detach();
            }
        });
    }

    public function roles(): MorphToMany
    {
        return $this->morphToMany(Role::class, 'subject', config('permission.tables.role_assignments'), 'subject_id', 'role_id')
            ->withTimestamps();
    }

    public function assignRole(string|Role ...$roles): static
    {
        $this->roles()->syncWithoutDetaching($this->permissionRoleIds($roles));

        return $this;
    }

    public function removeRole(string|Role ...$roles): static
    {
        $this->roles()->detach($this->permissionRoleIds($roles));

        return $this;
    }

    public function syncRoles(string|Role ...$roles): static
    {
        $this->roles()->sync($this->permissionRoleIds($roles));

        return $this;
    }

    public function hasRole(string $role): bool
    {
        return $this->roles()->where('name', $role)->exists();
    }

    public function hasPermissionTo(string $context, array $modulePath, Permission $permission): bool
    {
        $requirement = new PermissionRequirement($context, $modulePath, $permission);

        return $this->roles()->whereHas('permissions', fn ($query) => $query->matching($requirement))->exists();
    }

    private function permissionRoleIds(array $roles): array
    {
        if (! $this->exists) {
            throw new \LogicException('Save the permission subject before assigning roles.');
        }

        return array_map(function ($role) {
            $role = $role instanceof Role ? $role : Role::where('name', $role)->firstOrFail();
            if (! $role->exists || $role->getKey() === null) {
                throw new \LogicException('Save the role before assigning it.');
            }

            return $role->getKey();
        }, $roles);
    }
}
