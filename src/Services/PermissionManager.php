<?php

namespace Saola\Permission\Services;

use Closure;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Saola\Permission\Contracts\PermissionSubject;
use Saola\Permission\Data\PermissionRequirement;

/**
 * Bound as scoped: the memo arrays live for one request only, so Octane workers never
 * accumulate per-user state. Anything kept across requests goes to the external cache store.
 */
class PermissionManager
{
    /** @var array<int|string, list<string>> role id => granted scope keys */
    private array $roles = [];

    /** @var array<string, list<int|string>> subject cache key => role ids */
    private array $subjects = [];

    /** @param list<PermissionRequirement> $requirements ALL semantics across roles. */
    public function allowsAll(?Authenticatable $subject, array $requirements): bool
    {
        if (! $subject instanceof PermissionSubject) {
            return false;
        }
        $granted = $this->grantedScopes($subject);
        foreach ($requirements as $requirement) {
            if (! $requirement instanceof PermissionRequirement) {
                throw new InvalidArgumentException('Expected scoped PermissionRequirement objects.');
            }
            if (! isset($granted[$requirement->storageAttributes()['scope_key']])) {
                return false;
            }
        }

        return true;
    }

    public function forgetSubject(PermissionSubject $subject): void
    {
        $key = $this->subjectKey($subject);
        unset($this->subjects[$key]);
        $this->store()?->forget($key);
    }

    public function forgetRoles(int|string ...$roleIds): void
    {
        foreach ($roleIds as $id) {
            unset($this->roles[$id]);
            $this->store()?->forget('saola.permission.role.'.$id);
        }
    }

    /** @return array<string, true> */
    private function grantedScopes(PermissionSubject $subject): array
    {
        if ($subject->getKey() === null) {
            return [];
        }
        $key = $this->subjectKey($subject);
        $roleIds = $this->subjects[$key] ??= $this->remember($key, fn () => $subject->roles()->allRelatedIds()->all());
        $granted = [];
        foreach ($roleIds as $id) {
            // ponytail: one cache read per role; batch with many() if users carry dozens of roles.
            $this->roles[$id] ??= $this->remember('saola.permission.role.'.$id, fn () => $this->loadRole($id));
            foreach ($this->roles[$id] as $scope) {
                $granted[$scope] = true;
            }
        }

        return $granted;
    }

    private function loadRole(int|string $id): array
    {
        $tables = config('permission.tables');

        return DB::connection(config('permission.connection'))->table($tables['permission_role'])
            ->join($tables['permissions'], $tables['permissions'].'.id', '=', $tables['permission_role'].'.permission_id')
            ->where($tables['permission_role'].'.role_id', $id)
            ->pluck($tables['permissions'].'.scope_key')->all();
    }

    private function subjectKey(PermissionSubject $subject): string
    {
        return 'saola.permission.subject.'.$subject->getMorphClass().'.'.$subject->getKey();
    }

    // ponytail: a reader racing a grant change can re-cache old data after forget(); ttl bounds it.
    private function remember(string $key, Closure $load): array
    {
        $store = $this->store();

        return $store === null ? $load() : $store->remember($key, (int) config('permission.cache.ttl'), $load);
    }

    /** The process-local array store would grow per user and go stale between Octane workers. */
    private function store(): ?Repository
    {
        if ((int) config('permission.cache.ttl') <= 0) {
            return null;
        }
        $store = Cache::store(config('permission.cache.store'));

        return $store->getStore() instanceof ArrayStore ? null : $store;
    }
}
