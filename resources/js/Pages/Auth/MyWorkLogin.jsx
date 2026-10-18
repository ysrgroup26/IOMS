import { useForm, Link } from '@inertiajs/react';
import { useState } from 'react';
import { Loader2, Eye, EyeOff, HardHat } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import AuthLayout from '@/Layouts/AuthLayout';

/**
 * v2.123.0 -- THE FIELD SIGN-IN.
 *
 * A worker holding a My Work account has a badge number and an access
 * code. They may have no company email address at all, which is why this
 * page exists rather than another sentence on the Platform login: that
 * form asks for an email, and an email is not what this class was issued.
 *
 * Two fields and nothing else. No Google button (a yard account is not a
 * Google identity), no "forgot password" (there is no password to
 * remember; a lost code is reset by an administrator, which is also the
 * check that the person asking is who they say), and no sign-up link --
 * an account here is granted by a company, never self-created.
 *
 * `identifier` arrives prefilled from a Share Access Link. It is the badge
 * number, an IDENTIFIER and never a secret; the code is typed, never
 * carried in the URL.
 */
export default function MyWorkLogin({ identifier = '' }) {
    const { data, setData, post, processing, errors } = useForm({
        identifier,
        access_code: '',
    });

    // Local toggle on the input's `type` only -- it never touches the
    // submitted value. A code read off a phone in daylight is worth being
    // able to see; a code typed in front of a crew is worth hiding.
    const [showCode, setShowCode] = useState(false);

    function submit(e) {
        e.preventDefault();
        post(route('my-work.login.store'));
    }

    return (
        <AuthLayout
            title="My Work Sign In"
            heading="My Work"
            subheading="Masuk dengan Employee ID dan kode akses dari administrator Anda."
            footer={
                <div className="mt-6 rounded-lg border border-steel-200/70 bg-steel-50/70 px-3.5 py-3 text-center">
                    <p className="text-xs leading-relaxed text-graphite-600">
                        Menggunakan dashboard IOMS?{' '}
                        <Link href={route('login')} className="font-semibold text-brand-700 hover:underline">
                            Sign in with email
                        </Link>.
                    </p>
                </div>
            }
        >
            <div className="mb-5 flex items-start gap-3 rounded-lg border border-steel-200/70 bg-steel-50/70 px-3.5 py-3">
                <HardHat className="mt-0.5 h-4 w-4 shrink-0 text-brand-700" />
                <p className="text-xs leading-relaxed text-graphite-600">
                    Masukkan Employee ID pada badge Anda, atau nama lengkap Anda bila Employee ID tidak diketahui,
                    lalu kode akses yang diberikan administrator.
                </p>
            </div>

            <form onSubmit={submit} className="space-y-4">
                <div className="space-y-1.5">
                    <Label htmlFor="identifier">Employee ID or Full Name</Label>
                    <Input
                        id="identifier"
                        value={data.identifier}
                        onChange={(e) => setData('identifier', e.target.value)}
                        autoComplete="username"
                        autoCapitalize="characters"
                        placeholder="EMP-0001"
                        required
                    />
                    {errors.identifier && <p className="text-xs text-red-600">{errors.identifier}</p>}
                </div>

                <div className="space-y-1.5">
                    <Label htmlFor="access_code">Access Code</Label>
                    <div className="relative">
                        <Input
                            id="access_code"
                            type={showCode ? 'text' : 'password'}
                            value={data.access_code}
                            onChange={(e) => setData('access_code', e.target.value)}
                            autoComplete="one-time-code"
                            autoCapitalize="characters"
                            placeholder="XXXX-XXXX"
                            className="pr-10 tracking-[0.2em]"
                            required
                        />
                        <button
                            type="button"
                            onClick={() => setShowCode((v) => !v)}
                            aria-label={showCode ? 'Hide access code' : 'Show access code'}
                            className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-graphite-400 transition-colors hover:text-graphite-600"
                        >
                            {showCode ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                        </button>
                    </div>
                    {errors.access_code && <p className="text-xs text-red-600">{errors.access_code}</p>}
                    <p className="text-xs text-graphite-400">
                        Huruf besar atau kecil sama saja, dan tanda hubung boleh diabaikan.
                    </p>
                </div>

                <Button type="submit" className="w-full" disabled={processing}>
                    {processing && <Loader2 className="h-4 w-4 animate-spin" />}
                    Sign in to My Work
                </Button>
            </form>
        </AuthLayout>
    );
}
