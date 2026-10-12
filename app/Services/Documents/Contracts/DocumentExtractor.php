<?php

namespace App\Services\Documents\Contracts;

use App\Services\Documents\Support\ExtractedDocument;

/**
 * v2.110.0 -- the one thing every format adapter must do.
 *
 * Narrow on purpose. An extractor opens a file and returns what it found;
 * it does not map fields, apply tenant rules, validate business data or
 * write anything. Those stay inside IOMS, which is what lets an engine be
 * replaced without touching business logic.
 */
interface DocumentExtractor
{
    /** Formats (FormatDetector constants) this extractor handles. */
    public function supports(): array;

    /** True when this extractor can actually run on this deployment. */
    public function isAvailable(): bool;

    /** Why it cannot run, when `isAvailable()` is false. */
    public function unavailableReason(): ?string;

    public function extract(string $path): ExtractedDocument;
}
