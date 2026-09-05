<?php

namespace App\Support;

use App\Models\Asset;
use App\Models\ContractorDocument;
use App\Models\ControlledDocument;
use App\Models\DailyReportPhoto;
use App\Models\DocumentVersion;
use App\Models\InspectionEvidence;
use App\Models\Item;
use App\Models\MaterialRequestItem;
use App\Models\PpeReplacementRequestItem;
use App\Models\SafetyObservationPhoto;
use App\Models\VendorDocument;
use App\Models\VendorQuotation;
use App\Models\WasteMovementDocument;

/**
 * v2.38.0 (Master Audit, P1) -- the single source of truth for which
 * models are reachable through `SecureDocumentController`.
 *
 * WHY THIS EXISTS AS ITS OWN CLASS. The first iteration of secure
 * document delivery put a closure per document type inside the
 * controller. That was safe but did not scale: this audit confirmed 13
 * models still emitting public storage URLs, and wiring each of them
 * would have meant 13 hand-written closures, each restating an ownership
 * chain that the model already knows how to express through its own
 * relations. That is precisely the "repeated module-specific
 * implementation" this codebase tries to avoid (see HasApprovals /
 * HasWorkflow in app/Concerns for the established alternative).
 *
 * Ownership resolution now lives on the model, next to its relations,
 * via `App\Concerns\HasSecureDocument`. This class only answers "which
 * type key maps to which model" -- deliberately an explicit, hardcoded
 * whitelist rather than anything derived from user input, so a request
 * can never name an arbitrary class. That property is the security
 * boundary and must be preserved by anyone adding a type here.
 *
 * To secure a new document model:
 *   1. `use HasSecureDocument;` on the model.
 *   2. Override the path column / owner resolution if it isn't the
 *      default (`file_path`, `$this->company_id`).
 *   3. Point its URL accessor at `$this->secureDocumentUrl()`.
 *   4. Add one line here.
 * `SecureDocumentContractTest` then verifies the wiring automatically.
 */
class SecureDocumentRegistry
{
    /** type key (appears in the URL) => model class. */
    public const TYPES = [
        'contractor-document' => ContractorDocument::class,
        'waste-movement-document' => WasteMovementDocument::class,
        'controlled-document' => ControlledDocument::class,
        'document-version' => DocumentVersion::class,
        'vendor-document' => VendorDocument::class,
        'vendor-quotation' => VendorQuotation::class,
        'safety-observation-photo' => SafetyObservationPhoto::class,
        'inspection-evidence' => InspectionEvidence::class,
        'daily-report-photo' => DailyReportPhoto::class,
        'material-request-item' => MaterialRequestItem::class,
        'ppe-replacement-item' => PpeReplacementRequestItem::class,
        'asset-attachment' => Asset::class,
        'item-attachment' => Item::class,
    ];

    public static function classFor(string $type): ?string
    {
        return self::TYPES[$type] ?? null;
    }

    /** Reverse lookup used by the trait's URL accessor -- keeps one source of truth. */
    public static function typeFor(string $modelClass): ?string
    {
        $type = array_search($modelClass, self::TYPES, true);

        return $type === false ? null : $type;
    }
}
