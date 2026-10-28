<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** v1.11.1. Real inspection history for a SafetyEquipment row -- see the owning migration's own doc comment (mirrors GasTestRecord's own "child table, individually meaningful" reasoning). */
class SafetyEquipmentInspection extends Model
{
    use BelongsToCompany;

    public const CONDITIONS = ['good', 'fair', 'poor', 'damaged'];

    public const RESULTS = ['pass', 'fail', 'needs_action'];

    /** v2.135.0 -- how serious what was found is; required when the result is not Pass. */
    public const RISK_LEVELS = ['low', 'medium', 'high', 'critical'];

    /** v2.135.0 -- the kinds of check a checklist item can be. */
    public const CHECKS = ['pass_fail', 'reading', 'date'];

    protected $fillable = [
        'safety_equipment_id', 'company_id', 'inspection_date', 'inspector_id',
        'condition', 'result', 'findings', 'next_inspection_due', 'notes',
        'checklist_template_id', 'checklist', 'risk_level', 'photos',
    ];

    protected function casts(): array
    {
        return [
            'inspection_date' => 'date',
            'next_inspection_due' => 'date',
            'checklist' => 'array',
            'photos' => 'array',
        ];
    }

    /**
     * v2.135.0 -- JUDGE A CHECKLIST. Pure: items as the template reads today,
     * answers as the inspector gave them, the inspection date. Returns the
     * filed rows (each item with its answer and the outcome the SERVER
     * decided) and the overall result.
     *
     * The inspector does not choose Pass/Fail for a reading or a date: a
     * reading outside min..max fails, and a date before the inspection date
     * fails (an APAR past its expiry is expired however good it looks). An
     * N/A is allowed only on a non-critical item. Any critical Fail makes
     * the whole inspection Fail; any other Fail makes it Needs Action. A
     * Damaged condition is never Pass, whatever the list says.
     *
     * @return array{rows: array<int, array>, result: string, errors: array<string, string>}
     */
    public static function judge(array $items, array $answers, string $inspectionDate, string $condition): array
    {
        $rows = [];
        $errors = [];
        $criticalFail = false;
        $anyFail = false;

        foreach (array_values($items) as $i => $item) {
            $check = in_array($item['check'] ?? 'pass_fail', self::CHECKS, true) ? ($item['check'] ?? 'pass_fail') : 'pass_fail';
            $critical = (bool) ($item['critical'] ?? false);
            $answer = $answers[$i] ?? [];
            $value = isset($answer['value']) ? trim((string) $answer['value']) : '';
            $outcome = $answer['result'] ?? null;

            if ($check === 'reading' && $outcome !== 'na') {
                if ($value === '' || ! is_numeric($value)) {
                    $errors["checklist.{$i}.value"] = 'Enter the reading.';
                } else {
                    $n = (float) $value;
                    $outside = (isset($item['min']) && $item['min'] !== null && $item['min'] !== '' && $n < (float) $item['min'])
                        || (isset($item['max']) && $item['max'] !== null && $item['max'] !== '' && $n > (float) $item['max']);
                    $outcome = $outside ? 'fail' : ($outcome === 'fail' ? 'fail' : 'pass');
                }
            } elseif ($check === 'date' && $outcome !== 'na') {
                $date = $value !== '' ? strtotime($value) : false;
                if ($date === false) {
                    $errors["checklist.{$i}.value"] = 'Enter the date shown on the unit.';
                } else {
                    $outcome = date('Y-m-d', $date) < $inspectionDate ? 'fail' : 'pass';
                    $value = date('Y-m-d', $date);
                }
            } elseif (! in_array($outcome, ['pass', 'fail', 'na'], true)) {
                $errors["checklist.{$i}.result"] = 'Choose Pass, Fail or N/A.';
            }

            if ($outcome === 'na' && $critical) {
                $errors["checklist.{$i}.result"] = 'This item is critical and cannot be N/A.';
            }

            if ($outcome === 'fail') {
                $anyFail = true;
                $criticalFail = $criticalFail || $critical;
            }

            $rows[] = [
                'label' => (string) ($item['label'] ?? ''),
                'check' => $check,
                'unit' => $item['unit'] ?? null,
                'critical' => $critical,
                'photo_on_fail' => (bool) ($item['photo_on_fail'] ?? false),
                'result' => $outcome,
                'value' => $value !== '' ? $value : null,
                'remark' => isset($answer['remark']) ? mb_substr(trim((string) $answer['remark']), 0, 500) ?: null : null,
            ];
        }

        $result = $criticalFail ? 'fail' : ($anyFail ? 'needs_action' : 'pass');
        if ($condition === 'damaged' && $result === 'pass') {
            $result = 'needs_action';
        }

        return ['rows' => $rows, 'result' => $result, 'errors' => $errors];
    }

    public function correctiveActions()
    {
        return $this->morphMany(CorrectiveAction::class, 'source');
    }

    public function safetyEquipment()
    {
        return $this->belongsTo(SafetyEquipment::class);
    }

    public function inspector()
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }
}
