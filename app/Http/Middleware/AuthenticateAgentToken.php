<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR 086 -- THE AGENT API IS READ, BY A TOKEN, FOR A SCOPE.
 *
 * Runs after `auth:sanctum`. Four refusals, each answered as JSON because the
 * caller is a program, not a browser:
 *
 *  1. A SESSION is not a token. `statefulApi()` (bootstrap/app.php) lets a
 *     signed-in browser reach /api with its cookie, and Sanctum then reports a
 *     TransientToken that `can()` everything. The agent API accepts only a
 *     real PersonalAccessToken, so a browser tab can never be mistaken for an
 *     agent and no ability check is ever answered by "it's a session".
 *  2. The token must carry `agent:read` AND the route's scope
 *     (`agent:ops|tenants|billing|support|growth`). A token minted for one
 *     job cannot read another job's data.
 *  3. Its owner must still be an ACTIVE platform admin. Demoting or
 *     deactivating the account kills every token it owns, with no second
 *     switch to remember.
 *  4. Only GET/HEAD. The routes are GET-only already; this makes "the agent
 *     API never writes" true even if a route is added carelessly later.
 */
class AuthenticateAgentToken
{
    public const READ = 'agent:read';

    public const SCOPES = [
        'ops' => 'Ringkasan operasi, rilis, skema, paket',
        'tenants' => 'Daftar dan detail tenant',
        'billing' => 'Pembayaran dan invoice',
        'support' => 'Tiket dukungan beserta pesannya',
        'growth' => 'Pendaftaran (calon pelanggan)',
    ];

    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return $this->refuse(401, 'Agent API membutuhkan token Bearer; sesi browser tidak diterima.');
        }
        if (! $token->can(self::READ) || ! $token->can('agent:'.$scope)) {
            return $this->refuse(403, "Token ini tidak memiliki cakupan 'agent:{$scope}'.");
        }
        if (! $user->isPlatformAdmin() || ! $user->is_active) {
            return $this->refuse(403, 'Pemilik token bukan Master Admin aktif.');
        }
        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return $this->refuse(405, 'Agent API hanya membaca.');
        }

        return $next($request);
    }

    private function refuse(int $status, string $message): Response
    {
        return response()->json(['error' => $message], $status);
    }
}
