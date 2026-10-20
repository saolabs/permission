<?php

namespace Saola\Permission\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Saola\Core\Routing\Permission;
use Saola\Core\System;
use Saola\Permission\Models\Permission as Grant;
use Saola\Permission\Models\Role;
use Saola\Permission\Services\PermissionRegistry;
use Saola\Permission\Tests\Fixtures\Subject;
use Saola\Permission\Tests\TestCase;

class CacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->migratePermissions();
        $this->registerOperations('admin', ['users'], Permission::READ, Permission::UPDATE);
    }

    /** A fresh manager stands in for the next request (Octane flushes scoped instances). */
    private function nextRequest(): void
    {
        $this->app->forgetScopedInstances();
    }

    private function queries(callable $check): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $check();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    public function test_later_requests_check_permissions_without_queries(): void
    {
        $role = Role::create(['name' => 'reader'])->grantPermissionTo('admin', ['users'], Permission::READ);
        $subject = Subject::create([])->assignRole($role);
        $this->assertGreaterThan(0, $this->queries(fn () => $this->assertTrue($subject->hasPermissionTo('admin', ['users'], Permission::READ))));
        $this->nextRequest();
        $this->assertSame(0, $this->queries(function () use ($subject) {
            $this->assertTrue($subject->hasPermissionTo('admin', ['users'], Permission::READ));
            $this->assertFalse($subject->hasPermissionTo('admin', ['users'], Permission::UPDATE));
        }));
    }

    public function test_changes_through_the_package_api_invalidate_the_cache_immediately(): void
    {
        $role = Role::create(['name' => 'editor'])->grantPermissionTo('admin', ['users'], Permission::READ);
        $other = Role::create(['name' => 'other'])->grantPermissionTo('admin', ['users'], Permission::UPDATE);
        $subject = Subject::create([])->assignRole($role);
        $check = function (Permission $operation) use ($subject) {
            $this->nextRequest();

            return $subject->hasPermissionTo('admin', ['users'], $operation);
        };
        $this->assertTrue($check(Permission::READ));
        $role->grantPermissionTo('admin', ['users'], Permission::UPDATE);
        $this->assertTrue($check(Permission::UPDATE));
        $role->syncPermissions('admin', ['users'], Permission::READ);
        $this->assertFalse($check(Permission::UPDATE));
        $subject->assignRole($other);
        $this->assertTrue($check(Permission::UPDATE));
        $subject->removeRole($other);
        $this->assertFalse($check(Permission::UPDATE));
        Grant::where('operation', Permission::READ->value)->firstOrFail()->delete();
        $this->assertFalse($check(Permission::READ));
        $subject->assignRole($other);
        $other->delete();
        $this->assertFalse($check(Permission::UPDATE));
    }

    public function test_direct_database_changes_wait_for_the_ttl(): void
    {
        $role = Role::create(['name' => 'reader'])->grantPermissionTo('admin', ['users'], Permission::READ);
        $subject = Subject::create([])->assignRole($role);
        $this->assertTrue($subject->hasPermissionTo('admin', ['users'], Permission::READ));
        DB::table('role_assignments')->delete();
        $this->nextRequest();
        $this->assertTrue($subject->hasPermissionTo('admin', ['users'], Permission::READ));
        $this->travel(301)->seconds();
        $this->nextRequest();
        $this->assertFalse($subject->hasPermissionTo('admin', ['users'], Permission::READ));
    }

    public function test_process_local_array_store_is_never_used_across_requests(): void
    {
        config(['permission.cache.store' => 'array']);
        $role = Role::create(['name' => 'reader'])->grantPermissionTo('admin', ['users'], Permission::READ);
        $subject = Subject::create([])->assignRole($role);
        $this->assertTrue($subject->hasPermissionTo('admin', ['users'], Permission::READ));
        $this->assertFalse(Cache::store('array')->has('saola.permission.subject.'.$subject->getMorphClass().'.'.$subject->id));
        $this->nextRequest();
        $this->assertGreaterThan(0, $this->queries(fn () => $subject->hasPermissionTo('admin', ['users'], Permission::READ)));
    }

    public function test_route_index_is_built_once_and_picks_up_routes_added_later(): void
    {
        $context = System::admin();
        $context->module('users')->get('/', fn () => 'ok')->name('index')->permission(Permission::READ);
        $registry = app(PermissionRegistry::class);
        $this->assertSame(['read'], $registry->forRoute('admin.users.index', 'GET')['declared_permissions']);
        $this->nextRequest();
        $this->assertSame($registry, app(PermissionRegistry::class));
        $context->module('posts')->get('/', fn () => 'ok')->name('index')->permission(Permission::UPDATE);
        $this->assertSame(['update'], $registry->forRoute('admin.posts.index', 'GET')['declared_permissions']);
    }
}
