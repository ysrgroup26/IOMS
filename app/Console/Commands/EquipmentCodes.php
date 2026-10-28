<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\SafetyEquipment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * v2.135.0 -- SHORTEN EXISTING EQUIPMENT IDS, AFTER PROVING IT IS SAFE.
 *
 *   php artisan ioms:equipment-codes            READ-ONLY: what would change, and every clash
 *   php artisan ioms:equipment-codes --apply    rewrite APAR-00001 -> APAR-01 (refused on any clash)
 *
 * New units are numbered PREFIX-NN since v2.135.0; units registered before
 * keep PREFIX-00001 until this runs. Only `equipment_code` changes -- a
 * label, not a key: every relation (inspections, QR links, activity) points
 * at the numeric id, so nothing else moves. Codes that are not
 * PREFIX-number (a hand-typed serial) are left alone.
 *
 * It refuses to apply when, within one operating unit, two codes share a
 * NUMBER (APAR-1 and APAR-00001) or a shortened code is already taken: a
 * person must decide which unit keeps it. Each change is written to the
 * unit's audit trail. A printed label still shows the old code, so the
 * list it prints is also the reprint list.
 */
class EquipmentCodes extends Command
{
    protected $signature = 'ioms:equipment-codes {--apply : Rewrite the codes (refused while any clash exists)}';

    protected $description = 'Audit equipment IDs for duplicate numbers and shorten PREFIX-00001 to PREFIX-01';

    public function handle(): int
    {
        $units = SafetyEquipment::withoutGlobalScopes()
            ->whereNotNull('equipment_code')
            ->orderBy('company_id')->orderBy('id')
            ->get(['id', 'company_id', 'equipment_code', 'name']);

        $changes = [];
        $clashes = [];

        foreach ($units->groupBy('company_id') as $companyId => $company) {
            // Two existing codes with the same number are a clash already.
            foreach ($company->groupBy(fn ($u) => SafetyEquipment::codeKey($u->equipment_code)) as $key => $same) {
                if ($same->count() > 1) {
                    $clashes[] = [$companyId, $same->pluck('equipment_code')->implode(', '), 'same number used more than once'];
                }
            }

            $taken = $company->pluck('equipment_code')->map(fn ($c) => strtoupper($c))->all();
            foreach ($company as $unit) {
                if (! preg_match('/^([A-Z0-9]+)-(\d+)$/', strtoupper($unit->equipment_code), $m)) {
                    continue; // not PREFIX-number: left as typed
                }
                $target = SafetyEquipment::compactCode($m[1], (int) $m[2]);
                if ($target === strtoupper($unit->equipment_code)) {
                    continue;
                }
                if (in_array($target, $taken, true)) {
                    $clashes[] = [$companyId, "{$unit->equipment_code} -> {$target}", 'target already in use'];

                    continue;
                }
                $changes[] = ['unit' => $unit, 'from' => $unit->equipment_code, 'to' => $target];
            }
        }

        $this->info(sprintf('%d unit(s) with an ID; %d would be shortened; %d clash(es).', $units->count(), count($changes), count($clashes)));
        if ($changes !== []) {
            $this->table(['Operating unit', 'Unit', 'Current ID', 'New ID'], array_map(
                fn ($c) => [$c['unit']->company_id, $c['unit']->name, $c['from'], $c['to']], $changes));
        }
        if ($clashes !== []) {
            $this->error('Clashes -- decide which unit keeps the number, then re-run:');
            $this->table(['Operating unit', 'Codes', 'Problem'], $clashes);
        }

        if (! $this->option('apply')) {
            $this->line('Read-only. Nothing was changed. Re-run with --apply to rewrite.');

            return $clashes === [] ? self::SUCCESS : self::FAILURE;
        }
        if ($clashes !== []) {
            $this->error('Refused: --apply does nothing while any clash exists.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($changes) {
            foreach ($changes as $c) {
                $c['unit']->forceFill(['equipment_code' => $c['to']])->save();
                ActivityLog::record('updated', "Equipment ID shortened from {$c['from']} to {$c['to']}.", $c['unit']);
            }
        });
        $this->info(sprintf('%d ID(s) shortened. Reprint the labels of the units listed above.', count($changes)));

        return self::SUCCESS;
    }
}
