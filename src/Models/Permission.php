<?php

namespace Saola\Permission\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Saola\Core\Models\Model;
use Saola\Core\Routing\Permission as Operation;
use Saola\Permission\Data\PermissionRequirement;
use Saola\Permission\Services\PermissionManager;

class Permission extends Model
{
    protected $fillable = ['scope_key', 'context', 'module_path', 'operation', 'description'];

    protected function casts(): array
    {
        return ['module_path' => 'array', 'operation' => Operation::class];
    }

    protected static function booted(): void
    {
        static::saving(function (self $permission) {
            $requirement = new PermissionRequirement($permission->context, $permission->module_path, $permission->operation);
            $permission->module_path = $requirement->modulePath;
            $permission->scope_key = $requirement->storageAttributes()['scope_key'];
        });
        // Roles cache scope keys; a renamed or deleted cell must drop them. deleting: the pivot cascades away.
        $forget = fn (self $permission) => app(PermissionManager::class)->forgetRoles(...$permission->roles()->allRelatedIds()->all());
        static::updated($forget);
        static::deleting($forget);
    }

    public function getTable()
    {
        return config('permission.tables.permissions', 'permissions');
    }

    public function getConnectionName()
    {
        return config('permission.connection') ?? parent::getConnectionName();
    }

    public function scopeMatching(Builder $query, PermissionRequirement $requirement): Builder
    {
        return $query->where($requirement->storageAttributes());
    }

    public static function register(PermissionRequirement $requirement): self
    {
        return static::firstOrCreate($requirement->storageAttributes(), ['module_path' => $requirement->modulePath]);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, config('permission.tables.permission_role'), 'permission_id', 'role_id');
    }
}
