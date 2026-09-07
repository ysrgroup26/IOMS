import react from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';

/**
 * v2.54.0 -- a deliberately NARROW ESLint configuration.
 *
 * `npm run lint` was in package.json from the start but there was no
 * config file, so on ESLint 9 (flat config) the script simply failed and
 * nothing was ever linted. This release found out why that mattered:
 * Settings/Index.jsx read `canFieldAccess` inside a module-scope function
 * where it was never a prop, so opening Settings > Users threw a
 * ReferenceError and blanked the page. A static check would have caught
 * it the day it was written; a hundred hand-read files would not.
 *
 * The rule set is intentionally the smallest one that catches THAT class
 * of defect -- undefined identifiers, unreachable code, and JSX that
 * references an undefined component or an undeclared variable. Style,
 * hooks-exhaustive-deps, prop-types and the rest are left off on purpose:
 * turning them on across a codebase this size produces thousands of
 * findings nobody reads, and a lint run nobody reads is worse than none.
 */
export default [
    {
        files: ['resources/js/**/*.{js,jsx}'],
        plugins: { react, 'react-hooks': reactHooks },
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: 'module',
            parserOptions: { ecmaFeatures: { jsx: true } },
            globals: {
                // Browser + Laravel/Ziggy globals this app genuinely relies on.
                window: 'readonly', document: 'readonly', navigator: 'readonly',
                console: 'readonly', localStorage: 'readonly', sessionStorage: 'readonly',
                setTimeout: 'readonly', clearTimeout: 'readonly',
                setInterval: 'readonly', clearInterval: 'readonly',
                fetch: 'readonly', FormData: 'readonly', URL: 'readonly',
                URLSearchParams: 'readonly', Blob: 'readonly', File: 'readonly',
                FileReader: 'readonly', Image: 'readonly', Event: 'readonly',
                CustomEvent: 'readonly', MutationObserver: 'readonly',
                IntersectionObserver: 'readonly', ResizeObserver: 'readonly',
                requestAnimationFrame: 'readonly', cancelAnimationFrame: 'readonly',
                alert: 'readonly', confirm: 'readonly', prompt: 'readonly',
                matchMedia: 'readonly', getComputedStyle: 'readonly',
                HTMLElement: 'readonly', Node: 'readonly', AbortController: 'readonly',
                queueMicrotask: 'readonly', structuredClone: 'readonly',
                process: 'readonly', global: 'readonly',
                // Ziggy's route() helper, published globally by @routes.
                route: 'readonly', Ziggy: 'readonly', axios: 'readonly',
            },
        },
        settings: { react: { version: 'detect' } },
        rules: {
            'no-undef': 'error',
            'no-unreachable': 'error',
            'no-dupe-keys': 'error',
            'no-dupe-args': 'error',
            'no-duplicate-case': 'error',
            'no-const-assign': 'error',
            'no-obj-calls': 'error',
            'no-self-assign': 'error',
            'no-unsafe-negation': 'error',
            'use-isnan': 'error',
            'valid-typeof': 'error',
            'react/jsx-no-undef': 'error',
            'react/jsx-uses-vars': 'error',
            'react/jsx-uses-react': 'off',
            'react/no-direct-mutation-state': 'error',
            // Registered so the existing inline disable-comments refer to a
            // rule that exists, and warn (not error) where they do not.
            'react-hooks/rules-of-hooks': 'error',
            'react-hooks/exhaustive-deps': 'off',
        },
    },
];
