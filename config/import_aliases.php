<?php

/**
 * v2.102.0 -- WHAT A CUSTOMER'S OWN SPREADSHEET CALLS THINGS.
 *
 * IOMS publishes an official import template, and the golden path is to
 * fill it in. The reality is that a customer already keeps a workforce
 * list, in their own words, usually in Indonesian, and retyping it into
 * our column names is the part they do not do.
 *
 * `App\Services\ImportHeaderMatcher` reads this to recognise a heading it
 * has seen before. It is a vocabulary, nothing more: a list of names that
 * mean the same field, matched after normalisation (case, spacing and
 * punctuation folded away). Everything here is a WORD A HUMAN WROTE in a
 * column heading, never a guess about the data.
 *
 * DELIBERATELY NOT AN LLM. Recognising "Nama Lengkap" as a full name is a
 * lookup, and a lookup is auditable, instant, free, offline and identical
 * every time it runs. A model would make this feature sound clever and
 * make a customer's payroll import non-deterministic. Where a lookup
 * genuinely cannot decide, the matcher falls back to string similarity and
 * then to what the column's own sample values look like -- and when it
 * still cannot decide, it says so and asks, rather than guessing.
 *
 * Adding a module: add its key with `field_key => [aliases]`. The field
 * keys must exist in `config/mapping_fields.php` for that module's import.
 */
return [

    'employees' => [

        'employee_id' => [
            'employee id', 'employeeid', 'emp id', 'id karyawan', 'kode karyawan',
            'nik', 'no induk', 'nomor induk', 'no induk karyawan', 'nomor induk karyawan',
            'npk', 'badge', 'badge no', 'badge number', 'no badge',
        ],

        /*
         * v2.108.1 -- THE NATIONAL ID, AND DELIBERATELY NOT "NIK" ALONE.
         *
         * Bare "nik" already belongs to `employee_id` above, because in an
         * Indonesian workforce list it overwhelmingly means Nomor Induk
         * KARYAWAN -- the company's own staff number. That decision is
         * already shipped and is not reversed here.
         *
         * So this field claims only the spellings that can mean nothing
         * else: the ones naming KTP or Kependudukan outright. An ambiguous
         * heading is left to the field that already owns it rather than
         * fought over, because a column silently claimed by the wrong
         * field writes a staff number into a national-ID column.
         */
        'nik' => [
            // Distinct once normalised (punctuation and case are stripped),
            // so "no. ktp" is not listed beside "no ktp" -- the matcher's
            // own collision test refuses a key claimed twice.
            'nik ktp', 'no ktp', 'nomor ktp', 'ktp',
            'nomor induk kependudukan', 'national id', 'id card number',
        ],

        /*
         * Workforce Type. "Employment Type" is included, and it is far
         * enough from "Employment Status" (the OTHER field, which owns
         * "status" outright) that the fuzzy stage cannot confuse the two
         * -- they differ by more than the 0.25 tolerance.
         */
        'employment_type' => [
            'workforce type', 'employment type', 'worker type', 'staff type',
            'jenis karyawan', 'tipe karyawan', 'jenis kepegawaian', 'tipe kepegawaian',
            'jenis pekerja', 'jenis kontrak', 'tipe kontrak',
        ],

        'full_name' => [
            'full name', 'fullname', 'name', 'employee name', 'nama', 'nama lengkap',
            'nama karyawan', 'nama pegawai', 'nama pekerja',
        ],

        'department' => [
            'department', 'dept', 'departemen', 'departement', 'bagian', 'divisi',
            'division', 'unit kerja',
        ],

        'position' => [
            'position', 'job title', 'title', 'jabatan', 'posisi', 'job position',
            'designation', 'role', 'pekerjaan',
        ],

        'join_date' => [
            'join date', 'joined', 'date joined', 'hire date', 'start date',
            'tanggal masuk', 'tgl masuk', 'tanggal bergabung', 'tgl bergabung',
            'tanggal mulai kerja', 'mulai kerja',
        ],

        'employment_status' => [
            'employment status', 'status', 'status karyawan', 'status kepegawaian',
            'status kerja', 'employee status',
        ],

        'phone' => [
            'phone', 'phone number', 'mobile', 'mobile number', 'hp', 'no hp',
            'nomor hp', 'telepon', 'no telepon', 'nomor telepon', 'telp', 'no telp',
            'kontak', 'whatsapp', 'no wa',
        ],

        'email' => [
            'email', 'e mail', 'email address', 'alamat email', 'surel',
        ],

        'address' => [
            'address', 'alamat', 'alamat rumah', 'domisili', 'alamat domisili',
        ],

        'emergency_contact_name' => [
            'emergency contact name', 'emergency contact', 'kontak darurat',
            'nama kontak darurat', 'nama darurat', 'dihubungi darurat',
        ],

        'emergency_contact_phone' => [
            'emergency contact phone', 'emergency phone', 'no kontak darurat',
            'nomor kontak darurat', 'telepon darurat', 'hp darurat',
        ],

        'project' => [
            'project', 'proyek', 'nama proyek', 'project name', 'site', 'lokasi proyek',
        ],

    ],

];
