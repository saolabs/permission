<?php

namespace Saola\Permission\Tests\Feature;

use Saola\Core\Routing\Permission;
use Saola\Core\System;
use Saola\Permission\Services\PermissionRegistry;
use Saola\Permission\Tests\TestCase;

class RegistryTest extends TestCase
{
    public function test_matrix_preserves_local_declarations_and_scopes_access_barriers(): void
    {
        $context = System::admin(['prefix' => '/admin/', 'permission' => Permission::ACCESS]);
        $module = $context->module('users', ['prefix' => '/people/'])->permission(Permission::ACCESS, Permission::READ);
        $sub = $module->sub('profile', ['as' => 'profile', 'prefix' => 'profiles'])->permission(Permission::READ);
        $sub->get('/{id}', fn () => 'ok')->name('show')->permission(Permission::UPDATE);
        $before = $context->toArray();
        $registry = app(PermissionRegistry::class);
        $row = $registry->matrix('admin')[0];
        $this->assertSame('admin', $row['context']);
        $this->assertSame(['users', 'profile'], $row['module_path']);
        $this->assertSame('admin.users.profile.show', $row['route_name']);
        $this->assertSame('/admin/people/profiles/{id}', $row['uri']);
        $this->assertSame('admin/people/profiles', $row['prefix']);
        $this->assertSame(['GET', 'HEAD'], $row['methods']);
        $this->assertSame(['update'], $row['declared_permissions']);
        $this->assertSame([
            ['context' => 'admin', 'module_path' => [], 'operation' => 'access'],
            ['context' => 'admin', 'module_path' => ['users'], 'operation' => 'access'],
            ['context' => 'admin', 'module_path' => ['users', 'profile'], 'operation' => 'read'],
            ['context' => 'admin', 'module_path' => ['users', 'profile'], 'operation' => 'update'],
        ], $row['required_permissions']);
        $this->assertSame(array_slice($row['required_permissions'], 0, 3), $row['inherited_permissions']);
        $this->assertSame($row, $registry->forRoute('admin.users.profile.show', 'HEAD'));
        $this->assertSame($before, $context->toArray());
        $this->assertSame([], $registry->matrix('missing'));
    }

    public function test_parent_operations_stop_at_submodule_boundary_but_access_stays(): void
    {
        $parent = System::admin(['permission' => Permission::ACCESS])->module('users')->permission(Permission::READ);
        $parent->get('/', fn () => 'ok')->name('index');
        $sub = $parent->sub('profile', ['as' => 'profile']);
        $sub->get('/public', fn () => 'ok')->name('public');
        $sub->get('/private', fn () => 'ok')->name('private')->permission(Permission::READ);
        $rows = app(PermissionRegistry::class)->matrix();
        $this->assertSame(['access', 'read'], array_column($rows[0]['required_permissions'], 'operation'));
        $this->assertSame([['context' => 'admin', 'module_path' => [], 'operation' => 'access']], $rows[1]['required_permissions']);
        $this->assertSame(['users', 'profile'], $rows[2]['required_permissions'][1]['module_path']);
    }

    public function test_catalog_contains_scoped_operations_once_not_role_names_or_route_names(): void
    {
        $module = System::admin()->module('users');
        $module->get('/', fn () => 'ok')->name('index')->permission(Permission::READ);
        $module->get('/{id}', fn () => 'ok')->name('show')->permission(Permission::READ);
        System::api()->module('users')->get('/', fn () => 'ok')->permission(Permission::READ);
        System::addPermission('admin', ['users'], Permission::READ, Permission::RESTORE);
        $this->assertCount(3, app(PermissionRegistry::class)->catalog());
        $this->assertCount(2, app(PermissionRegistry::class)->catalog('admin'));
        $this->assertSame(['read', 'restore'], array_column(app(PermissionRegistry::class)->catalog('admin'), 'operation'));
    }

    public function test_route_without_declared_permissions_has_empty_requirements(): void
    {
        System::web()->module('home')->get('/', fn () => 'ok')->name('index');
        $this->assertSame([], app(PermissionRegistry::class)->forRoute('web.home.index', 'GET')['required_permissions']);
    }

    public function test_ambiguous_route_names_fail_closed(): void
    {
        $module = System::web()->module('home');
        $module->get('/one', fn () => 'one')->name('index')->permission(Permission::READ);
        $module->get('/two', fn () => 'two')->name('index')->permission(Permission::UPDATE);
        $this->expectException(\LogicException::class);
        app(PermissionRegistry::class)->forRoute('web.home.index', 'GET');
    }

    public function test_unknown_or_unnamed_route_fails_closed(): void
    {
        $this->expectException(\LogicException::class);
        app(PermissionRegistry::class)->forRoute(null, 'GET');
    }
}
