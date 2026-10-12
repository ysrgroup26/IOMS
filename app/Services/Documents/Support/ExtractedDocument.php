<?php

namespace App\Services\Documents\Support;

/**
 * v2.110.0 -- everything one uploaded file yielded.
 *
 * Tables are the part an import consumes. `text` is kept because a Word
 * or PDF document often carries meaning OUTSIDE its tables (a permit
 * number in a heading, a date in a paragraph) and discarding it would be
 * exactly the "silently discard meaningful information" failure the
 * platform is supposed to avoid.
 */
final class ExtractedDocument
{
    /**
     * @param  list<ExtractedTable>  $tables
     * @param  list<string>  $warnings  structures the extractor could not interpret
     */
    public function __construct(
        public readonly string $format,
        public readonly array $tables,
        public readonly string $text = '',
        public readonly array $warnings = [],
        public readonly array $meta = [],
    ) {}

    public function hasTables(): bool
    {
        return $this->tables !== [];
    }

    /** The table most likely to be the one the user meant to import. */
    public function primaryTable(): ?ExtractedTable
    {
        if (! $this->hasTables()) {
            return null;
        }

        // Most confident first; ties broken by size, because a 200-row
        // table is a register and a 2-row one is usually a header block.
        $sorted = $this->tables;
        usort($sorted, function (ExtractedTable $a, ExtractedTable $b) {
            return [$b->confidence, $b->rowCount()] <=> [$a->confidence, $a->rowCount()];
        });

        return $sorted[0];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'format' => $this->format,
            'tables' => array_map(fn (ExtractedTable $t) => $t->toArray(), $this->tables),
            'has_text' => $this->text !== '',
            'warnings' => $this->warnings,
            'meta' => $this->meta,
        ];
    }
}
