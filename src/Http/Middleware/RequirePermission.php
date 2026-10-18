<?php

namespace Saola\Permission\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Saola\Core\Routing\Permission;
use Saola\Permission\Data\PermissionRequirement;
use Saola\Permission\Services\PermissionManager;

class RequirePermission
{
    public function __construct(private PermissionManager $permissions) {}

    /** Typed declarations encoded as a route-cache-safe middleware string. */
    public static function using(string $context, array $modulePath, Permission $permission, Permission ...$permissions): string
    {
        $rows = array_map(fn ($operation) => (new PermissionRequirement($context, $modulePath, $operation))->toArray(), [$permission, ...$permissions]);

        return 'saola.permission:'.base64_encode(json_encode($rows, JSON_THROW_ON_ERROR));
    }

    public function handle(Request $request, Closure $next, string ...$payload): mixed
    {
        if (count($payload) !== 1 || ($decoded = base64_decode($payload[0], true)) === false) {
            throw new InvalidArgumentException('Declare explicit middleware with RequirePermission::using().');
        }
        try {
            $rows = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($rows) || ! array_is_list($rows) || $rows === []) {
                throw new InvalidArgumentException('Expected scoped permission requirements.');
            }
            $requirements = array_map(fn (array $row) => PermissionRequirement::fromArray($row), $rows);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('Invalid scoped permission middleware payload.', previous: $exception);
        }

        return $this->authorize($request, $next, $requirements);
    }

    /** @param list<PermissionRequirement> $requirements */
    public function authorize(Request $request, Closure $next, array $requirements): mixed
    {
        if ($request->user() === null) {
            throw new AuthenticationException;
        }
        if (! $this->permissions->allowsAll($request->user(), $requirements)) {
            throw new AuthorizationException;
        }

        return $next($request);
    }
}
