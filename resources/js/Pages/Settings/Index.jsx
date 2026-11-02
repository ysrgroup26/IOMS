import { Head } from '@inertiajs/react';
import PageHeader from '@/Components/shared/PageHeader';
import * as Tabs from '@radix-ui/react-tabs';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Table } from '@/Components/ui/table';
import BrandingTab from '@/Components/settings/BrandingTab';
import RolesTab from '@/Components/settings/RolesTab';
import NumberingTab from '@/Components/settings/NumberingTab';
import DocumentTemplatesTab from '@/Components/settings/DocumentTemplatesTab';
import FieldMappingTab from '@/Components/settings/FieldMappingTab';
import PtwWorkflowTab from '@/Components/settings/PtwWorkflowTab';
import ApprovalFlowsTab from '@/Components/settings/ApprovalFlowsTab';
import NotificationPreferencesTab from '@/Components/settings/NotificationPreferencesTab';
import WorkspaceLabelsCard from '@/Components/settings/WorkspaceLabelsCard';
import ModulesTab from '@/Components/settings/ModulesTab';
import OperatingUnitsTab from '@/Components/settings/OperatingUnitsTab';
import DepartmentsTab from '@/Components/settings/DepartmentsTab';
import PositionsTab from '@/Components/settings/PositionsTab';
import KpiCategoriesTab from '@/Components/settings/KpiCategoriesTab';
import AuthenticationTab from '@/Components/settings/AuthenticationTab';
import UsersTab from '@/Components/settings/UsersTab';
import SubscriptionTab from '@/Components/settings/SubscriptionTab';

// v2.31.0: added transition-colors so the active-tab tinted surface
// change reads as a smooth state change, not an instant snap -- the
// same micro-interaction convention this pass introduces on ModuleTabNav
// and Table row hover.
const TAB_CLASS = 'rounded-xl px-3.5 py-2 text-[13px] font-medium text-slate-500 outline-none transition-[background-color,color,box-shadow] duration-base ease-out-soft hover:bg-white/60 hover:text-navy-900 focus-visible:ring-4 focus-visible:ring-brand-500/15 data-[state=active]:bg-white data-[state=active]:font-bold data-[state=active]:text-brand-700 data-[state=active]:shadow-[0_1px_2px_0_rgba(30,64,120,0.08),0_6px_16px_-10px_rgba(36,112,221,0.45)] data-[state=active]:ring-1 data-[state=active]:ring-brand-100';


// v2.31.0 (Interior UI Transformation Phase 3, Part 9): a plain vertical
// rule between Settings' tab clusters -- purely visual grouping, not a
// Tabs.Trigger, so it carries no RBAC/routing behavior of its own.
function TabDivider() {
    return <span className="mx-1 hidden h-5 w-px shrink-0 bg-steel-200/70 sm:block" aria-hidden="true" />;
}


