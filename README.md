# saola/permission

Phân quyền backend tùy chọn cho Saola. Core cung cấp enum và metadata route;
package này quản lý nhóm quyền (role), các ô cấp quyền và quan hệ với User.
User vẫn nằm tại `App\Modules\User\Models\User` của ứng dụng. Admin UI và đăng nhập
thuộc ứng dụng.

```text
Developer: khai báo module + thao tác
                 ↓
        Catalog / ma trận route
                 ↓
Người quản trị: tạo role → chọn module + thao tác → gán role cho User
                 ↓
Request → kiểm tra các ô quyền cần thiết → Controller / Policy
```

## Trạng thái phát triển

Package yêu cầu `saola/core:^2.0`, có `Saola\Core\Routing\Permission` và
`System::permissionDeclarations()`. Core 1.x không tương thích. Package permission
chưa có release riêng trên Packagist. Không cấu hình sibling path repository
cho ứng dụng.

```bash
composer install
composer check
```

Kiểm thử nguồn core đang phát triển, từ thư mục package:

```bash
composer install
SAOLA_CORE_SOURCE=/absolute/path/to/core composer test
```

`SAOLA_CORE_SOURCE` chỉ được đọc trong bootstrap của test. Runtime vẫn dùng
Composer autoload của ứng dụng.

## Bộ thao tác chung

```php
use Saola\Core\Routing\Permission;

Permission::ACCESS;
Permission::READ;
Permission::CREATE;
Permission::UPDATE;
Permission::DELETE;
Permission::RESTORE;
Permission::FORCE_DELETE;
```

Chỉ có một enum dùng chung. Khai báo bằng string như `permission('users.read')`
hoặc `permission('read')` bị từ chối. String chỉ xuất hiện trong metadata/DB sau
khi serialize hoặc giải mã dữ liệu đầu vào đã kiểm tra enum.

## Khai báo route

```php
use Saola\Core\Routing\Permission;
use Saola\Core\System;

System::admin([
    'prefix' => 'admin',
    'permission' => Permission::ACCESS,
    'middleware' => ['web', 'auth', 'saola.permission.route'],
])->module('users', ['prefix' => 'people'])
    ->permission(Permission::ACCESS)
    ->group(function ($router) {
        $router->get('/', [UserController::class, 'index'])
            ->name('index')->permission(Permission::READ);
        $router->get('/{id}', [UserController::class, 'show'])
            ->name('show')->permission(Permission::READ);
        $router->put('/{id}', [UserController::class, 'update'])
            ->name('update')->permission(Permission::UPDATE);
    });
```

Hai action xem thuộc cùng ô READ của module User. Prefix `people` thay đổi URL,
không thay đổi danh tính module `users`. Thực hiện đăng ký Laravel route bằng
luồng `pushLaravelRoute()` hiện có của ứng dụng.

Quy tắc đã chốt:

- Một ô quyền được xác định bởi **context + đường dẫn module chính xác + thao tác**.
- Cấp READ tại `admin / users` không cấp READ cho `api / users`, module khác hoặc
  `admin / users / profile`.
- ACCESS khai báo ở context/module cha tiếp tục là điều kiện vào các route con.
  Có ACCESS ở cha không tự cấp thao tác trên module con.
- READ/CREATE/UPDATE… ở module cha không kế thừa sang submodule. Trong cùng module,
  quyền khai báo ở module và action được cộng dồn theo ALL.
- Nhiều thao tác trong một lần `permission(READ, UPDATE)` yêu cầu đủ cả hai,
  có thể nhận từ nhiều role. Gọi `permission()` lại trên cùng node thay bộ khai báo
  cục bộ của node đó.
- Action không có điều kiện quyền, kể cả điều kiện kế thừa, đi qua middleware
  quyền; `auth` vẫn kiểm soát đăng nhập độc lập.

`group(callback)` hiện vẫn chạy trên cùng module. API group nhận attributes và
có scope riêng chưa được triển khai. Không gọi `permission()` trên callback router
nếu chỉ muốn áp dụng cho một nhóm action trong module hiện tại.

## Dữ liệu ma trận

```php
$registry = app(\Saola\Permission\Services\PermissionRegistry::class);
$catalog = $registry->catalog('admin');
$matrix = $registry->matrix('admin');
```

Catalog gồm các ô khai báo trên cây và các ô bổ sung bằng:

```php
System::addPermission('admin', ['users'], Permission::RESTORE);
```

Một hàng action của ma trận:

```json
{
  "context": "admin",
  "module_path": ["users"],
  "route_name": "admin.users.index",
  "methods": ["GET", "HEAD"],
  "prefix": "admin/people",
  "uri": "/admin/people",
  "declared_permissions": ["read"],
  "inherited_permissions": [
    {"context": "admin", "module_path": [], "operation": "access"},
    {"context": "admin", "module_path": ["users"], "operation": "access"}
  ],
  "required_permissions": [
    {"context": "admin", "module_path": [], "operation": "access"},
    {"context": "admin", "module_path": ["users"], "operation": "access"},
    {"context": "admin", "module_path": ["users"], "operation": "read"}
  ]
}
```

