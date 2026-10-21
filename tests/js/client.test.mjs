// dist/client.js là bản JS của canRoute(). Trong trình duyệt import map trỏ
// `@saolabs/client` về runtime của app; ở đây một resolve hook trỏ nó về stub.
import { register } from 'node:module';
import test from 'node:test';
import assert from 'node:assert/strict';

const stub = 'export const App = globalThis.__saolaApp; export const defineBundle = (b) => b;';
register('data:text/javascript,' + encodeURIComponent(`
export async function resolve(specifier, context, next) {
    if (specifier === '@saolabs/client') {
        return { url: 'data:text/javascript,' + encodeURIComponent(${JSON.stringify(stub)}), shortCircuit: true };
    }
    return next(specifier, context);
}`));

let shared;
globalThis.__saolaApp = { shared: (key, fallback) => (key === 'permission' && shared ? shared : fallback) };
const { default: bundle } = await import('../../dist/client.js');
const { canRoute } = bundle.helpers;

test('đọc danh sách server chia sẻ qua App.shared("permission")', () => {
    shared = { allowed: ['admin.roles.index'], denied: ['admin.roles.destroy'] };
    assert.equal(bundle.name, 'saola/permission');
    assert.equal(canRoute('admin.roles.index'), true);
    assert.equal(canRoute('admin.roles.destroy'), false);
});

test('tên route lạ: false và cảnh báo đúng một lần', (t) => {
    shared = { allowed: [], denied: [] };
    const warn = t.mock.method(console, 'warn', () => {});
    assert.equal(canRoute('admin.roles.indx'), false);
    assert.equal(canRoute('admin.roles.indx'), false);
    assert.equal(warn.mock.callCount(), 1);
    assert.match(warn.mock.calls[0].arguments[0], /admin\.roles\.indx/);
});

test('chưa có dữ liệu chia sẻ (khách, trang không qua Saola) thì không nổ', (t) => {
    shared = undefined;
    t.mock.method(console, 'warn', () => {});
    assert.equal(canRoute('admin.roles.index'), false);
});
