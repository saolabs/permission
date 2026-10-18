<?php

namespace Saola\Permission\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Saola\Permission\Data\PermissionRequirement;
use Saola\Permission\Models\Permission;
use Saola\Permission\Services\PermissionRegistry;

class SyncPermissions extends Command
{
    protected $signature = 'saola:permission:sync {--context= : Limit to one context} {--dry-run : Show scoped operations without accessing the database}';

    protected $description = 'Register declared context/module operations without deleting grants or assigning roles';

    public function handle(PermissionRegistry $registry): int
    {
        $rows = $registry->catalog($this->option('context'));
        if ($this->option('dry-run')) {
            foreach ($rows as $row) {
                $this->line(json_encode($row, JSON_THROW_ON_ERROR));
            }

            return self::SUCCESS;
        }
        $created = DB::connection(config('permission.connection'))->transaction(function () use ($rows) {
            $count = 0;
            foreach ($rows as $row) {
                $count += (int) Permission::register(PermissionRequirement::fromArray($row))->wasRecentlyCreated;
            }

            return $count;
        });
        $this->info("Created {$created} scoped permissions; existing permissions and role grants preserved.");

        return self::SUCCESS;
    }
}