`module_path: []` chỉ context. Đường dẫn dùng danh sách segment, không ghép tên
module thành khóa string. Context/path lấy từ vị trí trong cây, kể cả submodule
thiếu field context riêng. Prefix/domain của group Laravel bên ngoài không có
trong projection; lấy URL thực tế từ Laravel router nếu cần.

Middleware đối chiếu tên route + HTTP method. Thiếu metadata, thiếu tên hoặc tên
trùng theo method là lỗi cấu hình. Dùng factory middleware trực tiếp bên dưới
cho route Laravel ngoài System và route resource/redirect/fallback đặc biệt.

## Migration và đồng bộ

Sau khi package có release dùng được với core tương ứng:

```bash
composer require saola/permission
php artisan vendor:publish --tag=saola-permission-config
# Chọn connection, tên bảng và subject_key_type trong config trước khi migrate.
php artisan vendor:publish --tag=saola-permission-migrations
php artisan migrate
php artisan saola:permission:sync --dry-run
php artisan saola:permission:sync --context=admin
```

Provider không tự load migration, không truy vấn bảng quyền khi boot, không sửa
bảng users. Migration tạo `roles`, `permissions`, `permission_role`,
`role_assignments`. Bảng permissions lưu context, module_path, operation và khóa
hash nội bộ của bộ ba để đối chiếu chính xác trên cả DB dùng collation không
phân biệt hoa/thường. Giá trị operation là bảy thao tác dùng chung, không phải tên
quyền tùy ý do developer gõ cho từng module.

Lệnh sync chỉ thêm ô chưa có, không xóa ô cũ, không cấp quyền cho role. Các action
cùng scope/thao tác chỉ tạo một ô. Role và tên role do người quản trị tự tạo.

Chọn `subject_key_type` là `int`, `uuid`, `ulid` hoặc `string` phù hợp khóa User.
Các bảng permission dùng chung connection cấu hình; quan hệ với User là
polymorphic và hỗ trợ morph map. Publish lại thông thường không nhân bản migration.
Rollback xóa các bảng quyền cùng dữ liệu của chúng.

Schema này thay thế bản capability string chưa phát hành. Nếu từng tự chạy bản
migration cũ, cần migration chuyển đổi riêng dựa trên dữ liệu thực tế; package
không đoán cách tách các khóa string cũ thành context/module/thao tác.

## User và thao tác cấp quyền

Thêm vào model User hiện có, giữ nguyên các trait và cấu hình của module:

```php
use Saola\Core\Models\Authenticatable;
use Saola\Permission\Concerns\HasRoles;
use Saola\Permission\Contracts\PermissionSubject;

class User extends Authenticatable implements PermissionSubject
{
    use HasRoles;
}
```

```php
use Saola\Core\Routing\Permission;
use Saola\Permission\Models\Role;

$editor = Role::firstOrCreate(['name' => 'Nhân viên nội dung']);
$editor->grantPermissionTo('admin', [], Permission::ACCESS);
$editor->grantPermissionTo('admin', ['articles'], Permission::READ, Permission::CREATE, Permission::UPDATE);
$user->assignRole($editor);

$user->hasPermissionTo('admin', ['articles'], Permission::READ);
$editor->revokePermissionTo('admin', ['articles'], Permission::UPDATE);
$editor->syncPermissions('admin', ['articles'], Permission::READ); // Chỉ thay ô của module này.
$editor->syncPermissions('admin', ['articles']);                   // Gỡ hết ô của module này.
$user->removeRole($editor);
$user->syncRoles($editor); // Cũng nhận tên role đã tạo: 'Nhân viên nội dung'.
$user->syncRoles();         // Gỡ mọi role của User.
```

Ô quyền phải được đăng ký trước khi cấp; grant không tự tạo ô ngoài catalog.
Model tham gia quan hệ phải được lưu trước. Mỗi lần kiểm tra đọc lại grant,
không dùng quan hệ đã load làm cache quyền. Thu hồi có hiệu lực ở lần kiểm tra
kế tiếp. Controller/service quản trị phải kiểm tra quyền trước khi sửa role hoặc
gán role cho User.

Xóa User qua Eloquent events dọn assignment khi hard delete và giữ lại khi soft
delete. Bulk delete qua query builder bỏ qua events, ứng dụng phải tự dọn.

## Middleware trực tiếp, Gate và Policy

```php
use Saola\Permission\Http\Middleware\RequirePermission;
use Saola\Permission\Data\PermissionRequirement;

Route::get('/articles', [ArticleController::class, 'index'])->middleware([
    'auth',
    RequirePermission::using('admin', ['articles'], Permission::READ),
]);

Gate::authorize('saola.permission', new PermissionRequirement('admin', ['articles'], Permission::READ));
Gate::authorize('update', $article); // Policy của ứng dụng.
```

Factory nhận enum và tự encode tham số để hỗ trợ route cache. Không viết tay
`saola.permission:read`. Package không dùng Gate::before để bỏ qua Policy; chỉ
đăng ký ability riêng `saola.permission`, có thể tắt bằng `permission.gate = false`.
Guest nhận authentication error (401 với JSON), thiếu quyền nhận 403. Chọn guard
bằng `auth:<guard>` trước middleware quyền khi ứng dụng có nhiều guard.

Chưa có admin UI, super-admin bypass, wildcard, deny hoặc role inheritance.
