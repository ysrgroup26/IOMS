import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import ApprovalActions from '@/Components/shared/ApprovalActions';
import StatusBadge from '@/Components/shared/StatusBadge';
import { CheckCircle2, Circle, CircleDot, ShieldCheck, XCircle } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * v2.141.0 -- WHERE A RECORD IS ON ITS COMPANY'S APPROVAL FLOW.
 *
 * Renders `PtwWorkflowService::flowProgress()`: every stage of the version
 * the record runs on (a later edit of the flow does not change it), who each
 * stage names, what was decided, and -- only when the SERVER says this
 * person may decide the current stage -- the approve/reject actions. The
 * Final Verification stage is marked as such, with the separation-of-duties
 * rule stated beside it, because "why can't I verify this?" is the question
 * a requester will ask.
 */
const STATE_ICON = { done: CheckCircle2, current: CircleDot, waiting: Circle, rejected: XCircle };
const STATE_TONE = {
    done: 'text-success',
    current: 'text-brand-700',
    waiting: 'text-graphite-300',
    rejected: 'text-danger',
};

const fmt = (value) =>
    value ? new Date(value).toLocaleString('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '';

export default function ApprovalFlowProgress({ flow }) {
    if (!flow) return null;

    return (
        <Card>
            <CardHeader>
                <CardTitle>Approval Flow</CardTitle>
                <CardDescription>
                    Tahap persetujuan sesuai Approval Flow perusahaan (versi {flow.version}). Perubahan alur setelah PTW
                    diajukan tidak memengaruhi PTW ini.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                <ol className="space-y-3">
                    {flow.stages.map((stage) => {
                        const Icon = stage.kind === 'final_verification' && stage.state !== 'rejected' ? ShieldCheck : STATE_ICON[stage.state];
                        return (
                            <li key={stage.step_number} className="flex gap-3">
                                <Icon className={cn('mt-0.5 h-5 w-5 shrink-0', STATE_TONE[stage.state])} aria-hidden="true" />
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="text-sm font-bold text-navy-900">
                                            {stage.kind === 'final_verification' ? 'Final Verification' : `Approval ${stage.step_number}`}
                                        </span>
                                        <StatusBadge value={stage.state === 'current' ? 'pending' : stage.state === 'done' ? 'approved' : stage.state === 'rejected' ? 'rejected' : 'draft'} label={STATE_LABEL[stage.state]} />
                                    </div>
                                    <p className="text-xs text-graphite-500">
                                        {stage.approvers.join(' / ')}
                                        {stage.approvers.length > 1 && ` · ${stage.mode === 'parallel_all' ? 'semua harus menyetujui' : 'salah satu cukup'}`}
                                    </p>
                                    {stage.kind === 'final_verification' && stage.state !== 'done' && (
                                        <p className="mt-0.5 text-[11px] text-graphite-500">
                                            Tidak dapat dilakukan oleh pemohon atau oleh orang yang sudah menyetujui tahap sebelumnya.
                                        </p>
                                    )}
                                    {stage.decisions.map((decision, index) => (
                                        <p key={index} className="mt-1 text-xs text-graphite-700">
                                            <span className="font-semibold">{decision.by}</span> · {decision.status === 'approved' ? 'Approved' : 'Rejected'} · {fmt(decision.at)}
                                            {decision.comments && <span className="block text-graphite-500">&ldquo;{decision.comments}&rdquo;</span>}
                                        </p>
                                    ))}
                                </div>
                            </li>
                        );
                    })}
                </ol>

                {flow.actionable && (
                    <div className="rounded-lg border border-steel-100 p-3">
                        <p className="mb-2 text-xs text-graphite-600">Tahap ini menunggu keputusan Anda.</p>
                        <ApprovalActions approval={flow.actionable} canDecide />
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

const STATE_LABEL = { done: 'Done', current: 'Waiting', waiting: 'Not started', rejected: 'Returned' };
