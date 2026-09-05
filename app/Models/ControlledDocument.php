<?php

namespace App\Models;

use App\Concerns\HasSecureDocument;

use App\Concerns\HasWorkflow;
use App\Services\NumberGeneratorService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Milestone 4, Acceleration Part 6 (Document Control Foundation). See the owning migration's own doc comment on how this differs from DocumentTemplate. */
class ControlledDocument extends Model
{
    use HasSecureDocument;
    use HasWorkflow, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_REVIEW = 'review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_EFFECTIVE = 'effective';

    public const STATUS_OBSOLETE = 'obsolete';

    protected static array $transitions = [
        self::STATUS_DRAFT => [self::STATUS_REVIEW],
        self::STATUS_REVIEW => [self::STATUS_APPROVED, self::STATUS_DRAFT],
        self::STATUS_APPROVED => [self::STATUS_EFFECTIVE],
        self::STATUS_EFFECTIVE => [self::STATUS_OBSOLETE],
        self::STATUS_OBSOLETE => [],
    ];

    protected $fillable = [
        'document_number', 'company_id', 'title', 'category', 'department_id', 'version',
        'owner_id', 'file_path', 'effective_date', 'status',
    ];

    protected $appends = ['file_url'];

    protected function casts(): array
    {
        return ['effective_date' => 'date'];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function versions()
    {
        return $this->hasMany(DocumentVersion::class)->latest('id');
    }

    protected function notificationRecipient(): ?User
    {
        return $this->owner;
    }

    public function getFileUrlAttribute(): ?string
    {
        return $this->file_path ? $this->secureDocumentUrl() : null;
    }

    public static function generateNumber(?int $companyId = null): string
    {
        return app(NumberGeneratorService::class)->generate('controlled_document', $companyId);
    }
}
