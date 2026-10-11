import { Head, useForm } from '@inertiajs/react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import PageHeader from '@/Components/shared/PageHeader';
import { Database, Download, Upload, Loader2, AlertTriangle } from 'lucide-react';

/**
 * v2.108.2 (SEC-010). Whole-database backup and restore, on the platform
 * console where it belongs.
 *
 * This page used to be a tab inside the CUSTOMER's Settings, reachable by
 * any tenant `super_admin`. `mysqldump` has no notion of a tenant, so that
 * tab handed one customer a complete copy of every other customer's data
 * and let them overwrite the database for all of them. The authorization
 * lives in the route group and `RestoreDatabaseRequest`; this page's job
 * is to state plainly what the operation covers.
 *
 * The restore form asks for three things on purpose: the file, the word,
 * and the name of the database being replaced. The last one is what tells
 * a staging restore from a production one, and it is checked server-side.
 */
export default function PlatformDatabase({ supported, unsupportedReason, database, confirmationWord }) {
    const { data, setData, post, processing, errors } = useForm({
        backup_file: null,
        confirmation: '',
        database: '',
    });

    function restore(e) {
        e.preventDefault();
        post(route('platform.database.restore'), { forceFormData: true });
    }

    const armed = data.backup_file && data.confirmation === confirmationWord && data.database === database;

    return (
        <PlatformLayout>
            <Head title="Database" />

            <PageHeader
                title="Database"
                subtitle="Backup dan pemulihan seluruh database instalasi ini. Mencakup SEMUA tenant, bukan satu perusahaan."
            />

            {!supported && (
                <Card className="mb-4 border-amber-300 bg-amber-50/70">
                    <CardContent className="flex items-start gap-3 p-4">
                        <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
                        <p className="text-sm text-amber-900">{unsupportedReason}</p>
                    </CardContent>
                </Card>
            )}

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2"><Database className="h-4 w-4" /> Backup Database</CardTitle>
                        <CardDescription>
                            Unduh dump SQL lengkap dari database <span className="font-mono">{database}</span>.
                            Berkas ini berisi data seluruh tenant, jadi perlakukan seperti kredensial.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Button asChild disabled={!supported}>
                            <a href={route('platform.database.backup')}>
                                <Download className="h-4 w-4" /> Download Backup
                            </a>
                        </Button>
                    </CardContent>
                </Card>

                <Card className="border-red-200">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-red-700">
                            <AlertTriangle className="h-4 w-4" /> Restore Database
                        </CardTitle>
                        <CardDescription>
                            Mengganti SELURUH database dengan isi berkas yang diunggah. Data seluruh tenant akan
                            ditimpa dan tindakan ini tidak dapat dibatalkan.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={restore} className="space-y-3">
                            <div className="space-y-1.5">
                                <Label htmlFor="backup_file">Backup File (.sql)</Label>
                                <Input
                                    id="backup_file"
                                    type="file"
                                    accept=".sql"
                                    onChange={(e) => setData('backup_file', e.target.files[0])}
                                />
                                {errors.backup_file && <p className="text-xs text-red-600">{errors.backup_file}</p>}
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="database">Type the database name to confirm the target</Label>
                                <Input
                                    id="database"
                                    autoComplete="off"
                                    placeholder={database}
                                    value={data.database}
                                    onChange={(e) => setData('database', e.target.value)}
                                />
                                {errors.database && <p className="text-xs text-red-600">{errors.database}</p>}
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="confirmation">Type {confirmationWord} to confirm</Label>
                                <Input
                                    id="confirmation"
                                    autoComplete="off"
                                    placeholder={confirmationWord}
                                    value={data.confirmation}
                                    onChange={(e) => setData('confirmation', e.target.value)}
                                />
                                {errors.confirmation && <p className="text-xs text-red-600">{errors.confirmation}</p>}
                                <p className="text-xs text-graphite-500">
                                    Konfirmasi diminta karena pemulihan menimpa data seluruh tenant dan tidak dapat dibatalkan.
                                </p>
                            </div>

                            <Button type="submit" variant="destructive" disabled={!supported || processing || !armed}>
                                {processing && <Loader2 className="h-4 w-4 animate-spin" />}
                                <Upload className="h-4 w-4" /> Restore Database
                            </Button>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </PlatformLayout>
    );
}
