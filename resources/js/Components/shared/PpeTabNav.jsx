import ModuleTabNav from '@/Components/shared/ModuleTabNav';

const PPE_TABS = [
    { name: 'Dashboard', href: 'ppe.dashboard' },
    { name: 'Employee PPE', href: 'ppe.employees' },
    { name: 'Replacement Due', href: 'ppe.replacement-due' },
    { name: 'Replacement Requests', href: 'ppe.replacement-requests.index' },
    { name: 'PPE Master', href: 'ppe.master' },
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
