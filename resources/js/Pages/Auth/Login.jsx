import { useForm, Head, usePage, Link } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import BrandWordmark from '@/Components/shared/BrandWordmark';
import BrandWatermark from '@/Components/shared/BrandWatermark';

export default function Login() {
    const { version, company } = usePage().props;
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    function submit(e) {
        e.preventDefault();
        post(route('login'));
    }

    return (
        <div className="relative flex min-h-screen items-center justify-center overflow-hidden bg-gradient-to-br from-graphite-50 via-white to-brand-50/30 px-4">
            <Head title="Sign in" />

            {/* Premium background decoration (v1.6.1) -- soft gradient blobs
                and a subtle geometric outline, in the same restrained
                register as Dashboard's background treatment. Kept very
                low-opacity and monochrome/brand-blue only -- no color
                variety, no gaming-style flourishes. */}
            <div className="pointer-events-none absolute -left-40 -top-40 -z-10 h-[28rem] w-[28rem] rounded-full bg-brand-400 opacity-[0.06] blur-3xl" aria-hidden="true" />
            <div className="pointer-events-none absolute -bottom-48 -right-32 -z-10 h-[32rem] w-[32rem] rounded-full bg-graphite-300 opacity-[0.08] blur-3xl" aria-hidden="true" />
            <div className="pointer-events-none absolute right-[8%] top-[12%] -z-10 h-40 w-40 rotate-12 rounded-3xl border border-brand-200/50 opacity-60" aria-hidden="true" />
            <div className="pointer-events-none absolute bottom-[15%] left-[10%] -z-10 h-24 w-24 -rotate-12 rounded-2xl border border-graphite-200/60 opacity-50" aria-hidden="true" />

            {/* Large, centered, ~3% opacity brand icon watermark behind
                everything. Blur scaled appropriately for its size (v1.6.0
                fix -- a fixed 2px blur was imperceptible at this scale). */}
            <BrandWatermark
                context="login"
                size="h-[40rem] w-[40rem]"
                blur="blur-3xl"
                className="left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2"
            />

            <div className="relative w-full max-w-sm">
                <div className="mb-12 flex flex-col items-center text-center">
                    <BrandWordmark className="h-auto w-[180px]" />
                    <p className="mt-2 text-sm text-graphite-500">{company?.subtitle || 'Industrial Operations Platform'}</p>
                    {/* v2.25.0 (Global UX & Copywriting Polish pass, Part
                        11): a login screen with zero explanatory text felt
                        bureaucratic by omission -- one natural line,
                        English labels/button below unchanged. */}
                    <p className="mt-1 text-xs text-graphite-400">Gunakan akun Anda untuk melanjutkan.</p>
                </div>

                <div className="rounded-xl border border-graphite-200 bg-white/90 p-6 shadow-card backdrop-blur-sm">
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="email">Email</Label>
                            <Input
                                id="email"
                                type="email"
                                autoFocus
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                placeholder="admin@ioms.local"
                            />
                            {errors.email && <p className="text-xs text-red-600">{errors.email}</p>}
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="password">Password</Label>
                            <Input
                                id="password"
                                type="password"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                placeholder="••••••••"
                            />
                            {errors.password && <p className="text-xs text-red-600">{errors.password}</p>}
                        </div>

                        <div className="flex items-center justify-between">
                            <label className="flex items-center gap-2 text-sm text-graphite-600">
                                <input
                                    type="checkbox"
                                    checked={data.remember}
                                    onChange={(e) => setData('remember', e.target.checked)}
                                    className="rounded border-graphite-300"
                                />
                                Remember me
                            </label>
                            <Link href={route('password.request')} className="text-sm font-medium text-brand-600 hover:underline">
                                Forgot password?
                            </Link>
                        </div>

                        <Button type="submit" className="w-full" disabled={processing}>
                            {processing && <Loader2 className="h-4 w-4 animate-spin" />}
                            Sign in
                        </Button>
                    </form>
                </div>

                <div className="mt-6 text-center text-xs text-graphite-400">
                    <p>{version?.edition} &middot; v{version?.number}</p>
                    <p>
                        Designed &amp; Developed by <span className="font-medium text-graphite-500">{version?.company}</span>
                    </p>
                    <p>&copy; {version?.copyright_year} All Rights Reserved.</p>
                </div>
            </div>
        </div>
    );
}
