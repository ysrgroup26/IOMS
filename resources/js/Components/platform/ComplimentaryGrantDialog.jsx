import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Gift, AlertTriangle, ArrowLeft } from 'lucide-react';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/Components/ui/dialog';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { FieldGrid, Field } from '@/Components/shared/DetailFields';
import { cn } from '@/lib/utils';

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
export default function ComplimentaryGrantDialog({
    registration = null,
    tenant = null,
    packages = [],
    bounds = { min_days: 3, max_days: 30 },
    onClose,
}) {
    const [step, setStep] = useState('form');

    const min = Number(bounds?.min_days ?? 3);
    const max = Number(bounds?.max_days ?? 30);

    /*
     * v2.97.0 -- ONE DIALOG, TWO TARGETS.
     *
     * A grant provisions a REGISTRATION into a new organization; a re-grant
     * changes the subscription of a TENANT that already exists. The decision
     * being made is identical -- which plan, for how many days, and why -- so
     * the operator is asked for it identically. Only the endpoint differs.
     */
    const subject = tenant ?? registration;
    const action = tenant
        ? route('platform.tenants.complimentary', tenant.id)
        : route('platform.registrations.complimentary', registration.id);

    const { data, setData, post, processing, errors } = useForm({
        package_id: String(packages[0]?.id ?? ''),
        days: String(min),
        reason: '',
    });

    const plan = packages.find((p) => String(p.id) === String(data.package_id));
    const days = Number(data.days);
    const daysValid = Number.isInteger(days) && days >= min && days <= max;

    // Shown so the operator sees the date they are actually granting to,
    // rather than counting days in their head. Display only: the server
    // computes the real one from its own clock.
    const endsAt = daysValid
        ? new Date(Date.now() + days * 24 * 60 * 60 * 1000)
        : null;

    const fmt = (d) => (d ? d.toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' }) : '-');

    const ready = data.package_id && daysValid && data.reason.trim().length >= 10;

    const submit = (e) => {
        e.preventDefault();
        post(action, {
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
                        Memberikan akses tanpa pembayaran untuk {subject?.company ?? subject?.name}. Organisasi
                        aktif dengan entitlement sesuai paket yang dipilih. Setelah masa berakhir, akses gratis
                        berhenti otomatis dan organisasi masuk ke status wajib bayar melalui alur tagihan biasa.
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
                            <Label htmlFor="comp-days">Duration (days)</Label>
                            <input
                                id="comp-days"
                                type="number"
                                inputMode="numeric"
                                min={min}
                                max={max}
                                step={1}
                                value={data.days}
                                onChange={(e) => setData('days', e.target.value)}
                                className="mt-1 w-full rounded-md border border-graphite-200 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500"
                            />

                            {/* Common lengths, as a shortcut rather than a
                                restriction -- any value inside the bounds is
                                valid, and the server enforces the same ones. */}
                            <div className="mt-2 flex flex-wrap gap-1.5">
                                {[min, 7, 14, 21, max].filter((d, i, a) => d >= min && d <= max && a.indexOf(d) === i).map((d) => (
                                    <button
                                        key={d}
                                        type="button"
                                        onClick={() => setData('days', String(d))}
                                        className={cn(
                                            'rounded-md border px-2 py-1 text-xs transition',
                                            Number(data.days) === d
                                                ? 'border-brand-500 bg-brand-50 text-brand-700'
                                                : 'border-graphite-200 text-graphite-600 hover:border-graphite-300'
                                        )}
                                    >
                                        {d} hari
                                    </button>
                                ))}
                            </div>

                            <p className="mt-1.5 text-xs text-graphite-400">
                                Antara {min} dan {max} hari. {daysValid
                                    ? `Akses berakhir pada ${fmt(endsAt)}, lalu otomatis menjadi wajib bayar.`
                                    : 'Masukkan durasi dalam rentang tersebut.'}
                            </p>
                            {errors.days && <p className="mt-1 text-xs text-danger">{errors.days}</p>}
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

                        {errors.complimentary && (
                            <div className="rounded-lg border border-danger/20 bg-danger/[0.06] p-3 text-sm text-red-900">
                                {errors.complimentary}
                            </div>
                        )}

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
                            <Field label="Organization" value={subject?.company ?? subject?.name} />
                            {registration && <Field label="Reference" value={registration.reference} />}
                            {registration && <Field label="Administrator" value={registration.contact_email} />}
                            <Field label="Plan" value={plan?.name} />
                            <Field label="Duration" value={`${days} hari`} />
                            <Field label="Ends" value={fmt(endsAt)} />
                            <Field label="Billing" value="Complimentary. Tidak ada tagihan sampai masa berakhir." />
                        </FieldGrid>

                        <div className="rounded-xl border border-steel-100 bg-steel-50/50 p-3 text-sm text-graphite-600">
                            <span className="block text-[11px] font-medium uppercase tracking-wide text-graphite-400">Reason</span>
                            {data.reason}
                        </div>

                        <div className="flex items-start gap-2.5 rounded-lg border border-warning/25 bg-warning/[0.07] p-3 text-sm leading-relaxed text-amber-900">
                            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                            <span>
                                {tenant
                                    ? 'Tindakan ini mengubah langganan yang sudah ada menjadi gratis untuk periode di atas. Tidak ada organisasi, pengguna, atau data yang dibuat maupun dihapus.'
                                    : 'Tindakan ini membuat organisasi, perusahaan pertama, dan akun administrator secara permanen. Tidak dapat dibatalkan dengan menekan tombol ini kembali.'}
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
