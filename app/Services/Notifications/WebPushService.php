<?php

namespace App\Services\Notifications;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * v2.129.0 -- WAKING A BROWSER THAT IS NOT LOOKING, WITH NO PROVIDER AND NO BILL.
 *
 * -----------------------------------------------------------------------------
 * WHAT THIS IS
 * -----------------------------------------------------------------------------
 *
 * The Web Push protocol (RFC 8030) with VAPID (RFC 8292). The browser's OWN
 * push service does the delivery -- Google's for Chrome, Mozilla's for
 * Firefox, Apple's for Safari -- and IOMS authenticates to it with a key
 * pair this installation generated itself (`php artisan ioms:vapid-keys`).
 *
 * No account, no third-party dashboard, no credentials to buy, no per-message
 * cost, nothing for the owner to sign up for. That is the entire reason this
 * is hand-rolled rather than delegated: the alternative (Pusher, Ably,
 * OneSignal) is a paid dependency with an operational owner, for a product
 * whose hosting cannot run a socket server anyway. See ADR 069.
 *
 * -----------------------------------------------------------------------------
 * WHY NO COMPOSER PACKAGE, WHICH IS A CLAIM THAT WAS MEASURED
 * -----------------------------------------------------------------------------
 *
 * `minishlink/web-push` exists and is good, and it is needed for ENCRYPTED
 * payloads -- the ECDH + HKDF + AES-128-GCM sealing that lets a push carry
 * the notification text itself. This service sends PAYLOADLESS pushes, for
 * which the whole requirement is one ES256-signed JWT. PHP's own `openssl`
 * does that unaided: a probe generated a P-256 pair, expressed the public
 * key as the 65-byte uncompressed point the Push API expects, signed, and
 * verified -- confirmed by running it, not by assuming it.
 *
 * So the dependency was not added. A package pulled in for one JWT is a
 * package whose transitive requirements (GMP among them) have to hold on a
 * shared host nobody here can inspect.
 *
 * -----------------------------------------------------------------------------
 * WHY A TICKLE AND NOT THE TEXT
 * -----------------------------------------------------------------------------
 *
 * A push carries NOTHING. The service worker, woken by it, fetches the
 * notification over the person's own authenticated session and shows what
 * it gets back. Three things follow, and all three are the point:
 *
 *   1. NOTHING OPERATIONAL CROSSES A LOCK SCREEN that the server did not
 *      just authorise. A payload is sealed to the device, but it is
 *      composed at SEND time -- so a permit's details would sit on a phone
 *      that may since have been deactivated, or handed over, or whose
 *      holder lost access to that company. A fetch asks again.
 *   2. The push service never sees anything. With a payload it sees
 *      ciphertext; with a tickle there is nothing to see.
 *   3. Read state cannot drift. The worker renders what the server says is
 *      unread now, not what was true when the push was queued.
 *
 * The cost is honest and stated: if the device is online but the session has
 * ended, the worker cannot read the notification and shows a generic line
 * instead. That is a worse notification, not a wrong one.
 *
 * -----------------------------------------------------------------------------
 * WHAT IT PROMISES THE CALLER
 * -----------------------------------------------------------------------------
 *
 * Nothing, and that is deliberate. `pushTo()` never throws and never
 * reports a failure upwards: a permit that was approved stays approved
 * whether or not a phone rang. Every failure is logged; the notification ROW
 * is already written, so the Notification Center and the live sync remain
 * the guarantee. A push is the best-effort layer on top.
 */
class WebPushService
{
    /** Push services answer these when an endpoint is permanently gone. */
    private const GONE = [404, 410];

    public function isConfigured(): bool
    {
        return $this->publicKey() !== null && $this->privateKey() !== null;
    }

    /** The application server key a browser subscribes with, base64url. */
    public function publicKey(): ?string
    {
        $key = (string) config('realtime.push.public_key');

        return $key === '' ? null : $key;
    }

    private function privateKey(): ?string
    {
        $key = (string) config('realtime.push.private_key');

        return $key === '' ? null : $key;
    }

