<?php

namespace App\Http\Controllers;

use App\Services\PricingService;
use App\Support\LegalDocuments;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * The public marketing site.
 *
 * v2.52.0 -- BAHASA INDONESIA IS THE EXPLANATORY LANGUAGE.
 *
 * The audience is an Indonesian industrial buyer: an HSE manager, a
 * project manager, a director at a shipyard or contractor. They read
 * Indonesian, so the paragraphs that have to persuade them are in
 * Indonesian. What stays English is the vocabulary they already use in
 * English at work — the product name, "Industrial Operations Platform",
 * department names, and established terms like Permit To Work, HIRADC,
 * JSA, LOTO, PPIC. Translating those would make the site read as LESS
 * professional to this reader, not more.
 *
 * DEPARTMENT NAMES ARE THE FULL ENGLISH NAME. "Health, Safety &
 * Environment", not "HSE"; "Human Resources", not "HR". Shorthand is what
 * a new reader cannot decode, and companies disagree about which
 * shorthand (HSE / HSSE / QHSE / EHS) is correct anyway.
 *
 * All copy lives here as server-side constants rather than in JSX, for one
 * reason that matters: every claim is a product claim, and keeping them in
 * one reviewable list is what makes "never advertise a capability that
 * does not exist" enforceable. Every domain below maps to a workspace
 * IOMS actually ships.
 */
class PublicController extends Controller
{
    /**
     * The operational domains IOMS covers. Mirrors the real Workspace
     * registry — if it is listed here, a customer can open it.
     */
    private const DOMAINS = [
        [
            'key' => 'hse',
            'name' => 'Health, Safety & Environment',
            'summary' => 'Permit To Work, insiden, observasi, inspeksi, HIRADC, JSA, LOTO, gas test, APD, CAPA, toolbox meeting, dan pengelolaan limbah.',
            'points' => [
                'Permit To Work diajukan dari lapangan dan disetujui oleh HSE',
                'Pelaporan insiden dan near-miss beserta tindakan perbaikan',
                'Inspeksi, HIRADC, dan JSA tercatat sebagai dokumen resmi',
                'Distribusi APD, penggantian, dan masa berlaku terpantau',
            ],
        ],
        [
            'key' => 'hr',
            'name' => 'Human Resources',
            'summary' => 'Data karyawan, kompetensi dan sertifikat, shift dan roster, cuti, serta pencatatan man-hour.',
            'points' => [
                'Master data karyawan lintas perusahaan dalam satu tenant',
                'Kompetensi dan masa berlaku sertifikat terlihat sebelum kedaluwarsa',
                'Pola shift, roster, dan penugasan tenaga kerja',
                'Pengajuan cuti mengikuti alur persetujuan',
            ],
        ],
        [
            'key' => 'operations',
            'name' => 'Operations',
            'summary' => 'Laporan harian, penugasan, catatan aktivitas, dan jejak persetujuan di balik pekerjaan sehari-hari.',
            'points' => [
                'Laporan harian langsung dari lapangan',
                'Penugasan dan tindak lanjut pekerjaan',
                'Setiap catatan memiliki riwayat aktivitas',
            ],
        ],
        [
            'key' => 'project-management',
            'name' => 'Project Management',
            'summary' => 'Proyek, milestone, aktivitas, dan penempatan tenaga kerja.',
            'points' => [
                'Proyek dan milestone terpantau',
                'Penempatan tenaga kerja per proyek',
                'Progres dan catatan aktivitas',
            ],
        ],
        [
            'key' => 'warehouse',
            'name' => 'Warehouse',
            'summary' => 'Item, stok, gudang, penerimaan barang, dan pergerakan stok dengan jejak audit.',
            'points' => [
                'Master item dan tingkat stok per gudang',
                'Goods Receipt terhadap Purchase Order',
                'Pergerakan stok dengan riwayat lengkap',
            ],
        ],
        [
            'key' => 'procurement',
            'name' => 'Procurement',
            'summary' => 'Permintaan pembelian, RFQ, vendor, penilaian vendor, dan Purchase Order.',
            'points' => [
                'Purchase Requisition (FPB) dengan persetujuan berjenjang',
                'RFQ dan perbandingan penawaran vendor',
                'Purchase Order dan evaluasi kinerja vendor',
            ],
        ],
        [
            'key' => 'logistics',
            'name' => 'Logistics / PPIC',
            'summary' => 'Permintaan material dan perpindahan material di seluruh area operasi.',
            'points' => [
                'Permintaan dan pengeluaran material',
                'Catatan perpindahan antar lokasi',
            ],
        ],
        [
            'key' => 'assets',
            'name' => 'Assets',
            'summary' => 'Daftar aset, penugasan, dan riwayat siklus hidup aset.',
            'points' => [
                'Register aset dengan penomoran otomatis',
                'Penugasan dan riwayat status',
            ],
        ],
        [
            'key' => 'maintenance',
            'name' => 'Maintenance',
            'summary' => 'Permintaan perawatan dan Work Order, termasuk pemakaian suku cadang.',
            'points' => [
                'Permintaan perawatan dari pengguna aset',
                'Work Order (SPK) beserta catatan suku cadang',
            ],
        ],
        [
            'key' => 'quality-control',
            'name' => 'Quality Control',
            'summary' => 'Permintaan inspeksi, laporan ketidaksesuaian (NCR), dan dokumen terkendali.',
            'points' => [
                'Permintaan dan hasil inspeksi',
                'Penanganan NCR sampai selesai',
                'Register dokumen terkendali',
            ],
        ],
        [
            'key' => 'management',
            'name' => 'Management',
            'summary' => 'Input dan riwayat KPI, analitik, Report Center, serta laporan terjadwal.',
            'points' => [
                'Definisi, input, dan riwayat KPI',
                'Report Center dengan ekspor PDF dan Excel',
                'Laporan terjadwal dikirim otomatis',
            ],
        ],
    ];

