/* v2.141.0 -- split out of Pages/Settings/Index.jsx (M0, Settings architecture). Behaviour unchanged. */
import { useForm } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Loader2 } from 'lucide-react';

const NOTIFICATION_CATEGORY_INFO = {
    approval: { label: 'Approval', description: 'Someone needs to decide, or a decision was made on your request.' },
    reminder: { label: 'Reminder', description: 'Upcoming deadlines and follow-ups.' },
    warning: { label: 'Warning', description: 'Rejections, cancellations, and escalations.' },
    success: { label: 'Success', description: 'Approvals and completions.' },
    information: { label: 'Information', description: 'General status updates.' },
};


/**
 * Milestone 3 (Company Settings completion, Task #62). Turns a
 * notification category off platform-wide -- `NotificationService::notify()`
 * checks this and skips creating the row entirely for a disabled
 * category, not just hiding it in the bell dropdown.
 */
export default function NotificationPreferencesTab({ preferences }) {
    const { data, setData, post, processing } = useForm({ preferences: preferences ?? {} });

    function toggle(category) {
        setData('preferences', { ...data.preferences, [category]: !data.preferences[category] });
    }

    function submit(e) {
        e.preventDefault();
        post(route('settings.notifications'));
    }

    return (
        <Card className="max-w-lg">
            <CardHeader>
                <CardTitle>Notification Preferences</CardTitle>
                <CardDescription>
                    Turn a category off to stop it from being created at all, for every user, not just hide
                    it in the bell dropdown.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="space-y-3">
                    {Object.entries(NOTIFICATION_CATEGORY_INFO).map(([key, { label, description }]) => (
                        <label key={key} className="flex items-center justify-between rounded-xl border border-steel-100 bg-white/60 px-3 py-2.5">
                            <div>
                                <p className="text-sm font-medium text-graphite-700">{label}</p>
                                <p className="text-xs text-graphite-400">{description}</p>
                            </div>
                            <Checkbox checked={data.preferences[key] !== false} onCheckedChange={() => toggle(key)} />
                        </label>
                    ))}
                    <Button type="submit" disabled={processing}>
                        {processing && <Loader2 className="h-4 w-4 animate-spin" />} Save Preferences
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}
