import { useForm, usePage, Link } from '@inertiajs/react';
import { useState } from 'react';
import { Loader2, Eye, EyeOff, ArrowRight } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import AuthLayout from '@/Layouts/AuthLayout';
import GoogleSignInButton from '@/Components/shared/GoogleSignInButton';

/**
 * v2.42.0 -- "Premium Enterprise Gateway". The two-panel shell moved to
 * `Layouts/AuthLayout` in v2.74.0 when Sign Up needed the same surface;
 * its design reasoning lives there now.
 *
 * v2.74.0 -- TWO WAYS IN, PRESENTED AS A CHOICE.
 *
 * Google first, then a labelled divider, then email and password. That
 * order is deliberate: Google is one tap and cannot be mistyped, so it
 * belongs where the eye lands. The divider is what stops the two reading
 * as one form -- without it, "Continue with Google" above an email field
 * looks like a step in a sequence rather than an alternative to it.
 *
 * The Google option renders only where the deployment has credentials
 * configured. An install without them shows a plain email/password form
 * with no gap where a button used to be.
 */
export default function Login() {
    const { googleEnabled } = usePage().props;

    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    // Local UI toggle on the input's `type` only -- never touches the
    // submitted value or any authentication behaviour.
    const [showPassword, setShowPassword] = useState(false);

    function submit(e) {
        e.preventDefault();
        post(route('login'));
    }

    return (
        <AuthLayout
            title="Sign in"
            heading="Sign in"
            subheading="Gunakan akun Anda untuk melanjutkan."
            footer={
                /* v2.50.0: the login page previously offered a visitor with no
                   IOMS account no way forward at all -- it could only reject
                   them. v2.74.0 points at Sign Up rather than at the pay-first
                   onboarding: creating an account is now free and takes a
                   minute, and choosing a plan is a decision for later. */
                <div className="mt-6 rounded-lg border border-steel-200/70 bg-steel-50/70 px-3.5 py-3 text-center">
                    <p className="text-xs leading-relaxed text-graphite-600">
                        Don&apos;t have an IOMS account?{' '}
                        <Link href={route('register')} className="font-semibold text-brand-700 hover:underline">Sign up</Link>
                        {' '}or{' '}
                        <Link href={route('pricing')} className="font-semibold text-brand-700 hover:underline">view plans</Link>.
                    </p>
                </div>
            }
        >
            {googleEnabled && (
                <>
                    <GoogleSignInButton label="Continue with Google" />
                    <AuthDivider />
                </>
            )}

            <form onSubmit={submit} className="space-y-4">
                <div className="space-y-1.5">
                    <Label htmlFor="email">Email</Label>
                    <Input
                        id="email"
                        type="email"
                        autoFocus
                        autoComplete="username"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        placeholder="nama@perusahaan.com"
                        aria-invalid={Boolean(errors.email)}
                    />
                    {errors.email && <p className="text-xs text-red-600">{errors.email}</p>}
                </div>

                <div className="space-y-1.5">
                    <Label htmlFor="password">Password</Label>
                    <div className="relative">
                        <Input
                            id="password"
                            type={showPassword ? 'text' : 'password'}
                            autoComplete="current-password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            placeholder="••••••••"
                            className="pr-10"
                            aria-invalid={Boolean(errors.password)}
                        />
                        <button
                            type="button"
                            onClick={() => setShowPassword((v) => !v)}
                            className="absolute right-0 top-0 flex h-9 w-9 items-center justify-center text-graphite-400 transition-colors hover:text-graphite-700"
                            aria-label={showPassword ? 'Hide password' : 'Show password'}
                            aria-pressed={showPassword}
                        >
                            {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                        </button>
                    </div>
                    {errors.password && <p className="text-xs text-red-600">{errors.password}</p>}
                </div>

                <div className="flex items-center justify-between pt-0.5">
                    <label className="flex cursor-pointer items-center gap-2 text-sm text-graphite-600">
                        <input
                            type="checkbox"
                            checked={data.remember}
                            onChange={(e) => setData('remember', e.target.checked)}
                            className="rounded border-graphite-300 text-brand-600 focus:ring-brand-500"
                        />
                        Remember me
                    </label>
                    <Link href={route('password.request')} className="text-sm font-medium text-brand-600 hover:underline">
                        Forgot password?
                    </Link>
                </div>

                <Button type="submit" className="group w-full" disabled={processing}>
                    {processing
                        ? <Loader2 className="h-4 w-4 animate-spin" />
                        : <ArrowRight className="h-4 w-4 transition-transform duration-200 motion-safe:group-hover:translate-x-0.5" />}
                    Sign in
                </Button>
            </form>
        </AuthLayout>
    );
}

/**
 * The labelled rule between the two authentication paths. Small, but it
 * is the element doing the work: it is what makes Google an ALTERNATIVE
 * to the form below rather than a step above it.
 */
export function AuthDivider({ label = 'or' }) {
    return (
        <div className="my-5 flex items-center gap-3" aria-hidden="true">
            <span className="h-px flex-1 bg-graphite-200" />
            <span className="text-[11px] font-medium uppercase tracking-wide text-graphite-400">{label}</span>
            <span className="h-px flex-1 bg-graphite-200" />
        </div>
    );
}