    /**
     * Centralize -> Operate -> Approve -> Monitor -> Report & Improve, and
     * back to Centralize. The operational LOOP IOMS is shaped around.
     *
     * v2.64.0 -- TWO CHANGES.
     *
     * ENGLISH. These five bodies were the last Indonesian text reaching the
     * landing page, which is otherwise English end to end. v2.61.0 fixed
     * the two blocks that lived in Welcome.jsx and missed this one because
     * it arrives as a server prop -- and the test written to pin that fix
     * only read the JSX file, so it passed without ever seeing this copy.
     * The test now reads the rendered props instead.
     *
     * TWO LENGTHS, ONE SOURCE. `summary` is the one-line version the
     * landing page's rail shows; `body` is the full paragraph /how-it-works
     * has room for. Both describe the same stage from the same array, so
     * the short version can never drift into claiming something the long
     * one does not.
     */
    private const HOW_IT_WORKS = [
        [
            'step' => '01',
            'title' => 'Centralize',
            'summary' => 'Operating Units, departments, employees, assets and master data, set up once.',
            'body' => 'Operating Units, departments, employees, assets and master data are set up once inside your own organization. Every process that follows reads from that same source, so there is no longer a different version of the truth in a different file.',
        ],
        [
            'step' => '02',
            'title' => 'Operate',
            'summary' => 'Permits, material issues, purchase requisitions and work orders are raised in the system.',
            'body' => 'Work runs inside the system: a field supervisor raises a Permit To Work, the warehouse issues material, procurement raises a purchase requisition, maintenance opens a Work Order. The record is created where the work happens, by the person doing it.',
        ],
        [
            'step' => '03',
            'title' => 'Approve',
            'summary' => 'Each record follows its own approval route — by role, department and workflow.',
            'body' => 'Every record follows its own approval route. Who may approve what is decided by role, department and your workflow configuration — not by habit, and not by whoever happens to be in the room.',
        ],
        [
            'step' => '04',
            'title' => 'Monitor',
            'summary' => 'Department dashboards show what is open, overdue or waiting, with a full activity trail.',
            'body' => 'Each department dashboard shows what is still open, overdue or waiting on someone. Every record carries its own activity timeline: who did what, and when.',
        ],
        [
            'step' => '05',
            'title' => 'Report & Improve',
            'summary' => 'Daily operational data becomes management reporting — on your own letterhead.',
            'body' => 'The operational data entered each day becomes management reporting through KPI records, the Report Center and scheduled reports — exported to PDF and Excel on your own company letterhead. What that reporting shows is what the next cycle starts from.',
        ],
    ];

