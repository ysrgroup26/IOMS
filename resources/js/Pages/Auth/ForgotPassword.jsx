import { useForm, Head, usePage, Link } from '@inertiajs/react';
import { Loader2, ArrowLeft } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import BrandWordmark from '@/Components/shared/BrandWordmark';
import BrandWatermark from '@/Components/shared/BrandWatermark';

export default function ForgotPassword() {
    const { company, flash } = usePage().props;
    const { data, setData, post, processing, errors } = useForm({ email: '' });

    function submit(e) {
        e.preventDefault();
        post(route('password.email'));
    }

    return (
        <div className="relative flex min-h-screen items-center justify-center overflow-hidden bg-gradient-to-br from-white via-brand-50/20 to-brand-50/40 px-4">
            <Head title="Forgot Password" />

            {/* v2.27.0 (Public Website & Auth Visual Transformation, Part
                12): same ambient blob treatment as Login.jsx, for visual
                consistency across the whole Auth flow. */}
            <div className="pointer-events-none absolute -left-40 -top-40 -z-10 h-[28rem] w-[28rem] rounded-full bg-brand-400 opacity-[0.08] blur-3xl motion-safe:animate-pulse-glow" aria-hidden="true" />
            <div className="pointer-events-none absolute -bottom-48 -right-32 -z-10 h-[32rem] w-[32rem] rounded-full bg-brand-300 opacity-[0.08] blur-3xl motion-safe:animate-pulse-glow" style={{ animationDelay: '2s' }} aria-hidden="true" />

            <BrandWatermark
                context="login"
                size="h-[40rem] w-[40rem]"
                blur="blur-3xl"
                className="left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2"
            />

            <div className="relative w-full max-w-sm">
                <div className="mb-10 flex flex-col items-center text-center">
                    <BrandWordmark className="h-[70px] w-auto" />
                    <p className="mt-[16px] text-sm text-graphite-500">{company?.subtitle || 'Industrial Operations Platform'}</p>
                </div>

                <div className="rounded-xl border border-graphite-200 bg-white/90 p-6 shadow-card backdrop-blur-sm">
                    <h1 className="text-base font-semibold text-graphite-900">Forgot your password?</h1>
                    {/* v2.25.0 (Global UX & Copywriting Polish pass): was
                        fully English explanatory text -- naturalized to
                        Indonesian, label/button/heading unchanged. */}
                    <p className="mt-1 text-sm text-graphite-500">
                        Masukkan email Anda, kami akan mengirimkan tautan untuk mengatur ulang password.
                    </p>

                    {flash?.success && (
                        <div className="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
                            {flash.success}
                        </div>
                    )}

                    <form onSubmit={submit} className="mt-5 space-y-4">
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

                        <Button type="submit" className="w-full" disabled={processing}>
                            {processing && <Loader2 className="h-4 w-4 animate-spin" />}
                            Send Reset Link
                        </Button>
                    </form>

                    <Link href={route('login')} className="mt-5 flex items-center justify-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                        <ArrowLeft className="h-3.5 w-3.5" /> Back to Sign In
                    </Link>
                </div>
            </div>
        </div>
    );
}
