<?php

namespace App\Http\Controllers;

use App\Services\PricingService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * The public marketing site.
 *
 * v2.51.0: Platform / Solutions / How It Works / FAQ became real pages.
 * They had been same-page anchors, which meant that from /pricing or any
 * legal page the entire primary navigation silently did nothing.
 *
 * All copy lives here as server-side constants rather than being buried
 * in JSX, for one reason that matters: every claim is a product claim,
 * and keeping them in one reviewable list is what makes "do not advertise
 * capabilities that do not exist" enforceable. Each domain below maps to
 * a workspace IOMS actually ships.
 */
class PublicController extends Controller
{
    /**
     * The operational domains IOMS covers. Deliberately mirrors the real
     * Workspace registry (resources/js/lib/workspaces.js) -- if a domain
     * is listed here, a customer can open it in the product.
     */
    private const DOMAINS = [
        [
            'key' => 'hse',
            'name' => 'HSE',
            'summary' => 'Permit To Work, incidents, observations, inspections, HIRADC, JSA, LOTO, gas testing, PPE, CAPA, toolbox meetings and waste handling.',
            'points' => ['Permit To Work with field submission and HSE approval', 'Incident and near-miss reporting with corrective actions', 'Inspections, HIRADC and JSA records', 'PPE issue, replacement and expiry tracking'],
        ],
        [
            'key' => 'hr',
            'name' => 'People & HR',
            'summary' => 'Employee records, competencies and certificates, shifts and rosters, leave and man-hour tracking.',
            'points' => ['Employee master data across multiple companies', 'Competency and certificate expiry visibility', 'Shift patterns, rosters and assignments', 'Leave requests with approval routing'],
        ],
        [
            'key' => 'operations',
            'name' => 'Operations',
            'summary' => 'Daily reporting, tasks, activity records and the approval trail behind day-to-day work.',
            'points' => ['Daily reports from the field', 'Task assignment and follow-up', 'Activity timeline on every record'],
        ],
        [
            'key' => 'projects',
            'name' => 'Project Management',
            'summary' => 'Projects, milestones, activities and manpower assignment.',
            'points' => ['Project and milestone tracking', 'Manpower assignment per project', 'Progress and activity records'],
        ],
        [
            'key' => 'warehouse',
            'name' => 'Warehouse',
            'summary' => 'Items, stock, warehouses, goods receipt and stock movement with an audit trail.',
            'points' => ['Item master and stock levels per warehouse', 'Goods receipt against purchase orders', 'Stock movements with full history'],
        ],
        [
            'key' => 'procurement',
            'name' => 'Procurement',
            'summary' => 'Purchase requisitions, RFQs, vendors, vendor performance and purchase orders.',
            'points' => ['Purchase requisition with approval', 'RFQ and vendor quotation comparison', 'Purchase orders and vendor performance'],
        ],
        [
            'key' => 'logistics',
            'name' => 'Logistics & PPIC',
            'summary' => 'Material requests and material movement across the operation.',
            'points' => ['Material request and issue', 'Movement records between locations'],
        ],
        [
            'key' => 'assets',
            'name' => 'Assets',
            'summary' => 'Asset register, assignment and lifecycle records.',
            'points' => ['Asset register with numbering', 'Assignment and status history'],
        ],
        [
            'key' => 'maintenance',
            'name' => 'Maintenance',
            'summary' => 'Maintenance requests and work orders, including spare parts.',
            'points' => ['Maintenance request intake', 'Work orders with spare part records'],
        ],
        [
            'key' => 'quality',
            'name' => 'Quality',
            'summary' => 'Inspection requests, non-conformance reports and controlled documents.',
            'points' => ['Inspection requests and results', 'NCR handling', 'Controlled document register'],
        ],
        [
            'key' => 'management',
            'name' => 'Management & Reporting',
            'summary' => 'KPI input and records, analytics, the Report Center and scheduled reports.',
            'points' => ['KPI definition, input and history', 'Report Center with PDF and Excel export', 'Scheduled reports delivered on a cadence'],
        ],
    ];

    /** Centralize -> Operate -> Approve -> Monitor -> Improve. */
    private const HOW_IT_WORKS = [
        ['step' => '01', 'title' => 'Centralize', 'body' => 'Your companies, departments, employees, assets and master data are set up once, inside your own isolated tenant. Everything downstream references that single source.'],
        ['step' => '02', 'title' => 'Operate', 'body' => 'Work happens in the product: a field user raises a Permit To Work, a warehouse issues material, procurement raises a requisition, maintenance opens a work order.'],
        ['step' => '03', 'title' => 'Approve', 'body' => 'Each record follows its own approval route. Who may approve what is governed by role, department and your configured workflow — not by convention.'],
        ['step' => '04', 'title' => 'Monitor', 'body' => 'Dashboards per department show what is open, overdue or waiting. Every record carries an activity timeline showing who did what, and when.'],
        ['step' => '05', 'title' => 'Report & Improve', 'body' => 'KPI records, the Report Center and scheduled reports turn day-to-day operational data into management reporting — exportable to PDF and Excel on your own letterhead.'],
    ];

