<?php

namespace Saola\Permission\Tests\Feature;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Saola\Core\Routing\Permission;
use Saola\Permission\Data\PermissionRequirement;
use Saola\Permission\Models\Permission as Grant;
use Saola\Permission\Models\Role;
use Saola\Permission\Services\PermissionManager;
use Saola\Permission\Tests\Fixtures\Subject;
use Saola\Permission\Tests\TestCase;

class RolePermissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->migratePermissions();
    }

    public function test_role_union_and_immediate_revocation_even_with_loaded_relationships(): void
    {
        $this->registerOperations('admin', ['users'], Permission::READ, Permission::UPDATE);
        $reader = Role::create(['name' => 'reader'])->grantPermissionTo('admin', ['users'], Permission::READ);
        $writer = Role::create(['name' => 'writer'])->grantPermissionTo('admin', ['users'], Permission::UPDATE);
        $subject = Subject::create([])->assignRole($reader, 'writer', $reader);
        $subject->load('roles.permissions');
        $manager = app(PermissionManager::class);
        $requirements = [new PermissionRequirement('admin', ['users'], Permission::READ), new PermissionRequirement('admin', ['users'], Permission::UPDATE)];
        $this->assertCount(2, $subject->roles);
        $this->assertTrue($manager->allowsAll($subject, $requirements));
        $writer->revokePermissionTo('admin', ['users'], Permission::UPDATE);
        $this->assertFalse($manager->allowsAll($subject, $requirements));
        $subject->removeRole($reader);
        $this->assertFalse($subject->hasPermissionTo('admin', ['users'], Permission::READ));
        $subject->syncRoles($writer);
        $this->assertFalse($subject->hasRole('reader'));
        $this->assertTrue($subject->hasRole('writer'));
        $subject->syncRoles();
        $this->assertFalse($subject->hasRole('writer'));
        $this->assertFalse($manager->allowsAll(null, $requirements));
    }

    public function test_grants_are_exact_and_do_not_leak_to_other_contexts_modules_or_descendants(): void
    {
        $this->registerOperations('admin', ['users'], Permission::READ);
        $role = Role::create(['name' => 'reader'])->grantPermissionTo('admin', ['users'], Permission::READ);
        $subject = Subject::create([])->assignRole($role);
        $this->assertTrue($subject->hasPermissionTo('admin', ['users'], Permission::READ));
        $this->assertFalse($subject->hasPermissionTo('api', ['users'], Permission::READ));
        $this->assertFalse($subject->hasPermissionTo('admin', ['articles'], Permission::READ));
        $this->assertFalse($subject->hasPermissionTo('admin', ['users', 'profile'], Permission::READ));
        $this->assertFalse($subject->hasPermissionTo('admin', [], Permission::READ));
        $this->assertFalse($subject->hasPermissionTo('admin', ['users'], Permission::DELETE));
    }

    public function test_module_segments_do_not_collide_with_dotted_module_names(): void
    {
        $this->registerOperations('admin', ['users.profile'], Permission::READ);
        $role = Role::create(['name' => 'reader'])->grantPermissionTo('admin', ['users.profile'], Permission::READ);
        $subject = Subject::create([])->assignRole($role);
        $this->assertTrue($subject->hasPermissionTo('admin', ['users.profile'], Permission::READ));
        $this->assertFalse($subject->hasPermissionTo('admin', ['users', 'profile'], Permission::READ));
    }

    public function test_sync_replaces_only_the_selected_scope_and_missing_operations_do_not_remove_grants(): void
    {
        $this->registerOperations('admin', ['users'], Permission::READ, Permission::UPDATE);
        $this->registerOperations('api', ['users'], Permission::READ);
        $this->registerOperations('admin', ['articles'], Permission::READ);
        $role = Role::create(['name' => 'editor'])
            ->grantPermissionTo('admin', ['users'], Permission::READ, Permission::UPDATE)
            ->grantPermissionTo('api', ['users'], Permission::READ)
            ->grantPermissionTo('admin', ['articles'], Permission::READ);
        $subject = Subject::create([])->assignRole($role);
        $role->syncPermissions('admin', ['users'], Permission::READ);
        $this->assertFalse($subject->hasPermissionTo('admin', ['users'], Permission::UPDATE));
        $this->assertTrue($subject->hasPermissionTo('api', ['users'], Permission::READ));
        $this->assertTrue($subject->hasPermissionTo('admin', ['articles'], Permission::READ));
        try {
            $role->syncPermissions('admin', ['users'], Permission::UPDATE, Permission::DELETE);
            $this->fail('Missing declared operation should fail.');
        } catch (ModelNotFoundException) {
            $this->assertTrue($subject->hasPermissionTo('admin', ['users'], Permission::READ));
        }
        $role->syncPermissions('admin', ['users']);
        $this->assertFalse($subject->hasPermissionTo('admin', ['users'], Permission::READ));
        $this->assertSame(2, $role->permissions()->count());
    }

    public function test_unknown_operation_cannot_be_implicitly_created_or_partially_granted(): void
    {
        $this->registerOperations('admin', ['users'], Permission::READ);
        $role = Role::create(['name' => 'reader']);
        try {
            $role->grantPermissionTo('admin', ['users'], Permission::READ, Permission::DELETE);
            $this->fail('Expected missing operation to fail.');
        } catch (ModelNotFoundException) {
            $this->assertSame(0, $role->permissions()->count());
            $this->assertSame(1, Grant::count());
        }
    }

    public function test_string_operation_is_rejected_by_public_grant_api(): void
    {
        $role = Role::create(['name' => 'reader']);
        $this->expectException(\TypeError::class);
        $role->grantPermissionTo('admin', ['users'], 'read');
    }

    public function test_missing_role_fails_without_partial_assignments(): void
    {
        Role::create(['name' => 'reader']);
        $subject = Subject::create([]);
        try {
            $subject->assignRole('reader', 'missing');
            $this->fail('Expected missing role to fail.');
        } catch (ModelNotFoundException) {
            $this->assertSame(0, $subject->roles()->count());
        }
    }

    public function test_unsaved_subject_cannot_assign_roles(): void
    {
        $role = Role::create(['name' => 'reader']);
        $this->expectException(\LogicException::class);
        (new Subject)->assignRole($role);
    }

    public function test_unsaved_role_cannot_be_assigned(): void
    {
        $subject = Subject::create([]);
        $this->expectException(\LogicException::class);
        $subject->assignRole(new Role(['name' => 'unsaved']));
    }

    public function test_deleting_permissions_and_roles_removes_grants(): void
    {
        $this->registerOperations('admin', ['users'], Permission::READ);
        $role = Role::create(['name' => 'reader'])->grantPermissionTo('admin', ['users'], Permission::READ);
        $subject = Subject::create([])->assignRole($role);
        Grant::firstOrFail()->delete();
        $this->assertFalse($subject->hasPermissionTo('admin', ['users'], Permission::READ));
        $this->assertSame(0, DB::table('permission_role')->count());
        $role->delete();
        $this->assertFalse($subject->hasRole('reader'));
        $this->assertSame(0, DB::table('role_assignments')->count());
    }

    public function test_subject_deletion_cleanup_respects_soft_deletion(): void
    {
        $role = Role::create(['name' => 'reader']);
        $hard = Subject::create([])->assignRole($role);
        $hard->delete();
        $this->assertSame(0, DB::table('role_assignments')->count());
        $soft = SoftSubject::create([])->assignRole($role);
        $soft->delete();
        $this->assertSame(1, DB::table('role_assignments')->count());
        $soft->restore();
        $this->assertTrue($soft->hasRole('reader'));
        $soft->forceDelete();
        $this->assertSame(0, DB::table('role_assignments')->count());
    }

    public function test_polymorphic_subjects_with_same_id_are_isolated(): void
    {
        Relation::morphMap(['person' => Subject::class, 'staff' => OtherSubject::class]);
        try {
            $role = Role::create(['name' => 'reader']);
            $person = Subject::create([])->assignRole($role);
            $staff = OtherSubject::findOrFail($person->id);
            $this->assertFalse($staff->hasRole('reader'));
            $staff->assignRole($role);
            $person->syncRoles();
            $this->assertTrue($staff->hasRole('reader'));
            $this->assertSame('staff', DB::table('role_assignments')->value('subject_type'));
        } finally {
            Relation::morphMap([], false);
        }
    }
}

class SoftSubject extends Subject
{
    protected $deleteMode = 'soft';
}

class OtherSubject extends Subject {}
