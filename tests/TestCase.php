<?php

namespace Saola\Permission\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Saola\Core\Http\Middleware\Next;
use Saola\Core\Routing\Permission;
use Saola\Core\System;
use Saola\Permission\Data\PermissionRequirement;
use Saola\Permission\Models\Permission as Grant;
use Saola\Permission\PermissionServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [PermissionServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    }

    protected function setUp(): void
    {
        parent::setUp();
        System::reset();
        // Saola applications register this pass-through alias in bootstrap/app.php.
        $this->app['router']->aliasMiddleware('next', Next::class);
    }

    protected function tearDown(): void
    {
        System::reset();
        parent::tearDown();
    }

    protected function migratePermissions(): void
    {
        (require __DIR__.'/../database/migrations/create_saola_permission_tables.php.stub')->up();
        Schema::create('test_subjects', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    protected function registerOperations(string $context, array $path, Permission ...$operations): void
    {
        foreach ($operations as $operation) {
            Grant::register(new PermissionRequirement($context, $path, $operation));
        }
    }
}
