import { useForm, Head, usePage } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import BrandWordmark from '@/Components/shared/BrandWordmark';
import BrandWatermark from '@/Components/shared/BrandWatermark';

export default function ResetPassword({ token, email }) {
    const { company } = usePage().props;
    const { data, setData, post, processing, errors } = useForm({
        token,
        email: email || '',
        password: '',
        password_confirmation: '',
    });

    function submit(e) {
        e.preventDefault();
        post(route('password.store'));
    }

    return (
        <div className="relative flex min-h-screen items-center justify-center overflow-hidden bg-gradient-to-br from-graphite-50 via-white to-brand-50/30 px-4">
            <Head title="Reset Password" />

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
                    <h1 className="text-base font-semibold text-graphite-900">Reset your password</h1>
                    {/* v2.25.0 (Global UX & Copywriting Polish pass): naturalized to Indonesian. */}
                    <p className="mt-1 text-sm text-graphite-500">Buat password baru untuk akun Anda.</p>

                    <form onSubmit={submit} className="mt-5 space-y-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="email">Email</Label>
                            <Input id="email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                            {errors.email && <p className="text-xs text-red-600">{errors.email}</p>}
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="password">New Password</Label>
                            <Input
                                id="password"
                                type="password"
                                autoFocus
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                            />
                            {errors.password && <p className="text-xs text-red-600">{errors.password}</p>}
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="password_confirmation">Confirm New Password</Label>
                            <Input
                                id="password_confirmation"
                                type="password"
                                value={data.password_confirmation}
                                onChange={(e) => setData('password_confirmation', e.target.value)}
                            />
                        </div>

                        <Button type="submit" className="w-full" disabled={processing}>
                            {processing && <Loader2 className="h-4 w-4 animate-spin" />}
                            Reset Password
                        </Button>
                    </form>
                </div>
            </div>
        </div>
    );
}
