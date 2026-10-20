<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Services\MyWorkAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * v2.123.0 -- SIGNING IN FROM THE YARD.
 *
 * A My Work account is issued to a worker who may have no company email
 * address and no reason to remember one. They have two things: the Employee
 * ID on their badge, and an access code an administrator gave them. So that
 * is what this page asks for.
 *
 * THIS SUPERSEDES A DELIBERATE EARLIER DECISION, and it is worth saying
 * why. v2.89.0 refused to add a second login page, on the reasoning that
 * "a second login page would duplicate authentication to solve a wording
 * problem". That was right at the time: both classes signed in with an
 * email and a password, so the only difference was the sentence on the
 * page. It is not a wording problem any more -- the two classes now present
 * DIFFERENT CREDENTIALS, and a form that asks for an email address cannot
 * accept a badge number. See ADR 062.
 *
 * WHAT IS NOT DUPLICATED, which is the part that matters: the guard, the
 * session, the hashing, the user provider and the `is_active` rule are all
 * the ones Platform sign-in uses. This class resolves an identity and then
 * hands over to `Auth`; it implements no authentication of its own.
 *
 * NAME IS A WAY TO FIND AN ACCOUNT, NEVER A WAY TO PROVE ONE.
 *
 * Yards have two Budi Santosos. Resolving an identifier can therefore
 * return several accounts, and the rule is that the ACCESS CODE decides
 * which one -- a code is checked against each candidate and the first that
 * matches is the account, so knowing a colleague's name or badge number
 * gets you precisely nowhere without their code. Candidates are capped
 * (see MAX_CANDIDATES) so the cost of a request stays bounded, Employee ID
 * matches are tried before name matches because a badge number is the
 * stronger identifier, and the whole endpoint is rate limited per
 * identifier and per IP on top of the route's own throttle.
 */
class MyWorkSessionController extends Controller
{
    /**
     * How many accounts one identifier may resolve to before the extras
     * are ignored. Every candidate costs one bcrypt verification, so this
     * is what stops a common name from turning a login attempt into a
     * measurable amount of work. Five is far above any real collision --
     * a yard with five identical full names has an Employee ID problem,
     * and the badge number resolves to exactly one.
     */
    private const MAX_CANDIDATES = 5;

    private const MAX_ATTEMPTS = 5;

    /**
     * A real bcrypt hash (of a phrase, not of any code) used
     * only to spend the same work on an unknown identifier as on a known
     * one. It is not a credential: nothing signs in with it, because no
     * account carries it.
     */
    private const TIMING_EQUALISER = '$2y$12$caydiftJaVcSwSSv9Ouo8OgubhWMsBgLpHzscfEeSGXokVf5tYDz2';

    private const DECAY_SECONDS = 60;

    public function create(Request $request): Response
    {
        return Inertia::render('Auth/MyWorkLogin', [
            /*
             * Prefill only. It is an IDENTIFIER, never a secret: a Share
             * Access Link carries the badge number so a worker does not
             * have to type it, and the access code is never in a URL --
             * see MyWorkAccountController and ADR 062.
             */
            'identifier' => (string) $request->query('employee', ''),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'access_code' => ['required', 'string', 'max:64'],
            // v2.128.0 -- see the comment on Auth::login() below.
            'remember' => ['nullable', 'boolean'],
        ]);

        $identifier = trim($validated['identifier']);

        $this->assertNotRateLimited($request, $identifier);

        $account = $this->resolve($identifier, $validated['access_code']);

        if (! $account) {
            RateLimiter::hit($this->throttleKey($request, $identifier), self::DECAY_SECONDS);

            /*
             * ONE MESSAGE FOR BOTH FAILURES. "No such worker" and "wrong
             * code" are the same sentence on purpose: telling them apart
             * would turn this form into a way to discover who holds an
             * account.
             */
            throw ValidationException::withMessages([
                'identifier' => 'Employee ID atau nama dan kode akses tidak cocok. Periksa kembali, atau hubungi administrator IOMS perusahaan Anda.',
            ]);
        }

        /*
         * Said only AFTER the code was correct, so it tells the holder of
         * the account something they are entitled to know and tells
         * everyone else nothing.
         */
        if (! $account->is_active) {
            RateLimiter::hit($this->throttleKey($request, $identifier), self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'identifier' => 'Akses My Work Anda sedang dinonaktifkan. Hubungi administrator IOMS perusahaan Anda.',
            ]);
        }

        RateLimiter::clear($this->throttleKey($request, $identifier));