export default function SettingsIndex({ company, companies, departments, positions, kpiCategories, users, can, filters, roles, permissionCatalog, numberingFormats, approvalFlows, approvalPositions = [], approvalUsers = [], approvalFlowVersions = {}, numberingModuleKeys, notificationPreferences, documentTemplates, documentModuleKeys, fieldMappings, subscription, invoices, ptwAccess, userSeats, organization, ptwWorkflow, assignableDepartments = [] }) {
    // System-level tabs (Companies, Users, Backup) are Super-Admin-only.
    // HSE sees only the operational tabs (Departments, Positions).
    const canSystem = can?.manage_system;
    // v2.19.0 (PTW Access Management Correction pass, Part 1/2): the
    // Users tab now also opens for HSE -- but ONLY to reach the Field &
    // PTW Access section inside it; UsersTab itself further restricts
    // the actual User Management table (create/edit/delete/role changes)
    // to canSystem, so this alone never grants HSE anything beyond PTW
    // Access toggling. Same server-side canManageHse() gate the route/
    // controller already enforce (see routes/web.php's own comment).
    const canPtwAccess = can?.manage_ptw_access;
    const canFieldAccess = can?.manage_field_access;

    /*
     * v2.99.0 -- A TAB THIS USER CANNOT SEE MUST NOT LEAVE THE PAGE BLANK.
     *
     * `?tab=` was read straight into Radix's `defaultValue`. Radix renders
     * no panel at all when the active value matches no `Tabs.Content`, and
     * every panel below is gated by the same `canSystem`/`canPtwAccess`
     * check as its trigger -- so an HSE or PTW-only user following a
     * bookmarked, shared or browser-history `/settings?tab=approval-flows`
     * link got the page chrome, the tab strip, no selected tab and an
     * entirely empty body. No console error, no failed request, nothing to
     * read: the reported "Approval Flow suddenly goes white". A stale or
     * misspelled value did the same, and because `defaultValue` is only
     * consulted on mount it stayed blank until another tab was clicked.
     *
     * Below is the set of tabs this user actually HAS a panel for, in
     * display order -- so [0] doubles as the fallback and keeps the
     * previous behaviour exactly (`branding` for a system admin,
     * `departments` otherwise). SettingsTabResolutionTest pins it against
     * the JSX, so a tab cannot be added to the strip without being added
     * here too.
     */
    const availableTabs = [
        ...(canSystem ? ['branding', 'modules', 'companies'] : []),
        'departments',
        'positions',
        'kpi-categories',
        'authentication',
        ...(canSystem || canPtwAccess ? ['users'] : []),
        ...(canSystem
            // SEC-010 (v2.108.2): the Backup & Restore tab was removed from
            // here. A whole-database dump is installation-wide and now lives
            // on the platform console; a customer administrator never had any
            // business holding a copy of every other customer's data.
            ? ['roles', 'numbering', 'approval-flows', 'ptw-workflow', 'notifications', 'documents', 'field-mapping', 'subscription']
            : []),
    ];
    const requestedTab = new URLSearchParams(window.location.search).get('tab');
    const initialTab = availableTabs.includes(requestedTab) ? requestedTab : availableTabs[0];

    return (
        <AuthenticatedLayout>
            <Head title="Settings" />

            <PageHeader title="Settings" subtitle="Company info, master data, and users." />

            <Tabs.Root
                defaultValue={initialTab}
                className="space-y-4"
            >
                {/* v2.31.0 (Interior UI Transformation Phase 3, Part 9):
                    16 tabs in one undifferentiated row read as a "giant
                    CRUD wall" -- exactly the problem this pass names.
                    Grouped into 5 logical clusters (Company, Master Data,
                    People & Access, Workflow & Documents, System) with a
                    visible divider between them -- same Tabs.Trigger
                    elements, same values, same conditional RBAC gates,
                    same tab bodies below; this changes only how the list
                    reads, not which tab does what or who can see it. */}
                <Tabs.List className="chrome inline-flex flex-wrap items-center gap-1 rounded-2xl p-1 shadow-card">
                    {canSystem && <Tabs.Trigger value="branding" className={TAB_CLASS}>Branding</Tabs.Trigger>}
                    {canSystem && <Tabs.Trigger value="modules" className={TAB_CLASS}>Module Visibility</Tabs.Trigger>}
                    {canSystem && <Tabs.Trigger value="companies" className={TAB_CLASS}>Operating Units</Tabs.Trigger>}
                    {canSystem && <TabDivider />}

                    <Tabs.Trigger value="departments" className={TAB_CLASS}>Departments</Tabs.Trigger>
                    <Tabs.Trigger value="positions" className={TAB_CLASS}>Positions</Tabs.Trigger>
                    <Tabs.Trigger value="kpi-categories" className={TAB_CLASS}>KPI Categories</Tabs.Trigger>
                    <TabDivider />

                    <Tabs.Trigger value="authentication" className={TAB_CLASS}>Authentication</Tabs.Trigger>
                    {/* v2.101.0: "Users & Access", matching the Admin Space row that
                        opens it. This tab holds User Management AND Field & PTW
                        Access, so the longer name is the accurate one. */}
                    {(canSystem || canPtwAccess) && <Tabs.Trigger value="users" className={TAB_CLASS}>Users &amp; Access</Tabs.Trigger>}
                    {canSystem && <Tabs.Trigger value="roles" className={TAB_CLASS}>Roles &amp; Permissions</Tabs.Trigger>}
                    {canSystem && <TabDivider />}

                    {canSystem && <Tabs.Trigger value="numbering" className={TAB_CLASS}>Numbering</Tabs.Trigger>}
                    {canSystem && <Tabs.Trigger value="approval-flows" className={TAB_CLASS}>Approval Flow</Tabs.Trigger>}
                    {canSystem && <Tabs.Trigger value="ptw-workflow" className={TAB_CLASS}>PTW Workflow</Tabs.Trigger>}
                    {canSystem && <Tabs.Trigger value="notifications" className={TAB_CLASS}>Notifications</Tabs.Trigger>}
                    {canSystem && <Tabs.Trigger value="documents" className={TAB_CLASS}>Documents</Tabs.Trigger>}
                    {canSystem && <Tabs.Trigger value="field-mapping" className={TAB_CLASS}>Import/Export Mapping</Tabs.Trigger>}
                    {canSystem && <TabDivider />}

                    {canSystem && <Tabs.Trigger value="subscription" className={TAB_CLASS}>Subscription</Tabs.Trigger>}
                </Tabs.List>

                {canSystem && <Tabs.Content value="branding"><BrandingTab company={company} /></Tabs.Content>}
                {canSystem && (
                    <Tabs.Content value="modules" className="space-y-4">
                        <WorkspaceLabelsCard />
                        <ModulesTab />
                    </Tabs.Content>
                )}
                {canSystem && <Tabs.Content value="companies"><OperatingUnitsTab companies={organization?.units ?? companies} organization={organization} /></Tabs.Content>}
                <Tabs.Content value="departments"><DepartmentsTab departments={departments} companies={companies} filters={filters} /></Tabs.Content>
                <Tabs.Content value="positions"><PositionsTab positions={positions} departments={departments} companies={companies} filters={filters} /></Tabs.Content>
                <Tabs.Content value="kpi-categories"><KpiCategoriesTab kpiCategories={kpiCategories} companies={companies} /></Tabs.Content>
                <Tabs.Content value="authentication"><AuthenticationTab /></Tabs.Content>
                {(canSystem || canPtwAccess) && <Tabs.Content value="users"><UsersTab users={users} roles={roles} ptwAccess={ptwAccess} userSeats={userSeats} canManageUsers={canSystem} canPtwAccess={canPtwAccess} canFieldAccess={canFieldAccess} companies={organization?.units ?? companies} assignableDepartments={assignableDepartments} /></Tabs.Content>}
                {canSystem && <Tabs.Content value="roles"><RolesTab roles={roles} permissionCatalog={permissionCatalog} /></Tabs.Content>}
                {canSystem && <Tabs.Content value="numbering"><NumberingTab numberingFormats={numberingFormats} /></Tabs.Content>}
                {canSystem && <Tabs.Content value="approval-flows"><ApprovalFlowsTab approvalFlows={approvalFlows} positions={approvalPositions} users={approvalUsers} versions={approvalFlowVersions} moduleKeys={numberingModuleKeys} /></Tabs.Content>}
                {canSystem && <Tabs.Content value="ptw-workflow"><PtwWorkflowTab ptwWorkflow={ptwWorkflow} companies={companies} /></Tabs.Content>}
                {canSystem && <Tabs.Content value="notifications"><NotificationPreferencesTab preferences={notificationPreferences} /></Tabs.Content>}
                {canSystem && <Tabs.Content value="documents"><DocumentTemplatesTab documentTemplates={documentTemplates} moduleKeys={documentModuleKeys} /></Tabs.Content>}
                {canSystem && <Tabs.Content value="field-mapping"><FieldMappingTab fieldMappings={fieldMappings} /></Tabs.Content>}
                {canSystem && <Tabs.Content value="subscription"><SubscriptionTab subscription={subscription} invoices={invoices} /></Tabs.Content>}
            </Tabs.Root>
        </AuthenticatedLayout>
    );
}
