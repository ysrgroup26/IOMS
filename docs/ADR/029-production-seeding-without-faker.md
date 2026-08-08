# 029 — Production Seeding Must Not Require `fakerphp/faker`

## Status

Accepted.

## Problem

`./deploy.sh --first` reached the seeding stage successfully -- every seeder up through
`CompanySettingSeeder` completed -- then crashed on the last one, `EmployeeSeeder`, with:

```
Call to undefined function Database\Factories\fake()
```

## Root cause

`Illuminate\Foundation\helpers.php` (part of `laravel/framework`, always a required, non-dev
dependency) defines the global `fake()` helper like this:

```php
if (! function_exists('fake') && class_exists(\Faker\Factory::class)) {
    function fake($locale = null): \Faker\Generator { ... }
}
```

The function definition itself is guarded behind `class_exists(\Faker\Factory::class)`. Under
`composer install --no-dev`, `fakerphp/faker` (declared in `composer.json`'s `require-dev`,
correctly, per this project's own "do not add faker as a production dependency" constraint) is
never installed, so that class doesn't exist, so `fake()` is **never defined at all** -- not
"defined but broken," genuinely absent from both the current namespace and the global one, which is
exactly why the error names it as `Database\Factories\fake()` (PHP tried the current namespace,
then fell back to global, found neither).

`EmployeeSeeder::run()` and `database/factories/EmployeeFactory.php`'s `definition()` both call
`fake()` unconditionally, with no such guard of their own -- confirmed via `grep` that
`EmployeeSeeder` is the *only* production code path calling `Employee::factory()` anywhere in the
app, and that it is the **last** seeder in `DatabaseSeeder::run()`'s call order, which is exactly
why everything before it (tenants, packages, modules, workspaces, companies, departments,
positions, KPI categories, PPE types, users, roles/permissions, company settings) had already
completed successfully before the crash.

## Decision

`EmployeeSeeder::run()` now mirrors Laravel's own guard, explicitly:

```php
if (! class_exists(\Faker\Factory::class)) {
    $this->command?->warn('Skipping EmployeeSeeder: fakerphp/faker is not installed ...');
    return;
}
```

placed after the existing idempotency check (`Employee::count() > 0`) and before the first
`fake()`/`Employee::factory()` call. Production seeding now completes with a clear, non-error
console message instead of crashing -- and since this is the last seeder, skipping it doesn't
block or skip anything else `db:seed` needs to do.

**`fakerphp/faker` stays in `require-dev`, unchanged** -- not moved to `require`. This is
deliberate, not an oversight: the 60 demo employees + 6 months of fake KPI history this seeder
generates are explicitly sample/demo data (see the seeder's own pre-existing doc comment --
"Safe to remove for a production dataset"), never something a real production deployment should
want seeded automatically in the first place. Skipping it in production isn't a workaround for a
missing dependency; it's the *correct* behavior independent of the dependency question.

`database/factories/EmployeeFactory.php` was left untouched -- it's the only caller-guarded
correctly already via the seeder-level check above it (nothing else in the app calls
`Employee::factory()`), and it must keep working exactly as before for local development, where
`composer install` (with dev dependencies) still installs Faker normally.

## Verified

Since `composer` isn't invokable in this environment, the `--no-dev` autoloader state was
reproduced directly and faithfully: the installed `fakerphp/faker` package directory was removed,
and the stale `Faker`-related entries in `vendor/composer/autoload_classmap.php`,
`autoload_static.php`, and `autoload_psr4.php` were stripped to match what a real `--no-dev`
regeneration would actually produce (not just deleting files and leaving broken references, which
would have produced misleading warnings a genuine `--no-dev` install would never show). Confirmed
`class_exists(\Faker\Factory::class)` and `function_exists('fake')` both cleanly return `false` with
no warnings in that state.

Against that state:
- `php artisan migrate:fresh --seed --force` completed end-to-end, `EmployeeSeeder` printed the
  skip message and returned in ~5ms, `DONE`.
- `php artisan app:deploy --seed --no-maintenance` (what `./deploy.sh --first` actually runs)
  completed end-to-end to "Deployment complete."

Then Faker was fully restored (package directory and all three autoload files reverted to their
original committed content) and `php artisan migrate:fresh --seed` re-run to confirm local
development is completely unaffected: 60 employees, 438 KPI records -- identical to before this
change.

## Consequences

- A brand-new production tenant starts with zero employees and zero KPI history after
  `./deploy.sh --first` -- expected and correct; a real company's Employees page starts empty and
  is populated by the actual company's admin (via CSV import or manual entry), not sample data.
- Local development (and any environment that runs a full `composer install` with dev dependencies)
  is completely unaffected -- `EmployeeSeeder` behaves exactly as it always has.
- If a future seeder needs demo/sample data generation for a different module, the same guard
  pattern (`class_exists(\Faker\Factory::class)` before any `fake()`/`Model::factory()` call, with a
  clear skip message) should be used rather than assuming Faker is always available.
