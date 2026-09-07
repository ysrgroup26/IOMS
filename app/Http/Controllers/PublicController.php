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

    /** Centralize -> Operate -> Approve -> Monitor -> Report / Improve. */
    private const HOW_IT_WORKS = [
        [
            'step' => '01',
            'title' => 'Centralize',
            'body' => 'Perusahaan, departemen, karyawan, aset, dan master data disiapkan satu kali di dalam tenant Anda sendiri. Seluruh proses berikutnya mengacu pada sumber data yang sama, sehingga tidak ada lagi versi berbeda di file yang berbeda.',
        ],
        [
            'step' => '02',
            'title' => 'Operate',
            'body' => 'Pekerjaan dijalankan di dalam sistem: pengawas lapangan mengajukan Permit To Work, gudang mengeluarkan material, procurement membuat permintaan pembelian, maintenance membuka Work Order.',
        ],
        [
            'step' => '03',
            'title' => 'Approve',
            'body' => 'Setiap catatan mengikuti alur persetujuannya sendiri. Siapa yang berhak menyetujui apa ditentukan oleh peran, departemen, dan konfigurasi workflow Anda — bukan oleh kebiasaan.',
        ],
        [
            'step' => '04',
            'title' => 'Monitor',
            'body' => 'Dashboard tiap departemen menampilkan apa yang masih terbuka, terlambat, atau menunggu. Setiap catatan membawa riwayat aktivitas: siapa melakukan apa, dan kapan.',
        ],
        [
            'step' => '05',
            'title' => 'Report & Improve',
            'body' => 'Data operasional harian menjadi laporan manajemen melalui KPI, Report Center, dan laporan terjadwal — siap diekspor ke PDF dan Excel dengan kop surat perusahaan Anda sendiri.',
        ],
    ];

    private const FAQS = [
        ['q' => 'Apa itu IOMS?', 'a' => 'IOMS adalah Industrial Operations Platform: satu sistem yang menghubungkan pekerjaan lapangan, Health, Safety & Environment, data tenaga kerja, gudang, procurement, logistik, aset, maintenance, quality control, dan pelaporan manajemen. Pekerjaan dan catatan yang dihasilkannya berada di tempat yang sama.'],
        ['q' => 'Apa itu Operating Unit?', 'a' => 'IOMS menyusun organisasi Anda sebagai IOMS → Organization → Operating Unit → Department. Satu langganan adalah SATU organisasi. Operating Unit adalah unit operasional di dalamnya — galangan, site, atau divisi — dan setiap Department berada di bawah salah satu Operating Unit. Dua Operating Unit bukan berarti dua perusahaan atau dua langganan terpisah.'],
        ['q' => 'Untuk siapa IOMS dibuat?', 'a' => 'Perusahaan industri yang menjalankan pekerjaan lapangan dan memikul kewajiban keselamatan kerja — galangan kapal, konstruksi, manufaktur, pertambangan, minyak dan gas, energi, fabrikasi, logistik, dan penyedia jasa industri.'],
        ['q' => 'Industri apa saja yang didukung?', 'a' => 'IOMS dibangun untuk operasi industri secara umum, bukan satu sektor tertentu. Modulnya sama di semua industri; yang berbeda adalah domain operasional mana yang diaktifkan oleh perusahaan Anda.'],
        ['q' => 'Apa isi masing-masing paket?', 'a' => 'Starter mencakup Health, Safety & Environment secara lengkap untuk satu Operating Unit. Professional menambahkan Human Resources dan visibilitas Management lintas departemen, untuk organisasi yang menjalankan sampai dua Operating Unit. Enterprise membuka seluruh domain operasional IOMS untuk beberapa Operating Unit sekaligus. Ketiganya adalah platform yang sama — yang berbeda hanyalah luas akses dan kapasitas.'],
        ['q' => 'Bagaimana perhitungan jumlah pengguna?', 'a' => 'Kapasitas paket dinyatakan sebagai SATU angka: jumlah akun login (Users) yang tercakup. Starter mencakup 10 akun dan Professional 50 akun; Enterprise memakai kapasitas standar tertinggi. Izin di dalam IOMS — termasuk PTW Access — diberikan kepada akun yang sudah ada dan tidak menambah jumlah akun maupun biaya.'],
        ['q' => 'Apa itu PTW Access?', 'a' => 'PTW Access adalah izin yang diberikan kepada akun tertentu untuk membuat Permit To Work — bukan kapasitas yang dijual dan tidak dikenakan biaya tambahan. Seorang foreman atau supervisor lapangan yang diberi PTW Access dapat mengajukan izin kerja langsung dari My Work, dan HSE tetap yang meninjau serta menyetujuinya.'],
        ['q' => 'Apa itu My Work?', 'a' => 'My Work adalah ruang kerja untuk orang yang menjalankan pekerjaan di lapangan — foreman, supervisor, teknisi, operator. Akun yang ditandai sebagai pengguna lapangan langsung diarahkan ke My Work saat login, bukan ke dashboard kantor yang tidak relevan bagi mereka.'],
        ['q' => 'Apakah Enterprise bisa dikustomisasi?', 'a' => 'Enterprise adalah paket standar IOMS yang paling lengkap, bukan paket pengembangan khusus. IOMS adalah satu produk yang terus disempurnakan untuk semua pelanggan — tidak ada pengembangan khusus per perusahaan, dan tidak ada paket seumur hidup.'],
        ['q' => 'Bagaimana proses pembayarannya?', 'a' => 'Anda memilih paket dan siklus penagihan, mengonfirmasi alamat email, lalu membayar melalui penyedia pembayaran kami. Data kartu Anda tidak pernah melewati atau tersimpan di IOMS.'],
        ['q' => 'Kapan workspace kami aktif?', 'a' => 'Setelah penyedia pembayaran mengonfirmasi pembayaran Anda ke server kami. Sekadar sampai di halaman konfirmasi di browser tidak mengaktifkan apa pun — aktivasi mengikuti notifikasi terverifikasi dari penyedia pembayaran.'],
        ['q' => 'Apakah langganan bisa dibatalkan?', 'a' => 'Bisa, kapan saja. Langganan yang dibatalkan tetap berjalan sampai akhir periode yang sudah dibayar, setelah itu workspace berhenti dapat digunakan dan tidak ada penagihan berikutnya. Data Anda tidak dihapus pada saat pembatalan. Ketentuan lengkapnya ada di halaman Refund & Cancellation Policy.'],
        ['q' => 'Bagaimana jika pembayaran gagal?', 'a' => 'Tidak ada yang diaktifkan. Untuk langganan yang sedang berjalan, kegagalan perpanjangan melewati masa tenggang lebih dulu sebelum akses dibatasi — Anda diberi tahu, bukan langsung diputus.'],
        ['q' => 'Apakah identitas perusahaan kami bisa ditampilkan?', 'a' => 'Bisa. Setiap pelanggan mengelola nama, logo, alamat, kontak, NPWP, dan NIB perusahaannya sendiri, dan seluruh data itu otomatis menjadi kop pada dokumen yang dihasilkan IOMS.'],
        ['q' => 'Apakah dokumen bisa diekspor ke PDF dan Excel?', 'a' => 'Ya. Dokumen operasional diekspor sebagai PDF A4 siap cetak dengan kop perusahaan Anda, dan data laporan diekspor ke workbook Excel yang sudah tertata.'],
        ['q' => 'Apakah IOMS berbasis web?', 'a' => 'Ya. IOMS adalah aplikasi web multi-tenant berbasis cloud, dengan tampilan lapangan yang dirancang untuk dipakai dari ponsel di lokasi kerja. Tidak ada yang perlu diinstal.'],
        ['q' => 'Apakah data kami terpisah dari pelanggan lain?', 'a' => 'Ya. Setiap pelanggan adalah tenant tersendiri, dan pemisahan data diterapkan pada lapisan akses data — bukan diserahkan pada setiap query untuk mengingatnya sendiri.'],
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
