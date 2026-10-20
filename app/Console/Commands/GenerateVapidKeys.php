<?php

namespace App\Console\Commands;

use App\Services\Notifications\WebPushService;
use Illuminate\Console\Command;

/**
 * v2.129.0 -- THE ONE SETUP STEP WEB PUSH NEEDS.
 *
 * Web Push in IOMS has no provider, no account and no bill (see
 * WebPushService). What it does need is an application server key pair,
 * which identifies this installation to whichever push service a browser
 * happens to use. This generates one and prints the two lines to paste into
 * `.env`.
 *
 * NOT WRITTEN TO `.env` AUTOMATICALLY, deliberately: `.env` is the one file
 * a deploy must never rewrite, and on the owner's host it carries database
 * credentials and payment keys. The command prints; the owner pastes.
 *
 * REGENERATING INVALIDATES EVERY DEVICE. A browser subscribes to a specific
 * application server key, and a push signed with a different one is refused
 * for subscriptions issued under the old one -- so every person would have
 * to grant permission again. The command says so before it prints, because
 * "it stopped working and nobody knows why" is the shape that failure takes.
 */
class GenerateVapidKeys extends Command
{
    protected $signature = 'ioms:vapid-keys {--force : Print a new pair even though one is already configured}';

    protected $description = 'Generate the VAPID key pair IOMS web push notifications are signed with';

    public function handle(WebPushService $push): int
    {
        if ($push->isConfigured() && ! $this->option('force')) {
            $this->components->warn('A VAPID key pair is already configured.');
            $this->line('');
            $this->line('  Generating a new one INVALIDATES every device that has already granted');
            $this->line('  notification permission: a push signed with a different key is refused');
            $this->line('  for subscriptions issued under the old one, and every person would have');
            $this->line('  to allow notifications again.');
            $this->line('');
            $this->line('  Re-run with --force if that is what you intend.');

            return self::FAILURE;
        }

        try {
            $pair = $push->generateKeyPair();
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('VAPID key pair generated.');
        $this->line('');
        $this->line('Add these two lines to .env on the server, then run `php artisan config:clear`:');
        $this->line('');
        $this->line('  IOMS_VAPID_PUBLIC_KEY='.$pair['public']);
        $this->line('  IOMS_VAPID_PRIVATE_KEY='.$pair['private']);
        $this->line('');
        $this->components->warn('The private key is a credential. It belongs in .env and nowhere else -- not in the repository, not in a commit, not in a chat message.');
        $this->line('');
        $this->line('Until both are set, IOMS does not ask anyone for notification permission:');
        $this->line('the in-app live notifications work regardless, and background push is simply');
        $this->line('not offered rather than offered and broken.');

        return self::SUCCESS;
    }
}
