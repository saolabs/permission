<?php

namespace Saola\Permission\Tests\Fixtures;

use Saola\Core\Models\Authenticatable;
use Saola\Permission\Concerns\HasRoles;
use Saola\Permission\Contracts\PermissionSubject;

class Subject extends Authenticatable implements PermissionSubject
{
    use HasRoles;

    protected $table = 'test_subjects';

    protected $fillable = ['name'];
}
