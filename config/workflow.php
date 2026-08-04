<?php

/**
 * Workflow role mapping (v1.6.9.1). Genuinely reusable, not
 * Material-Request-specific -- the same four generic actions (approve,
 * process, complete, override) apply to any future module built on
 * HasWorkflow/HasApprovals (Permit To Work, Purchase Request, Asset
 * Request, Inspection). A module-specific override (e.g. "only THIS
 * module's approvers, not the global default") is a future extension
 * point (a `per_module` key here), not needed yet since Material Request
 * is still the only real consumer.
 *
 * Mapped from the spec's example role table (Employee/Supervisor/
 * Warehouse/Company Admin) onto the actual roles that exist in this
 * app: Employee -> whoever creates the request (already gated by
 * canManageMaterialRequests(), not repeated here), Supervisor ->
 * `manager` (a real, meaningful expansion of what Manager can do --
 * previously read-only), Warehouse -> the new `warehouse` role,
 * Company Admin -> `super_admin`, who can always override any decision
 * regardless of what's listed below.
 */
return [

    'approvers' => ['super_admin', 'manager'],

    'processors' => ['super_admin', 'warehouse'],

    // Anyone who can override a decision outright (e.g. reopening a
    // Rejected request back to Draft) -- deliberately a short, explicit
    // list rather than reusing 'approvers', since override is a
    // meaningfully bigger power than a normal approval decision.
    'overriders' => ['super_admin'],

];
