<?php

namespace App\Services\Documents\Library;

use App\Models\User;

/**
 * v2.110.0 -- ONE DOCUMENT, DESCRIBED ONCE, RENDERED IN THREE FORMATS.
 *
 * Before this interface, every printable list in IOMS was a one-off: the
 * Employee Register had an Excel export class, a Blade view and two
 * controller methods, and the next register that wanted the same thing
 * had to grow its own set. The formats drifted -- the Excel export and
 * the PDF of the SAME list were written months apart from different
 * queries -- which is the failure mode the project's own CLAUDE.md warns
 * about ("shared, reusable engines rather than per-module one-offs").
 *
 * A definition says WHAT the document is: its columns, its rows, its
 * reference block, and who may read it. It says nothing about XLSX, DOCX
 * or PDF, because the format is the renderer's business
 * (`DocumentRenderer`). One query therefore backs all three outputs, and
 * they cannot disagree.
 *
 * TENANT SCOPING IS THE DEFINITION'S OWN JOB, deliberately. A definition
 * returns rows; nothing downstream filters them again. Putting the scope
 * anywhere else would mean a renderer deciding what a tenant may see,
 * and the rule would then live in two places.
 */
interface DocumentDefinition
{
    /** Stable url key, e.g. `employee-register`. */
    public function key(): string;

    /** English, because it is a document title (see CLAUDE.md's language hierarchy). */
    public function title(): string;

    /** Indonesian, because it is help text. */
    public function description(): string;

    /** A workspace key from config/workspaces, for grouping in the library. */
    public function workspace(): string;

    /**
     * Column definitions, in the shape BrandedWorkbook consumes.
     *
     * @return list<array{key: string, label: string, type?: string, width?: int}>
     */
    public function columns(): array;

    /**
     * The rows, already tenant-scoped.
     *
     * @param  array<string, mixed>  $filters
     * @return iterable<array<string, mixed>>
     */
    public function rows(array $filters = []): iterable;

    /**
     * The reference lines printed under the document title (label =>
     * value). Used to state the filters in force, so a filtered document
     * can never be mistaken for a complete one.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, string|null>
     */
    public function refs(array $filters = []): array;

    /** May this user read this document at all? */
    public function authorize(User $user): bool;

    /** @return list<string> a subset of DocumentRenderer::FORMATS */
    public function formats(): array;
}
