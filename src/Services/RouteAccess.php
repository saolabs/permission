<?php

namespace Saola\Permission\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Saola\Permission\Data\PermissionRequirement;

/**
 * "Người dùng có được vào route tên X không" — cùng luật với middleware
 * `saola.permission.route`, dùng để ẩn giao diện (`canRoute`, `#permission`).
 *
 * Chỉ là lớp giao diện: middleware ở server vẫn là chỗ chặn thật.
 */
class RouteAccess
{
    public function __construct(private readonly PermissionRegistry $registry) {}

    public function can(string $name, ?Authenticatable $user): bool
    {
        $rows = array_filter($this->registry->matrix(), fn (array $row) => ($row['route_name'] ?? null) === $name);
        if ($rows === []) {
            if (Route::has($name)) {
                return true; // route Laravel thường: không khai báo quyền nào
            }
            // Gõ sai tên thì phần tử lặng lẽ biến mất — ở môi trường debug phải nổ to.
            if (config('app.debug')) {
                throw new InvalidArgumentException("canRoute(): không có route tên [{$name}].");
            }

            return false;
        }
        foreach ($rows as $row) {
            if (! $this->allows($user, $row)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Tên mọi route System, chia theo người dùng được vào hay không — dữ liệu
     * `App.shared('permission')` cho `canRoute` phía JS.
     *
     * @return array{allowed: list<string>, denied: list<string>}
     */
    public function split(?Authenticatable $user): array
    {
        $verdict = [];
        foreach ($this->registry->matrix() as $row) {
            $name = $row['route_name'] ?? null;
            if ($name === null || $name === '') {
                continue;
            }
            $verdict[$name] = ($verdict[$name] ?? true) && $this->allows($user, $row);
        }

        return [
            'allowed' => array_keys(array_filter($verdict)),
            'denied' => array_keys(array_filter($verdict, fn (bool $ok) => ! $ok)),
        ];
    }

    private function allows(?Authenticatable $user, array $row): bool
    {
        $requirements = array_map(fn (array $r) => PermissionRequirement::fromArray($r), $row['required_permissions'] ?? []);

        // Manager là scoped (memo theo request) nên lấy mới mỗi lần, không giữ trong singleton.
        return $requirements === [] || app(PermissionManager::class)->allowsAll($user, $requirements);
    }
}
