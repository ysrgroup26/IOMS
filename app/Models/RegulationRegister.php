<?php

namespace App\Models;

use App\Concerns\HasSecureDocument;
use Illuminate\Database\Eloquent\Model;

/**
 * v2.52.0 -- one entry in the Regulations & Standards Register.
 *
 * The legal and normative requirements a Health, Safety & Environment
 * function must identify, keep current, and evidence compliance against.
 *
 * `category` and `document_type` are strings backed by suggestion lists
 * rather than enums, on purpose: a provincial regulation, a
 * pressure-vessel rule, an ISO standard and an internal company standard
 * all have to fit, and an enum would mean a code change every time a
 * customer meets a requirement nobody anticipated. The suggestions below
 * are starting points for the UI, never a closed set.
 */
class RegulationRegister extends Model
{
    // The attachment is served through SecureDocumentController: authorized,
    // tenant-checked, and never a public URL.
    use HasSecureDocument;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_DRAFT = 'draft';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_SUPERSEDED, self::STATUS_REVOKED, self::STATUS_DRAFT];

    /** Suggested categories. Free text underneath — a tenant may add its own. */
    public const CATEGORIES = [
        'Occupational Safety',
        'Occupational Health',
        'Workplace Environment',
        'Environmental Protection',
        'B3 / Hazardous Materials',
        'Fire Protection',
        'Lifting Equipment',
        'Machinery',
        'Electrical Safety',
        'Pressure Equipment',
        'Construction',
        'Mining',
        'Oil & Gas',
        'Marine / Shipyard',
        'Management System',
        'Internal Standard',
    ];

    /** Suggested document types, covering Indonesian instruments and international standards. */
    public const DOCUMENT_TYPES = [
        'Undang-Undang',
        'Peraturan Pemerintah',
        'Peraturan Presiden',
        'Peraturan Menteri',
        'Keputusan Menteri',
        'Peraturan Daerah',
        'SNI',
        'ISO',
        'OHSAS',
        'API / ASME / ANSI',
        'Internal Standard',
        'Client Requirement',
    ];

    protected $fillable = [
        'register_number', 'company_id', 'category', 'document_type',
        'regulation_number', 'year', 'title', 'issuing_authority',
        'status', 'superseded_by', 'effective_date', 'review_date',
        'applicability', 'scope', 'owner_employee_id', 'source_reference',
        'document_path', 'document_name', 'compliance_reference', 'notes', 'created_by',
    ];

    /**
     * `document_path` is a private-disk path. Hidden so it can never leak
     * into an Inertia prop or an API response — the file is reachable only
     * through the authorized download route.
     */
    protected $hidden = ['document_path'];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'review_date' => 'date',
            'year' => 'integer',
        ];
    }

    /** The register stores its file in `document_path`, not the trait default `file_path`. */
    public function secureDocumentPathColumn(): string
    {
        return 'document_path';
    }

    public function secureDocumentName(): ?string
    {
        return $this->document_name ?: ($this->secureDocumentPath() ? basename($this->secureDocumentPath()) : null);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function owner()
    {
        return $this->belongsTo(Employee::class, 'owner_employee_id');
    }

    public function supersededBy()
    {
        return $this->belongsTo(self::class, 'superseded_by');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Due for review. This is what makes the register a living document
     * rather than a list typed once and forgotten — a regulation whose
     * review date has passed may well have been superseded without anyone
     * noticing.
     */
    public function isDueForReview(): bool
    {
        return $this->review_date !== null
            && $this->status === self::STATUS_ACTIVE
            && $this->review_date->isPast();
    }

    /** "Permenaker No. 5 Tahun 2018" — the way a register entry is actually cited. */
    public function citation(): string
    {
        return trim(implode(' ', array_filter([
            $this->document_type,
            $this->regulation_number ? 'No. '.$this->regulation_number : null,
            $this->year ? 'Tahun '.$this->year : null,
        ])));
    }
}
