<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v2.76.0 -- WHAT IOMS SAYS IT IS, AND THAT IT SAYS IT ONCE.
 *
 * Pins three things that are easy to undo by accident:
 *
 *   1. the product definition is visible content -- the hero H1 and its
 *      paragraph name the category, the domains and the industries;
 *   2. the landing page does not inject its own meta tags on top of the
 *      server's (it did, and the page carried two disagreeing
 *      descriptions);
 *   3. the domain stories name only capabilities that exist.
 *
 * Page bodies render in the browser (Inertia SSR is off), so the visible
 * copy is asserted against the component source; what the SERVER sends
 * is asserted against the response.
 */
class LandingPositioningTest extends TestCase
{
    use RefreshDatabase;

    private function source(string $path): string
    {
        return file_get_contents(resource_path('js/'.$path));
    }

    public function test_the_hero_states_the_product_definition_as_visible_text(): void
    {
        $welcome = $this->source('Pages/Public/Welcome.jsx');

        $this->assertSame(1, substr_count($welcome, '<h1'), 'The landing page must have exactly one h1.');
        $this->assertStringContainsString('IOMS / Industrial Operations Platform', $welcome);

        /*
         * v2.85.0 -- THIS TEST USED TO REQUIRE THE DEFECT IT NOW FORBIDS.
         *
         * It asserted that the hero names 'projects' and 'procurement', and
         * it passed, because the hero did. Both departments were retired
         * from the customer-facing product in v2.84.0 (ADR 045), so the
         * landing page was promising two workspaces no plan opens and a
         * green test was the reason nobody noticed.
         *
         * The list is now the FOUR workspaces config/plans.php pins in
         * `operational`, and the retired names are asserted ABSENT rather
         * than present, so the failure mode is inverted: reintroducing one
         * breaks the build instead of satisfying it.
         *
         * The exact headline string is no longer pinned. A test that
         * transcribes the h1 verbatim fails on every copy edit while
         * proving nothing about whether the hero states the product, so it
         * asserts the DEFINITION is present and leaves the wording free.
         */
        foreach (['Health, Safety & Environment', 'People / HRD', 'Warehouse Logistics', 'Management'] as $workspace) {
            $this->assertStringContainsString($workspace, $welcome, "The hero no longer names {$workspace}.");
        }

        foreach (['projects', 'procurement', 'Quality Control', 'Project Management'] as $retired) {
            $this->assertStringNotContainsString($retired, $welcome, "{$retired} is not a workspace IOMS sells.");
        }

        foreach (['Shipyards', 'Construction', 'Manufacturing', 'Mining', 'Energy'] as $industry) {
            $this->assertStringContainsString($industry, $welcome, "The hero no longer names {$industry}.");
        }

        $this->assertStringNotContainsString('Integrated Operations Management System', $welcome);
    }

    /**
     * v2.85.0 -- THE EM DASH RULE, PINNED WHERE IT IS ACTUALLY BROKEN.
     *
     * Public copy may not contain the em dash character. The rule had no
     * test, and it was being broken in four places at once: the page
     * constants, the SEO catalogue, the Blade layout and the `packages`
     * table's own description column.
     *
     * Checked against the RENDERED response rather than the source files,
     * because copy reaches a public page from all four of those and a
     * source-only check would have seen none of the last two. Both forms
     * are searched: Inertia serializes its props as JSON, where the
     * character appears as the escape sequence —, which is exactly how
     * the database descriptions evaded the first sweep.
     */
    /**
     * v2.87.0 -- THE PRICING GRID HAS AS MANY COLUMNS AS THERE ARE PLANS.
     *
     * This is written because the page shipped for five releases with
     * `xl:grid-cols-4` after the catalogue narrowed to three tiers in
     * v2.82.0. At wide viewports the row laid out four columns for three
     * cards and left an empty cell on the right, which is why the section
     * read as left-weighted rather than centred. Nothing failed: the markup
     * was valid, the plans were correct, and the only symptom was a
     * composition nobody could name.
     *
     * Asserted against the markup rather than a screenshot, because the
     * defect is a constant in the class list that outlived the catalogue it
     * was written for.
     */
    public function test_the_pricing_grid_has_a_column_for_every_public_plan(): void
    {
        $this->seed(\Database\Seeders\PackageSeeder::class);

        $planCount = app(\App\Services\PricingService::class)->publicPlans()->count();
        $welcome = $this->source('Pages/Public/Welcome.jsx');

        $this->assertSame(3, $planCount, 'The public catalogue is no longer three tiers; this test and the grid both need revisiting.');

        // Scoped to the grid that actually holds the plan cards. The page has
        // other grids, including a twelve-column one in the hero, and a check
        // that counted those would be measuring the wrong thing.
        $pricingBlock = substr($welcome, (int) strpos($welcome, 'function Pricing('));
        $cardGrid = substr($pricingBlock, 0, (int) strpos($pricingBlock, '{plans.map('));

        preg_match_all('/(?:md|lg|xl):grid-cols-(\d+)/', $cardGrid, $matches);
        $columnCounts = array_map('intval', $matches[1] ?? []);

        $this->assertNotEmpty($columnCounts, 'The pricing grid no longer declares a column count.');
        $this->assertLessThanOrEqual(
            $planCount,
            max($columnCounts),
            'A grid on the landing page asks for more columns than there are public plans, which leaves an empty cell.'
        );
    }

