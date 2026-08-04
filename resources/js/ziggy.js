/**
 * Lightweight route-name -> URL helper mirroring routes/web.php.
 *
 * In a normal install, the `tightenco/ziggy` package generates this
 * automatically (via the `@routes` Blade directive in app.blade.php,
 * see resources/views/app.blade.php) by introspecting Laravel's route
 * list, and exposes a global `route()` function identical in usage to
 * this one. That generation step requires PHP/Composer to run, so this
 * hand-written equivalent is provided so the frontend works immediately;
 * once you run `composer install` the real Ziggy `@routes` directive
 * takes over and this file becomes redundant (safe to delete).
 */
const routes = {
    'login': '/login',
    'logout': '/logout',
    'home': '/',
    'dashboard': '/dashboard',

    'employees.index': '/employees',
    'employees.export': '/employees/export',
    'employees.show': '/employees/:employee',
    'employees.create': '/employees-create',
    'employees.store': '/employees',
    'employees.edit': '/employees/:employee/edit',
    'employees.update': '/employees/:employee',
    'employees.destroy': '/employees/:employee',

    'reports.index': '/reports',
    'reports.export.excel': '/reports/export/excel',
    'reports.export.pdf': '/reports/export/pdf',

    'kpi-input.index': '/kpi-input',
    'kpi-input.attendance-employees': '/kpi-input/attendance-employees',
    'kpi-input.single': '/kpi-input/single',
    'kpi-input.quick-attendance': '/kpi-input/quick-attendance',

    'settings.index': '/settings',
    'settings.company': '/settings/company',
    'settings.departments.store': '/settings/departments',
    'settings.departments.update': '/settings/departments/:department',
    'settings.departments.destroy': '/settings/departments/:department',
    'settings.positions.store': '/settings/positions',
    'settings.positions.update': '/settings/positions/:position',
    'settings.positions.destroy': '/settings/positions/:position',
    'settings.users.store': '/settings/users',
    'settings.users.update': '/settings/users/:user',
    'settings.users.destroy': '/settings/users/:user',
    'settings.backup': '/settings/backup',
    'settings.restore': '/settings/restore',
};

export function route(name, params) {
    let path = routes[name];
    if (!path) {
        throw new Error(`[route] Unknown route name: ${name}`);
    }

    if (params !== undefined && params !== null) {
        const values = Array.isArray(params) ? params : [params];
        let i = 0;
        path = path.replace(/:[a-zA-Z_]+/g, () => {
            const value = values[i++];
            return value?.id ?? value;
        });
    }

    return path;
}

if (typeof window !== 'undefined') {
    window.route = route;
}
