<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * v2.52.0 -- BAST (Berita Acara Serah Terima).
 *
 * A FORMAL HANDOVER INSTRUMENT, and deliberately not a Goods Receipt.
 * Two named parties record that defined work, goods or services were
 * handed over and accepted; both sign; it is later produced as evidence
 * against a contract or a payment. Its consequence is contractual.
 *
 * A Goods Receipt, by contrast, is a warehouse transaction whose
 * consequence is a stock level. See the create_handover_records migration
 * for the full reasoning on why collapsing the two corrupts both.
 *
 * Tenant isolation flows through `company_id`, exactly as it does for
 * every other operational record — a Company row can only be reached
 * through TenantScope, so everything hanging off one inherits isolation
 * transitively.
 */
class HandoverRecord extends Model
{
    use BelongsToCompany;

    public const TYPE_WORK_COMPLETION = 'work_completion';
    public const TYPE_GOODS = 'goods';
    public const TYPE_SERVICE = 'service';
    public const TYPE_PROJECT_PHASE = 'project_phase';
    public const TYPE_ASSET = 'asset';

    public const TYPES = [
        self::TYPE_WORK_COMPLETION,
        self::TYPE_GOODS,
        self::TYPE_SERVICE,
        self::TYPE_PROJECT_PHASE,
        self::TYPE_ASSET,
    ];

    /** Human labels, in the language the document is actually written in. */
    public const TYPE_LABELS = [
        self::TYPE_WORK_COMPLETION => 'Penyelesaian Pekerjaan',
        self::TYPE_GOODS => 'Serah Terima Barang',
        self::TYPE_SERVICE => 'Serah Terima Jasa',
        self::TYPE_PROJECT_PHASE => 'Serah Terima Tahap Proyek',
        self::TYPE_ASSET => 'Serah Terima Aset',
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ISSUED = 'issued';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ISSUED, self::STATUS_ACCEPTED, self::STATUS_REJECTED];

    /**
     * What a BAST may be raised against. Restricting the morph map is a
     * security decision as much as a modelling one: without it, a crafted
     * request could point `source_type` at any Eloquent class in the
     * application.
     */
    public const SOURCE_TYPES = [
        'work_order' => WorkOrder::class,
        'purchase_order' => PurchaseOrder::class,
        'goods_receipt' => GoodsReceipt::class,
        'project' => Project::class,
    ];

    protected $fillable = [
        'bast_number', 'company_id', 'handover_type', 'title', 'handover_date',
        'first_party_name', 'first_party_position', 'first_party_organization', 'first_party_employee_id',
        'second_party_name', 'second_party_position', 'second_party_organization',
        'source_type', 'source_id', 'reference_number',
        'scope', 'acceptance_statement', 'items', 'status',
        'accepted_at', 'accepted_by', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'handover_date' => 'date',
            'accepted_at' => 'datetime',
            'items' => 'array',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function firstPartyEmployee()
    {
        return $this->belongsTo(Employee::class, 'first_party_employee_id');
    }

    public function acceptor()
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The record being handed over — a Work Order, Purchase Order, Goods Receipt or Project. */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->handover_type] ?? $this->handover_type;
    }

    /**
     * The default acceptance statement — the operative sentence of a BAST.
     *
     * Only ever a DEFAULT: the stored value wins whenever it is set,
     * because the exact wording is a commercial matter between the parties
     * and not something the software should dictate.
     */
    public function statement(): string
    {
        if ($this->acceptance_statement) {
            return $this->acceptance_statement;
        }

        return 'Pihak Pertama menyerahkan dan Pihak Kedua menerima pekerjaan/barang sebagaimana diuraikan '
            .'dalam dokumen ini dalam keadaan baik dan sesuai dengan ketentuan yang disepakati.';
    }
}
