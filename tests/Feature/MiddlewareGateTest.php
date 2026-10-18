<?php

namespace Saola\Permission\Tests\Feature;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Saola\Core\Routing\Permission;
use Saola\Core\System;
use Saola\Permission\Data\PermissionRequirement;
use Saola\Permission\Http\Middleware\RequirePermission;
use Saola\Permission\Models\Role;
use Saola\Permission\Tests\Fixtures\Subject;
use Saola\Permission\Tests\TestCase;

class MiddlewareGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->migratePermissions();
    }

    public function test_typed_explicit_middleware_requires_all_scoped_permissions(): void
    {
        $middleware = RequirePermission::using('admin', ['users'], Permission::READ, Permission::UPDATE);
        $this->assertIsString($middleware);
        Route::get('/explicit', fn () => 'ok')->middleware($middleware);
        $this->getJson('/explicit')->assertUnauthorized();
        $this->registerOperations('admin', ['users'], Permission::READ, Permission::UPDATE);
        $role = Role::create(['name' => 'editor'])->grantPermissionTo('admin', ['users'], Permission::READ);
        $subject = Subject::create([])->assignRole($role);
        $this->actingAs($subject)->getJson('/explicit')->assertForbidden();
        $role->grantPermissionTo('admin', ['users'], Permission::UPDATE);
        $this->getJson('/explicit')->assertOk();
        $role->revokePermissionTo('admin', ['users'], Permission::READ);
        $this->getJson('/explicit')->assertForbidden();
    }

    public function test_route_middleware_requires_context_access_and_exact_submodule_grant(): void
    {
        $context = System::admin(['prefix' => 'admin', 'permission' => Permission::ACCESS, 'middleware' => ['saola.permission.route']]);
        $module = $context->module('users')->permission(Permission::ACCESS, Permission::READ);
        $sub = $module->sub('profile', ['as' => 'profile']);
        $sub->get('/', fn () => 'ok')->name('index')->permission(Permission::READ);
        $context->pushLaravelRoute();
        $this->getJson('/admin/users/profile')->assertUnauthorized();
        $this->artisan('saola:permission:sync')->assertSuccessful();
        $role = Role::create(['name' => 'reader'])
            ->grantPermissionTo('admin', [], Permission::ACCESS)
            ->grantPermissionTo('admin', ['users'], Permission::ACCESS, Permission::READ);
        $subject = Subject::create([])->assignRole($role);
        $this->actingAs($subject)->getJson('/admin/users/profile')->assertForbidden();
        $role->grantPermissionTo('admin', ['users', 'profile'], Permission::READ);
        $this->getJson('/admin/users/profile')->assertOk();
        $this->call('HEAD', '/admin/users/profile')->assertOk();
        $role->revokePermissionTo('admin', [], Permission::ACCESS);
        $this->getJson('/admin/users/profile')->assertForbidden();
    }

    public function test_route_with_empty_requirements_is_public(): void
    {
        $context = System::web(['middleware' => ['saola.permission.route']]);
        $context->module('home')->get('/public', fn () => 'ok')->name('index');
        $context->pushLaravelRoute();
        $this->getJson('/home/public')->assertOk();
    }

    public function test_empty_explicit_middleware_is_configuration_error(): void
    {
        Route::get('/empty', fn () => 'ok')->middleware('saola.permission');
        $this->withoutExceptionHandling();
        $this->expectException(\InvalidArgumentException::class);
        $this->getJson('/empty');
    }

    public function test_raw_string_middleware_payload_is_rejected(): void
    {
        Route::get('/raw', fn () => 'ok')->middleware('saola.permission:read');
        $this->withoutExceptionHandling();
        $this->expectException(\InvalidArgumentException::class);
        $this->getJson('/raw');
    }

    public function test_explicit_middleware_factory_rejects_string_operations(): void
    {
        $this->expectException(\TypeError::class);
        RequirePermission::using('admin', ['users'], 'read');
    }

    public function test_route_middleware_rejects_missing_system_metadata(): void
    {
        Route::get('/unknown', fn () => 'ok')->name('unknown')->middleware('saola.permission.route');
        $this->withoutExceptionHandling();
        $this->expectException(\LogicException::class);
        $this->getJson('/unknown');
    }

    public function test_scoped_gate_never_intercepts_policies_or_other_abilities(): void
    {
        $this->registerOperations('admin', ['users'], Permission::READ);
        $role = Role::create(['name' => 'reader'])->grantPermissionTo('admin', ['users'], Permission::READ);
        $subject = Subject::create([])->assignRole($role);
        Gate::define('unrelated', fn ($user) => true);
        Gate::policy(Subject::class, SubjectPolicy::class);
        $gate = Gate::forUser($subject);
        $requirement = new PermissionRequirement('admin', ['users'], Permission::READ);
        $this->assertTrue($gate->allows('saola.permission', $requirement));
        $this->assertFalse($gate->allows('saola.permission', new PermissionRequirement('api', ['users'], Permission::READ)));
        $this->assertTrue($gate->allows('unrelated'));
        $this->assertFalse($gate->allows('read', $subject));
        $role->revokePermissionTo('admin', ['users'], Permission::READ);
        $this->assertFalse($gate->allows('saola.permission', $requirement));
    }
}

class SubjectPolicy
{
    public function read(Subject $actor, Subject $record): bool
    {
        return false;
    }
}
