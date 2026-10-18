<?php

namespace Saola\Permission\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Saola\Core\Routing\Permission as Operation;
use Saola\Core\System;
use Saola\Permission\Models\Permission;
use Saola\Permission\Models\Role;
use Saola\Permission\Tests\Fixtures\Subject;
use Saola\Permission\Tests\TestCase;

class InstallationTest extends TestCase
{
    public function test_boot_and_dry_run_do_not_require_permission_tables(): void
    {
        $this->assertFalse(Schema::hasTable('roles'));
        $this->assertFalse(Schema::hasTable('permissions'));
        System::addPermission('admin', ['users'], Operation::READ);
        DB::listen(fn () => $this->fail('Dry run must not query the database.'));
        $this->artisan('saola:permission:sync', ['--dry-run' => true])->expectsOutput('{"context":"admin","module_path":["users"],"operation":"read"}')->assertSuccessful();
    }

    public function test_migrations_are_publish_only_and_republish_does_not_duplicate(): void
    {
        $path = database_path('migrations');
        $this->assertNotContains(realpath(__DIR__.'/../../database/migrations'), app('migrator')->paths());
        try {
            $this->artisan('vendor:publish', ['--tag' => 'saola-permission-migrations', '--force' => false])->assertSuccessful();
            $files = glob($path.'/*_create_saola_permission_tables.php');
            $this->assertCount(1, $files);
            $this->artisan('vendor:publish', ['--tag' => 'saola-permission-migrations', '--force' => false])->assertSuccessful();
            $this->assertCount(1, glob($path.'/*_create_saola_permission_tables.php'));
            $this->artisan('migrate', ['--force' => true])->assertSuccessful();
            $this->assertTrue(Schema::hasTable('role_assignments'));
            $this->assertFalse(Schema::hasTable('users'));
            $this->artisan('migrate:rollback', ['--force' => true])->assertSuccessful();
            $this->assertFalse(Schema::hasTable('permissions'));
        } finally {
            foreach (glob($path.'/*_create_saola_permission_tables.php') as $file) {
                unlink($file);
            }
        }
    }

    public function test_sync_is_append_only_idempotent_and_does_not_grant_roles(): void
    {
        $this->migratePermissions();
        $this->registerOperations('admin', ['manual'], Operation::READ);
        $role = Role::create(['name' => 'reader'])->grantPermissionTo('admin', ['manual'], Operation::READ);
        System::addPermission('admin', ['users'], Operation::READ, Operation::UPDATE);
        System::addPermission('web', [], Operation::ACCESS);
        $this->artisan('saola:permission:sync', ['--context' => 'admin'])->assertSuccessful();
        $this->artisan('saola:permission:sync', ['--context' => 'admin'])->assertSuccessful();
        $this->assertSame(3, Permission::count());
        $this->assertFalse(Permission::where('context', 'web')->exists());
        $this->assertSame(1, $role->permissions()->count());
        $this->assertSame(['manual'], $role->permissions()->first()->module_path);
        System::reset();
        $this->artisan('saola:permission:sync')->assertSuccessful();
        $this->assertSame(3, Permission::count());
    }

    public function test_custom_table_names_and_uuid_subject_keys(): void
    {
        config(['permission.tables' => [
            'roles' => 'access_roles', 'permissions' => 'access_permissions',
            'permission_role' => 'access_grants', 'role_assignments' => 'access_subjects',
        ], 'permission.subject_key_type' => 'uuid']);
        $migration = require __DIR__.'/../../database/migrations/create_saola_permission_tables.php.stub';
        $migration->up();
        Schema::create('uuid_subjects', function ($table) {
            $table->uuid('id')->primary();
            $table->timestamps();
        });
        $role = Role::create(['name' => 'reader']);
        $this->registerOperations('admin', ['users'], Operation::READ);
        $role->grantPermissionTo('admin', ['users'], Operation::READ);
        $subject = UuidSubject::create(['id' => '95aa092f-991a-439e-a5a5-9973027801e3'])->assignRole($role);
        $this->assertTrue($subject->hasPermissionTo('admin', ['users'], Operation::READ));
        $this->assertSame($subject->id, DB::table('access_subjects')->value('subject_id'));
        $migration->down();
        $this->assertFalse(Schema::hasTable('access_roles'));
        $this->assertTrue(Schema::hasTable('uuid_subjects'));
    }
}

class UuidSubject extends Subject
{
    protected $table = 'uuid_subjects';

    protected $fillable = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';
}
