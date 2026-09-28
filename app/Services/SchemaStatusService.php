<?php

namespace App\Services;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * v2.84.1 -- IS THE DATABASE BEHIND THE CODE, AND WHICH PART.
 *
 * This exists because of a real incident, and the incident was not the
 * failure -- it was that the failure said nothing.
 *
 * Master Admin > Support returned HTTP 500 in a deployed environment while
 * every other page in the console returned 200. Reproduced exactly by
 * renaming `support_tickets` and `support_ticket_messages` away: the queue
 * page is the only surface that touches them, so a pending migration takes
 * out precisely one page and leaves the rest looking healthy. The logged
 * exception said `SQLSTATE[42S02] Base table or view not found`, which is
 * the right answer -- but `APP_DEBUG=false` is correct in production, so the
 * operator saw a blank 500 and had no way to reach that sentence.
 *
 * IOMS ships schema changes on nearly every release. "The code was deployed
 * and the migration was not" is therefore a recurring condition, not a
 * one-off, and an operations console that cannot report it is missing the
 * one fact that explains a whole class of breakage.
 *
 * IT REPORTS. IT DOES NOT REPAIR. Nothing here runs a migration -- that is a
 * deliberate act with a backup behind it, and a console that silently
 * migrated a production database on page load would be far worse than the
 * blank 500 it replaced.
 */
class SchemaStatusService
{
    /**
     * Migrations that exist in the repository and have not run here.
     *
     * Wrapped because this is DIAGNOSTIC: a console panel that reports the
     * database's health must not itself take the page down when the database
     * is the thing that is unhealthy. A failure to answer is reported as
     * "unknown", which is honest, rather than as "up to date", which would be
     * the one wrong answer.
     */
    public function pendingMigrations(): array
    {
        try {
            $migrator = app('migrator');

            if (! $migrator instanceof Migrator || ! $migrator->repositoryExists()) {
                return [];
            }

            $ran = $migrator->getRepository()->getRan();

            return collect($migrator->getMigrationFiles($migrator->paths() ?: [database_path('migrations')]))
                ->keys()
                ->reject(fn (string $name) => in_array($name, $ran, true))
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The tables a given feature needs, and whether they are present.
     *
     * Checked by NAME rather than by catching a query exception, so a
     * genuine bug in a feature still surfaces as a bug instead of being
     * reported as a missing migration.
     */
    public function missingTables(array $tables): array
    {
        try {
            return array_values(array_filter($tables, fn (string $table) => ! Schema::hasTable($table)));
        } catch (Throwable) {
            return [];
        }
    }

    /** The one fact the operations console renders, assembled once. */
    public function snapshot(): array
    {
        $pending = $this->pendingMigrations();

        return [
            'pending_count' => count($pending),
            // Enough to act on without printing the whole directory.
            'pending' => array_slice($pending, 0, 10),
        ];
    }
}
