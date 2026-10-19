<?php

namespace App\Services\Documents;

/**
 * v2.126.0 -- ONE STAMP, DRAWN ONCE, FOR THE SCREEN AND FOR THE PAPER.
 *
 * v2.125.0 built the stamps as a React component and gave the PDF a plain
 * bordered box instead, on the stated belief that Dompdf could not honour
 * a rotation or an ink texture. **That belief was wrong and was never
 * tested.** Dompdf 3.1.5 renders `transform: rotate()` correctly, and it
 * renders SVG through php-svg-lib -- both verified by rendering a probe
 * PDF and looking at it. The owner's reply to that release was, correctly,
 * that the PDF stamp was still a plain blue box.
 *
 * So the stamp is drawn HERE, as SVG, and the same bytes are used by both
 * surfaces: the PDF embeds the SVG as a data URI, and the Inertia props
 * hand the identical data URI to the page. There is no second
 * implementation to drift, and no "the PDF is a different medium" excuse
 * for the two disagreeing -- which is exactly what the owner found.
 *
 * WHY SVG RATHER THAN CSS. A stamp is an ink impression: rotated, double
 * ruled, and broken up where the ink did not transfer. CSS can express the
 * first two and reaches for blend modes and repeating gradients for the
 * third, neither of which survives Dompdf. SVG expresses all three in
 * primitives both renderers already support, at any size, with no raster.
 *
 * WHY IT IS STILL A DYNAMIC COMPONENT, not the reference picture pasted
 * in: nothing here is a stored asset. Every stamp is composed per record
 * from the real action record -- the word, the colour, the person, the job
 * title their company configured, the time they acted -- and a stamp with
 * no actor is not drawn at all. @see ADR 065.
 *
 * THE DISTRESS IS DETERMINISTIC. `mt_rand()` here would mean the same
 * permit printed twice produced two different documents, and on a
 * controlled document that is a difference somebody has to explain. The
 * speckle is seeded from the stamp's own text, so one permit always yields
 * one impression, and two different stamps never wear identically.
 */
class StampRenderer
{
    /** A standing: the word only, and never a date. @see ADR 065 */
    public const STATE_INKS = [
        'active' => ['word' => 'ACTIVE', 'ink' => '#047857', 'tint' => '#ECFDF5'],
        'closed' => ['word' => 'CLOSED', 'ink' => '#B91C1C', 'tint' => '#FEF2F2'],
    ];

    /** An act: green marks a verification, blue marks an approval. */
    public const ACTION_INKS = [
        'verified' => ['word' => 'VERIFIED', 'ink' => '#047857', 'tint' => '#ECFDF5'],
        'approved' => ['word' => 'APPROVED', 'ink' => '#1D4ED8', 'tint' => '#EFF6FF'],
    ];

    /**
     * The standing stamp. Returns null for any status that is not a
     * standing -- the caller then renders its ordinary status badge, which
     * is the rule that stops a stamp appearing in six colours.
     */
    public function state(string $status): ?string
    {
        $spec = self::STATE_INKS[$status] ?? null;

        if ($spec === null) {
            return null;
        }

        $w = 250;
        $h = 92;

        $svg = $this->open($w, $h)
            .'<g transform="rotate(-7 '.($w / 2).' '.($h / 2).')">'
            .$this->plate(10, 16, $w - 20, $h - 32, $spec['ink'], $spec['tint'])
            .$this->word($w / 2, 60, $spec['word'], $spec['ink'], 28, 6)
            .'</g>'
            .$this->distress($spec['word'], $w, $h, 34)
            .'</svg>';

        return $this->dataUri($svg);
    }

