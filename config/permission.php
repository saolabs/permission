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
];
