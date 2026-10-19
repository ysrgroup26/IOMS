<?php

namespace Tests\Feature;

use App\Services\Documents\StampRenderer;
use Tests\TestCase;

/**
 * v2.126.0 -- THE STAMPS, AND THE THINGS THEY MUST NEVER DRAW.
 *
 * v2.125.0 built these as a React component and gave the PDF a plain
 * bordered box, on a stated belief that Dompdf could honour neither a
 * rotation nor an ink texture. That belief was never tested and is wrong,
 * and the owner's reply to the release was that the PDF stamp was still a
 * plain blue box. It was.
 *
 * So the interesting assertions here are the NEGATIVE ones -- the states in
 * which no stamp may exist -- plus the two properties that make one stamp
 * serve both surfaces: it is SVG, and it is deterministic.
 */
class DocumentStampRenderingTest extends TestCase
{
    private function svg(string $dataUri): string
    {
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $dataUri);

        return base64_decode(substr($dataUri, strlen('data:image/svg+xml;base64,')));
    }

    /*
    |--------------------------------------------------------------------------
    | A standing
    |--------------------------------------------------------------------------
    */

    public function test_active_and_closed_differ_only_in_colour_and_word(): void
    {
        $stamps = new StampRenderer;

        $active = $this->svg($stamps->state('active'));
        $closed = $this->svg($stamps->state('closed'));

        $this->assertStringContainsString('ACTIVE', $this->wordOf($active));
        $this->assertStringContainsString('CLOSED', $this->wordOf($closed));
        $this->assertStringContainsString('#047857', $active, 'ACTIVE is green.');
        $this->assertStringContainsString('#B91C1C', $closed, 'CLOSED is red.');

        /*
         * Same plate, same slant, same rules -- only the ink and the word.
         *
         * The type is stripped before comparing, because tracking is
         * applied by POSITIONING EACH GLYPH (php-svg-lib ignores
         * `letter-spacing`, measured against a probe PDF), so the two
         * words are six <text> elements each and of course differ. What
         * must be identical is everything else.
         */
        $this->assertSame(
            str_replace(['#047857', '#ECFDF5'], ['INK', 'TINT'], $this->plateOf($active)),
            str_replace(['#B91C1C', '#FEF2F2'], ['INK', 'TINT'], $this->plateOf($closed)),
        );
    }

    /**
     * NO DATE INSIDE A STANDING STAMP. The owner's brief is explicit, and
     * the reason is good: a date in a standing stamp reads as the date the
     * standing was granted, which is a different fact from the validity
     * window printed in the document body.
     */
    public function test_a_standing_stamp_carries_no_date_and_no_name(): void
    {
        foreach (['active', 'closed'] as $status) {
            $svg = $this->svg((new StampRenderer)->state($status));

            $this->assertDoesNotMatchRegularExpression('/\d{4}/', $this->wordOf($svg),
                "The {$status} stamp rendered something that looks like a year.");
        }
    }

    /**
     * Only a standing gets a stamp. A stamp that can appear in six colours
     * is a status badge wearing a costume, and somebody reading an amber
     * seal on site as authorisation is the failure worth designing out.
     */
    public function test_no_other_status_is_given_a_stamp(): void
    {
        $stamps = new StampRenderer;

        foreach (['draft', 'submitted', 'approved', 'rejected', 'cancelled', 'in_progress', ''] as $status) {
            $this->assertNull($stamps->state($status), "\"{$status}\" is a status, not a standing.");
        }
    }

    /*
    |--------------------------------------------------------------------------
    | An act
    |--------------------------------------------------------------------------
    */

    /**
     * THE ONE THING THIS CLASS MUST NEVER DO. An action stamp without an
     * actor is what would let a reader take an unsigned document for a
     * signed one, so the refusal lives in the renderer and not in a
     * caller's `if`.
     */
    public function test_an_action_stamp_without_an_actor_is_not_drawn(): void
    {
        $stamps = new StampRenderer;

        $this->assertNull($stamps->action('approved', ''));
        $this->assertNull($stamps->action('approved', '   '));
        $this->assertNull($stamps->action('nonsense', 'Sri Handayani'));
    }

    /** Green marks a verification, blue marks an approval. */
    public function test_the_two_acts_are_told_apart_by_colour(): void
    {
        $stamps = new StampRenderer;

        $this->assertStringContainsString('#047857', $this->svg($stamps->action('verified', 'Sri Handayani')));
        $this->assertStringContainsString('#1D4ED8', $this->svg($stamps->action('approved', 'Sri Handayani')));
    }

    /** The person, their company's job title, and when -- rendered as given. */
    public function test_an_action_stamp_names_who_what_and_when(): void
    {
        $svg = $this->svg((new StampRenderer)->action(
            'approved', 'Sri Handayani', 'SPV HSE', '19 Oct 2026 21:34', 'HSE',
        ));

        /*
         * Read as the GLYPHS the seal draws, not as substrings of the
         * markup: a tracked word is one <text> element per character (see
         * StampRenderer::text()), so "SPV" never appears contiguously in
         * the file even though it is plainly on the stamp.
         */
        $drawn = $this->wordOf($svg);

        foreach (['APPROVED', 'HSE', 'SPVHSE'] as $tracked) {
            $this->assertStringContainsString($tracked, str_replace(' ', '', $drawn),
                "The seal lost \"{$tracked}\".");
        }

        // Untracked lines are drawn whole, so these ARE contiguous.
        $this->assertStringContainsString('Sri Handayani', $drawn);
        $this->assertStringContainsString('19 Oct 2026 21:34', $drawn);
    }

    /**
     * A MISSING TITLE LEAVES A SMALLER STAMP, never a placeholder. Nothing
     * is substituted for a fact the record does not hold -- the rule the
     * whole stamp family is built on.
     */
    public function test_a_missing_title_or_time_is_omitted_and_never_invented(): void
    {
        $svg = $this->svg((new StampRenderer)->action('approved', 'Sri Handayani'));

        foreach (['n/a', 'N/A', 'unknown', 'Unknown', '-', 'null'] as $placeholder) {
            $this->assertStringNotContainsString(">{$placeholder}<", $svg);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | What makes one stamp serve two surfaces
    |--------------------------------------------------------------------------
    */

    /**
     * THE SAME PERMIT PRINTED TWICE IS THE SAME DOCUMENT.
     *
     * `mt_rand()` in the distress would mean two renders of one permit
     * differ, and on a controlled document a difference is something
     * somebody has to explain.
     */
    public function test_the_ink_texture_is_deterministic(): void
    {
        $stamps = new StampRenderer;

        $this->assertSame($stamps->state('active'), $stamps->state('active'));
        $this->assertSame(
            $stamps->action('approved', 'Sri Handayani', 'SPV HSE', '19 Oct 2026 21:34'),
            $stamps->action('approved', 'Sri Handayani', 'SPV HSE', '19 Oct 2026 21:34'),
        );
    }

    /** And two different stamps never wear identically. */
    public function test_two_different_stamps_do_not_wear_the_same_way(): void
    {
        $stamps = new StampRenderer;

        $this->assertNotSame(
            $this->distressOf($this->svg($stamps->action('approved', 'Sri Handayani'))),
            $this->distressOf($this->svg($stamps->action('approved', 'Paten Wibowo'))),
        );
    }

    /**
     * Only primitives php-svg-lib can actually render.
     *
     * It supports no filters, no masks and no gradients, so a stamp built
     * from them would look right in a browser and arrive blank on paper --
     * which is a subtler version of the mistake this class exists to undo.
     */
    public function test_the_svg_uses_nothing_dompdf_cannot_render(): void
    {
        $stamps = new StampRenderer;

        foreach ([$stamps->state('active'), $stamps->action('approved', 'Sri Handayani', 'SPV HSE')] as $uri) {
            $svg = $this->svg($uri);

            foreach (['<filter', 'feTurbulence', '<mask', 'linearGradient', 'radialGradient', 'mix-blend-mode'] as $unsupported) {
                $this->assertStringNotContainsString($unsupported, $svg,
                    "{$unsupported} does not survive Dompdf, so it must not be in the stamp.");
            }

            $this->assertStringContainsString('font-family="sans-serif"', $svg,
                'Measured against a probe PDF: every other family fell back to a serif or lost the bold weight.');
            $this->assertStringContainsString('rotate(', $svg, 'A stamp is applied by hand, not aligned to the grid.');
        }
    }

    /** The word, as the glyphs the stamp actually draws. */
    private function wordOf(string $svg): string
    {
        preg_match_all('/<text[^>]*>([^<]*)<\/text>/', $svg, $matches);

        return implode('', $matches[1]);
    }

    /** The plate and its rules, with both the wear and the type removed. */
    private function plateOf(string $svg): string
    {
        $withoutDistress = preg_replace('/<g>(?:(?!<g>).)*<\/g>\s*<\/svg>/s', '</svg>', $svg) ?? $svg;

        return preg_replace('/<text[^>]*>[^<]*<\/text>/', '', $withoutDistress) ?? $withoutDistress;
    }

    /** The wear, without the plate. */
    private function distressOf(string $svg): string
    {
        preg_match('/<g>((?:(?!<g>).)*)<\/g>\s*<\/svg>/s', $svg, $m);

        return $m[1] ?? '';
    }
}
