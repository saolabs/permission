<?php

namespace Saola\Permission\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Saola\Core\Routing\Permission;
use Saola\Core\Services\ClientShare;
use Saola\Core\System;
use Saola\Permission\Models\Role;
use Saola\Permission\Services\RouteAccess;
use Saola\Permission\Tests\Fixtures\Subject;
use Saola\Permission\Tests\TestCase;

/**
 * canRoute() / #permission: cùng luật với middleware route, ở PHP và qua
 * Saola::share cho bản JS.
 */
class RouteAccessTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        // SaolaServiceProvider của app bind singleton này; package test không nạp nó.
        $app->singleton(ClientShare::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->migratePermissions();
        $context = System::admin(['prefix' => 'admin', 'permission' => Permission::ACCESS, 'middleware' => ['saola.permission.route']]);
        $context->module(['slug' => 'roles', 'prefix' => 'roles'])->as('roles')->group(function ($module) {
            $module->get('/', fn () => 'list')->name('index')->permission(Permission::READ);
            $module->post('/{id}/delete', fn () => 'gone')->name('destroy')->permission(Permission::DELETE);
        });
        $context->pushLaravelRoute();
        Route::get('/plain', fn () => 'ok')->name('plain');
        Route::getRoutes()->refreshNameLookups();
        $this->artisan('saola:permission:sync')->assertSuccessful();
    }

    private function reader(): Subject
    {
        $role = Role::create(['name' => 'reader'])
            ->grantPermissionTo('admin', [], Permission::ACCESS)
            ->grantPermissionTo('admin', ['roles'], Permission::READ);

        return Subject::create([])->assignRole($role);
    }

    public function test_can_route_theo_dung_quyen_cua_route(): void
    {
        $user = $this->reader();
        $this->actingAs($user);

        $this->assertTrue(canRoute('admin.roles.index'));
        $this->assertFalse(canRoute('admin.roles.destroy'));
        $this->assertTrue(canRoute('plain'), 'route Laravel thường không khai báo quyền');
        $this->get('/admin/roles')->assertOk();
        $this->post('/admin/roles/1/delete')->assertForbidden();

        $this->assertFalse(app(RouteAccess::class)->can('admin.roles.index', null), 'khách');
    }

    public function test_ten_route_sai_no_to_khi_debug_va_tra_false_khi_production(): void
    {
        config(['app.debug' => false]);
        $this->assertFalse(canRoute('admin.roles.indx'));

        config(['app.debug' => true]);
        $this->expectException(InvalidArgumentException::class);
        canRoute('admin.roles.indx');
    }

    public function test_share_phat_danh_sach_cho_ban_js(): void
    {
        $request = Request::create('/admin/roles');
        $request->setUserResolver(fn () => $this->reader());

        $shared = app(ClientShare::class)->export($request);

        $this->assertSame(['admin.roles.index'], $shared['permission']['allowed']);
        $this->assertSame(['admin.roles.destroy'], $shared['permission']['denied']);
    }
}
