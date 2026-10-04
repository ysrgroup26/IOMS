import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Gift, AlertTriangle, ArrowLeft } from 'lucide-react';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/Components/ui/dialog';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { FieldGrid, Field } from '@/Components/shared/DetailFields';

/**
 * v2.93.0 -- Master Admin > Registrations > Grant Complimentary Access.
 *
 * TWO STEPS, AND THE SECOND ONE IS NOT DECORATION. Granting free access
 * provisions a real tenant for a real company and cannot be undone by
 * pressing the button again, so the operator reads back what they are
 * about to create -- which organization, which plan, how long, and the
 * reason in their own words -- before anything is written. A plain
 * confirm() could not show the end date, which is the fact most likely to
 * be wrong.
 *
 * It collects THREE fields and no more. There is deliberately no billing
 * mode, status or end-date input: those are the server's to decide, and a
 * form that offered them would be a form that could grant something other
 * than what this dialog is named after.
 */
export default function ComplimentaryGrantDialog({ registration, packages = [], durations = [], onClose }) {
    const [step, setStep] = useState('form');

    const { data, setData, post, processing, errors } = useForm({
        package_id: String(packages[0]?.id ?? ''),
        months: String(durations[0] ?? ''),
        reason: '',
    });

    const plan = packages.find((p) => String(p.id) === String(data.package_id));
    const months = Number(data.months);

    // Shown so the operator sees the date they are actually granting to,
    // rather than inferring it from a month count. Display only: the
    // server computes the real one from its own clock.
    const endsAt = Number.isFinite(months) && months > 0
        ? new Date(new Date().setMonth(new Date().getMonth() + months))
        : null;

    const fmt = (d) => (d ? d.toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' }) : '-');

    const ready = data.package_id && months > 0 && data.reason.trim().length >= 10;

    const submit = (e) => {
        e.preventDefault();
        post(route('platform.registrations.complimentary', registration.id), {
            preserveScroll: true,
            onSuccess: onClose,
            // Back to the form on a server rejection, so the operator sees
            // the field errors next to the fields rather than on a review
            // screen that cannot show them.
            onError: () => setStep('form'),
        });
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Akses Gratis</DialogTitle>
                    <DialogDescription>
                        Memberikan akses tanpa pembayaran untuk {registration.company}. Organisasi akan aktif
                        dengan entitlement sesuai paket yang dipilih, dan berakhir normal pada tanggal yang ditentukan.
                    </DialogDescription>
                </DialogHeader>

                {step === 'form' ? (
                    <div className="space-y-4">
                        <div>
                            <Label htmlFor="comp-plan">Plan</Label>
                            <select
                                id="comp-plan"
                                value={data.package_id}
                                onChange={(e) => setData('package_id', e.target.value)}
                                className="mt-1 w-full rounded-md border border-graphite-200 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500"
                            >
                                {packages.map((p) => (
                                    <option key={p.id} value={p.id}>{p.name}</option>
                                ))}
                            </select>
                            {errors.package_id && <p className="mt-1 text-xs text-danger">{errors.package_id}</p>}
                        </div>

                        <div>
                            <Label htmlFor="comp-months">Duration</Label>
                            <select
                                id="comp-months"
                                value={data.months}
                                onChange={(e) => setData('months', e.target.value)}
                                className="mt-1 w-full rounded-md border border-graphite-200 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500"
                            >
                                {durations.map((m) => (
                                    <option key={m} value={m}>{m} bulan</option>
                                ))}
                            </select>
                            <p className="mt-1 text-xs text-graphite-400">
                                Akses berakhir pada {fmt(endsAt)} dan menjadi read-only setelah masa tenggang.
                            </p>
                            {errors.months && <p className="mt-1 text-xs text-danger">{errors.months}</p>}
                        </div>

                        <div>
                            <Label htmlFor="comp-reason">Reason</Label>
                            <Textarea
                                id="comp-reason"
                                value={data.reason}
                                onChange={(e) => setData('reason', e.target.value)}
                                rows={3}
                                maxLength={500}
                                placeholder="Mengapa organisasi ini mendapat akses tanpa pembayaran"
                                className="mt-1"
                            />
                            <p className="mt-1 text-xs text-graphite-400">
                                Tercatat permanen pada log aktivitas bersama nama pemberi akses. Minimal 10 karakter.
                            </p>
                            {errors.reason && <p className="mt-1 text-xs text-danger">{errors.reason}</p>}
                        </div>

                        {errors.registration && (
                            <div className="rounded-lg border border-danger/20 bg-danger/[0.06] p-3 text-sm text-red-900">
                                {errors.registration}
                            </div>
                        )}

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={onClose}>Batal</Button>
                            <Button type="button" disabled={! ready} onClick={() => setStep('review')}>
                                Tinjau
                            </Button>
                        </DialogFooter>
                    </div>
                ) : (
                    <form onSubmit={submit} className="space-y-4">
                        <FieldGrid>
                            <Field label="Organization" value={registration.company} />
                            <Field label="Reference" value={registration.reference} />
                            <Field label="Administrator" value={registration.contact_email} />
                            <Field label="Plan" value={plan?.name} />
                            <Field label="Duration" value={`${months} bulan`} />
                            <Field label="Ends" value={fmt(endsAt)} />
                            <Field label="Billing" value="Complimentary. Tidak ada tagihan." />
                        </FieldGrid>

                        <div className="rounded-lg border border-graphite-200 bg-graphite-50 p-3 text-sm text-graphite-600">
                            <span className="block text-[11px] font-medium uppercase tracking-wide text-graphite-400">Reason</span>
                            {data.reason}
                        </div>

                        <div className="flex items-start gap-2.5 rounded-lg border border-warning/25 bg-warning/[0.07] p-3 text-sm leading-relaxed text-amber-900">
                            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                            <span>
                                Tindakan ini membuat organisasi, perusahaan pertama, dan akun administrator secara
                                permanen. Tidak dapat dibatalkan dengan menekan tombol ini kembali.
                            </span>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setStep('form')}>
                                <ArrowLeft className="h-4 w-4" /> Ubah
                            </Button>
                            <Button type="submit" disabled={processing}>
                                <Gift className="h-4 w-4" /> Aktifkan
                            </Button>
                        </DialogFooter>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}
