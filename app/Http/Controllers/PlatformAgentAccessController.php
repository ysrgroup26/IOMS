<?php

namespace App\Http\Controllers;

use App\Http\Middleware\AuthenticateAgentToken;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * ADR 086 -- MASTER ADMIN > AKSES AGENT.
 *
 * Where the owner issues and revokes the tokens TICorp's AI Workforce reads
 * the Agent API with. Behind `role:platform_admin` like the rest of /platform.
 *
 * - A token is owned by an ACTIVE platform admin -- normally the dedicated AI
 *   Workforce account, never necessarily the person pressing the button -- so
 *   what an agent read is attributable to that account, not to the owner.
 * - Its plaintext is shown ONCE, through the session flash of the redirect, and
 *   is never stored or retrievable again (Sanctum keeps only the SHA-256).
 * - Only tokens carrying `agent:read` are listed or revocable here; this page
 *   can neither see nor touch any other personal access token.
 * - Issuing and revoking are recorded in the Activity Log.
 */
class PlatformAgentAccessController extends Controller
{
    public function index(Request $request): Response
    {
        $tokens = PersonalAccessToken::query()
            ->where('tokenable_type', (new User)->getMorphClass())
            ->latest()
            ->get()
            ->filter(fn (PersonalAccessToken $t) => in_array(AuthenticateAgentToken::READ, $t->abilities ?? [], true))
            ->values();
        $owners = User::withoutGlobalScopes()->whereIn('id', $tokens->pluck('tokenable_id'))->get(['id', 'name', 'email'])->keyBy('id');

        return Inertia::render('Platform/AgentAccess', [
            'tokens' => $tokens->map(fn (PersonalAccessToken $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'owner' => $owners->get($t->tokenable_id)?->only(['id', 'name', 'email']),
                'scopes' => collect($t->abilities)->filter(fn ($a) => $a !== AuthenticateAgentToken::READ)
                    ->map(fn ($a) => str_replace('agent:', '', $a))->values(),
                'last_used_at' => $t->last_used_at,
                'expires_at' => $t->expires_at,
                'created_at' => $t->created_at,
            ]),
            'owners' => $this->owners()->map(fn (User $u) => $u->only(['id', 'name', 'email'])),
            'scopes' => AuthenticateAgentToken::SCOPES,
            'endpoint' => url('/api/agent/v1'),
            'new_token' => $request->session()->get('agent_token'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:80'],
            'owner_id' => ['required', 'integer', Rule::in($this->owners()->pluck('id')->all())],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(array_keys(AuthenticateAgentToken::SCOPES))],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:730'],
        ]);

        $owner = User::withoutGlobalScopes()->findOrFail($data['owner_id']);
        $abilities = [AuthenticateAgentToken::READ, ...array_map(fn ($s) => "agent:{$s}", array_values(array_unique($data['scopes'])))];
        $expires = isset($data['expires_in_days']) ? now()->addDays($data['expires_in_days']) : null;
        $issued = $owner->createToken('agent: '.$data['name'], $abilities, $expires);

        ActivityLog::record('created', "Token agent '{$data['name']}' diterbitkan untuk {$owner->name}", $owner, [
            'kind' => 'agent_token', 'token_id' => $issued->accessToken->id, 'scopes' => $abilities, 'expires_at' => $expires?->toIso8601String(),
        ]);

        return redirect()->route('platform.agent-access')
            ->with('agent_token', ['id' => $issued->accessToken->id, 'name' => $data['name'], 'plaintext' => $issued->plainTextToken])
            ->with('success', 'Token agent diterbitkan. Salin sekarang: token tidak akan ditampilkan lagi.');
    }

    public function destroy(int $token): RedirectResponse
    {
        $row = PersonalAccessToken::findOrFail($token);
        abort_unless(in_array(AuthenticateAgentToken::READ, $row->abilities ?? [], true), 404);

        $name = $row->name;
        $row->delete();
        ActivityLog::record('deleted', "Token agent '{$name}' dicabut", null, ['kind' => 'agent_token', 'token_id' => $token]);

        return redirect()->route('platform.agent-access')->with('success', 'Token agent dicabut; akses berhenti seketika.');
    }

    /** Active platform admins: the only accounts a token may belong to. */
    private function owners()
    {
        return User::withoutGlobalScopes()
            ->where('role', User::ROLE_PLATFORM_ADMIN)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }
}