    /**
     * v2.64.0 -- ENGLISH, AND THE FOUR-TIER MODEL.
     *
     * Two problems, found by the same test. The landing page renders the
     * first eight of these, and every one of them was still Indonesian --
     * the language pass in v2.61.0 read the JSX file and never saw copy
     * that arrives as a server prop.
     *
     * Worse, "Apa isi masing-masing paket?" still described THREE tiers,
     * four releases after v2.60.0 shipped four: it named Professional as
     * adding "Management visibility", never mentioned Business at all, and
     * quoted seat capacities for a catalogue that no longer existed. A
     * public FAQ contradicting the pricing table two sections above it is
     * a product-fidelity defect, not a translation one.
     *
     * The plan answers below now state the ladder the way PricingService
     * derives it -- each tier as the one below it PLUS what it adds -- so
     * this copy and the pricing cards cannot disagree.
     */
    private const FAQS = [
        ['q' => 'What is IOMS?', 'a' => 'IOMS is an Industrial Operations Platform: one system connecting field work, Health, Safety & Environment, workforce data, warehousing, procurement, logistics, assets, maintenance, quality control and management reporting. The work and the records it produces live in the same place.'],
        ['q' => 'What is an Operating Unit?', 'a' => 'IOMS structures your organization as IOMS → Organization → Operating Unit → Department. One subscription is ONE organization. An Operating Unit is an operational unit inside it — a yard, a site or a division — and every Department sits under one Operating Unit. Two Operating Units does not mean two companies or two separate subscriptions.'],
        ['q' => 'Who is IOMS built for?', 'a' => 'Industrial companies that run field work and carry occupational safety obligations — shipyards, construction, manufacturing, mining, oil and gas, energy, fabrication, logistics and industrial service providers.'],
        ['q' => 'Which industries are supported?', 'a' => 'IOMS is built for industrial operations generally, not one sector. The modules are the same across industries; what differs is which operational domains your company switches on.'],
        ['q' => 'What does each plan include?', 'a' => 'Starter covers Health, Safety & Environment in full for one Operating Unit. Professional adds Human Resources, for an organization running up to two Operating Units. Business adds Project Management, Logistics / PPIC and Procurement on top of that, across up to four Operating Units. Enterprise opens every operational department IOMS ships, with unlimited Operating Units. All four are the same platform — what differs is the breadth of access and the capacity.'],
        ['q' => 'How are users counted?', 'a' => 'Plan capacity is stated as ONE number: how many login accounts (Users) are included. Starter includes 10 accounts, Professional 50 and Business 150; Enterprise has no stated ceiling. Permissions inside IOMS — including PTW Access — are granted to accounts you already have, and add neither accounts nor cost.'],
        ['q' => 'What is PTW Access?', 'a' => 'PTW Access is a permission granted to specific accounts so they can raise a Permit To Work. It is not sold capacity and carries no extra charge. A foreman or field supervisor given PTW Access can raise a permit straight from My Work, and HSE still reviews and approves it.'],
        ['q' => 'What is My Work?', 'a' => 'My Work is the workspace for the people doing the work in the field — foremen, supervisors, technicians, operators. An account marked as a field user lands directly in My Work at sign-in rather than in an office dashboard that means nothing to them.'],
        ['q' => 'Can Enterprise be customized?', 'a' => 'Enterprise is the most complete STANDARD IOMS plan, not a custom development track. IOMS is one product improved for every customer — there is no per-company development, and no lifetime plan.'],
        ['q' => 'How does payment work?', 'a' => 'You choose a plan and a billing cycle, confirm your email address, then pay through our payment provider. Your card details never pass through or rest in IOMS.'],
        ['q' => 'When does our workspace become active?', 'a' => 'Once the payment provider confirms your payment to our server. Simply reaching the confirmation page in your browser activates nothing — activation follows the verified notification from the payment provider.'],
        ['q' => 'Can a subscription be cancelled?', 'a' => 'Yes, at any time. A cancelled subscription runs to the end of the period already paid for, after which the workspace stops being usable and there is no further billing. Your data is not deleted at cancellation. The full terms are on the Refund & Cancellation Policy page.'],
        ['q' => 'What happens if a payment fails?', 'a' => 'Nothing is activated. For a running subscription, a failed renewal passes through a grace period before access is affected, and you are notified before that point.'],
        ['q' => 'Can our own company identity appear on documents?', 'a' => 'Yes. Each customer manages its own company name, logo, address, contact details, NPWP and NIB, and all of it appears on the documents IOMS generates for you.'],
        ['q' => 'Can documents be exported to PDF and Excel?', 'a' => 'Yes. Operational documents export as print-ready A4 PDFs carrying your company letterhead, and report data exports to Excel workbooks.'],
        ['q' => 'Is IOMS web-based?', 'a' => 'Yes. IOMS is a cloud, multi-tenant web application, with a field view designed to be used from a phone on site. There is nothing to install.'],
        ['q' => 'Is our data separated from other customers?', 'a' => 'Yes. Each customer is its own tenant, and the separation is enforced at the data-access layer rather than left to individual screens to remember.'],
    ];