    private const FAQS = [
        ['q' => 'What is IOMS?', 'a' => 'IOMS is an Industrial Operations Platform. It connects field operations, HSE, people, warehouse, procurement, logistics, assets, maintenance, quality and management reporting in one system, so operational work and the records it produces live in the same place.'],
        ['q' => 'Who is IOMS for?', 'a' => 'Industrial companies that run field work and carry HSE obligations — shipyards, construction and civil, oil and gas, energy, manufacturing, mining, engineering and fabrication, logistics and industrial services.'],
        ['q' => 'What industries are supported?', 'a' => 'IOMS is built around industrial operations generally rather than one sector. The modules are the same everywhere; what differs is which operational domains a company enables.'],
        ['q' => 'What is included in each plan?', 'a' => 'Starter covers HSE end to end. Professional adds People/HR and management visibility across multiple companies. Enterprise enables every operational domain IOMS ships. All three are the same platform — the difference is breadth of access and capacity.'],
        ['q' => 'How are users and capacity handled?', 'a' => 'Each plan sets a maximum number of user accounts, a maximum number of companies, and how many of those accounts may additionally hold PTW Access. Those limits are enforced by the product, not by policy.'],
        ['q' => 'Is Enterprise customizable?', 'a' => 'Enterprise is the most complete standardized IOMS tier, not a custom build. IOMS is one product improved for every customer — there is no per-company custom development, and no lifetime plan.'],
        ['q' => 'How does payment work?', 'a' => 'You choose a plan and billing cycle, confirm your email, and pay through our payment provider. IOMS never receives or stores your card details.'],
        ['q' => 'When does my workspace become active?', 'a' => 'Only after the payment provider confirms your payment to our server. Reaching a confirmation page in your browser does not activate anything — activation follows the provider\'s own verified notification.'],
        ['q' => 'Can the subscription be cancelled?', 'a' => 'Yes. A cancelled subscription runs to the end of the period you have already paid for, after which the workspace stops being usable. Your data is not deleted at the moment of cancellation.'],
        ['q' => 'What happens if a payment fails?', 'a' => 'Nothing is activated. For an existing subscription, a failed renewal moves it through a grace period before access is restricted — you are notified rather than cut off without warning.'],
        ['q' => 'Is company branding supported?', 'a' => 'Yes. Each customer maintains their own company name, logo, address and contact details, and those flow automatically into generated documents as a proper letterhead.'],
        ['q' => 'Are documents exportable to PDF and Excel?', 'a' => 'Yes. Operational documents export as print-ready A4 PDFs on your own letterhead, and report data exports to formatted Excel workbooks.'],
        ['q' => 'Is IOMS web-based?', 'a' => 'Yes. IOMS is a cloud-hosted, multi-tenant web application, with the field experience designed for use on a phone on site. There is nothing to install.'],
        ['q' => 'Is our data separated from other customers?', 'a' => 'Yes. Each customer is its own tenant, and tenant isolation is enforced at the data-access layer rather than left to individual queries to remember.'],
    ];

    public function home(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user) {
            return $user->isPlatformAdmin()
                ? redirect()->route('platform.dashboard')
                : redirect()->route('dashboard');
        }

        return Inertia::render('Public/Welcome', [
            // Same shape `SettingsController::plans()` already sends the
            // authenticated Plans page -- reused, not duplicated.
            'plans' => app(PricingService::class)->publicPlans(),
        ]);
    }

    public function pricing(): Response
    {
        return Inertia::render('Public/Pricing', [
            'plans' => app(PricingService::class)->publicPlans(),
            'supportEmail' => config('ioms.support_email'),
        ]);
    }

    public function platform(): Response
    {
        return Inertia::render('Public/Platform', [
            'domains' => self::DOMAINS,
        ]);
    }

    public function solutions(): Response
    {
        return Inertia::render('Public/Solutions', [
            'domains' => self::DOMAINS,
        ]);
    }

    public function howItWorks(): Response
    {
        return Inertia::render('Public/HowItWorks', [
            'steps' => self::HOW_IT_WORKS,
        ]);
    }

    public function faq(): Response
    {
        return Inertia::render('Public/Faq', [
            'faqs' => self::FAQS,
            'supportEmail' => config('ioms.support_email'),
        ]);
    }

    public function privacy(): Response
    {
        return Inertia::render('Public/Legal', ['title' => 'Privacy Policy']);
    }

    public function terms(): Response
    {
        return Inertia::render('Public/Legal', ['title' => 'Terms of Service']);
    }
}
