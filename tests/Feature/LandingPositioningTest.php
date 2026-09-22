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
        $this->assertStringContainsString('IOMS · Industrial Operations Platform', $welcome);
        $this->assertMatchesRegularExpression('#<h1[^>]*>\s*One platform for complex<br[^>]*/>\s*industrial operations\.#', $welcome);

        foreach (['management', 'HSE', 'people', 'field operations', 'projects', 'procurement', 'warehouse', 'logistics'] as $domain) {
            $this->assertStringContainsString($domain, $welcome, "The hero no longer names {$domain}.");
        }

        foreach (['shipyards', 'construction', 'manufacturing', 'mining', 'energy', 'marine'] as $industry) {
            $this->assertStringContainsString($industry, $welcome, "The hero no longer names {$industry}.");
        }

        $this->assertStringNotContainsString('Integrated Operations Management System', $welcome);
    }

    public function test_the_landing_page_does_not_inject_its_own_meta_tags(): void
    {
        $welcome = $this->source('Pages/Public/Welcome.jsx');

        $this->assertDoesNotMatchRegularExpression('#<meta\s#', $welcome,
            'Search and social metadata come from config/seo.php via the server; a page-level <meta> duplicates them.');
    }

    /** Each of the eight domains is a story, and each has real content. */
    public function test_every_operational_domain_is_told_as_a_story(): void
    {
        $stories = $this->source('Components/public/domainStories.js');

        foreach ([
            'Industrial Operations', 'HSE & Safety', 'People & Workforce', 'Field Operations',
            'Projects & Execution', 'Procurement & Warehouse', 'Logistics / PPIC', 'Management Visibility',
        ] as $eyebrow) {
            $this->assertStringContainsString("eyebrow: '{$eyebrow}'", $stories);
        }

        $this->assertSame(8, substr_count($stories, "eyebrow: '"));
        $this->assertSame(8, substr_count($stories, 'items: ['));
    }

    /**
     * Every capability a story names is a real menu item. Checked against
     * the navigation registry, the same source the product's sidebar reads.
     */
    public function test_domain_stories_name_only_real_capabilities(): void
    {
        $registry = strtolower($this->source('lib/workspaces.js'));

        foreach ([
            'my work', 'permit', 'loto', 'gas test', 'investigation', 'jsa', 'hiradc', 'capa',
            'competenc', 'contractor', 'visitor', 'material request', 'item master', 'inventory',
            'stock movement', 'goods receipt', 'rfq', 'purchase order', 'bast', 'daily report',
            'work order', 'report center', 'kpi',
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

        $this->assertMatchesRegularExpression('#<noscript>.*<h1>IOMS — Industrial Operations Platform</h1>.*</noscript>#s', $html);

        foreach (['platform-overview', 'solutions', 'pricing', 'faq'] as $name) {
            $this->assertStringContainsString('<a href="'.route($name).'">', $html);
        }

        // Not on private pages.
        $this->assertStringNotContainsString('<noscript>', $this->get(route('login'))->getContent());
    }
}
