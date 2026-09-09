<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v2.68.0 -- THE LANGUAGE HIERARCHY, PINNED WHERE IT KEPT SLIPPING.
 *
 * IOMS is deliberately bilingual, and v2.53.0 set the rule after v2.52.0
 * over-translated and had to be corrected in both directions:
 *
 *   ENGLISH    module and feature names, navigation, page titles, column
 *              headers, status labels, action labels, operational terms.
 *   INDONESIAN explanatory sentences, help text, guidance, empty states.
 *
 * The reason the English half is not negotiable is stated in that release:
 * a user must be able to say "open Regulations & Standards" out loud and
 * have it match what is on screen. When a menu item and the page it opens
 * disagree, they are no longer obviously the same thing.
 *
 * WHAT WENT WRONG, THREE TIMES. Every previous attempt to hold a language
 * line was scoped to the file its author happened to be editing:
 *
 *   v2.61.0  fixed Welcome.jsx and pinned it by reading Welcome.jsx --
 *            so the landing copy arriving as a server prop stayed
 *            Indonesian for three more releases (v2.64.0's pitfall entry).
 *   v1.11.7  translated the five department Overview dashboards under the
 *            older "standardize on Indonesian" policy. v2.53.0 replaced
 *            that policy; nothing re-checked those five pages, so until
 *            v2.68.0 the sidebar said "CAPA" while the card under it said
 *            "Tindakan Perbaikan".
 *
 * So this test covers BOTH sources a label can come from: the JSX literal
 * AND the rendered server prop. Explanatory copy is deliberately NOT
 * asserted on -- it is supposed to be Indonesian, and a test that flagged
 * it would push the product the wrong way.
 */
class LanguageHierarchyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Function words and domain nouns that are common in Indonesian and do
     * not occur in English UI copy. Deliberately not a dictionary: the
     * point is to catch a lapse without having to guess the sentence.
     */
    private const INDONESIAN_MARKERS = [
        'yang', 'untuk', 'dengan', 'pada', 'dari', 'tidak', 'Anda', 'setiap',
        'dapat', 'adalah', 'belum', 'sudah', 'semua', 'akan', 'seluruh',
        'Ringkasan', 'Keselamatan', 'Pengunjung', 'Terlambat', 'Silakan',
        'Diperlukan', 'Perbaikan', 'Pelaporan', 'Disetujui', 'Menunggu',
        'Ditolak', 'Karyawan', 'Gudang', 'Barang', 'Permintaan', 'Pengelolaan',
        'Penilaian', 'Bahaya', 'Kecelakaan', 'Limbah', 'Pelatihan', 'Pekerjaan',
        'Pergerakan', 'Penerimaan', 'Terbaru', 'Tercatat', 'Aktivitas',
        'Insiden', 'Observasi', 'Kompetensi', 'Inventaris', 'Pengadaan',
        'Proyek', 'Tugas', 'Laporan', 'Inspeksi', 'Persetujuan', 'Sertifikasi',
        'Portofolio', 'Riwayat', 'Tampilan', 'Hapus', 'Simpan', 'Ubah',
        'Tambah', 'Pilih', 'Lihat', 'Bandingkan', 'Hubungi',
    ];

    /**
     * The positions the policy assigns to English. `description=`,
     * `subtitle=`, `<CardDescription>` and `emptyTitle=` are deliberately
     * absent: that is the Indonesian half of the rule.
     */
    private const LABEL_PATTERNS = [
        'label attribute' => '/\blabel="([^"]{2,90})"/',
        'label property' => "/\blabel:\s*'([^']{2,90})'/",
        'title property' => "/\btitle:\s*'([^']{2,90})'/",
        'CardTitle' => '/<CardTitle[^>]*>([^<{]{2,90})<\/CardTitle>/',
        'table header' => '/<th[^>]*>([^<{]{2,90})<\/th>/',
        'Head title' => '/<Head title="([^"]{2,90})"/',
        'DashboardShell title' => '/<DashboardShell title="([^"]{2,90})"/',
    ];

    /**
     * Every navigable label written as a literal in a page component is
     * English. This one legitimately reads the files: these strings are
     * authored in the component, so the component IS the source of truth
     * for them. The server-prop half is covered by the test below.
     */
    public function test_no_label_position_in_the_ui_is_written_in_indonesian(): void
    {
        $offenders = [];

        foreach ($this->componentFiles() as $path) {
            $source = $this->stripComments(file_get_contents($path));
            $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);

            foreach (self::LABEL_PATTERNS as $position => $pattern) {
                preg_match_all($pattern, $source, $matches);

                foreach ($matches[1] as $value) {
                    $value = trim($value);

                    if ($value === '' || str_starts_with($value, '{')) {
                        continue;
                    }

                    if ($marker = $this->indonesianMarkerIn($value)) {
                        $offenders[] = "{$relative} [{$position}] \"{$value}\" (matched \"{$marker}\")";
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These are label positions, which v2.53.0 assigns to English so a menu item and the page it "
            ."opens are recognisably the same thing. Explanatory copy stays Indonesian and is not checked "
            ."here.\n  - ".implode("\n  - ", $offenders)."\n"
        );
    }

    /**
     * The other half, and the one three previous attempts missed: copy the
     * visitor reads that is authored on the SERVER and arrives as an
     * Inertia prop. Reading the JSX cannot see any of this.
     */
    public function test_no_server_supplied_public_copy_is_indonesian(): void
    {
        // `platform-overview` is the public marketing page. (The bare
        // `platform` route name belongs to the Platform Super Admin
        // dashboard, which is a different surface entirely.)
        $routes = ['home', 'platform-overview', 'solutions', 'pricing', 'how-it-works', 'faq', 'contact'];
        $offenders = [];

        foreach ($routes as $routeName) {
            $props = $this->get(route($routeName))->viewData('page')['props'];

            // Legal documents are deliberately Indonesian for an Indonesian
            // legal audience (v2.56.0) and are not served from these routes.
            $copy = json_encode(
                collect($props)->except(['auth', 'company', 'version', 'flash', 'errors', 'ziggy'])->all(),
                JSON_UNESCAPED_UNICODE
            );

            if ($marker = $this->indonesianMarkerIn($copy)) {
                $offenders[] = "route \"{$routeName}\" (matched \"{$marker}\")";
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Server-supplied copy on a public page is still Indonesian, so a visitor changes language "
            ."partway through the site. This is the exact blind spot v2.61.0's file-reading test had.\n  - "
            .implode("\n  - ", $offenders)."\n"
        );
    }

    /**
     * The property that makes the rule worth having: a workspace's own
     * Overview page must call itself what the sidebar calls it. Regression
     * guard for the specific defect v2.68.0 fixed.
     */
    public function test_department_overview_pages_title_themselves_in_english(): void
    {
        $overviews = [
            'Hse/Dashboard.jsx' => 'HSE Overview',
            'Hr/Dashboard.jsx' => 'HR Overview',
            'Logistics/Dashboard.jsx' => 'Logistics / PPIC Overview',
            'Warehouses/Dashboard.jsx' => 'Warehouse Overview',
            'ProjectManagement/Dashboard.jsx' => 'Project Management Overview',
        ];

        foreach ($overviews as $file => $expectedTitle) {
            $source = file_get_contents(resource_path('js/Pages/'.$file));

            $this->assertStringContainsString(
                '<Head title="'.$expectedTitle.'"',
                $source,
                "{$file} should title itself \"{$expectedTitle}\" -- the sidebar entry that opens it reads "
                ."\"Overview\", and the two must match."
            );
        }
    }

    /**
     * `resources/js/lib/id.js` was the terminology map for the superseded
     * v1.11.7 "translate everything" policy. It was imported by nothing for
     * many releases and was deleted in v2.68.0. A dictionary that is easy
     * to wire back up is how a reverted policy returns.
     */
    public function test_the_superseded_indonesian_terminology_map_has_not_returned(): void
    {
        $this->assertFileDoesNotExist(
            resource_path('js/lib/id.js'),
            'resources/js/lib/id.js implements the language policy v2.53.0 replaced. If UI terminology '
            .'needs a shared source of truth again, it should encode the CURRENT hierarchy, not the old one.'
        );
    }

    /** Every .jsx under resources/js. */
    private function componentFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('js'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'jsx') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Comments in this codebase carry a lot of Indonesian history and are
     * not user-facing. They must not be scanned.
     */
    private function stripComments(string $source): string
    {
        $source = preg_replace('#/\*.*?\*/#s', '', $source);

        return preg_replace('#^\s*//.*$#m', '', $source);
    }

    private function indonesianMarkerIn(string $value): ?string
    {
        foreach (self::INDONESIAN_MARKERS as $marker) {
            if (preg_match('/\b'.preg_quote($marker, '/').'\b/iu', $value) === 1) {
                return $marker;
            }
        }

        return null;
    }
}
