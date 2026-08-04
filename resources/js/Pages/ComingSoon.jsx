import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Construction } from 'lucide-react';

/**
 * Shared page for every Future Department (v1.9.0/v1.10.0) -- these stay
 * visible in the Department selector so the platform's direction is
 * honestly previewed, but have no real module build-out yet. One page,
 * not six near-identical ones.
 */
export default function ComingSoon({ label }) {
    return (
        <AuthenticatedLayout>
            <Head title={label} />

            <Card>
                <CardContent className="flex flex-col items-center gap-3 py-16 text-center">
                    <div className="flex h-12 w-12 items-center justify-center rounded-full bg-graphite-100 text-graphite-400 dark:bg-slate-800 dark:text-slate-500">
                        <Construction className="h-6 w-6" />
                    </div>
                    <h1 className="text-lg font-bold tracking-tight text-graphite-900 dark:text-slate-50">{label}</h1>
                    <p className="max-w-sm text-sm text-graphite-500 dark:text-slate-400">
                        This department is on the IOMS roadmap but hasn't been built yet. Check back in a future release.
                    </p>
                </CardContent>
            </Card>
        </AuthenticatedLayout>
    );
}
