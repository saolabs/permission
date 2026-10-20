<?php

namespace Saola\Permission\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use LogicException;
use Saola\Core\Models\Model;
use Saola\Core\Routing\Permission as Operation;
use Saola\Permission\Data\PermissionRequirement;
use Saola\Permission\Services\PermissionManager;

class Role extends Model
{
    protected $fillable = ['name', 'description'];

    protected static function booted(): void
    {
        static::deleted(function (self $role) {
            $role->permissions()->detach();
            DB::connection($role->getConnectionName())->table(config('permission.tables.role_assignments'))
                ->where('role_id', $role->getKey())->delete();
            app(PermissionManager::class)->forgetRoles($role->getKey());
        });
    }

    public function getTable()
    {
        return config('permission.tables.roles', 'roles');
    }

    public function getConnectionName()
    {
        return config('permission.connection') ?? parent::getConnectionName();
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, config('permission.tables.permission_role'), 'role_id', 'permission_id');
    }

    public function grantPermissionTo(string $context, array $modulePath, Operation $permission, Operation ...$permissions): static
    {
        $this->permissions()->syncWithoutDetaching($this->permissionIds($context, $modulePath, [$permission, ...$permissions]));
        app(PermissionManager::class)->forgetRoles($this->getKey());

        return $this;
    }

    public function revokePermissionTo(string $context, array $modulePath, Operation $permission, Operation ...$permissions): static
    {
        $this->permissions()->detach($this->permissionIds($context, $modulePath, [$permission, ...$permissions]));
        app(PermissionManager::class)->forgetRoles($this->getKey());

        return $this;
    }

    /** Replace grants for ONE exact scope; preserve every other module/context. */
    public function syncPermissions(string $context, array $modulePath, Operation ...$permissions): static
    {
        $ids = $this->permissionIds($context, $modulePath, $permissions);
        $scope = new PermissionRequirement($context, $modulePath, Operation::ACCESS);
        DB::connection($this->getConnectionName())->transaction(function () use ($scope, $ids) {
            $existing = $this->permissions()->where('context', $scope->context)
                ->where('module_path', $scope->encodedPath())
                ->get()->filter(fn (Permission $grant) => $grant->context === $scope->context
                    && $grant->module_path === $scope->modulePath)->modelKeys();
            $this->permissions()->detach(array_values(array_diff($existing, $ids)));
            $this->permissions()->syncWithoutDetaching($ids);
        });
        app(PermissionManager::class)->forgetRoles($this->getKey());

        return $this;
    }

    private function permissionIds(string $context, array $modulePath, array $permissions): array
    {
        if (! $this->exists) {
            throw new LogicException('Save the role before changing its permissions.');
        }

        return array_map(fn (Operation $permission) => Permission::matching(
            new PermissionRequirement($context, $modulePath, $permission)
        )->firstOrFail()->getKey(), $permissions);
    }
}
