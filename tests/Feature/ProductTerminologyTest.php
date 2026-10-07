<?php

namespace Tests\Feature;

use App\Support\NameFormatter;
use Tests\TestCase;

/**
 * v2.106.0 -- THE PRODUCT'S OWN NAMES, AND THE TWO WAYS THEY WERE LOST.
 *
 * STORED KEYS WERE SHOWN TO CUSTOMERS, de-underscored and nothing more:
 * "material request", "permit to work", "hse inspection" appeared in
 * Settings' Numbering, Approval Flow, Documents and Import/Export Mapping
 * pickers. A menu item and the page it opens must be recognisably the same
 * thing, and "material request" is not what IOMS calls a Material Request.
 *
 * WHERE A FIX HAD BEEN ATTEMPTED IT MADE ACRONYMS WORSE. Several of those
 * sites added the CSS `capitalize` rule, which upper-cases the first letter
 * of every word and therefore renders "Hse Inspection", "Jsa", "Loto" and
 * "Ptw". Those are initialisms; capitalising them as if they were ordinary
 * words is the specific thing the product's language rule forbids.
 *
 * No mechanical rule gets these right either -- "Permit to Work" has a
 * lower-case "to" -- so the names are written down in `config/naming.php`,
 * mirrored for the client in `resources/js/lib/moduleLabels.js`, and this
 * test is what keeps the two honest.
 */
class ProductTerminologyTest extends TestCase
{
    /** @return array<string, string> */
    private function jsModuleLabels(): array
    {
        $source = file_get_contents(resource_path('js/lib/moduleLabels.js'));

        preg_match('/export const MODULE_LABELS = \{(.*?)\n\};/s', $source, $block);
        $this->assertNotEmpty($block, 'MODULE_LABELS must exist in the mirrored file.');

        preg_match_all("/^\s{4}([a-z_]+):\s*'([^']+)',$/m", $block[1], $matches, PREG_SET_ORDER);

        $labels = [];
        foreach ($matches as $m) {
            $labels[$m[1]] = $m[2];
        }

        return $labels;
    }

    /** @return array<int, string> */
    private function jsAcronyms(): array
    {
        $source = file_get_contents(resource_path('js/lib/humanizeToken.js'));

        preg_match('/const ACRONYMS = new Set\(\[(.*?)\]\);/s', $source, $block);
        $this->assertNotEmpty($block, 'The mirrored acronym list must exist.');

        preg_match_all("/'([A-Z0-9]+)'/", $block[1], $matches);

        return $matches[1];
    }

    /* ================================================== the vocabulary */

    public function test_every_module_key_has_a_product_label(): void
    {
        $keys = array_keys(\App\Services\NumberGeneratorService::DEFAULTS);
        $labels = config('naming.module_labels');

        $missing = array_values(array_diff($keys, array_keys($labels)));

        $this->assertSame(
            [],
            $missing,
            "These module keys would be shown to a customer as a de-underscored key:\n".implode("\n", $missing)
        );
    }

    public function test_the_client_and_the_server_agree_on_every_label(): void
    {
        $php = config('naming.module_labels');
        $js = $this->jsModuleLabels();

        $this->assertSame($php, $js, 'config/naming.php and resources/js/lib/moduleLabels.js have drifted.');
    }

    public function test_the_client_and_the_server_agree_on_the_acronyms(): void
    {
        $php = array_map('mb_strtoupper', config('naming.acronyms'));
        $js = $this->jsAcronyms();

        sort($php);
        sort($js);

        $this->assertSame($php, $js, 'config/naming.php and humanizeToken.js have drifted on acronyms.');
    }

    /* =============================================== the labels themselves */

    public function test_the_established_product_names_are_used(): void
    {
        foreach ([
            'material_request' => 'Material Request',
            'incident_investigation' => 'Incident Investigation',
            'permit_to_work' => 'Permit to Work',
            'hse_inspection' => 'HSE Inspection',
            'ppe_replacement_request' => 'PPE Replacement Request',
            'jsa' => 'JSA',
            'loto' => 'LOTO',
            'ncr' => 'NCR',
            'rfq' => 'RFQ',
            'tbm' => 'TBM',
        ] as $key => $expected) {
            $this->assertSame($expected, NameFormatter::moduleLabel($key));
        }
    }

    /** An unnamed key still never shows an underscore. */
    public function test_an_unknown_module_key_still_reads_as_words(): void
    {
        $this->assertSame('Something New', NameFormatter::moduleLabel('something_new'));
        $this->assertSame('HSE Something', NameFormatter::moduleLabel('hse_something'));
        $this->assertSame('', NameFormatter::moduleLabel(null));
    }

    /* ================================================== no raw keys on screen */

    /**
     * The transformation that produced "material request" and, with CSS
     * capitalize on top, "Hse Inspection". A module key reaches a customer
     * through `moduleLabel()` or not at all.
     */
    public function test_no_page_renders_a_module_key_by_swapping_underscores(): void
    {
        $offenders = [];

        foreach ($this->jsxFiles() as $path) {
            $source = file_get_contents($path);

            if (preg_match('/\{\s*\w*\.?module_key\.replace\(\/_\/g/', $source)
                || preg_match('/\{\s*k\.replace\(\/_\/g/', $source)) {
                $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A module key must reach a customer through moduleLabel():\n".implode("\n", $offenders)
        );
    }

    /**
     * CSS `capitalize` upper-cases the first letter of every word, so it
     * turns an initialism into a word. Values that can contain one must be
     * humanised in JavaScript, where the acronym list is consulted.
     */
    public function test_acronym_bearing_values_are_not_capitalised_by_css(): void
    {
        $offenders = [];

        foreach ($this->jsxFiles() as $path) {
            $source = file_get_contents($path);

            // The specific shape that was wrong: a snake_case value
            // de-underscored inline AND handed to CSS capitalize.
            if (preg_match('/className="[^"]*capitalize[^"]*"[^>]*>\{[^}]*\.replace\(\/_\/g/', $source)) {
                $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "CSS capitalize renders HSE as \"Hse\". Use humanizeToken():\n".implode("\n", $offenders)
        );
    }

    /** @return list<string> */
    private function jsxFiles(): array
    {
        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('js'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'jsx') {
                $found[] = $file->getPathname();
            }
        }

        return $found;
    }
}
