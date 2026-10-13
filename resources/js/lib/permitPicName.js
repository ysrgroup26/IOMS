/**
 * v2.115.0 -- THE ACCOUNTABLE PERSON, AS A SIGNED PERMIT SHOULD PRINT THEM.
 *
 * Employee deletion is permanent, so `pic_employee_id` can be cleared long
 * after a Permit to Work was issued and signed. `pic_name` is the tombstone
 * `EmployeeEraser` writes at that moment, and it is the only thing left to
 * say once the employee record is gone.
 *
 * Relation FIRST: while the employee exists their record is the truth, so a
 * rename follows them everywhere. The stored text answers only when it is
 * the last remaining answer -- which is why the two are read in this order
 * and never merged into one stored copy that could drift (see the v2.101.0
 * pitfall in `docs/CONVENTIONS.md`).
 *
 * Exported from its own module, not from a page, because three pages and one
 * PDF render this line and a fourth place deciding it differently is exactly
 * how the same document came to disagree with itself before.
 * `PermitToWork::picDisplayName()` is the server-side half, and
 * `PermitPicTombstoneTest` pins the two to the same answer.
 */
export function picName(permit) {
    const live = (permit?.pic?.full_name ?? '').trim();

    if (live !== '') {
        return live;
    }

    const kept = (permit?.pic_name ?? '').trim();

    return kept === '' ? null : kept;
}
