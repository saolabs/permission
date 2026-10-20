<?php

return [
    'connection' => null,
    'tables' => [
        'roles' => 'roles',
        'permissions' => 'permissions',
        'permission_role' => 'permission_role',
        'role_assignments' => 'role_assignments',
    ],

    // Choose before publishing/running the migration: int, uuid, ulid, string.
    'subject_key_type' => 'int',

    // Registers the explicit 'saola.permission' Gate ability; never bypasses Policies.
    'gate' => true,

    'cache' => [
        // Null uses the default store. A process-local 'array' store is ignored: under Octane it
        // would grow with every user and stay stale between workers.
        'store' => null,
        // Seconds a user's role ids and a role's grants stay cached. 0 queries on every request.
        'ttl' => 300,
    ],
];
