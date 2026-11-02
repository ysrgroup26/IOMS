/*
 * v2.141.0 -- THE APPROVAL FLOW EDITOR'S MODEL.
 *
 * The server stores a flow as ROWS (one per approver, grouped by
 * step_number); people think in STAGES (Approval 1, Approval 2, Final
 * Verification), each with one or more approvers. These pure functions
 * convert between the two and give the editor immediate feedback. The
 * server re-checks everything (App\Services\Approvals\ApprovalFlowValidator)
 * -- these checks are a courtesy, never the control.
 */

export const PTW_MODULE = 'permit_to_work';
export const KIND_APPROVAL = 'approval';
export const KIND_FINAL = 'final_verification';

let counter = 0;
export const newKey = () => `stage-${Date.now().toString(36)}-${(counter += 1)}`;

export function approverOf(row) {
    if (row.approver_user_id) return { type: 'user', id: Number(row.approver_user_id) };
    if (row.approver_position_id) return { type: 'position', id: Number(row.approver_position_id) };
    return { type: 'role', id: row.approver_role ?? 'manager' };
}

export function stagesFromSteps(steps = []) {
    const groups = new Map();
    [...steps]
        .sort((a, b) => a.step_number - b.step_number)
        .forEach((row) => {
            if (!groups.has(row.step_number)) groups.set(row.step_number, []);
            groups.get(row.step_number).push(row);
        });

    return [...groups.values()].map((rows) => ({
        key: newKey(),
        kind: rows[0].kind || KIND_APPROVAL,
        mode: rows[0].mode || 'single',
        escalate_after_hours: rows[0].escalate_after_hours ?? null,
        escalate_to_role: rows[0].escalate_to_role ?? null,
        approvers: rows.map(approverOf),
    }));
}

export function stepsFromStages(stages) {
    return stages.flatMap((stage, index) =>
        stage.approvers.map((approver) => ({
            step_number: index + 1,
            kind: stage.kind,
            mode: stage.approvers.length > 1 && stage.mode === 'single' ? 'parallel_any' : stage.mode,
            approver_role: approver.type === 'role' ? approver.id : null,
            approver_position_id: approver.type === 'position' ? approver.id : null,
            approver_user_id: approver.type === 'user' ? approver.id : null,
            escalate_after_hours: stage.kind === KIND_FINAL ? null : stage.escalate_after_hours || null,
            escalate_to_role: stage.kind === KIND_FINAL ? null : stage.escalate_to_role || null,
        })),
    );
}

export function stageTitle(stage, index, stages) {
    if (stage.kind === KIND_FINAL) return 'Final Verification';
    const approvalNumber = stages.slice(0, index + 1).filter((s) => s.kind !== KIND_FINAL).length;
    return `Approval ${approvalNumber}`;
}

export const MODE_LABEL = {
    single: 'Single',
    parallel_any: 'Parallel (any)',
    parallel_all: 'Parallel (all)',
};

/** Immediate feedback in the editor. Indonesian, as help text is. */
export function clientProblems(moduleKey, stages) {
    const problems = [];
    if (moduleKey === PTW_MODULE) {
        const finals = stages.filter((s) => s.kind === KIND_FINAL);
        if (finals.length !== 1 || stages.at(-1)?.kind !== KIND_FINAL) {
            problems.push('Alur PTW harus diakhiri tepat satu tahap Final Verification.');
        }
        if (stages.filter((s) => s.kind !== KIND_FINAL).length < 1) {
            problems.push('Alur PTW memerlukan minimal satu tahap Approval sebelum Final Verification.');
        }
    } else if (stages.some((s) => s.kind === KIND_FINAL)) {
        problems.push('Final Verification saat ini hanya tersedia untuk alur Permit To Work.');
    }
    stages.forEach((stage, index) => {
        if (stage.approvers.length === 0) {
            problems.push(`${stageTitle(stage, index, stages)} belum memiliki penyetuju.`);
        }
    });
    return problems;
}

export function ptwTemplate() {
    return [
        { key: newKey(), kind: KIND_APPROVAL, mode: 'single', escalate_after_hours: null, escalate_to_role: null, approvers: [{ type: 'role', id: 'manager' }] },
        { key: newKey(), kind: KIND_FINAL, mode: 'parallel_any', escalate_after_hours: null, escalate_to_role: null, approvers: [{ type: 'role', id: 'hse' }] },
    ];
}
