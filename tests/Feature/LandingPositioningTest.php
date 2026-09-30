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
        // never inside it -- an image replaces only the placeholder.
        $this->assertStringContainsString('<h3', $story);
        $this->assertStringContainsString('loading="lazy"', $story);
        $this->assertStringContainsString("alt={image.alt ?? ''}", $story);

        // v2.78.0: and the landing page passes each story's image through, so
        // adding real IOMS imagery later is ONE field on a domainStories.js
        // entry -- { src, alt } -- with no change to either component.
        $welcome = $this->source('Pages/Public/Welcome.jsx');
        $this->assertStringContainsString('image={story.image}', $welcome);
        $this->assertStringContainsString('{ src, alt }', $this->source('Components/public/domainStories.js'));
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
