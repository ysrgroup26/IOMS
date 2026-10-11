<?php

namespace App\Services;

use RuntimeException;

/**
 * v2.108.2 (SEC-010) -- THE WHOLE-DATABASE DUMP AND RESTORE, IN ONE PLACE
 * AND BEHIND ONE DOOR.
 *
 * This logic used to live in `SettingsController`, reachable by any
 * tenant's `super_admin` -- a CUSTOMER role. `mysqldump` takes no notice
 * of tenancy, so one customer's administrator could download every other
 * customer's data, and `mysql <` could overwrite the database for all of
 * them. The authorization fix is in the routes and the controller; this
 * class exists so the shell work is written once, tested, and kept away
 * from the request.
 *
 * TWO THINGS ARE DIFFERENT FROM THE ORIGINAL, besides where it lives.
 *
 * 1. THE PASSWORD IS NO LONGER ON THE COMMAND LINE. `-p'secret'` is
 *    visible to every user on the host in `ps`, and on shared hosting
 *    (which is where IOMS runs -- ADR 027) that is not a theoretical
 *    audience. Credentials go in a 0600 defaults file that exists for the
 *    length of one command and is removed in a `finally`.
 *
 * 2. IT REFUSES WHAT IT CANNOT DO. `mysqldump` against a SQLite
 *    connection produced an empty file and a cheerful success message.
 *    `isSupported()` answers honestly and the controller says so.
 */
class DatabaseBackupService
{
    /**
     * Whether a dump/restore is possible at all on this deployment: the
     * default connection has to be MySQL, and the binaries have to exist.
     */
    public function isSupported(): bool
    {
        return config('database.default') === 'mysql'
            && $this->binary('mysqldump') !== null
            && $this->binary('mysql') !== null;
    }

    /** Human-readable reason `isSupported()` said no, for the operator. */
    public function unsupportedReason(): ?string
    {
        if (config('database.default') !== 'mysql') {
            return 'This deployment does not run on MySQL, so a mysqldump backup does not apply here.';
        }

        if ($this->binary('mysqldump') === null || $this->binary('mysql') === null) {
            return 'The mysqldump/mysql binaries are not available on this server.';
        }

        return null;
    }

    /**
     * Writes a full SQL dump to $path. Returns the dump's own exit status
     * and output so the caller can report a failure rather than hand the
     * operator an empty file.
     *
     * `--single-transaction` so the dump is consistent without locking the
     * application out, which matters because this runs against the live
     * database of a running system.
     *
     * @return array{ok: bool, output: string}
     */
    public function dump(string $path): array
    {
        return $this->run(
            sprintf(
                '%s --defaults-extra-file=%%s --single-transaction --quick --routines --no-tablespaces %s > %s 2>&1',
                escapeshellarg($this->binary('mysqldump') ?? 'mysqldump'),
                escapeshellarg($this->database()),
                escapeshellarg($path)
            )
        );
    }

    /**
     * Replaces the database with the contents of $path.
     *
     * DESTRUCTIVE AND NOT UNDOABLE. Every guard that decides whether this
     * may be called -- who is asking, whether they typed the confirmation,
     * whether the file looks like a dump -- lives in the controller and
     * its form request, deliberately: by the time a caller reaches this
     * method the decision has already been made.
     *
     * @return array{ok: bool, output: string}
     */
    public function restore(string $path): array
    {
        return $this->run(
            sprintf(
                '%s --defaults-extra-file=%%s %s < %s 2>&1',
                escapeshellarg($this->binary('mysql') ?? 'mysql'),
                escapeshellarg($this->database()),
                escapeshellarg($path)
            )
        );
    }

    /**
     * Runs $template with a temporary 0600 defaults file substituted for
     * `%s`, and removes that file whatever happens.
     *
     * @return array{ok: bool, output: string}
     */
    private function run(string $template): array
    {
        $credentials = $this->writeDefaultsFile();

        try {
            $command = sprintf($template, escapeshellarg($credentials));

            exec($command, $output, $exitCode);

            return ['ok' => $exitCode === 0, 'output' => implode("\n", $output)];
        } finally {
            @unlink($credentials);
        }
    }

    /**
     * A MySQL defaults file holding the credentials, readable only by the
     * user running PHP. This is the documented way to keep a password out
     * of the process list.
     */
    private function writeDefaultsFile(): string
    {
        $config = config('database.connections.mysql');

        $path = tempnam(sys_get_temp_dir(), 'ioms-db-');

        if ($path === false) {
            throw new RuntimeException('Could not create a temporary credentials file.');
        }

        // Narrow the file BEFORE the password is in it.
        chmod($path, 0600);

        $contents = "[client]\n"
            ."host=\"{$config['host']}\"\n"
            ."port=\"{$config['port']}\"\n"
            ."user=\"{$config['username']}\"\n"
            .'password="'.str_replace('"', '\"', (string) ($config['password'] ?? ''))."\"\n";

        file_put_contents($path, $contents);

        return $path;
    }

    private function database(): string
    {
        return (string) config('database.connections.mysql.database');
    }

    /** Absolute path to a binary, or null when it is not installed. */
    private function binary(string $name): ?string
    {
        $found = trim((string) shell_exec('command -v '.escapeshellarg($name).' 2>/dev/null'));

        return $found !== '' ? $found : null;
    }
}