    public function home(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user) {
            return $user->isPlatformAdmin()
                ? redirect()->route('platform.dashboard')
                : redirect()->route($user->landingRouteName());
        }

        return Inertia::render('Public/Welcome', [
            'plans' => app(PricingService::class)->publicPlans(),
            'domains' => self::DOMAINS,
            'steps' => self::HOW_IT_WORKS,
            'faqs' => array_slice(self::FAQS, 0, 8),
            'contactEmail' => config('ioms.emails.hello'),
        ]);
    }

    public function pricing(): Response
    {
        return Inertia::render('Public/Pricing', [
            'plans' => app(PricingService::class)->publicPlans(),
            // Sales, not support -- a prospect comparing plans is a sales
            // conversation.
            'contactEmail' => config('ioms.emails.hello'),
        ]);
    }

    public function platform(): Response
    {
        return Inertia::render('Public/Platform', ['domains' => self::DOMAINS]);
    }

    public function solutions(): Response
    {
        return Inertia::render('Public/Solutions', ['domains' => self::DOMAINS]);
    }

    public function howItWorks(): Response
    {
        return Inertia::render('Public/HowItWorks', ['steps' => self::HOW_IT_WORKS]);
    }

    public function faq(): Response
    {
        return Inertia::render('Public/Faq', [
            'faqs' => self::FAQS,
            'contactEmail' => config('ioms.emails.hello'),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Public policy documents (v2.55.0)
    |--------------------------------------------------------------------------
    | These were honest placeholders ("This page is being prepared") from
    | v2.18.0, when the instruction was not to invent legal text. IOMS now
    | sells subscriptions through a payment provider, and a merchant whose
    | Terms page is a placeholder is not a merchant a provider can verify.
    |
    | The content lives in App\Support\LegalDocuments, not here and not in
    | JSX -- see that class for why, and for the mechanism that OMITS any
    | clause whose underlying fact (registered entity, address, governing
    | law) has not been configured, rather than printing a placeholder that
    | reads like a statement.
    */
    public function privacy(): Response
    {
        return $this->legalDocument('Privacy Policy', LegalDocuments::privacy(),
            'Bagaimana IOMS menangani data akun dan data operasional pelanggan.');
    }

    public function terms(): Response
    {
        return $this->legalDocument('Terms of Service', LegalDocuments::terms(),
            'Ketentuan penggunaan IOMS sebagai layanan langganan untuk organisasi.');
    }

    public function refunds(): Response
    {
        return $this->legalDocument('Refund & Cancellation Policy', LegalDocuments::refunds(),
            'Kapan langganan dapat dibatalkan, dan dalam keadaan apa dana dikembalikan.');
    }

    private function legalDocument(string $title, array $sections, string $summary): Response
    {
        // A section whose every clause depended on an unconfigured fact
        // would otherwise render as a numbered heading with nothing under
        // it, which reads as an omission rather than a deliberate absence.
        $sections = array_values(array_filter($sections, fn ($s) => $s['body'] !== []));

        return Inertia::render('Public/LegalDocument', [
            'title' => $title,
            'summary' => $summary,
            'sections' => $sections,
            // Null unless configured. The page prints neither an operator
            // line nor an effective date it does not have.
            'operator' => LegalDocuments::operator(),
            'effectiveDate' => LegalDocuments::effectiveDate(),
            'emails' => config('ioms.emails'),
        ]);
    }

    /**
     * A real Contact page, replacing a footer `mailto:` that opened the
     * operating system's "choose an app" dialog and did nothing at all on a
     * machine with no mail client -- the same acquisition dead end v2.51.0
     * removed from the pricing CTAs.
     *
     * Deliberately publishes the four IOMS mailboxes and NOTHING ELSE. The
     * identity a payment provider verifies is a personal, private one; a
     * registered address is frequently a home address, and it does not
     * belong on a public page. A postal address appears here only when the
     * operator has explicitly configured one for publication.
     */
    public function contact(): Response
    {
        return Inertia::render('Public/Contact', [
            'emails' => config('ioms.emails'),
            'operator' => LegalDocuments::operator(),
            'address' => config('ioms.legal.address') ?: null,
            'sandboxEnabled' => (bool) config('ioms.sandbox.enabled'),
        ]);
    }
}
