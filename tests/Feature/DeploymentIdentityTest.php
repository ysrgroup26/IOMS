<?php

namespace Tests\Feature;

use App\Services\ReleaseStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * v2.118.0 -- THE RELEASE THAT WAS DELIVERED AND WAS NOT RUNNING.
 *
 * Corrections to PPE Master and My Work were committed, tested and reported
 * as released. The owner deployed them and PPE Master still answered "Jenis
 * APD bawaan tidak dapat diubah" -- a sentence present in NO file of the
 * release they deployed. Running that exact commit in an isolated
 * environment and driving the same screen in a browser, the twelve-to-six
 * month edit saves and persists.
 *
 * So every test in `SharedMasterDataOwnershipTest` was passing, correctly,
 * about code that was not the code answering requests. No test asserting
 * BEHAVIOUR could have caught that, however many of them there were, which
 * is why this file asserts something different: that the application can
 * SAY which code is running, and that a deploy refuses to finish quietly
 * when the database does not match it.
 */
class DeploymentIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_application_can_name_the_commit_and_branch_it_is_running(): void
    {
        $checkout = app(ReleaseStatusService::class)->checkout();

        // Read from `.git` directly rather than through a shell, because
        // shared hosting routinely has no `git` on the PHP process's PATH
        // and `exec()` disabled -- the hosts this exists to diagnose.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', (string) $checkout['commit']);
        $this->assertNotNull($checkout['detached']);

        if ($checkout['detached'] === false) {
            $this->assertNotEmpty($checkout['branch'], 'An attached checkout must be able to name its branch.');
        }
    }

    public function test_the_fingerprint_changes_when_release_critical_code_changes(): void
    {
        $release = app(ReleaseStatusService::class);
        $before = $release->fingerprint();

        $this->assertSame(
            array_keys($before['files']),
            ReleaseStatusService::FINGERPRINTED,
            'Every fingerprinted file must be reported, including one that could not be read.'
        );
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $before['signature']);

        // Each fingerprinted file must actually exist in the release -- a
        // silent null here would make the signature stop discriminating
        // between releases, which is the one thing it is for.
        foreach ($before['files'] as $path => $hash) {
            $this->assertNotNull($hash, "{$path} is fingerprinted but could not be read.");
        }

        $path = base_path(ReleaseStatusService::FINGERPRINTED[0]);
        $original = file_get_contents($path);

        try {
            file_put_contents($path, $original."\n// deployment identity probe\n");
            $after = app(ReleaseStatusService::class)->fingerprint();

            $this->assertNotSame(
                $before['signature'],
                $after['signature'],
                'A changed release file must change the signature, or it cannot detect a stale deploy.'
            );
        } finally {
            file_put_contents($path, $original);
        }

        $this->assertSame($before['signature'], app(ReleaseStatusService::class)->fingerprint()['signature']);
    }

    /**
     * The condition in which a correct release can go on being ignored: the
     * engine holds compiled code and never re-checks the file it came from.
     */
    public function test_stale_code_is_only_reported_when_the_engine_can_actually_serve_it(): void
    {
        $release = app(ReleaseStatusService::class);
        $opcache = $release->opcache();

        $this->assertArrayHasKey('available', $opcache);
        $this->assertArrayHasKey('validate_timestamps', $opcache);

        if (($opcache['enabled'] ?? false) !== true) {
            // Nothing is compiled ahead of the request, so nothing can be
            // stale. Reporting a risk here would be a false alarm.
            $this->assertFalse($release->mayServeStaleCode());

            return;
        }

        $this->assertSame(
            ($opcache['validate_timestamps'] ?? true) === false,
            $release->mayServeStaleCode()
        );
    }

    public function test_the_snapshot_carries_every_fact_the_console_and_the_deploy_log_print(): void
    {
        $snapshot = app(ReleaseStatusService::class)->snapshot();

        foreach (['version', 'stage', 'build', 'checkout', 'fingerprint', 'opcache', 'may_serve_stale_code', 'config_cached'] as $key) {
            $this->assertArrayHasKey($key, $snapshot);
        }

        $this->assertSame(config('ioms.version'), $snapshot['version']);
    }

    /**
     * The schema assertion is the half of the deploy check that can fail a
     * release. These are the columns the PPE override path and the My Work
     * seat pools read -- the two subsystems whose releases were reported
     * delivered and were not working.
     */
    public function test_the_schema_this_release_depends_on_is_present(): void
    {
        $required = [
            'ppe_types' => ['company_id', 'source_default_id'],
            'users' => ['user_type', 'is_field_user', 'ptw_access'],
            'subscriptions' => ['additional_users', 'additional_my_work_packs'],
        ];

        foreach ($required as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table), "Table {$table} is missing.");

            foreach ($columns as $column) {
                $this->assertTrue(Schema::hasColumn($table, $column), "Column {$table}.{$column} is missing.");
            }
        }
    }

    /**
     * Pins the list the deploy command asserts against the list this test
     * asserts, so the two cannot drift into disagreeing about what the
     * release needs.
     */
    public function test_the_deploy_command_checks_exactly_what_this_test_checks(): void
    {
        $reflection = new \ReflectionClass(\App\Console\Commands\DeployCommand::class);
        $required = $reflection->getConstant('REQUIRED_SCHEMA');

        $this->assertSame([
            'ppe_types' => ['company_id', 'source_default_id'],
            // v2.123.0: a My Work account is an employee's login.
            'users' => ['user_type', 'is_field_user', 'ptw_access', 'employee_id', 'access_code_set_at'],
            'subscriptions' => ['additional_users', 'additional_my_work_packs'],
        ], $required);
    }

    /** The operator reads this from a WEB request; the deploy prints it from the CLI. */
    public function test_the_operations_console_is_given_the_running_release(): void
    {
        $operator = \App\Models\User::create([
            'name' => 'Operator',
            'email' => 'operator-'.uniqid().'@ioms.test',
            'password' => bcrypt('secret-pass-1'),
            'role' => \App\Models\User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
            'is_active' => true,
        ]);

        $props = $this->actingAs($operator)->get(route('platform.dashboard'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertArrayHasKey('release', $props);
        $this->assertSame(config('ioms.version'), $props['release']['version']);
        $this->assertArrayHasKey('signature', $props['release']['fingerprint']);
    }
}
