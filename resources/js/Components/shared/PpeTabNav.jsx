import ModuleTabNav from '@/Components/shared/ModuleTabNav';

// v2.5.0 (Field HSE Experience pass, Part 11): PPE Master data (types/
// specs, canManagePpeMaster() -- Super Admin only) is a different KIND
// of page from the other five tabs (day-to-day PPE operations, gated
// separately by canManagePpeDistribution() -- Super Admin + HSE). This
// was previously visually indistinguishable from the operational tabs
// (confirmed via audit: same tab styling, no badge, no sidebar
// indication) -- an "Admin" badge on this one tab makes that distinction
// obvious without hiding or restructuring the tab itself.
const PPE_TABS = [
    { name: 'Dashboard', href: 'ppe.dashboard' },
    { name: 'Employee PPE', href: 'ppe.employees' },
    { name: 'Replacement Due', href: 'ppe.replacement-due' },
    { name: 'Replacement Requests', href: 'ppe.replacement-requests.index' },
    { name: 'PPE Master', href: 'ppe.master', badge: 'Admin' },
    { name: 'Reports', href: 'ppe.index' },
];

/**
 * PPE module top navigation. Now a thin wrapper around the generic
 * `ModuleTabNav` (v1.6.7) -- kept as its own named component purely so
 * the four existing PPE pages that already import `PpeTabNav` don't need
 * any changes. A future module should import `ModuleTabNav` directly
 * with its own tabs array instead of copying this file.
 */
export default function PpeTabNav() {
    return <ModuleTabNav tabs={PPE_TABS} />;
}
