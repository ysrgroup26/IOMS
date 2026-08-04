import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { CheckCircle2, XCircle } from 'lucide-react';

const STATUS_VARIANT = { pending: 'secondary', approved: 'success', rejected: 'destructive' };

/**
 * Universal Approval Engine (v1.6.9) -- the reusable frontend half.
 * Any future approvable module's Show page renders this the same way
 * MaterialRequests/Show.jsx does: <ApprovalActions approval={approval}
 * canDecide={canDecide} />. Nothing here is Material-Request-specific --
 * the two POST actions (route('approvals.approve'/'reject', approval.id))
 * work against the Approval record itself regardless of what kind of
 * model it belongs to.
 */
export default function ApprovalActions({ approval, canDecide }) {
    const [rejectOpen, setRejectOpen] = useState(false);
    const [comments, setComments] = useState('');

    if (!approval) return null;

    function approve() {
        if (confirm('Approve this request?')) {
            router.post(route('approvals.approve', approval.id));
        }
    }

    function reject() {
        router.post(route('approvals.reject', approval.id), { comments }, {
            onSuccess: () => setRejectOpen(false),
        });
    }

    return (
        <div className="flex items-center gap-2">
            <Badge variant={STATUS_VARIANT[approval.status]}>{approval.status}</Badge>
            {approval.status === 'pending' && canDecide && (
                <>
                    <Button size="sm" onClick={approve}><CheckCircle2 className="h-3.5 w-3.5" /> Approve</Button>
                    <Button size="sm" variant="destructive" onClick={() => setRejectOpen(true)}><XCircle className="h-3.5 w-3.5" /> Reject</Button>
                </>
            )}
            {approval.status !== 'pending' && approval.approver && (
                <span className="text-xs text-graphite-500">by {approval.approver.name}</span>
            )}

            <Dialog open={rejectOpen} onOpenChange={setRejectOpen}>
                <DialogContent>
                    <DialogHeader><DialogTitle>Reject Request</DialogTitle></DialogHeader>
                    <div className="space-y-1.5">
                        <Label>Reason (required)</Label>
                        <Input value={comments} onChange={(e) => setComments(e.target.value)} placeholder="Why is this being rejected?" />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setRejectOpen(false)}>Cancel</Button>
                        <Button type="button" variant="destructive" onClick={reject} disabled={!comments.trim()}>Reject</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