    /**
     * Wake every device this person has agreed to be woken on.
     *
     * Returns how many push services accepted, which is NOT how many
     * notifications a person saw: acceptance means queued for delivery, and
     * the device may be off, offline or have the app uninstalled. Treating
     * the return value as a delivery receipt would be the lie this whole
     * class is written to avoid.
     */
    public function pushToUser(User $user, array $data = []): int
    {
        if (! $this->isConfigured()) {
            return 0;
        }

        $accepted = 0;

        foreach (PushSubscription::where('user_id', $user->id)->get() as $subscription) {
            if ($this->pushTo($subscription, $data)) {
                $accepted++;
            }
        }

        return $accepted;
    }

    /**
     * One device. True when the push service accepted the message.
     *
     * `$data` carries no operational content -- only a `topic`, which lets a
     * push service COLLAPSE an older undelivered message for the same
     * subject rather than queueing five tickles for one permit that moved
     * five times while a phone was off.
     */
    public function pushTo(PushSubscription $subscription, array $data = []): bool
    {
        $audience = $subscription->audience();

        if (! $this->isConfigured() || $audience === null) {
            return false;
        }

        try {
            $token = $this->vapidToken($audience);
        } catch (\Throwable $e) {
            // A malformed key pair is a configuration fault, not a runtime
            // one: log it once per send rather than per device and stop.
            Log::warning('IOMS web push: VAPID token could not be signed.', ['error' => $e->getMessage()]);

            return false;
        }

        $headers = [
            // RFC 8292 section 3: the token and the public key that signed it.
            'Authorization' => 'vapid t='.$token.', k='.$this->publicKey(),
            // Payloadless. Stated explicitly because some push services
            // reject a body-less POST with no declared length.
            'Content-Length' => '0',
            'TTL' => (string) config('realtime.push.ttl'),
            // A permit waiting for a signature is worth waking a screen for;
            // 'high' is what tells a dozing phone not to batch it until the
            // next time it wakes for something else.
            'Urgency' => 'high',
        ];

        if (! empty($data['topic'])) {
            // Must be base64url-safe and short. A subject's own key, so a
            // later tickle for the same permit replaces an undelivered one.
            $headers['Topic'] = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) $data['topic']), 0, 32);
        }

        try {
            $response = Http::withHeaders($headers)
                ->timeout((int) config('realtime.push.timeout'))
                ->withBody('', 'application/octet-stream')
                ->post($subscription->endpoint);
        } catch (\Throwable $e) {
            // A timeout or a DNS failure. The notification row is already
            // written; this device simply was not reached.
            Log::info('IOMS web push: endpoint unreachable.', [
                'subscription' => $subscription->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if (in_array($response->status(), self::GONE, true)) {
            /*
             * The address is permanently dead -- permission revoked, the
             * app uninstalled, the profile cleared. Deleting it here is the
             * ONLY place these rows are cleaned up, and it is the right
             * place: the push service is the only party that knows.
             */
            $subscription->delete();

            return false;
        }

        if ($response->successful()) {
            $subscription->forceFill(['last_pushed_at' => now()])->saveQuietly();

            return true;
        }

        Log::info('IOMS web push: refused by the push service.', [
            'subscription' => $subscription->id,
            'status' => $response->status(),
        ]);

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | VAPID
    |--------------------------------------------------------------------------
    */

    /**
     * A JWT for one push service, signed ES256 with this installation's key.
     *
     * The audience is the push service's ORIGIN and nothing else -- a token
     * issued for Google's service is refused by Mozilla's, which is the
     * whole point of the claim. Twelve hours: comfortably inside the 24-hour
     * maximum the spec allows, with no clock-skew argument to have.
     */
    private function vapidToken(string $audience): string
    {
        $header = $this->base64url(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $payload = $this->base64url(json_encode([
            'aud' => $audience,
            'exp' => time() + 43200,
            'sub' => $this->subject(),
        ]));

        $signed = $header.'.'.$payload;

        $key = openssl_pkey_get_private($this->privateKeyPem());

        if ($key === false) {
            throw new \RuntimeException('The configured VAPID private key could not be read.');
        }

        if (! openssl_sign($signed, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('The VAPID token could not be signed.');
        }

        return $signed.'.'.$this->base64url($this->derToRaw($der));
    }

    /**
     * How a push service operator reaches whoever is sending.
     *
     * RFC 8292 requires a mailto: or https: URL, and an invalid one is
     * grounds for refusing the push -- so an unset configuration falls back
     * to the product's own support address rather than to something that
     * will be rejected.
     */
    private function subject(): string
    {
        $configured = (string) config('realtime.push.subject');

        if ($configured !== '' && (str_starts_with($configured, 'mailto:') || str_starts_with($configured, 'https://'))) {
            return $configured;
        }

        $email = config('ioms.emails.support') ?? config('ioms.emails.hello');

        return $email ? 'mailto:'.$email : 'https://'.parse_url((string) config('app.url'), PHP_URL_HOST);
    }

    /**
     * The stored 32-byte private scalar, rebuilt as a PEM openssl will read.
     *
     * The key is stored as the raw base64url scalar because that is what
     * every VAPID tool and every other implementation emits, and a PEM in an
     * environment variable is a multi-line value that `.env` handles badly.
     * The ASN.1 wrapper below is a fixed prefix for a prime256v1 private key
     * -- it carries no key material of its own, only the curve identifier.
     */
    private function privateKeyPem(): string
    {
        $scalar = $this->base64urlDecode((string) $this->privateKey());

        if (strlen($scalar) !== 32) {
            throw new \RuntimeException('A VAPID private key must be 32 bytes.');
        }

        $public = $this->base64urlDecode((string) $this->publicKey());

        if (strlen($public) !== 65 || $public[0] !== "\x04") {
            throw new \RuntimeException('A VAPID public key must be a 65-byte uncompressed point.');
        }

        // SEQUENCE { INTEGER 1, OCTET STRING <scalar>, [0] prime256v1, [1] BIT STRING <point> }
        $der = "\x30\x77"                                    // SEQUENCE, 119 bytes
            ."\x02\x01\x01"                                  // version 1
            ."\x04\x20".$scalar                              // the private scalar
            ."\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07" // [0] OID prime256v1
            ."\xa1\x44\x03\x42\x00".$public;                 // [1] BIT STRING, the point

        return "-----BEGIN EC PRIVATE KEY-----\n"
            .chunk_split(base64_encode($der), 64, "\n")
            ."-----END EC PRIVATE KEY-----\n";
    }

    /**
     * openssl signs ECDSA as DER `SEQUENCE { INTEGER r, INTEGER s }`; JWS
     * wants the two 32-byte halves concatenated. A DER INTEGER is
     * minimally encoded and signed, so it may carry a leading zero byte or
     * be short -- both are normalised here, and getting that wrong produces
     * a signature that verifies locally and is refused by the push service.
     */
    private function derToRaw(string $der): string
    {
        $position = 2;

        // A long-form length on the outer SEQUENCE.
        if ((ord($der[1]) & 0x80) !== 0) {
            $position = 2 + (ord($der[1]) & 0x7f);
        }

        $read = function (string $der, int &$position): string {
            if (ord($der[$position]) !== 0x02) {
                throw new \RuntimeException('Malformed ECDSA signature: expected an INTEGER.');
            }

            $length = ord($der[$position + 1]);
            $value = substr($der, $position + 2, $length);
            $position += 2 + $length;

            return str_pad(ltrim($value, "\x00"), 32, "\x00", STR_PAD_LEFT);
        };

        $r = $read($der, $position);
        $s = $read($der, $position);

        return $r.$s;
    }

    public function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public function base64urlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }

    /**
     * A fresh VAPID key pair, as the two base64url strings the environment
     * holds. Used by `ioms:vapid-keys`; kept here so the format and the
     * code that consumes it cannot drift apart.
     *
     * @return array{public: string, private: string}
     */
    public function generateKeyPair(): array
    {
        $resource = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if ($resource === false) {
            throw new \RuntimeException('This PHP build cannot generate a P-256 key. Check that ext-openssl is enabled.');
        }

        $details = openssl_pkey_get_details($resource);
        $ec = $details['ec'] ?? [];

        // Left-padded to 32 bytes each: openssl strips leading zeros, and a
        // 31-byte coordinate makes an invalid 64-byte point.
        $x = str_pad($ec['x'] ?? '', 32, "\x00", STR_PAD_LEFT);
        $y = str_pad($ec['y'] ?? '', 32, "\x00", STR_PAD_LEFT);
        $d = str_pad($ec['d'] ?? '', 32, "\x00", STR_PAD_LEFT);

        return [
            'public' => $this->base64url("\x04".$x.$y),
            'private' => $this->base64url($d),
        ];
    }
}
