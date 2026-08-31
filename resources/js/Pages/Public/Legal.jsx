import { Head } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';

/**
 * v2.18.0 (Public Website / Landing Page Foundation). Honest placeholder
 * -- this codebase has no actual Privacy Policy/Terms of Service
 * document, and this pass was explicitly told not to invent legal text.
 * A real, working route rather than a dead `#` link in the footer.
 */
export default function PublicLegal({ title }) {
    return (
        <PublicLayout>
            <Head title={title} />
            <div className="mx-auto max-w-3xl px-4 py-20 sm:px-6 lg:px-8">
                <h1 className="text-3xl font-semibold tracking-tight text-graphite-900">{title}</h1>
                <p className="mt-4 text-base text-graphite-600">
                    This page is being prepared. If you have a question about {title.toLowerCase()}, please contact
                    us directly.
                </p>
            </div>
        </PublicLayout>
    );
}
