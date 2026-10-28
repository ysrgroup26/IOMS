<?php

/*
|--------------------------------------------------------------------------
| Reference checklists for equipment inspections (v2.135.0)
|--------------------------------------------------------------------------
|
| STARTING POINTS, NOT PROCEDURES. The template editor offers these as a
| list to start from; nothing is written until somebody in HSE reviews the
| items and saves a template for one of their own equipment types. Each
| list names its basis, and the editor says plainly that the company's own
| approved procedure decides the final criteria.
|
| Item fields (the same shape a saved template's `items` use):
|   label          what is checked, as the inspector reads it
|   check          pass_fail | reading | date
|                  date: the date read off the unit; one BEFORE the
|                  inspection date is recorded as Fail (expired)
|   unit           for a reading (bar, kg, ppm)
|   min / max      for a reading: outside the range is recorded as Fail
|   critical       a Fail makes the whole inspection Fail (unit Action
|                  Required); otherwise a Fail makes it Needs Action
|   photo_on_fail  a Fail on this item needs photo evidence
|
| Indonesian labels: these are read by field inspectors.
*/

return [
    'apar' => [
        'name' => 'APAR (Alat Pemadam Api Ringan)',
        'basis' => 'Disusun mengacu pada Permenakertrans No. PER.04/MEN/1980 tentang Syarat-syarat Pemasangan dan Pemeliharaan APAR. Sesuaikan dengan prosedur perusahaan dan petunjuk pabrikan.',
        'items' => [
            ['label' => 'APAR berada di lokasi yang ditetapkan, terlihat, dan mudah dijangkau', 'check' => 'pass_fail', 'critical' => true],
            ['label' => 'Tanda pemasangan APAR terpasang dan terlihat jelas', 'check' => 'pass_fail'],
            ['label' => 'Segel dan pin pengaman utuh', 'check' => 'pass_fail', 'critical' => true, 'photo_on_fail' => true],
            ['label' => 'Jarum manometer berada di area hijau (APAR bertekanan tetap)', 'check' => 'pass_fail', 'critical' => true, 'photo_on_fail' => true],
            ['label' => 'Tabung bebas karat, penyok, retak, atau kerusakan fisik lain', 'check' => 'pass_fail', 'critical' => true, 'photo_on_fail' => true],
            ['label' => 'Selang dan nozzle utuh, tidak retak atau tersumbat', 'check' => 'pass_fail', 'critical' => true, 'photo_on_fail' => true],
            ['label' => 'Label dan petunjuk penggunaan terbaca', 'check' => 'pass_fail'],
            ['label' => 'Berat isi sesuai spesifikasi (APAR CO2 ditimbang)', 'check' => 'reading', 'unit' => 'kg'],
            ['label' => 'Tanggal kedaluwarsa / isi ulang', 'check' => 'date', 'critical' => true],
            ['label' => 'Kartu pemeriksaan diperbarui', 'check' => 'pass_fail'],
        ],
    ],
    'p3k' => [
        'name' => 'Kotak P3K',
        'basis' => 'Disusun mengacu pada Permenakertrans No. PER.15/MEN/VIII/2008 tentang Pertolongan Pertama pada Kecelakaan di Tempat Kerja. Jumlah isi minimum mengikuti tipe kotak (A/B/C) pada lampiran peraturan tersebut.',
        'items' => [
            ['label' => 'Kotak P3K berada di lokasi yang ditetapkan dan mudah dijangkau', 'check' => 'pass_fail', 'critical' => true],
            ['label' => 'Tanda P3K terpasang dan terlihat jelas', 'check' => 'pass_fail'],
            ['label' => 'Kotak bersih, tidak rusak, dan dapat ditutup rapat', 'check' => 'pass_fail', 'photo_on_fail' => true],
            ['label' => 'Daftar isi kotak tertempel', 'check' => 'pass_fail'],
            ['label' => 'Isi lengkap sesuai daftar untuk tipe kotak ini', 'check' => 'pass_fail', 'critical' => true, 'photo_on_fail' => true],
            ['label' => 'Tidak ada isi yang kedaluwarsa atau kemasannya rusak', 'check' => 'pass_fail', 'critical' => true],
            ['label' => 'Nama petugas P3K yang bertanggung jawab tercantum', 'check' => 'pass_fail'],
        ],
    ],
    'gas_detector' => [
        'name' => 'Gas Detector',
        'basis' => 'Disusun mengikuti praktik umum petunjuk pabrikan (bump test dan kalibrasi berkala). Kriteria, gas uji, dan batas nilai mengikuti manual alat dan prosedur perusahaan.',
        'items' => [
            ['label' => 'Housing, layar, dan klip tidak rusak', 'check' => 'pass_fail', 'photo_on_fail' => true],
            ['label' => 'Baterai terisi dan alat menyala tanpa pesan galat', 'check' => 'pass_fail', 'critical' => true],
            ['label' => 'Pembacaan nol di udara bersih (fresh air zero)', 'check' => 'pass_fail', 'critical' => true],
            ['label' => 'Bump test dengan gas uji lulus', 'check' => 'pass_fail', 'critical' => true],
            ['label' => 'Alarm suara, lampu, dan getar berfungsi', 'check' => 'pass_fail', 'critical' => true],
            ['label' => 'Filter / saluran masuk sensor bersih', 'check' => 'pass_fail'],
            ['label' => 'Tanggal kalibrasi berikutnya', 'check' => 'date', 'critical' => true],
        ],
    ],
];
