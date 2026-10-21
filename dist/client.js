// Bundle client của saola/permission — nạp qua APP_CONFIGS.bundles
// (extra.saola.client + `php artisan saola:assets`). `@saolabs/client` là
// external: import map trỏ nó về runtime của app, nên `App` là cùng một instance.
import { App, defineBundle } from '@saolabs/client';

const warned = new Set();

export default defineBundle({
    name: 'saola/permission',
    helpers: {
        /**
         * Bản JS của canRoute() (helpers.php). Dữ liệu do server tính theo
         * request: Saola::share('permission') → App.shared('permission').
         */
        canRoute(name) {
            const access = App.shared('permission', { allowed: [], denied: [] });
            if (access.allowed.includes(name)) return true;
            if (!access.denied.includes(name) && !warned.has(name)) {
                // Gõ sai tên route thì phần tử biến mất — báo thay vì im lặng.
                warned.add(name);
                console.warn(`[saola/permission] canRoute(): không có route tên [${name}].`);
            }
            return false;
        },
    },
});
