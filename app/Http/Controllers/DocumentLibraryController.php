<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Services\Documents\DocumentIngestionService;
use App\Services\Documents\Library\DocumentLibrary;
use App\Services\Documents\Library\DocumentRenderer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * v2.110.0 -- ONE SURFACE FOR EVERY GENERATED DOCUMENT.
 *
 * IOMS could already produce documents, but only from inside the module
 * that owned the data, one hardcoded route at a time. This controller is
 * the other half of the document platform: the registry
 * (`DocumentLibrary`) decides WHAT exists, the renderer
 * (`DocumentRenderer`) decides how it becomes a file, and this class does
 * nothing but authorize, resolve and hand over.
 *
 * AUTHORIZATION IS THE DEFINITION'S, NOT THIS CLASS'S. `resolveFor()`
 * fails closed on both the key and the user, and reports an unauthorized
 * key as not-found so the library does not confirm the existence of
 * documents a user may not see. There is deliberately no second gate
 * here: a rule in two places is a rule that will disagree with itself.
 *
 * TENANT ISOLATION comes from the definitions' own queries, every one of
 * which is anchored on `Company::query()` -- already tenant-scoped by
 * CompanyScope. No document can be rendered across tenants without first
 * breaking that scope, which the existing isolation tests pin.
 */
class DocumentLibraryController extends Controller
{
    public function __construct(
        private readonly DocumentLibrary $library,
        private readonly DocumentRenderer $renderer,
    ) {}

    public function index(Request $request, DocumentIngestionService $ingestion): Response
    {
        return Inertia::render('Documents/Library', [
            'documents' => $this->library->catalogue($request->user()),
            // What this DEPLOYMENT can actually read, reported honestly:
            // a format with no working extractor is shown as unavailable
            // with its reason, never hidden and never implied to work.
            'capabilities' => $ingestion->capabilities(),
            'formats' => DocumentRenderer::FORMATS,
        ]);
    }

    public function export(Request $request, string $key, string $format)
    {
        try {
            $definition = $this->library->resolveFor($request->user(), $key);
        } catch (\RuntimeException) {
            /*
             * 404, not 403, and not a 500. An unknown key and a key this
             * user may not read are answered IDENTICALLY on purpose: a
             * distinguishable refusal would let someone enumerate the
             * documents other roles can see.
             */
            abort(404);
        }

        // Only the filters this document declares; anything else is
        // ignored rather than passed into a query.
        $filters = array_filter(
            $request->query(),
            fn ($value) => $value !== null && $value !== ''
        );

        ActivityLog::record('exported', "Generated the [{$key}] document as ".strtoupper($format).'.');

        return $this->renderer->render($definition, $format, $filters);
    }

    /**
     * Reads an uploaded document and reports what is in it. WRITES
     * NOTHING, by construction -- there is no model, no persistence and
     * no file retained; the upload lives only for the length of the
     * request.
     *
     * This is the module-agnostic counterpart of the employee import's
     * own inspect step: the same engine, asked about whichever module the
     * caller names.
     */
    public function inspect(Request $request, DocumentIngestionService $ingestion)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt,docx,pdf', 'max:'.((int) config('documents.max_upload_mb') * 1024)],
            'module' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            return response()->json(
                $ingestion->inspect($request->file('file'), $request->input('module', 'employees'))
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
