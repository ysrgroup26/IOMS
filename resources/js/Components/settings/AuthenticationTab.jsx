/* v2.141.0 -- split out of Pages/Settings/Index.jsx (M0, Settings architecture). Behaviour unchanged. */
import { useForm, usePage } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { PasswordInput } from '@/Components/ui/password-input';
import { Label } from '@/Components/ui/label';
import { Loader2, Lock } from 'lucide-react';

export default function AuthenticationTab() {
    const { auth } = usePage().props;
    const { data, setData, post, processing, reset, errors } = useForm({
        current_password: '',
        email: auth?.user?.email || '',
        password: '',
        password_confirmation: '',
    });

    function submit(e) {
        e.preventDefault();
        post(route('settings.authentication'), {
            onSuccess: () => reset('current_password', 'password', 'password_confirmation'),
        });
    }

    return (
        <Card className="max-w-lg">
            <CardHeader>
                <CardTitle className="flex items-center gap-2"><Lock className="h-4 w-4" /> Authentication</CardTitle>
                <CardDescription>Change your own login email and password. Future-ready for email verification and 2FA.</CardDescription>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label>Current Password</Label>
                        <PasswordInput value={data.current_password} onChange={(e) => setData('current_password', e.target.value)} />
                        {errors.current_password && <p className="text-xs text-red-600">{errors.current_password}</p>}
                    </div>
                    <div className="space-y-1.5">
                        <Label>Username (Email)</Label>
                        <Input type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                        {errors.email && <p className="text-xs text-red-600">{errors.email}</p>}
                    </div>
                    <div className="space-y-1.5">
                        <Label>New Password (optional)</Label>
                        <PasswordInput value={data.password} onChange={(e) => setData('password', e.target.value)} placeholder="Leave blank to keep your current password" />
                        {errors.password && <p className="text-xs text-red-600">{errors.password}</p>}
                    </div>
                    {data.password && (
                        <div className="space-y-1.5">
                            <Label>Confirm New Password</Label>
                            <PasswordInput value={data.password_confirmation} onChange={(e) => setData('password_confirmation', e.target.value)} />
                        </div>
                    )}
                    <Button type="submit" disabled={processing}>
                        {processing && <Loader2 className="h-4 w-4 animate-spin" />} Update Credentials
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}
