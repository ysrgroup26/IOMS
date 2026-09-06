import { useForm, Head, usePage, Link } from '@inertiajs/react';
import { useState } from 'react';
import { Loader2, Eye, EyeOff, ShieldCheck, ArrowRight } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import BrandWordmark from '@/Components/shared/BrandWordmark';

/**
 * v2.42.0 -- "Premium Enterprise Gateway".
 *
 * WHAT WAS WRONG: the previous login was a single centred card floating on a
 * near-white page dressed with decorative blobs, a pulsing glow, a rotated
 * empty square and a masked grid. Five ambient decorations carrying no
 * information, on a surface with no structure -- which is exactly the
 * "too white, too flat, generic" reading. A sign-in screen is the first
 * impression of an industrial operations platform; it should feel built,
 * not floated.
 *
 * THE STRUCTURE: a two-panel gateway. The left panel is a deep navy brand
 * surface that establishes what this product IS; the right is a clean white
 * working surface where credentials are entered. That split is the whole
 * design -- weight and hierarchy come from the two real surfaces meeting,
 * not from decoration layered onto one.
 *
 * White is kept deliberately, on the half where it belongs: the form. Data
 * entry wants maximum contrast and zero atmosphere.
 *
 * EVERY WORD ON THE NAVY PANEL IS REAL. The product name, descriptor,
 * positioning line and target industries are the brand's own established
 * copy; edition/version come from live props. There are no invented
 * customer counts, uptime figures, testimonials or logos -- a login screen
 * is the easiest place to fabricate credibility and the worst place to be
 * caught doing it.
 *
 * On mobile the navy panel collapses to a compact identity band above the
 * form rather than being hidden, so the brand still frames the page at
 * 320px without pushing the form below the fold.
 */
export default function Login() {
    const { version, company } = usePage().props;
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

    const industries = ['Shipyards', 'Construction', 'Manufacturing', 'Heavy Industry'];

    return (
        <div className="min-h-screen bg-white lg:grid lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)] xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            <Head title="Sign in" />

            {/* ---------------------------------------------------------------
                BRAND PANEL. Deep navy, full height on desktop, a compact band
                on mobile. The only texture is a fine technical grid at very
                low opacity -- an industrial reference, not a gradient light
                show, and it costs nothing at 320px.
            --------------------------------------------------------------- */}
            <aside className="relative isolate overflow-hidden bg-navy-900 px-6 py-8 text-white sm:px-10 lg:flex lg:flex-col lg:justify-between lg:py-14 xl:px-16">
                <div
                    className="pointer-events-none absolute inset-0 -z-10 opacity-[0.18]"
                    aria-hidden="true"
                    style={{
                        backgroundImage:
                            'linear-gradient(to right, rgba(255,255,255,0.10) 1px, transparent 1px), linear-gradient(to bottom, rgba(255,255,255,0.10) 1px, transparent 1px)',
                        backgroundSize: '56px 56px',
                    }}
                />
                {/* One soft steel wash so the navy reads as depth rather than
                    a flat fill. Deliberately a single layer. */}
                <div
                    className="pointer-events-none absolute -right-32 -top-32 -z-10 h-[34rem] w-[34rem] rounded-full bg-steel-500 opacity-[0.16] blur-3xl"
                    aria-hidden="true"
                />

                <div>
                    {/* Explicit text-3xl: BrandWordmark suppresses its own default size
                        whenever className carries any `text-` utility, and this passes
                        text-white for the navy panel -- without a size the typographic
                        fallback would inherit the body scale. h-auto is stripped by the
                        text branch and used by the image branch. */}
                    <BrandWordmark className="h-auto w-[168px] text-3xl text-white" alt={company?.name || 'IOMS'} />
                    <p className="mt-2.5 text-[13px] font-medium uppercase tracking-[0.18em] text-steel-300">
                        {company?.subtitle || 'Industrial Operations Platform'}
                    </p>
                </div>

                {/* Desktop-only positioning block. Hidden on mobile so the
                    form stays above the fold on a phone. */}
                <div className="hidden lg:block">
                    <h1 className="max-w-md text-[28px] font-semibold leading-tight tracking-tight xl:text-[32px]">
                        Built for industrial operations.
                    </h1>
                    <p className="mt-3 max-w-md text-sm leading-relaxed text-navy-300">
                        One platform for HSE, workforce, projects, maintenance and the documents
                        that have to stand up to an audit.
                    </p>

                    <ul className="mt-7 flex flex-wrap gap-x-2.5 gap-y-2" aria-label="Target industries">
                        {industries.map((industry) => (
                            <li
                                key={industry}
                                className="rounded-md border border-white/10 bg-white/[0.06] px-2.5 py-1 text-xs font-medium text-steel-100"
                            >
                                {industry}
                            </li>
                        ))}
                    </ul>
                </div>

                <div className="hidden items-center gap-2 text-xs text-navy-300 lg:flex">
                    <ShieldCheck className="h-3.5 w-3.5 shrink-0 text-steel-400" />
                    <span>{version?.edition} &middot; v{version?.number}</span>
                </div>
            </aside>

            {/* ---------------------------------------------------------------
                WORKING SURFACE. White, high contrast, no ambient decoration --
                everything here is either a control or a label.
            --------------------------------------------------------------- */}
            <main className="flex items-center justify-center px-5 py-10 sm:px-8 lg:py-14">
                <div className="w-full max-w-[380px]">
                    <div className="mb-7">
                        <h2 className="text-[22px] font-semibold tracking-tight text-navy-900">Sign in</h2>
                        <p className="mt-1 text-sm text-graphite-500">Gunakan akun Anda untuk melanjutkan.</p>
                    </div>

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

                    <div className="mt-8 border-t border-graphite-100 pt-5 text-xs leading-relaxed text-graphite-400">
                        {/* Edition/version already sit on the navy panel at lg+;
                            repeated here only where that panel is collapsed. */}
                        <p className="lg:hidden">{version?.edition} &middot; v{version?.number}</p>
                        <p>
                            Designed &amp; Developed by{' '}
                            <span className="font-medium text-graphite-500">{version?.company}</span>
                        </p>
                        <p>&copy; {version?.copyright_year} All Rights Reserved.</p>
                    </div>
                </div>
            </main>
        </div>
    );
}
