import { useForm, usePage, Link } from '@inertiajs/react';
import { useState } from 'react';
import { Loader2, Eye, EyeOff, ArrowRight, Check } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import AuthLayout from '@/Layouts/AuthLayout';
import GoogleSignInButton from '@/Components/shared/GoogleSignInButton';
import { AuthDivider } from '@/Pages/Auth/Login';

/**
 * v2.74.0 -- CREATE AN IOMS ACCOUNT.
 *
 * This page did not exist. Signing up meant `/get-started`: a single form
 * asking for identity, company legal name, full address, plan and billing
 * cycle before anything at all was created, because the old model had no
 * account until a payment cleared.
 *
 * This asks for four things -- name, email, password, terms -- and
 * creates a person. No organization, no plan, no card. The note beneath
 * the button says so explicitly, because "Sign up" on a B2B SaaS site is
 * exactly where a visitor braces for a pricing wall.
 *
 * The page deliberately does NOT sell. Plan comparison belongs on
 * Pricing, and a visitor who has decided to create an account has already
 * got past that question.
 */
export default function Register() {
    const { googleEnabled } = usePage().props;

    const { data, setData, post, processing, errors } = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        terms: false,
    });

    const [showPassword, setShowPassword] = useState(false);

    function submit(e) {
        e.preventDefault();
        post(route('register.account'));
    }

    return (
        <AuthLayout
            title="Create your account"
            heading="Create your account"
            subheading="Buat akun dulu. Pilih paket kapan pun Anda siap."
            footer={
                <div className="mt-6 rounded-lg border border-steel-200/70 bg-steel-50/70 px-3.5 py-3 text-center">
                    <p className="text-xs leading-relaxed text-graphite-600">
                        Already have an IOMS account?{' '}
                        <Link href={route('login')} className="font-semibold text-brand-700 hover:underline">Sign in</Link>.
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
                    <Label htmlFor="name">Full name</Label>
                    <Input
                        id="name"
                        autoFocus
                        autoComplete="name"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        placeholder="Nama lengkap Anda"
                        aria-invalid={Boolean(errors.name)}
                    />
                    {errors.name && <p className="text-xs text-red-600">{errors.name}</p>}
                </div>

                <div className="space-y-1.5">
                    <Label htmlFor="email">Work email</Label>
                    <Input
                        id="email"
                        type="email"
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
                            autoComplete="new-password"
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
                    {/* States the rule before it is broken rather than after.
                        The compromised-password check is server-side and its
                        message is specific when it fires. */}
                    <p className="text-[11px] leading-relaxed text-graphite-500">
                        Minimal 8 karakter, mengandung huruf dan angka.
                    </p>
                    {errors.password && <p className="text-xs text-red-600">{errors.password}</p>}
                </div>

                <div className="space-y-1.5">
                    <Label htmlFor="password_confirmation">Confirm password</Label>
                    <Input
                        id="password_confirmation"
                        type={showPassword ? 'text' : 'password'}
                        autoComplete="new-password"
                        value={data.password_confirmation}
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                        placeholder="••••••••"
                    />
                </div>

                <label className="flex cursor-pointer items-start gap-2.5 pt-0.5">
                    <input
                        type="checkbox"
                        checked={data.terms}
                        onChange={(e) => setData('terms', e.target.checked)}
                        className="mt-0.5 rounded border-graphite-300 text-brand-600 focus:ring-brand-500"
                        aria-invalid={Boolean(errors.terms)}
                    />
                    <span className="text-xs leading-relaxed text-graphite-600">
                        Saya menyetujui{' '}
                        <Link href={route('legal.terms')} className="font-medium text-brand-700 hover:underline">Syarat &amp; Ketentuan</Link>
                        {' '}dan{' '}
                        <Link href={route('legal.privacy')} className="font-medium text-brand-700 hover:underline">Kebijakan Privasi</Link>.
                    </span>
                </label>
                {errors.terms && <p className="text-xs text-red-600">{errors.terms}</p>}

                <Button type="submit" className="group w-full" disabled={processing}>
                    {processing
                        ? <Loader2 className="h-4 w-4 animate-spin" />
                        : <ArrowRight className="h-4 w-4 transition-transform duration-200 motion-safe:group-hover:translate-x-0.5" />}
                    Create account
                </Button>
            </form>

            {/* THE REASSURANCE THAT MATTERS ON THIS PAGE. A B2B signup form
                is where a visitor expects to be asked for a card; saying
                plainly that they will not be is worth more than any feature
                list. Every line is literally true of what happens next. */}
            <ul className="mt-5 space-y-1.5">
                {[
                    'Tanpa kartu kredit',
                    'Tidak ada langganan yang dimulai otomatis',
                    'Pilih paket dan isi data perusahaan saat Anda siap',
                ].map((line) => (
                    <li key={line} className="flex items-start gap-2 text-xs leading-relaxed text-graphite-600">
                        <Check className="mt-0.5 h-3.5 w-3.5 shrink-0 text-success" aria-hidden="true" />
                        {line}
                    </li>
                ))}
            </ul>
        </AuthLayout>
    );
}