    /**
     * The action stamp: the act, the function it was performed under, and
     * then WHO did it -- name, their company's own job title, and when.
     *
     * `$by` is required by the caller, not defaulted here, because an
     * action stamp without an actor is the one thing this class must never
     * draw: it is what would let a reader take an unsigned document for a
     * signed one.
     */
    public function action(string $variant, string $by, ?string $title = null, ?string $at = null, ?string $kindLabel = null): ?string
    {
        $spec = self::ACTION_INKS[$variant] ?? null;

        if ($spec === null || trim($by) === '') {
            return null;
        }

        $w = 300;
        $h = 168;
        $cx = $w / 2;

        $lines = '';
        $y = 104;

        // The identity block, under the rule. Each line is drawn only if
        // there is a real value for it -- a missing job title leaves a
        // smaller stamp, never a placeholder.
        $lines .= $this->text($cx, $y, $this->truncate($by, 26), $spec['ink'], 17, 0, 'bold');
        $y += 21;

        if ($title !== null && trim($title) !== '') {
            $lines .= $this->text($cx, $y, mb_strtoupper($this->truncate($title, 30)), $spec['ink'], 12, 1.2);
            $y += 18;
        }

        if ($at !== null && trim($at) !== '') {
            // Tracking 0 deliberately: the manual advance below is tuned
            // for upper-case words, and applied to a timestamp it spaces
            // the digits out like a lottery ticket. Seen in the probe.
            $lines .= $this->text($cx, $y, $at, $spec['ink'], 12);
        }

        $svg = $this->open($w, $h)
            .'<g transform="rotate(-3 '.$cx.' '.($h / 2).')">'
            .$this->plate(10, 10, $w - 20, $h - 20, $spec['ink'], $spec['tint'])
            .$this->actAndMark($cx, 51, $spec['word'], $spec['ink'], $variant)
            .($kindLabel ? $this->text($cx, 72, mb_strtoupper($this->truncate($kindLabel, 28)), $spec['ink'], 12, 2.6) : '')
            .'<line x1="40" y1="82" x2="'.($w - 40).'" y2="82" stroke="'.$spec['ink'].'" stroke-width="1.4" opacity="0.55"/>'
            .$lines
            .'</g>'
            .$this->distress($spec['word'].$by, $w, $h, 52, 80)
            .'</svg>';

        return $this->dataUri($svg);
    }

    /* ------------------------------------------------------------------
     | The primitives. Deliberately plain shapes and text: php-svg-lib
     | (what Dompdf renders SVG with) supports no filters, no masks and no
     | gradients, so anything built from them would look right in a browser
     | and arrive blank on paper -- which is the mistake this class exists
     | to undo.
     ------------------------------------------------------------------ */

