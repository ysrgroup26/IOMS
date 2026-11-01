import { useCallback, useEffect, useMemo, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import {
    ReactFlow, ReactFlowProvider, Background, Handle, Position as HandlePosition,
    BaseEdge, EdgeLabelRenderer, getStraightPath, useReactFlow,
} from '@xyflow/react';
import '@xyflow/react/dist/base.css';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import {
    AlertTriangle, ArrowLeft, ArrowRight, CircleCheck, Flag, Loader2, Maximize2, Minus, Plus,
    ShieldCheck, Stamp, Trash2, UserRound, Users, X, Zap,
} from 'lucide-react';
import { useMediaQuery } from '@/lib/useMediaQuery';
import { cn } from '@/lib/utils';
import { ROLE_LABELS, APPROVAL_ROLE_OPTIONS } from '../shared';
import {
    KIND_APPROVAL, KIND_FINAL, MODE_LABEL, PTW_MODULE, clientProblems, newKey,
    ptwTemplate, stageTitle, stagesFromSteps, stepsFromStages,
} from './model';

/**
 * v2.141.0 -- THE VISUAL APPROVAL FLOW BUILDER (A7, approved by the owner).
 *
 * One flow, drawn as what it is: Created -> Approval 1..N -> (Final
 * Verification) -> Active/Approved. React Flow (@xyflow/react, MIT) draws
 * the canvas; everything a person sees is IOMS's own -- the same milky
 * glass surfaces, capsules and type as the rest of Settings, in Light and
 * Midnight -- because the canvas is a view of the company's policy, not a
 * diagramming tool.
 *
 * STRUCTURED, NOT FREEHAND. A flow is an ordered list of stages, so the
 * canvas does not let anybody drag a node or draw an edge that the engine
 * could not run: stages are added with the "+" between two stages, moved
 * with Earlier/Later, and edited in the inspector. On a phone the same
 * stages are a vertical list and the inspector opens as a dialog -- a
 * pannable canvas is not usable at 390 px.
 *
 * SAVING PUBLISHES A VERSION. The server validates (structure, the PTW
 * shape, separation of duties) and publishes; requests already under way
 * keep the version they started on. The editor says so beside the button.
 */
export default function ApprovalFlowEditor({ flow, positions = [], users = [], versionInfo }) {
    const isPtw = flow.module_key === PTW_MODULE;
    const { errors } = usePage().props;
    const [stages, setStages] = useState(() => stagesFromSteps(flow.steps));
    const [selectedKey, setSelectedKey] = useState(null);
    const [dirty, setDirty] = useState(false);
    const [saving, setSaving] = useState(false);
    const desktop = useMediaQuery('(min-width: 1024px)');

    // A different flow, or a fresh save, replaces the drawing.
    const serverSteps = JSON.stringify(flow.steps ?? []);
    useEffect(() => {
        setStages(stagesFromSteps(flow.steps));
        setSelectedKey(null);
        setDirty(false);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [flow.id, serverSteps]);

    const update = useCallback((next) => {
        setStages(next);
        setDirty(true);
    }, []);

    const problems = useMemo(() => clientProblems(flow.module_key, stages), [flow.module_key, stages]);
    const selectedIndex = stages.findIndex((s) => s.key === selectedKey);
    const selected = selectedIndex >= 0 ? stages[selectedIndex] : null;

    const describe = useCallback((approver) => describeApprover(approver, positions, users), [positions, users]);

    const insertAt = (index) => {
        const stage = { key: newKey(), kind: KIND_APPROVAL, mode: 'single', escalate_after_hours: null, escalate_to_role: null, approvers: [{ type: 'role', id: 'manager' }] };
        update([...stages.slice(0, index), stage, ...stages.slice(index)]);
        setSelectedKey(stage.key);
    };

    const patchSelected = (patch) => {
        update(stages.map((s) => (s.key === selectedKey ? { ...s, ...patch } : s)));
    };

    const move = (delta) => {
        const target = selectedIndex + delta;
        if (target < 0 || target >= stages.length) return;
        const next = [...stages];
        [next[selectedIndex], next[target]] = [next[target], next[selectedIndex]];
        update(next);
    };

    const remove = () => {
        update(stages.filter((s) => s.key !== selectedKey));
        setSelectedKey(null);
    };

    const save = () => {
        setSaving(true);
        router.put(route('settings.approval-flows.steps', flow.id), { steps: stepsFromStages(stages) }, {
            preserveScroll: true,
            onFinish: () => setSaving(false),
        });
    };

    const discard = () => {
        setStages(stagesFromSteps(flow.steps));
        setSelectedKey(null);
        setDirty(false);
    };

    const serverError = errors?.steps ? (Array.isArray(errors.steps) ? errors.steps[0] : errors.steps) : null;
    const inspector = selected && (
        <StageInspector
            stage={selected}
            index={selectedIndex}
            stages={stages}
            isPtw={isPtw}
            positions={positions}
            users={users}
            describe={describe}
            onChange={patchSelected}
            onMove={move}
            onRemove={remove}
            onClose={() => setSelectedKey(null)}
        />
    );

    return (
        <div className="space-y-3">
            {stages.length === 0 && (
                <div className="surface-nested flex flex-wrap items-center justify-between gap-3 rounded-xl px-4 py-3">
                    <p className="text-[13px] text-graphite-600">
                        Alur ini belum memiliki tahap. {isPtw ? 'Mulai dari templat PTW, lalu sesuaikan dengan struktur perusahaan.' : 'Tambahkan tahap persetujuan pertama.'}
                    </p>
                    <Button type="button" size="sm" onClick={() => { update(isPtw ? ptwTemplate() : []); if (!isPtw) insertAt(0); }}>
                        <Plus className="h-4 w-4" /> {isPtw ? 'Use PTW template' : 'Add Stage'}
                    </Button>
                </div>
            )}

            {stages.length > 0 && (desktop ? (
                <div className="space-y-3">
                    <ReactFlowProvider>
                        <FlowCanvas
                            stages={stages}
                            isPtw={isPtw}
                            selectedKey={selectedKey}
                            describe={describe}
                            onSelect={setSelectedKey}
                            onInsert={insertAt}
                        />
                    </ReactFlowProvider>
                    {inspector ?? (
                        <p className="surface-nested rounded-xl px-4 py-3 text-[13px] leading-relaxed text-graphite-500">
                            Pilih sebuah tahap untuk mengubah penyetuju, aturan keputusan, atau eskalasinya. Gunakan tombol + di
                            antara dua tahap untuk menyisipkan tahap baru; geser kanvas untuk melihat seluruh alur.
                        </p>
                    )}
                </div>
            ) : (
                <>
                    <StageList stages={stages} isPtw={isPtw} describe={describe} onSelect={setSelectedKey} onInsert={insertAt} />
                    <Dialog open={!!selected} onOpenChange={(open) => !open && setSelectedKey(null)}>
                        <DialogContent className="max-h-[85vh] overflow-y-auto">
                            <DialogHeader><DialogTitle>{selected ? stageTitle(selected, selectedIndex, stages) : ''}</DialogTitle></DialogHeader>
                            {inspector}
                        </DialogContent>
                    </Dialog>
                </>
            ))}

            {(problems.length > 0 || serverError) && (
                <div role="alert" className="space-y-1 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-[13px] text-amber-800">
                    {serverError && <p className="flex gap-2"><AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />{serverError}</p>}
                    {problems.map((problem) => (
                        <p key={problem} className="flex gap-2"><AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />{problem}</p>
                    ))}
                </div>
            )}

            <div className="flex flex-wrap items-center gap-3">
                <Button type="button" onClick={save} disabled={!dirty || saving || problems.length > 0}>
                    {saving && <Loader2 className="h-4 w-4 animate-spin" />} Save &amp; Publish
                </Button>
                {dirty && <Button type="button" variant="outline" onClick={discard}>Discard Changes</Button>}
                <p className="text-xs text-graphite-500">
                    {versionInfo?.latest ? `Versi aktif: v${versionInfo.latest}. ` : 'Belum ada versi terbit. '}
                    Menyimpan menerbitkan versi baru
                    {versionInfo?.in_flight ? `; ${versionInfo.in_flight} permintaan yang sedang berjalan tetap memakai versinya.` : '.'}
                </p>
            </div>
        </div>
    );
}

// ---------------------------------------------------------------- canvas

const X_GAP = 280;
const nodeTypes = { stage: StageNode, terminal: TerminalNode };
const edgeTypes = { insert: InsertEdge };

function FlowCanvas({ stages, isPtw, selectedKey, describe, onSelect, onInsert }) {
    const nodes = useMemo(() => {
        const list = [{ id: 'start', type: 'terminal', position: { x: 0, y: 0 }, data: { label: isPtw ? 'PTW Created' : 'Request Submitted', start: true }, selectable: false }];
        stages.forEach((stage, index) => {
            list.push({
                id: stage.key,
                type: 'stage',
                position: { x: 200 + index * X_GAP, y: 0 },
                selected: stage.key === selectedKey,
                data: { stage, title: stageTitle(stage, index, stages), approvers: stage.approvers.map(describe) },
            });
        });
        list.push({
            id: 'end', type: 'terminal', position: { x: 200 + stages.length * X_GAP, y: 0 },
            data: { label: isPtw ? 'PTW Active' : 'Approved', hint: isPtw ? 'pada waktu mulai' : null }, selectable: false,
        });
        return list;
    }, [stages, isPtw, selectedKey, describe]);

    const edges = useMemo(() => {
        const ids = ['start', ...stages.map((s) => s.key), 'end'];
        return ids.slice(0, -1).map((id, index) => {
            // On PTW nothing may follow the Final Verification.
            const canInsert = !(isPtw && stages[index - 1]?.kind === KIND_FINAL && ids[index + 1] === 'end');
            return { id: `e-${id}`, source: id, target: ids[index + 1], type: 'insert', data: { index, canInsert, onInsert } };
        });
    }, [stages, isPtw, onInsert]);

    return (
        <div className="approval-flow-canvas surface-nested relative h-[340px] overflow-hidden rounded-xl">
            <ReactFlow
                nodes={nodes}
                edges={edges}
                nodeTypes={nodeTypes}
                edgeTypes={edgeTypes}
                nodeOrigin={[0, 0.5]}
                nodesDraggable={false}
                nodesConnectable={false}
                edgesFocusable={false}
                zoomOnScroll={false}
                zoomOnDoubleClick={false}
                preventScrolling={false}
                minZoom={0.4}
                maxZoom={1.4}
                fitView
                // Readable before complete: never shrink below 80% to fit --
                // a long flow is panned, not miniaturised.
                fitViewOptions={{ padding: 0.12, minZoom: 0.8, maxZoom: 1 }}
                onNodeClick={(_, node) => node.type === 'stage' && onSelect(node.id)}
                onPaneClick={() => onSelect(null)}
                proOptions={{ hideAttribution: true }}
                aria-label="Approval flow diagram"
            >
                <Background gap={20} size={1} className="approval-flow-dots" />
                <ZoomControls />
            </ReactFlow>
        </div>
    );
}

function ZoomControls() {
    const { zoomIn, zoomOut, fitView } = useReactFlow();
    const button = 'flex h-9 w-9 items-center justify-center text-graphite-600 hover:bg-steel-50 hover:text-navy-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500';
    return (
        <div className="surface absolute bottom-3 left-3 z-10 flex overflow-hidden rounded-lg">
            <button type="button" className={button} onClick={() => zoomIn()} aria-label="Zoom in"><Plus className="h-4 w-4" /></button>
            <button type="button" className={button} onClick={() => zoomOut()} aria-label="Zoom out"><Minus className="h-4 w-4" /></button>
            <button type="button" className={button} onClick={() => fitView({ padding: 0.12, minZoom: 0.8, maxZoom: 1 })} aria-label="Fit diagram"><Maximize2 className="h-4 w-4" /></button>
        </div>
    );
}

function StageNode({ data, selected }) {
    const { stage, title, approvers } = data;
    const final = stage.kind === KIND_FINAL;
    return (
        <div
            className={cn(
                'surface w-[220px] cursor-pointer rounded-xl p-3 transition-shadow',
                selected ? 'ring-2 ring-brand-500' : 'hover:ring-1 hover:ring-brand-300',
            )}
        >
            <Handle type="target" position={HandlePosition.Left} className="approval-flow-handle" isConnectable={false} />
            <StageHeader stage={stage} title={title} final={final} />
            <ul className="mt-2 space-y-1">
                {approvers.map((label, i) => (
                    <li key={i} className="truncate rounded-md bg-steel-50 px-2 py-1 text-[11px] font-medium text-graphite-700">{label}</li>
                ))}
            </ul>
            {stage.escalate_after_hours && !final && (
                <p className="mt-2 flex items-center gap-1 text-[10px] text-graphite-500">
                    <Zap className="h-3 w-3" /> Eskalasi setelah {stage.escalate_after_hours} jam ke {ROLE_LABELS[stage.escalate_to_role] ?? '-'}
                </p>
            )}
            <Handle type="source" position={HandlePosition.Right} className="approval-flow-handle" isConnectable={false} />
        </div>
    );
}

function StageHeader({ stage, title, final }) {
    return (
        <div className="flex items-start gap-2">
            <span className={cn('icon-tile h-8 w-8 shrink-0 rounded-[10px]', final ? 'icon-tile-green' : 'icon-tile-blue')}>
                {final ? <ShieldCheck className="h-4 w-4" /> : <Stamp className="h-4 w-4" />}
            </span>
            <span className="min-w-0">
                <span className="block text-[13px] font-bold leading-tight text-navy-900">{title}</span>
                <span className="block text-[10px] font-semibold uppercase tracking-wide text-graphite-400">
                    {stage.approvers.length > 1 ? MODE_LABEL[stage.mode === 'single' ? 'parallel_any' : stage.mode] : 'Single'}
                </span>
            </span>
        </div>
    );
}

function TerminalNode({ data }) {
    return (
        <div className="flex w-[130px] flex-col items-center gap-1 text-center">
            <span className={cn('icon-tile h-10 w-10 rounded-full', data.start ? 'icon-tile-slate' : 'icon-tile-teal')}>
                {data.start ? <Flag className="h-4 w-4" /> : <CircleCheck className="h-4 w-4" />}
            </span>
            <span className="text-[12px] font-bold text-navy-900">{data.label}</span>
            {data.hint && <span className="text-[10px] text-graphite-500">{data.hint}</span>}
            {data.start
                ? <Handle type="source" position={HandlePosition.Right} className="approval-flow-handle" isConnectable={false} />
                : <Handle type="target" position={HandlePosition.Left} className="approval-flow-handle" isConnectable={false} />}
        </div>
    );
}

function InsertEdge({ id, sourceX, sourceY, targetX, targetY, data }) {
    const [path, labelX, labelY] = getStraightPath({ sourceX, sourceY, targetX, targetY });
    return (
        <>
            <BaseEdge id={id} path={path} className="approval-flow-edge" />
            {data.canInsert && (
                <EdgeLabelRenderer>
                    <button
                        type="button"
                        onClick={() => data.onInsert(data.index)}
                        aria-label="Insert a stage here"
                        title="Sisipkan tahap"
                        className="nodrag nopan surface pointer-events-auto absolute flex h-7 w-7 items-center justify-center rounded-full text-brand-700 hover:bg-brand-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                        style={{ transform: `translate(-50%, -50%) translate(${labelX}px, ${labelY}px)` }}
                    >
                        <Plus className="h-4 w-4" />
                    </button>
                </EdgeLabelRenderer>
            )}
        </>
    );
}

// ------------------------------------------------------- phone: a list

function StageList({ stages, isPtw, describe, onSelect, onInsert }) {
    const insertButton = (index) => (
        <button
            type="button"
            onClick={() => onInsert(index)}
            className="flex min-h-[44px] w-full items-center justify-center gap-1 text-xs font-semibold text-brand-700"
        >
            <Plus className="h-4 w-4" /> Sisipkan tahap
        </button>
    );

    return (
        <ol className="space-y-1">
            <li className="flex items-center gap-2 px-1 text-xs font-semibold text-graphite-500"><Flag className="h-4 w-4" /> {isPtw ? 'PTW Created' : 'Request Submitted'}</li>
            <li>{insertButton(0)}</li>
            {stages.map((stage, index) => (
                <li key={stage.key} className="space-y-1">
                    <button type="button" onClick={() => onSelect(stage.key)} className="surface block w-full rounded-xl p-3 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
                        <StageHeader stage={stage} title={stageTitle(stage, index, stages)} final={stage.kind === KIND_FINAL} />
                        <span className="mt-1 block text-[11px] text-graphite-600">{stage.approvers.map(describe).join(' / ') || 'Belum ada penyetuju'}</span>
                    </button>
                    {!(isPtw && stage.kind === KIND_FINAL && index === stages.length - 1) && insertButton(index + 1)}
                </li>
            ))}
            <li className="flex items-center gap-2 px-1 pt-1 text-xs font-semibold text-graphite-500"><CircleCheck className="h-4 w-4" /> {isPtw ? 'PTW Active (pada waktu mulai)' : 'Approved'}</li>
        </ol>
    );
}

// --------------------------------------------------------- the inspector

function StageInspector({ stage, index, stages, isPtw, positions, users, describe, onChange, onMove, onRemove, onClose }) {
    const final = stage.kind === KIND_FINAL;
    const [draftType, setDraftType] = useState('role');
    const [draftValue, setDraftValue] = useState('');

    const options = {
        role: APPROVAL_ROLE_OPTIONS.map((r) => ({ value: r, label: ROLE_LABELS[r] ?? r })),
        position: positions.map((p) => ({ value: String(p.id), label: p.company ? `${p.name} (${p.company})` : p.name })),
        user: users.map((u) => ({ value: String(u.id), label: `${u.name}${u.role ? ` · ${ROLE_LABELS[u.role] ?? u.role}` : ''}` })),
    };

    const addApprover = () => {
        if (!draftValue) return;
        const approver = { type: draftType, id: draftType === 'role' ? draftValue : Number(draftValue) };
        if (stage.approvers.some((a) => a.type === approver.type && a.id === approver.id)) return;
        const approvers = [...stage.approvers, approver];
        onChange({ approvers, mode: approvers.length > 1 && stage.mode === 'single' ? 'parallel_any' : stage.mode });
        setDraftValue('');
    };

    const removeApprover = (i) => {
        const approvers = stage.approvers.filter((_, idx) => idx !== i);
        onChange({ approvers, mode: approvers.length <= 1 ? 'single' : stage.mode });
    };

    const setKind = (kind) => {
        onChange({ kind, ...(kind === KIND_FINAL ? { escalate_after_hours: null, escalate_to_role: null } : {}) });
    };

    return (
        <div className="surface space-y-4 rounded-xl p-4 lg:grid lg:grid-cols-2 lg:gap-x-6 lg:space-y-0 lg:[&>*]:mb-4">
            <div className="hidden items-start justify-between gap-2 lg:col-span-2 lg:flex">
                <StageHeader stage={stage} title={stageTitle(stage, index, stages)} final={final} />
                <button type="button" onClick={onClose} className="flex h-8 w-8 items-center justify-center rounded-md text-graphite-400 hover:bg-steel-50" aria-label="Close stage settings">
                    <X className="h-4 w-4" />
                </button>
            </div>

            {isPtw && (
                <div className="space-y-1.5">
                    <Label className="text-xs">Stage Type</Label>
                    <div className="grid grid-cols-2 gap-1.5">
                        {[[KIND_APPROVAL, 'Approval'], [KIND_FINAL, 'Final Verification']].map(([value, label]) => (
                            <button
                                key={value}
                                type="button"
                                aria-pressed={stage.kind === value}
                                onClick={() => setKind(value)}
                                className={cn('min-h-[40px] rounded-lg border px-2 text-xs font-semibold', stage.kind === value ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-steel-200 text-graphite-600 hover:bg-steel-50')}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                    {final && (
                        <p className="text-[11px] leading-relaxed text-graphite-500">
                            Final Verification tidak dapat dilakukan oleh pemohon PTW atau oleh orang yang sudah menyetujui tahap
                            sebelumnya pada PTW yang sama. Administrator tidak otomatis menjadi Final Verifier kecuali dipilih di sini.
                        </p>
                    )}
                </div>
            )}

            <div className="space-y-1.5">
                <Label className="text-xs">Approvers</Label>
                <ul className="space-y-1">
                    {stage.approvers.map((approver, i) => (
                        <li key={`${approver.type}-${approver.id}`} className="flex items-center gap-2 rounded-lg bg-steel-50 px-2 py-1.5 text-xs text-graphite-700">
                            {approver.type === 'user' ? <UserRound className="h-3.5 w-3.5 shrink-0" /> : <Users className="h-3.5 w-3.5 shrink-0" />}
                            <span className="min-w-0 flex-1 truncate">{describe(approver)}</span>
                            <button type="button" onClick={() => removeApprover(i)} className="flex h-7 w-7 items-center justify-center rounded text-graphite-400 hover:text-danger" aria-label={`Remove ${describe(approver)}`}>
                                <X className="h-3.5 w-3.5" />
                            </button>
                        </li>
                    ))}
                </ul>
                <div className="grid grid-cols-[110px_minmax(0,1fr)_auto] gap-1.5">
                    <Select value={draftType} onValueChange={(v) => { setDraftType(v); setDraftValue(''); }}>
                        <SelectTrigger aria-label="Approver type" className="h-9"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="role">Role</SelectItem>
                            <SelectItem value="position">Position</SelectItem>
                            <SelectItem value="user">Person</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select value={draftValue} onValueChange={setDraftValue}>
                        <SelectTrigger aria-label="Approver" className="h-9"><SelectValue placeholder="Pilih" /></SelectTrigger>
                        <SelectContent>
                            {options[draftType].map((o) => <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <Button type="button" size="sm" variant="outline" className="h-9" onClick={addApprover} disabled={!draftValue} aria-label="Add approver">
                        <Plus className="h-4 w-4" />
                    </Button>
                </div>
            </div>

            {stage.approvers.length > 1 && (
                <div className="space-y-1.5">
                    <Label className="text-xs">Decision Rule</Label>
                    <Select value={stage.mode === 'single' ? 'parallel_any' : stage.mode} onValueChange={(mode) => onChange({ mode })}>
                        <SelectTrigger className="h-9"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="parallel_any">Any one approver</SelectItem>
                            <SelectItem value="parallel_all">All approvers</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            )}

            {!final && (
                <div className="grid grid-cols-2 gap-2">
                    <div className="space-y-1.5">
                        <Label className="text-xs">Escalate After (h)</Label>
                        <Input type="number" min={1} max={720} className="h-9" value={stage.escalate_after_hours ?? ''} placeholder="-"
                            onChange={(e) => onChange({ escalate_after_hours: e.target.value ? Number(e.target.value) : null })} />
                    </div>
                    <div className="space-y-1.5">
                        <Label className="text-xs">Escalate To</Label>
                        <Select value={stage.escalate_to_role ?? 'none'} onValueChange={(v) => onChange({ escalate_to_role: v === 'none' ? null : v })}>
                            <SelectTrigger className="h-9"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">None</SelectItem>
                                {APPROVAL_ROLE_OPTIONS.map((r) => <SelectItem key={r} value={r}>{ROLE_LABELS[r] ?? r}</SelectItem>)}
                            </SelectContent>
                        </Select>
                    </div>
                    <p className="col-span-2 text-[11px] text-graphite-500">Eskalasi hanya menambah penyetuju untuk permintaan yang terlambat itu saja.</p>
                </div>
            )}

            <div className="flex flex-wrap gap-1.5 border-t border-steel-100 pt-3 lg:col-span-2 lg:mb-0">
                <Button type="button" size="sm" variant="outline" onClick={() => onMove(-1)} disabled={index === 0}><ArrowLeft className="h-4 w-4" /> Earlier</Button>
                <Button type="button" size="sm" variant="outline" onClick={() => onMove(1)} disabled={index === stages.length - 1}>Later <ArrowRight className="h-4 w-4" /></Button>
                <Button type="button" size="sm" variant="ghost" className="ml-auto text-danger" onClick={onRemove}><Trash2 className="h-4 w-4" /> Remove</Button>
            </div>
        </div>
    );
}

function describeApprover(approver, positions, users) {
    if (approver.type === 'role') return `Role: ${ROLE_LABELS[approver.id] ?? approver.id}`;
    if (approver.type === 'position') {
        const position = positions.find((p) => p.id === approver.id);
        return `Position: ${position ? position.name : 'tidak ditemukan'}`;
    }
    const user = users.find((u) => u.id === approver.id);
    return user ? user.name : 'Pengguna tidak ditemukan';
}