    /**
     * v2.87.0 -- EYEBROWS ARE RATIONED.
     *
     * A small uppercase label above every section headline gives a long page
     * one repeating LABEL / Headline / body rhythm, and that rhythm is the
     * most reliable signature of a generated marketing site. The budget is
     * one per three sections.
     *
     * Counted in the SOURCE rather than the DOM, so the check runs without a
     * browser. It counts `SectionHeading` eyebrow props plus the hand-rolled
     * uppercase labels that sit directly above an `h2`.
     */
    public function test_section_eyebrows_stay_within_budget(): void
    {
        $welcome = $this->source('Pages/Public/Welcome.jsx');

        // The sections the landing page renders, from its own composition.
        preg_match('/<PublicLayout>(.*?)<\/PublicLayout>/s', $welcome, $shell);
        $sectionCount = preg_match_all('/^\s{12}<[A-Z]\w+/m', $shell[1] ?? '');

        $eyebrows = preg_match_all('/eyebrow="/', $welcome)
            + preg_match_all('/uppercase tracking-\[0\.2em\]/', $welcome);

        $this->assertGreaterThan(6, $sectionCount, 'The section count could not be read; this test needs updating.');
        $this->assertLessThanOrEqual(
            (int) ceil($sectionCount / 3),
            $eyebrows,
            "The landing page uses {$eyebrows} section eyebrows across {$sectionCount} sections. The budget is one per three."
        );
    }