        /*
         * v2.128.0 -- REMEMBER ME, THROUGH LARAVEL'S OWN REMEMBER TOKEN.
         *
         * A worker opens IOMS from a phone, in a yard, several times a
         * shift, and typing a badge number and an 8-character access code
         * each time is the kind of friction that ends with the code written
         * on the inside of a helmet. So the form offers to be remembered.
         *
         * WHAT THIS IS, EXACTLY: `Auth::login($user, true)` issues the
         * framework's own remember cookie, holding the user's id and a
         * random 60-character `remember_token` from the `users` table --
         * NOT the access code, and nothing derived from it. Recovering a
         * session from that cookie is the session guard's job, the same one
         * Platform sign-in uses; this class still implements no
         * authentication of its own. The cookie is encrypted by Laravel's
         * cookie middleware like every other.
         *
         * WHAT REVOKES IT: `logout()` cycles `remember_token`, which
         * invalidates every remembered device at once -- so does a password
         * (access code) reset through `MyWorkAccessService`. And
         * `EnsureAccountIsActive` was added with this feature, because
         * `is_active` had been checked only by the two sign-in forms: with
         * a five-year cookie, "takes effect at their next sign-in" is not a
         * revocation. It is now re-checked on every request, as
         * `RestrictMyWorkUser`'s allow-list already was.
         */
        Auth::login($account, (bool) ($validated['remember'] ?? false));
        $request->session()->regenerate();

        $account->forceFill(['last_login_at' => now()])->save();

        /*
         * Straight to My Work, never `intended()`. A worker who followed a
         * link into a page this class is refused would otherwise land on a
         * 403 the moment they signed in correctly, which reads as a broken
         * account -- the exact failure v2.122.0 exists to stop. My Work is
         * the only destination this class has.
         */
        return redirect()->route('my-work');
    }

    /**
     * Find the account this identifier and code belong to, or null.
     *
     * Read WITHOUT the tenant scope deliberately: nobody is signed in yet,
     * so there is no current tenant to scope by, and two customers may
     * legitimately both number a worker EMP-0001. The code is what
     * separates them, and the account carries its own `tenant_id` -- the
     * scope applies from the next request onward, as it does for every
     * other sign-in.
     */
    private function resolve(string $identifier, string $code): ?User
    {
        $normalised = MyWorkAccessService::normalise($code);

        if ($normalised === '') {
            return null;
        }

        $candidates = $this->candidates($identifier);

        foreach ($candidates as $candidate) {
            if ($candidate->password && Hash::check($normalised, $candidate->password)) {
                return $candidate;
            }
        }

        /*
         * An identifier that matches nobody would otherwise answer without
         * hashing anything, and an identifier that matches somebody would
         * answer one bcrypt later -- a measurable difference that says
         * whether a badge number is in use. The messages are identical on
         * purpose (see store()); the timing should be too. One verification
         * against a hash that cannot match costs the same as a real miss.
         */
        if ($candidates->isEmpty()) {
            Hash::check($normalised, self::TIMING_EQUALISER);
        }

        return null;
    }

    /**
     * The accounts this identifier could name, badge-number matches first.
     *
     * The ordering is done in PHP over a list that is at most
     * MAX_CANDIDATES long, rather than in SQL: a CASE expression over a
     * bound id list is the kind of query that reads correctly on one
     * engine and sorts differently on another, and this is small enough
     * that there is nothing to gain by it.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function candidates(string $identifier)
    {
        $byNumber = $this->employeeIdsMatching('employee_id', $identifier);
        $byName = $this->employeeIdsMatching('full_name', $identifier);

        $ordered = $byNumber->merge($byName)->unique()->values()->take(self::MAX_CANDIDATES);

        if ($ordered->isEmpty()) {
            return collect();
        }

        $accounts = User::withoutGlobalScopes()
            ->where('user_type', User::TYPE_MY_WORK)
            ->whereIn('employee_id', $ordered->all())
            ->get()
            ->keyBy('employee_id');

        return $ordered->map(fn ($id) => $accounts->get($id))->filter()->values();
    }

    /**
     * Employees whose column is EXACTLY this string, case-insensitively.
     *
     * Exact, never a LIKE: "find the worker called Budi" must not resolve
     * to "Budi Santoso" as well, and a prefix search over a register is
     * a way to enumerate it.
     */
    private function employeeIdsMatching(string $column, string $identifier)
    {
        return Employee::withoutGlobalScopes()
            ->whereRaw('lower('.$column.') = ?', [Str::lower($identifier)])
            ->orderBy('id')
            ->limit(self::MAX_CANDIDATES)
            ->pluck('id');
    }

    private function assertNotRateLimited(Request $request, string $identifier): void
    {
        $key = $this->throttleKey($request, $identifier);

        if (! RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return;
        }

        $seconds = RateLimiter::availableIn($key);

        throw ValidationException::withMessages([
            'identifier' => sprintf(
                'Terlalu banyak percobaan masuk. Coba lagi dalam %d detik.',
                $seconds,
            ),
        ]);
    }

    /** Per identifier AND per address, so one worker's mistakes are their own. */
    private function throttleKey(Request $request, string $identifier): string
    {
        return 'my-work-login|'.Str::lower($identifier).'|'.$request->ip();
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('my-work.login');
    }
}