    private function open(int|float $w, int|float $h): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'">';
    }

    /** The double rule -- what makes it an impression rather than an outlined chip. */
    private function plate(float $x, float $y, float $w, float $h, string $ink, string $tint): string
    {
        return '<rect x="'.$x.'" y="'.$y.'" width="'.$w.'" height="'.$h.'" rx="3" fill="'.$tint.'" stroke="'.$ink.'" stroke-width="5"/>'
            .'<rect x="'.($x + 7).'" y="'.($y + 7).'" width="'.($w - 14).'" height="'.($h - 14).'" rx="2" fill="none" stroke="'.$ink.'" stroke-width="1.6"/>';
    }

    private function word(float $cx, float $y, string $word, string $ink, float $size, float $tracking): string
    {
        return $this->text($cx, $y, $word, $ink, $size, $tracking, 'bold');
    }

    /**
     * The act's mark and the act's word, centred TOGETHER.
     *
     * The first render placed the icon at a fixed offset and the word at
     * another, so the shield sat on top of the A in APPROVED -- visible in
     * the probe PDF. They are one lockup, so they are measured and placed
     * as one: the pair's total width is computed from the same advance the
     * tracking uses, and the pair is centred on the plate.
     */
    private function actAndMark(float $cx, float $baseline, string $word, string $ink, string $variant): string
    {
        $size = 24;
        $tracking = 5;
        $advance = $size * 0.6 + $tracking;

        $wordWidth = max(0, mb_strlen($word) - 1) * $advance;
        $markWidth = 30;
        $gap = 14;
        $total = $markWidth + $gap + $wordWidth;

        $markCx = $cx - $total / 2 + $markWidth / 2;
        $wordCx = $cx - $total / 2 + $markWidth + $gap + $wordWidth / 2;

        return $this->mark($markCx, $baseline - 8, $ink, $variant)
            .$this->text($wordCx, $baseline, $word, $ink, $size, $tracking, 'bold');
    }

    /**
     * `sans-serif`, and the tracking drawn by hand.
     *
     * Both were measured against a probe PDF rather than assumed, because
     * php-svg-lib agrees with a browser about neither:
     *
     *  - FONT FAMILY. `Helvetica, Arial, sans-serif` fell back to a SERIF
     *    face, and so did `Arial` on its own; `Helvetica` alone resolved
     *    sans but dropped the bold weight. Only the generic `sans-serif`
     *    (and, oddly, a lower-case `helvetica`) gave bold sans. A stamp set
     *    in a serif is not the design.
     *  - LETTER SPACING. `letter-spacing` is ignored outright, so a word
     *    tracked out on screen arrived tight on paper -- the two surfaces
     *    showing two different stamps, which is the whole thing this class
     *    exists to stop.
     *
     * So tracking is applied by POSITIONING each glyph: identical in both
     * renderers, because neither has to interpret anything. The advance is
     * approximated from the font size (0.6em is close enough for Helvetica
     * capitals) and only ever used for short, upper-case words.
     */
    private function text(float $x, float $y, string $value, string $ink, float $size, float $tracking = 0, string $weight = 'normal'): string
    {
        $open = '<text font-family="sans-serif" font-size="'.$size.'" font-weight="'.$weight.'" fill="'.$ink.'"';

        if ($tracking <= 0) {
            return $open.' x="'.$x.'" y="'.$y.'" text-anchor="middle">'
                .htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8')
                .'</text>';
        }

        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $advance = $size * 0.6 + $tracking;
        $start = $x - (count($chars) - 1) * $advance / 2;
        $out = '';

        foreach ($chars as $i => $char) {
            if ($char === ' ') {
                continue;
            }

            $out .= $open.' x="'.round($start + $i * $advance, 2).'" y="'.$y.'" text-anchor="middle">'
                .htmlspecialchars($char, ENT_QUOTES | ENT_XML1, 'UTF-8')
                .'</text>';
        }

        return $out;
    }

    /**
     * The act's mark, drawn rather than fetched: a check for a
     * verification, a shield for an approval. An icon font would be one
     * more thing Dompdf has to have loaded.
     */
    private function mark(float $cx, float $cy, string $ink, string $variant): string
    {
        $check = '<path d="M '.($cx - 7).' '.$cy.' l 5 6 l 10 -13" fill="none" stroke="'.$ink.'" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"/>';

        if ($variant === 'approved') {
            // A shield outline around the same check.
            return '<path d="M '.$cx.' '.($cy - 15).' l 13 5 v 9 c 0 8 -6 14 -13 17 c -7 -3 -13 -9 -13 -17 v -9 z"'
                .' fill="none" stroke="'.$ink.'" stroke-width="2.6" stroke-linejoin="round"/>'.$check;
        }

        return '<circle cx="'.$cx.'" cy="'.$cy.'" r="13" fill="none" stroke="'.$ink.'" stroke-width="2.6"/>'.$check;
    }

    /**
     * Where the ink did not take.
     *
     * Drawn OUTSIDE the rotated group on purpose: ink breaks up along the
     * grain of the paper, not along the angle the stamp happened to be
     * held at, and a texture that rotates with the plate reads as a
     * pattern rather than as wear.
     *
     * Opaque white rather than a translucent overlay, because php-svg-lib
     * handles `opacity` on a shape but nothing that composites.
     */
    private function distress(string $seedText, float $w, float $h, int $count, ?float $sparseBelow = null): string
    {
        $seed = crc32($seedText);
        $next = function (int $mod) use (&$seed): int {
            $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;

            return $seed % max(1, $mod);
        };

        $out = '';

        for ($i = 0; $i < $count; $i++) {
            $x = $next((int) $w);
            $y = $next((int) $h);
            $sw = 2 + $next(20);
            $sh = 1 + $next(3);

            /*
             * WEAR MUST NOT EAT THE EVIDENCE.
             *
             * The first render of this struck through "MANAGER SHIPYARD"
             * and half of "HSE" -- seen in the probe PDF, not reasoned
             * about. Real ink does break across text, but a stamp whose
             * job is to say WHO signed cannot have the name obscured by
             * decoration. Below the rule the speckle thins out and stays
             * short, so the texture reads as one impression while the
             * identity stays readable at print size.
             */
            if ($sparseBelow !== null && $y > $sparseBelow) {
                if ($next(3) !== 0) {
                    continue;
                }

                $sw = min($sw, 6);
            }

            $out .= '<rect x="'.$x.'" y="'.$y.'" width="'.$sw.'" height="'.$sh.'" fill="#ffffff" opacity="0.9"/>';
        }

        // A few vertical nicks too, so the wear is not all one direction.
        for ($i = 0; $i < (int) ($count / 4); $i++) {
            $x = $next((int) $w);
            $y = $next((int) $h);
            $out .= '<rect x="'.$x.'" y="'.$y.'" width="'.(1 + $next(2)).'" height="'.(3 + $next(7)).'" fill="#ffffff" opacity="0.85"/>';
        }

        return '<g>'.$out.'</g>';
    }

    private function truncate(string $value, int $max): string
    {
        $value = trim($value);

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 1).'…' : $value;
    }

    /**
     * Base64 rather than a percent-encoded inline SVG: Dompdf resolves a
     * `data:` image by decoding it, and a raw `<svg>` carrying `#` in a
     * colour is a URI fragment to anything that parses the string first.
     */
    private function dataUri(string $svg): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
