import {
    Factory, ShieldCheck, Users, HardHat, PackageSearch, BarChart3,
} from 'lucide-react';

/**
 * v2.76.0 -- THE OPERATIONAL DOMAINS, AS STORIES.
 *
 * One entry per domain the public site presents, in the order a reader
 * meets an industrial operation: the operation as a whole, the safety
 * and people it depends on, the field, the projects, the materials, and
 * finally what management sees. Rendered by <StorySection>.
 *
 * EVERY CAPABILITY HERE IS REAL, AND REACHABLE. Each `items` entry is a menu
 * item that exists under a workspace IOMS actually sells
 * (resources/js/lib/workspaces.js).
 *
 * v2.84.0 -- the Procurement and Projects stories were removed, and the
 * Operations and Field stories stopped naming maintenance, assets, quality
 * inspections and daily reports. Every one of those belongs to a workspace
 * that is no longer customer-facing. Describing them here would be worse than
 * a stale roadmap entry: it is a promise on the page a prospect buys from,
 * about capabilities the plan they buy does not open. The code is untouched;
 * the claim is gone.
 *
 * `image` is empty on purpose. When real IOMS photography or screenshots
 * exist, add { src, alt } here and the visual changes; the heading and
 * copy, which search engines and screen readers read, do not.
 *
 * `cta` is optional and used sparingly -- a link on every row is noise.
 * Anchors point at the deeper sections on the same page.
 */
export const DOMAIN_STORIES = [
    {
        key: 'operations',
        eyebrow: 'Industrial Operations',
        icon: Factory,
        title: 'Every department working from one operational record',
        body: 'Operating units, sites, people and materials share one set of master data and one approval layer. A permit, a material request and a KPI record all point at the same job, the same site and the same people.',
        items: ['Operating units & sites', 'Shared master data', 'One approval layer', 'One audit trail'],
        cta: { label: 'Explore the platform', route: 'platform-overview' },
    },
    {
        key: 'hse',
        eyebrow: 'HSE & Safety',
        icon: ShieldCheck,
        title: 'Safety work that is part of the job, not paperwork beside it',
        body: 'Permits to work, LOTO and gas tests, incidents and investigations, inspections, JSA and HIRADC, PPE and CAPA. HSE reviews and approves inside the same record the field raised.',
        items: ['Permit To Work, LOTO & gas test', 'Incidents & investigations', 'Inspections, JSA & HIRADC', 'PPE, waste & CAPA'],
        cta: { label: 'See how a permit moves', anchor: '#solutions' },
    },
    {
        key: 'people',
        eyebrow: 'People & Workforce',
        icon: Users,
        title: 'The workforce record every other module relies on',
        body: 'Employee master data, competency and certificate expiry, shifts, rosters and leave, contractors and visitors — the same people who are assigned to projects and named on permits.',
        items: ['Employee master data', 'Competency & certificate expiry', 'Shifts, rosters & leave', 'Contractors & visitors'],
    },
    {
        key: 'field',
        eyebrow: 'Field Operations',
        icon: HardHat,
        title: 'Built for the people on site',
        body: 'Field users open to their own work first — permits to raise, tasks to close, what is running now — on a phone. The office sees the same record the moment it is submitted.',
        items: ['My Work for field users', 'Permit requests from site', 'Observations & checklists', 'Tasks & progress updates'],
        cta: { label: 'See the field experience', anchor: '#field' },
    },
    {
        key: 'logistics',
        eyebrow: 'Warehouse Logistics',
        icon: PackageSearch,
        title: 'Materials planned against the work that needs them',
        body: 'Material requests, the item master, inventory across storage locations and warehouses, goods receipt, and every stock out, transfer and adjustment kept as a movement history.',
        items: ['Material Request', 'Item master & inventory', 'Warehouse & goods receipt', 'Stock movement history'],
    },
    {
        key: 'management',
        eyebrow: 'Management Visibility',
        icon: BarChart3,
        title: 'Management reads the operation, not a rebuilt spreadsheet',
        body: 'KPI records, the Report Center and scheduled reports draw on the same records the departments work in, exported to PDF and Excel on your own letterhead.',
        items: ['KPI records', 'Report Center', 'Scheduled reports', 'PDF & Excel on your letterhead'],
        cta: { label: 'Compare plans', route: 'pricing' },
    },
];
