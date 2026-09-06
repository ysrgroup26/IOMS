<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\RegulationRegister;
use Illuminate\Database\Seeder;

/**
 * v2.52.0 -- a STARTING POINT for the Regulations & Standards Register.
 *
 * These are widely-cited Indonesian occupational-safety instruments that
 * most industrial employers will find applicable. They are seeded as a
 * head start so a new customer does not face an empty register, NOT as a
 * statement of what any particular company must comply with.
 *
 * DELIBERATELY NOT RUN BY DatabaseSeeder. Applicability is a legal
 * judgement that belongs to the customer's own HSE function, and quietly
 * inserting a compliance register nobody reviewed would be worse than an
 * empty one — it would look reviewed. Run it explicitly, per tenant:
 *
 *     php artisan db:seed --class=RegulationReferenceSeeder
 *
 * Every entry is created with `review_date` set, so the register
 * immediately behaves as a living document: each one surfaces under "due
 * for review" and has to be confirmed by a human before it can be relied
 * on. `applicability` is left blank on purpose — only the customer can
 * say which of their operations a rule binds.
 *
 * Regulations change. Nothing here is presented as current or complete,
 * and the register is built to be maintained, superseded and extended.
 */
class RegulationReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->first();

        if (! $company) {
            $this->command?->warn('No company in the current tenant context — nothing seeded.');

            return;
        }

        $references = [
            [
                'category' => 'Occupational Safety',
                'document_type' => 'Undang-Undang',
                'regulation_number' => '1',
                'year' => 1970,
                'title' => 'Keselamatan Kerja',
                'issuing_authority' => 'Pemerintah Republik Indonesia',
            ],
            [
                'category' => 'Occupational Safety',
                'document_type' => 'Undang-Undang',
                'regulation_number' => '13',
                'year' => 2003,
                'title' => 'Ketenagakerjaan',
                'issuing_authority' => 'Pemerintah Republik Indonesia',
            ],
            [
                'category' => 'Management System',
                'document_type' => 'Peraturan Pemerintah',
                'regulation_number' => '50',
                'year' => 2012,
                'title' => 'Penerapan Sistem Manajemen Keselamatan dan Kesehatan Kerja (SMK3)',
                'issuing_authority' => 'Pemerintah Republik Indonesia',
            ],
            [
                'category' => 'Workplace Environment',
                'document_type' => 'Peraturan Menteri',
                'regulation_number' => '5',
                'year' => 2018,
                'title' => 'Keselamatan dan Kesehatan Kerja Lingkungan Kerja',
                'issuing_authority' => 'Kementerian Ketenagakerjaan',
            ],
            [
                'category' => 'Lifting Equipment',
                'document_type' => 'Peraturan Menteri',
                'regulation_number' => '8',
                'year' => 2020,
                'title' => 'Keselamatan dan Kesehatan Kerja Pesawat Angkat dan Pesawat Angkut',
                'issuing_authority' => 'Kementerian Ketenagakerjaan',
            ],
            [
                'category' => 'Fire Protection',
                'document_type' => 'Keputusan Menteri',
                'regulation_number' => 'KEP-186/MEN/1999',
                'year' => 1999,
                'title' => 'Unit Penanggulangan Kebakaran di Tempat Kerja',
                'issuing_authority' => 'Kementerian Tenaga Kerja',
            ],
            [
                'category' => 'Occupational Health',
                'document_type' => 'Peraturan Menteri',
                'regulation_number' => 'Per-15/MEN/VIII/2008',
                'year' => 2008,
                'title' => 'Pertolongan Pertama Pada Kecelakaan di Tempat Kerja',
                'issuing_authority' => 'Kementerian Tenaga Kerja dan Transmigrasi',
            ],
            [
                'category' => 'Management System',
                'document_type' => 'ISO',
                'regulation_number' => '45001',
                'year' => 2018,
                'title' => 'Occupational Health and Safety Management Systems',
                'issuing_authority' => 'International Organization for Standardization',
            ],
        ];

        foreach ($references as $reference) {
            RegulationRegister::firstOrCreate(
                [
                    'company_id' => $company->id,
                    'document_type' => $reference['document_type'],
                    'regulation_number' => $reference['regulation_number'],
                    'year' => $reference['year'],
                ],
                [
                    ...$reference,
                    'status' => RegulationRegister::STATUS_ACTIVE,
                    // Every seeded entry starts due for review, so a human
                    // confirms applicability before anyone relies on it.
                    'review_date' => now()->toDateString(),
                    'notes' => 'Referensi awal dari IOMS. Tinjau keberlakuannya untuk operasi perusahaan Anda, '
                        .'lengkapi ruang lingkup dan bukti kepatuhan, lalu perbarui tanggal tinjau ulang.',
                ]
            );
        }

        $this->command?->info('Seeded '.count($references).' reference entries into the Regulations & Standards Register.');
    }
}
