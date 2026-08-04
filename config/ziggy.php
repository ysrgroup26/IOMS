<?php

return [
    // Ziggy's @routes Blade directive (already in app.blade.php) auto-injects
    // all named routes into `window.Ziggy` and makes `route()` globally
    // available in JS via the vite-plugin-ziggy helper. No extra config
    // needed here beyond optionally restricting which routes are exposed.
    'except' => [],
];