    /**
     * v2.89.0 -- THE GUEST SURFACES OUTSIDE /public COUNT AS PUBLIC COPY.
     *
     * The sign-in page advertised "HSE, workforce, projects, maintenance"
     * five releases after Project Management and Maintenance left the
     * customer-facing product. Every copy audit since v2.84.0 missed it for
     * the same reason: they scoped themselves to the marketing routes under
     * `Pages/Public`, and the auth shell lives under `guest`.
     *
     * A prospect reaches the sign-in page from the marketing site, so its
     * claims are public claims. This covers the surfaces those audits do not.
     */
    public function test_the_authentication_surfaces_name_no_retired_workspace(): void
    {
        $sources = [
            'Layouts/AuthLayout.jsx',
            'Pages/Auth/Login.jsx',
            'Pages/Auth/Register.jsx',
        ];

        foreach ($sources as $file) {
            $path = resource_path('js/'.$file);

            if (! file_exists($path)) {
                continue;
            }

            // Comments are stripped first: this file documents the retired
            // names in the comment explaining why they were removed.
            $source = preg_replace('#\{?/\*.*?\*/\}?#s', '', file_get_contents($path));
            $source = preg_replace('#^\s*//.*$#m', '', $source);

            foreach (['Procurement', 'Quality Control', 'Project Management', 'maintenance', 'projects'] as $retired) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $retired,
                    $source,
                    "{$file} names {$retired}, which is not a workspace IOMS sells."
                );
            }
        }
    }

    public function test_no_public_page_contains_an_em_dash(): void
    {
        $this->seed(\Database\Seeders\PackageSeeder::class);

        foreach ([
            'home', 'pricing', 'platform-overview', 'solutions', 'how-it-works',
            'faq', 'contact', 'legal.privacy', 'legal.terms', 'legal.refunds',
        ] as $name) {
            $html = $this->get(route($name))->assertOk()->getContent();

            $this->assertStringNotContainsString('—', $html, "The {$name} page renders an em dash.");
            $this->assertStringNotContainsString('—', $html, "The {$name} page carries an em dash inside its Inertia props.");
        }
    }

    public function test_the_landing_page_does_not_inject_its_own_meta_tags(): void
    {
        $welcome = $this->source('Pages/Public/Welcome.jsx');

        $this->assertDoesNotMatchRegularExpression('#<meta\s#', $welcome,
            'Search and social metadata come from config/seo.php via the server; a page-level <meta> duplicates them.');
    }

    /**
     * Each domain is a story, and each has real content.
     *
     * v2.84.0: six, not eight. Projects & Execution and Procurement &
     * Warehouse went with the workspaces that owned them -- the landing page
     * may not promise a domain the plan a prospect buys does not open. The
     * count is asserted so a retired domain cannot quietly reappear.
     */
    public function test_every_operational_domain_is_told_as_a_story(): void
    {
        $stories = $this->source('Components/public/domainStories.js');

        foreach ([
            'Industrial Operations', 'HSE & Safety', 'People & Workforce', 'Field Operations',
            'Warehouse Logistics', 'Management Visibility',
        ] as $eyebrow) {
            $this->assertStringContainsString("eyebrow: '{$eyebrow}'", $stories);
        }

        foreach (['Projects & Execution', 'Procurement & Warehouse'] as $retired) {
            $this->assertStringNotContainsString("eyebrow: '{$retired}'", $stories, "{$retired} is not a workspace IOMS sells.");
        }

        $this->assertSame(6, substr_count($stories, "eyebrow: '"));
        $this->assertSame(6, substr_count($stories, 'items: ['));
    }

    /**
     * Every capability a story names is a real menu item. Checked against
     * the navigation registry, the same source the product's sidebar reads.
     */
    public function test_domain_stories_name_only_real_capabilities(): void
    {
        $registry = strtolower($this->source('lib/workspaces.js'));

        foreach ([
            // v2.84.0: RFQ, Purchase Order, BAST, Daily Report and Work Order
            // left this list with the workspaces that owned them. The landing
            // page stopped naming them in the same change -- a claim on the
            // page a prospect buys from must point at something the plan they
            // buy actually opens.
            'my work', 'permit', 'loto', 'gas test', 'investigation', 'jsa', 'hiradc', 'capa',
            'competenc', 'contractor', 'visitor', 'material request', 'item master', 'inventory',
            'stock movement', 'goods receipt', 'report center', 'kpi',
        ] as $capability) {
            $this->assertStringContainsString($capability, $registry, "\"{$capability}\" is not in the workspace registry.");
        }
    }

    public function test_the_visual_slot_keeps_text_in_html_when_an_image_is_supplied(): void
    {
        $story = $this->source('Components/public/StorySection.jsx');

        // Heading, eyebrow and explanation are elements beside the visual,
        // never inside it. An image replaces only the placeholder, so nothing
        // a search engine or a screen reader needs is ever baked into a
        // photograph.
        $this->assertStringContainsString('<h3', $story);

        /*
         * v2.88.0 -- THE SLOT IS FILLED, AND THE CONTRACT CHANGED WITH IT.
         *
         * This asserted `loading="lazy"` and `alt={image.alt ?? ''}` directly
         * on a bare `<img>`, which was correct while the slot was an empty
         * placeholder taking a raw `src`. Real photography arrived, and a
         * bare `<img>` would hand a phone the desktop file, so the visual now
         * goes through `Photo`, which owns the srcset, the WebP source, the
         * JPEG fallback, the reserved aspect box and the lazy default.
         *
         * The assertions moved with it rather than being dropped: alt is
         * still passed through, laziness is still guaranteed, and both are
         * now checked where they actually live.
         */
        $this->assertStringContainsString('<Photo', $story, 'The story visual no longer uses the responsive image component.');
        $this->assertStringContainsString('alt={image.alt', $story);

        $photo = $this->source('Components/public/Photo.jsx');
        $this->assertStringContainsString("loading={priority ? 'eager' : 'lazy'}", $photo, 'Photo no longer lazy-loads by default.');
        $this->assertStringContainsString('srcSet', $photo, 'Photo no longer offers a responsive source set.');
        $this->assertStringContainsString('image/webp', $photo);

        // The landing page still passes each story's image straight through,
        // so a photograph is one field on a domainStories entry.
        $welcome = $this->source('Pages/Public/Welcome.jsx');
        $this->assertStringContainsString('image={story.image}', $welcome);

        /*
         * And the slot is no longer empty. This is the assertion the old test
         * could not make: photography exists now, so "the mechanism works" is
         * weaker than "the mechanism is carrying real images".
         */
        $stories = $this->source('Components/public/domainStories.js');
        $this->assertGreaterThanOrEqual(
            4,
            preg_match_all('/image: \{ name:/', $stories),
            'The domain stories lost their photography.'
        );
    }

    /** The one part of the definition a non-JavaScript crawler receives. */
    public function test_the_server_sends_a_readable_summary_and_internal_links(): void
    {
        $this->seed(\Database\Seeders\PackageSeeder::class);

        $html = $this->get(route('home'))->assertOk()->getContent();

        // v2.85.0: the descriptor is joined with a colon, not an em dash.
        $this->assertMatchesRegularExpression('#<noscript>.*<h1>IOMS: Industrial Operations Platform</h1>.*</noscript>#s', $html);

        foreach (['platform-overview', 'solutions', 'pricing', 'faq'] as $name) {
            $this->assertStringContainsString('<a href="'.route($name).'">', $html);
        }

        // Not on private pages.
        $this->assertStringNotContainsString('<noscript>', $this->get(route('login'))->getContent());
    }
}
