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
        ['q' => 'Untuk siapa IOMS dibuat?', 'a' => 'Perusahaan industri yang menjalankan pekerjaan lapangan dan memikul kewajiban keselamatan kerja — galangan kapal, konstruksi, manufaktur, pertambangan, minyak dan gas, energi, fabrikasi, logistik, dan penyedia jasa industri.'],
        ['q' => 'Industri apa saja yang didukung?', 'a' => 'IOMS dibangun untuk operasi industri secara umum, bukan satu sektor tertentu. Modulnya sama di semua industri; yang berbeda adalah domain operasional mana yang diaktifkan oleh perusahaan Anda.'],
        ['q' => 'Apa isi masing-masing paket?', 'a' => 'Starter mencakup Health, Safety & Environment secara lengkap. Professional menambahkan Human Resources dan visibilitas Management lintas departemen. Enterprise membuka seluruh domain operasional IOMS. Ketiganya adalah platform yang sama — yang berbeda hanyalah luas akses dan kapasitas.'],
        ['q' => 'Bagaimana perhitungan jumlah pengguna?', 'a' => 'Setiap paket memiliki batas jumlah akun login (Users) dan batas berapa akun di antaranya yang boleh diberi PTW Access. PTW Access adalah izin pada akun yang sudah ada, bukan tambahan akun. Jadi "10 Users, 5 PTW Access" berarti sepuluh orang dapat login dan lima di antara mereka boleh membuat Permit To Work — bukan lima belas akun.'],
        ['q' => 'Apa itu PTW Access?', 'a' => 'PTW Access adalah izin yang diberikan kepada akun tertentu untuk membuat Permit To Work. Seorang foreman atau supervisor lapangan yang diberi PTW Access dapat mengajukan izin kerja langsung dari My Work, dan HSE tetap yang meninjau serta menyetujuinya.'],
        ['q' => 'Apa itu My Work?', 'a' => 'My Work adalah ruang kerja untuk orang yang menjalankan pekerjaan di lapangan — foreman, supervisor, teknisi, operator. Akun yang ditandai sebagai pengguna lapangan langsung diarahkan ke My Work saat login, bukan ke dashboard kantor yang tidak relevan bagi mereka.'],
        ['q' => 'Apakah Enterprise bisa dikustomisasi?', 'a' => 'Enterprise adalah paket standar IOMS yang paling lengkap, bukan paket pengembangan khusus. IOMS adalah satu produk yang terus disempurnakan untuk semua pelanggan — tidak ada pengembangan khusus per perusahaan, dan tidak ada paket seumur hidup.'],
        ['q' => 'Bagaimana proses pembayarannya?', 'a' => 'Anda memilih paket dan siklus penagihan, mengonfirmasi alamat email, lalu membayar melalui penyedia pembayaran kami. Data kartu Anda tidak pernah melewati atau tersimpan di IOMS.'],
        ['q' => 'Kapan workspace kami aktif?', 'a' => 'Setelah penyedia pembayaran mengonfirmasi pembayaran Anda ke server kami. Sekadar sampai di halaman konfirmasi di browser tidak mengaktifkan apa pun — aktivasi mengikuti notifikasi terverifikasi dari penyedia pembayaran.'],
        ['q' => 'Apakah langganan bisa dibatalkan?', 'a' => 'Bisa. Langganan yang dibatalkan tetap berjalan sampai akhir periode yang sudah dibayar, setelah itu workspace berhenti dapat digunakan. Data Anda tidak dihapus pada saat pembatalan.'],
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

    public function privacy(): Response
    {
        return Inertia::render('Public/Legal', ['title' => 'Privacy Policy']);
    }

    public function terms(): Response
    {
        return Inertia::render('Public/Legal', ['title' => 'Terms of Service']);
    }
}
