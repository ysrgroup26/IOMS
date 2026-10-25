<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ADR 076 -- the migration policy decides which releases deploy without a person.
 *
 * Pins both directions: what is additive deploys on its own, and everything that
 * can lose or rewrite data still goes to a person. If a new migration idiom is
 * misclassified, extend deploy/tencent/hermes/migration-policy.php AND this test.
 */
class MigrationPolicyTest extends TestCase
{
    private function judge(string $up, string $status = 'A'): array
    {
        $src = "<?php\nuse Illuminate\\Support\\Facades\\Schema;\nuse Illuminate\\Database\\Schema\\Blueprint;\n"
            ."return new class extends Migration {\n public function up(): void {\n{$up}\n }\n"
            ." public function down(): void { Schema::dropIfExists('t'); }\n};\n";
        $proc = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2).'/deploy/tencent/hermes/migration-policy.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        fwrite($pipes[0], "==== {$status} database/migrations/x.php\n{$src}\n");
        fclose($pipes[0]);
        $out = trim(stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $out];
    }

    public function test_additive_changes_deploy_without_a_person(): void
    {
        foreach ([
            "Schema::create('t', function (Blueprint \$table) { \$table->id(); \$table->string('name'); \$table->timestamps(); });",
            "Schema::createIfMissing('t', function (Blueprint \$table) { \$table->id(); });",
            "Schema::table('t', function (Blueprint \$table) { \$table->string('x')->nullable(); \$table->index('x'); });",
            "Schema::table('t', function (Blueprint \$table) { \$table->boolean('f')->default(false); \$table->foreignId('u')->nullable()->constrained(); });",
            "if (! Schema::hasColumn('t', 'x')) { Schema::table('t', fn (Blueprint \$table) => \$table->text('x')->nullable()); }",
            "if (DB::getDriverName() === 'sqlite') { return; } Schema::table('t', function (Blueprint \$table) { \$table->index(['a', 'b']); });",
        ] as $up) {
            [$code, $out] = $this->judge($up);
            $this->assertSame(0, $code, "expected additive: {$up}\n{$out}");
            $this->assertStringStartsWith('additive', $out);
        }
    }

    public function test_anything_that_can_lose_or_rewrite_data_needs_a_person(): void
    {
        foreach ([
            "Schema::table('t', function (Blueprint \$table) { \$table->string('x'); });" => 'NOT NULL column without a default',
            "Schema::table('t', fn (Blueprint \$table) => \$table->text('x'));" => 'NOT NULL column without a default',
            "Schema::table('t', function (Blueprint \$table) { \$table->dropColumn('x'); });" => 'dropColumn',
            "Schema::table('t', function (Blueprint \$table) { \$table->renameColumn('a', 'b'); });" => 'renameColumn',
            "Schema::table('t', function (Blueprint \$table) { \$table->string('x')->nullable()->change(); });" => 'change',
            "Schema::dropIfExists('t');" => 'dropIfExists',
            "Schema::rename('a', 'b');" => 'rename',
            "DB::table('t')->update(['a' => 1]);" => 'DB::table',
            "DB::statement('ALTER TABLE t DROP x');" => 'DB::statement',
            'App\\Models\\User::query()->delete();' => 'User::query',
            '$this->backfill();' => 'helper',
        ] as $up => $why) {
            [$code, $out] = $this->judge($up);
            $this->assertSame(1, $code, "expected review: {$up}\n{$out}");
            $this->assertStringContainsString($why, $out);
        }
    }

    public function test_an_edited_or_deleted_migration_is_never_automatic(): void
    {
        $additive = "Schema::create('t', function (Blueprint \$table) { \$table->id(); });";
        foreach (['M' => 'modified', 'D' => 'deleted', 'R100' => 'renamed'] as $status => $word) {
            [$code, $out] = $this->judge($additive, $status);
            $this->assertSame(1, $code);
            $this->assertStringContainsString($word, $out);
        }
    }
}
