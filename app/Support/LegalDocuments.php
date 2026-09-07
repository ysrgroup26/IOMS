<?php

namespace App\Support;

/**
 * v2.55.0 -- THE PUBLIC POLICY DOCUMENTS, IN ONE PLACE.
 *
 * Terms of Service, Privacy Policy and Refund & Cancellation Policy, built
 * server-side as structured sections rather than written into JSX.
 *
 * WHY SERVER-SIDE. Three reasons, all of them practical:
 *
 *  1. These documents must state the operator's registered name, address and
 *     governing law -- facts that live in configuration and that IOMS does
 *     not have yet. Building them here means a clause whose fact is missing
 *     can be OMITTED ENTIRELY rather than rendered with a placeholder that
 *     reads like a statement. See `paragraphs()`.
 *  2. Prices, plan capacities and mailbox addresses already have one source
 *     of truth. A policy that restates them from memory is a policy that
 *     goes stale the next time a price changes.
 *  3. One renderer (`Public/LegalDocument.jsx`) then serves all three, so
 *     they cannot drift apart typographically.
 *
 * WHAT THIS IS NOT. It is not legal advice, and it is not a copy of anyone
 * else's document. The structure follows the concepts a digital-goods /
 * SaaS merchant agreement is normally expected to cover -- use, licence,
 * fees, term, IP, confidentiality, security, warranties, liability,
 * governing law -- with the substance written for what IOMS actually is: a
 * standardized business-to-business subscription platform for industrial
 * operations, sold in IDR, delivered over the web, with payment handled by
 * a licensed Indonesian payment provider.
 *
 * NOTHING HERE IS INVENTED. Where a fact is unavailable -- the registered
 * entity, a business address, a jurisdiction -- the corresponding sentence
 * does not appear at all. Have the operator set `config('ioms.legal')` and
 * the clauses complete themselves.
 */
class LegalDocuments
{
    /**
     * Drops any paragraph that still contains an unresolved placeholder.
     *
     * A clause is written with `:token` markers; if a token has no value the
     * whole paragraph is discarded rather than printed with a gap. That is
     * the mechanism that keeps this class honest: an absent legal entity
     * cannot become "the Operator (  )" on a live page.
     */
    private static function paragraphs(array $paragraphs, array $replacements): array
    {
        $resolved = [];

        foreach ($paragraphs as $paragraph) {
            $text = $paragraph;
            $missing = false;

            foreach ($replacements as $token => $value) {
                if (! str_contains($text, ':'.$token)) {
                    continue;
                }

                if (blank($value)) {
                    $missing = true;
                    break;
                }

                $text = str_replace(':'.$token, (string) $value, $text);
            }

            if (! $missing) {
                $resolved[] = $text;
            }
        }

        return $resolved;
    }

    private static function tokens(): array
    {
        $legal = config('ioms.legal');
        $emails = config('ioms.emails');

        return [
            'entity' => $legal['entity_name'] ?? null,
            'address' => $legal['address'] ?? null,
            'jurisdiction' => $legal['jurisdiction'] ?? null,
            'venue' => $legal['venue'] ?? null,
            'support' => $emails['support'] ?? null,
            'billing' => $emails['billing'] ?? null,
            'hello' => $emails['hello'] ?? null,
            'website' => config('ioms.website'),
        ];
    }

    /** The line naming who operates IOMS -- omitted entirely when the entity is not configured. */
    public static function operator(): ?string
    {
        return config('ioms.legal.entity_name') ?: null;
    }

    public static function effectiveDate(): ?string
    {
        return config('ioms.legal.effective_date') ?: null;
    }

    public static function terms(): array
    {
        $t = self::tokens();

        return [
            [
                'heading' => '1. Tentang dokumen ini',
                'body' => self::paragraphs([
                    'Syarat & Ketentuan ini mengatur penggunaan IOMS — Industrial Operations Platform ("IOMS", "Layanan"), sebuah platform perangkat lunak berbasis langganan yang diakses melalui internet. Dokumen ini berlaku antara IOMS sebagai penyedia layanan dan organisasi yang berlangganan ("Pelanggan").',
                    'IOMS dioperasikan oleh :entity.',
                    'Alamat korespondensi resmi: :address.',
                    'Dengan membuat akun, melakukan pembayaran, atau menggunakan Layanan, Pelanggan menyatakan telah membaca, memahami, dan terikat pada Syarat & Ketentuan ini. Apabila Pelanggan tidak menyetujuinya, Pelanggan tidak boleh menggunakan Layanan.',
                    'IOMS ditujukan untuk penggunaan bisnis oleh organisasi. Layanan ini bukan produk konsumen dan tidak ditujukan untuk penggunaan pribadi.',
                ], $t),
            ],
            [
                'heading' => '2. Perubahan Layanan dan Syarat & Ketentuan',
                'body' => self::paragraphs([
                    'IOMS adalah satu produk standar yang terus dikembangkan untuk seluruh pelanggan. Fitur dapat ditambahkan, disempurnakan, atau diubah dari waktu ke waktu sebagai bagian dari pengembangan produk yang normal.',
                    'Syarat & Ketentuan ini dapat diperbarui. Versi yang berlaku selalu dipublikasikan pada halaman ini beserta tanggal berlakunya. Perubahan yang secara material merugikan Pelanggan akan diberitahukan melalui email ke kontak yang terdaftar sebelum berlaku.',
                    'Kelanjutan penggunaan Layanan setelah tanggal berlaku suatu perubahan dianggap sebagai penerimaan atas perubahan tersebut.',
                ], $t),
            ],
            [
                'heading' => '3. Akun dan pendaftaran',
                'body' => self::paragraphs([
                    'Pelanggan mendaftar dengan memberikan informasi organisasi dan kontak yang benar dan mutakhir, serta mengonfirmasi alamat email yang digunakan. Konfirmasi email merupakan syarat sebelum langganan dapat diaktifkan.',
                    'Setiap akun pengguna bersifat perorangan. Kredensial tidak boleh dibagikan antar individu. Pelanggan bertanggung jawab atas seluruh aktivitas yang terjadi melalui akun-akun dalam organisasinya, termasuk pemberian dan pencabutan akses oleh administrator Pelanggan sendiri.',
                    'Pelanggan wajib segera memberitahukan kepada kami apabila mengetahui adanya penggunaan akun tanpa izin.',
                ], $t),
            ],
            [
                'heading' => '4. Hak akses dan lisensi',
                'body' => self::paragraphs([
                    'Selama langganan aktif dan biaya yang terutang telah dibayar, Pelanggan memperoleh hak akses yang terbatas, tidak eksklusif, tidak dapat dialihkan, dan tidak dapat disublisensikan untuk menggunakan IOMS bagi keperluan operasional internal organisasinya.',
                    'IOMS diberikan sebagai layanan (software as a service). Tidak ada perangkat lunak yang dijual, diserahkan, atau dilisensikan secara permanen kepada Pelanggan, dan tidak ada paket seumur hidup.',
                    'Cakupan akses ditentukan oleh paket langganan yang dipilih, termasuk jumlah akun pengguna dan jumlah Operating Unit yang tercakup di dalamnya.',
                ], $t),
            ],
            [
                'heading' => '5. Penggunaan yang dilarang',
                'body' => self::paragraphs([
                    'Pelanggan tidak diperbolehkan menjual kembali, menyewakan, atau menyediakan Layanan kepada pihak ketiga di luar organisasinya; melakukan rekayasa balik atau berupaya memperoleh kode sumber Layanan; mengakses atau mencoba mengakses data pelanggan lain; melakukan pengujian keamanan tanpa izin tertulis; atau menggunakan Layanan untuk tujuan yang melanggar hukum yang berlaku.',
                    'Pelanggan juga tidak diperbolehkan mengunggah konten yang melanggar hak pihak lain, atau menggunakan Layanan dengan cara yang membebani atau mengganggu ketersediaannya bagi pelanggan lain.',
                ], $t),
            ],
            [
                'heading' => '6. Biaya, harga, dan pembayaran',
                'body' => self::paragraphs([
                    'Harga langganan dinyatakan dalam Rupiah (IDR) dan dipublikasikan pada halaman Pricing. Harga yang berlaku bagi Pelanggan adalah harga yang ditampilkan pada saat pemesanan dilakukan.',
                    'Langganan ditagihkan di muka untuk satu periode penuh, baik bulanan maupun tahunan, sesuai siklus yang dipilih Pelanggan.',
                    'Pembayaran diproses oleh penyedia pembayaran berlisensi. Data kartu atau instrumen pembayaran Pelanggan tidak pernah melewati maupun disimpan pada sistem IOMS.',
                    'Langganan diaktifkan setelah pembayaran dikonfirmasi kepada server kami oleh penyedia pembayaran. Berhasil sampai pada halaman konfirmasi di peramban tidak dengan sendirinya mengaktifkan langganan.',
                    'Faktur diterbitkan untuk setiap periode penagihan dan dapat diunduh dari area Billing pada akun Pelanggan.',
                    'Apabila terdapat pajak yang wajib dipungut berdasarkan peraturan yang berlaku, pajak tersebut akan ditampilkan pada faktur.',
                ], $t),
            ],
            [
                'heading' => '7. Masa berlaku, perpanjangan, dan pembatalan',
                'body' => self::paragraphs([
                    'Langganan berlaku untuk periode yang telah dibayar dan berlanjut ke periode berikutnya sesuai siklus penagihan yang dipilih.',
                    'Pelanggan dapat membatalkan langganan kapan saja. Pembatalan berlaku pada akhir periode yang sudah dibayar: Layanan tetap dapat digunakan sampai periode tersebut berakhir, dan setelah itu akses dihentikan.',
                    'Ketentuan mengenai pengembalian dana diatur tersendiri dalam Refund & Cancellation Policy, yang merupakan bagian tidak terpisahkan dari dokumen ini.',
                ], $t),
            ],
            [
                'heading' => '8. Penangguhan dan pengakhiran',
                'body' => self::paragraphs([
                    'Kami dapat menangguhkan akses apabila pembayaran yang terutang tidak diselesaikan setelah masa tenggang berakhir, atau apabila penggunaan Layanan melanggar Bagian 5 dokumen ini.',
                    'Kecuali dalam hal pelanggaran yang bersifat berat atau melanggar hukum, penangguhan didahului dengan pemberitahuan kepada kontak Pelanggan yang terdaftar, sehingga Pelanggan memiliki kesempatan untuk menyelesaikannya.',
                    'Pengakhiran tidak menghapus kewajiban pembayaran yang telah timbul sebelum tanggal pengakhiran.',
                ], $t),
            ],
            [
                'heading' => '9. Data Pelanggan dan kepemilikan',
                'body' => self::paragraphs([
                    'Seluruh data operasional yang dimasukkan Pelanggan ke dalam IOMS — data karyawan, dokumen, catatan keselamatan kerja, dan seluruh catatan lain — tetap menjadi milik Pelanggan.',
                    'Kami memproses data tersebut semata-mata untuk menyediakan dan memelihara Layanan bagi Pelanggan, sebagaimana diuraikan dalam Privacy Policy.',
                    'Selama langganan aktif, Pelanggan dapat mengekspor datanya sendiri dari dalam aplikasi dalam format PDF dan Excel yang telah tersedia.',
                    'Setelah pengakhiran, Pelanggan disarankan mengekspor data yang diperlukan sebelum masa akses berakhir.',
                ], $t),
            ],
            [
                'heading' => '10. Hak kekayaan intelektual',
                'body' => self::paragraphs([
                    'IOMS, termasuk perangkat lunak, antarmuka, dokumentasi, merek, dan seluruh materi yang menyertainya, merupakan hak kekayaan intelektual kami dan/atau pemberi lisensi kami. Syarat & Ketentuan ini tidak mengalihkan hak kepemilikan apa pun kepada Pelanggan.',
                    'Masukan dan saran yang disampaikan Pelanggan dapat kami gunakan untuk mengembangkan Layanan tanpa menimbulkan kewajiban apa pun, dan tanpa mengurangi hak Pelanggan atas datanya sendiri.',
                ], $t),
            ],
            [
                'heading' => '11. Kerahasiaan',
                'body' => self::paragraphs([
                    'Masing-masing pihak menjaga kerahasiaan informasi non-publik milik pihak lain yang diperolehnya sehubungan dengan Layanan, dan menggunakannya hanya untuk keperluan pelaksanaan hubungan langganan ini.',
                    'Kewajiban ini tidak berlaku atas informasi yang telah menjadi milik publik tanpa pelanggaran, atau yang wajib diungkapkan berdasarkan peraturan atau perintah pejabat yang berwenang.',
                ], $t),
            ],
            [
                'heading' => '12. Keamanan',
                'body' => self::paragraphs([
                    'Kami menerapkan pengamanan teknis dan organisasi yang wajar untuk melindungi Layanan dan data Pelanggan, termasuk pemisahan data antar pelanggan yang diterapkan pada lapisan akses data, kontrol akses berbasis peran, dan pembatasan akses ke dokumen yang bersifat privat.',
                    'Pelanggan bertanggung jawab atas pengamanan pada sisinya sendiri, termasuk penggunaan kata sandi yang kuat, pengelolaan akun penggunanya, dan pencabutan akses bagi personel yang sudah tidak berhak.',
                    'Tidak ada sistem yang sepenuhnya bebas risiko. Apabila terjadi insiden keamanan yang berdampak pada data Pelanggan, kami akan memberitahukannya tanpa penundaan yang tidak wajar.',
                ], $t),
            ],
            [
                'heading' => '13. Ketersediaan layanan dan dukungan',
                'body' => self::paragraphs([
                    'Kami berupaya menjaga Layanan tetap tersedia, namun tidak menjamin ketersediaan tanpa gangguan. Pemeliharaan terencana diupayakan pada waktu yang berdampak minimal, dan pemberitahuan diberikan bila pemeliharaan tersebut diperkirakan mengganggu.',
                    'Dukungan diberikan melalui email pada hari kerja: :support.',
                    'Pertanyaan mengenai penagihan dan faktur: :billing.',
                    'Kami tidak menyediakan pengembangan khusus per pelanggan. IOMS adalah satu produk standar; Enterprise adalah tingkatan IOMS yang paling lengkap, bukan paket pengembangan khusus.',
                ], $t),
            ],
            [
                'heading' => '14. Jaminan dan penyangkalan',
                'body' => self::paragraphs([
                    'Kami menjamin bahwa Layanan akan disediakan dengan keterampilan dan kehati-hatian yang wajar.',
                    'Selebihnya, Layanan disediakan sebagaimana adanya. Sepanjang diizinkan oleh hukum yang berlaku, kami tidak memberikan jaminan lain, baik tersurat maupun tersirat, termasuk kesesuaian untuk tujuan tertentu.',
                    'IOMS merupakan alat bantu pencatatan dan pengelolaan operasi. IOMS tidak menggantikan penilaian profesional, kewajiban kepatuhan, maupun tanggung jawab keselamatan kerja Pelanggan. Keputusan operasional dan keselamatan tetap menjadi tanggung jawab Pelanggan.',
                ], $t),
            ],
            [
                'heading' => '15. Batasan tanggung jawab',
                'body' => self::paragraphs([
                    'Sepanjang diizinkan oleh hukum yang berlaku, tanggung jawab kami yang timbul dari atau berkaitan dengan Layanan dibatasi sebesar biaya langganan yang secara nyata telah dibayarkan Pelanggan untuk periode dua belas bulan sebelum timbulnya peristiwa yang menjadi dasar tuntutan.',
                    'Kami tidak bertanggung jawab atas kerugian tidak langsung, kehilangan keuntungan, kehilangan peluang usaha, atau kerugian yang timbul dari kehilangan data sepanjang Pelanggan tidak melakukan ekspor data yang tersedia baginya.',
                    'Pembatasan ini tidak berlaku terhadap kerugian yang tidak dapat dibatasi berdasarkan hukum yang berlaku.',
                ], $t),
            ],
            [
                'heading' => '16. Hukum yang berlaku',
                'body' => self::paragraphs([
                    'Syarat & Ketentuan ini tunduk pada hukum :jurisdiction.',
                    'Sengketa yang timbul dari Syarat & Ketentuan ini diselesaikan pada :venue.',
                    'Para pihak terlebih dahulu mengupayakan penyelesaian secara musyawarah sebelum menempuh jalur hukum.',
                ], $t),
            ],
            [
                'heading' => '17. Pertanyaan dan masukan',
                'body' => self::paragraphs([
                    'Pertanyaan mengenai Syarat & Ketentuan ini dapat disampaikan ke :support.',
                    'Pertanyaan komersial dan pra-penjualan: :hello.',
                ], $t),
            ],
        ];
    }

    public static function privacy(): array
    {
        $t = self::tokens();

        return [
            [
                'heading' => '1. Ruang lingkup',
                'body' => self::paragraphs([
                    'Kebijakan ini menjelaskan bagaimana IOMS — Industrial Operations Platform menangani data dalam penyediaan layanannya kepada organisasi pelanggan.',
                    'IOMS dioperasikan oleh :entity.',
                    'Terdapat dua jenis data yang perlu dibedakan, karena perlakuannya berbeda: data akun yang kami kumpulkan untuk menjalankan hubungan langganan, dan data operasional yang dimasukkan Pelanggan ke dalam aplikasi.',
                ], $t),
            ],
            [
                'heading' => '2. Data akun yang kami kumpulkan',
                'body' => self::paragraphs([
                    'Saat pendaftaran dan selama langganan berjalan, kami mengumpulkan nama organisasi, nama dan alamat email kontak, nomor telepon apabila diberikan, paket dan siklus penagihan yang dipilih, serta riwayat faktur dan status pembayaran.',
                    'Untuk akun pengguna di dalam workspace Pelanggan, kami menyimpan nama, alamat email, peran, status aktif, dan waktu login terakhir.',
                    'Kami juga mencatat log teknis yang wajar untuk keamanan dan pemecahan masalah.',
                ], $t),
            ],
            [
                'heading' => '3. Data operasional Pelanggan',
                'body' => self::paragraphs([
                    'Data yang dimasukkan Pelanggan ke dalam IOMS — data karyawan, proyek, izin kerja, inspeksi, dokumen, dan catatan operasional lainnya — merupakan milik Pelanggan.',
                    'Terhadap data tersebut kami bertindak sebagai pemroses atas nama Pelanggan. Kami memprosesnya untuk menyediakan, memelihara, mengamankan, dan mendukung Layanan, dan tidak untuk tujuan lain.',
                    'Kami tidak menjual data Pelanggan, tidak menggunakannya untuk periklanan, dan tidak membagikannya kepada pelanggan lain.',
                    'Penentuan data pribadi apa yang dimasukkan ke dalam IOMS dan atas dasar hukum apa merupakan tanggung jawab Pelanggan sebagai pengendali data.',
                ], $t),
            ],
            [
                'heading' => '4. Dasar dan tujuan pemrosesan',
                'body' => self::paragraphs([
                    'Kami memproses data akun untuk melaksanakan perjanjian langganan, menerbitkan faktur, memberikan dukungan, mengirimkan pemberitahuan transaksional yang diperlukan, serta menjaga keamanan Layanan.',
                    'Kami tidak mengirimkan materi pemasaran kepada pengguna di dalam workspace Pelanggan.',
                ], $t),
            ],
            [
                'heading' => '5. Pemisahan data antar pelanggan',
                'body' => self::paragraphs([
                    'Setiap pelanggan menempati tenant tersendiri. Pemisahan data diterapkan pada lapisan akses data aplikasi, bukan diserahkan pada setiap kueri untuk mengingatnya sendiri, sehingga data satu pelanggan tidak dapat dijangkau dari konteks pelanggan lain.',
                    'Di dalam satu organisasi, akses selanjutnya dibatasi oleh peran pengguna dan, bila diaktifkan, oleh Operating Unit yang secara tegas diberikan kepada akun tersebut oleh administrator Pelanggan.',
                ], $t),
            ],
            [
                'heading' => '6. Pihak ketiga',
                'body' => self::paragraphs([
                    'Pembayaran diproses oleh penyedia pembayaran berlisensi. Kami mengirimkan informasi yang diperlukan untuk memproses transaksi — antara lain nomor pesanan, jumlah tagihan, serta nama dan email kontak penagihan. Data kartu atau instrumen pembayaran Pelanggan tidak pernah melewati maupun disimpan pada sistem IOMS.',
                    'Layanan dijalankan pada infrastruktur penyedia hosting, dan email transaksional dikirimkan melalui penyedia layanan email. Penyedia-penyedia tersebut memproses data hanya sejauh diperlukan untuk menjalankan fungsinya.',
                    'Kami tidak mengalihkan data Pelanggan kepada pihak ketiga untuk tujuan komersial pihak ketiga tersebut.',
                ], $t),
            ],
            [
                'heading' => '7. Penyimpanan dan retensi',
                'body' => self::paragraphs([
                    'Data operasional disimpan selama langganan berjalan. Setelah pengakhiran, data disimpan untuk jangka waktu yang wajar guna memungkinkan pemulihan dan penyelesaian administrasi, kemudian dihapus, kecuali apabila peraturan mewajibkan penyimpanan lebih lama.',
                    'Catatan penagihan disimpan selama jangka waktu yang diwajibkan oleh ketentuan perpajakan dan akuntansi yang berlaku.',
                    'Pelanggan dapat mengekspor datanya sendiri kapan saja selama langganan aktif.',
                ], $t),
            ],
            [
                'heading' => '8. Keamanan',
                'body' => self::paragraphs([
                    'Akses ke Layanan dilakukan melalui koneksi terenkripsi. Kata sandi disimpan dalam bentuk hash, tidak pernah dalam bentuk teks biasa.',
                    'Lampiran dan dokumen yang bersifat privat disimpan di luar direktori publik dan hanya dapat diakses melalui pemeriksaan otorisasi pada setiap permintaan.',
                    'Akses internal ke sistem produksi dibatasi pada personel yang benar-benar memerlukannya.',
                ], $t),
            ],
            [
                'heading' => '9. Hak subjek data',
                'body' => self::paragraphs([
                    'Permintaan akses, perbaikan, atau penghapusan data pribadi yang berada di dalam workspace suatu pelanggan diajukan kepada organisasi pelanggan tersebut sebagai pengendali data. Kami akan membantu Pelanggan dalam menanggapi permintaan semacam itu.',
                    'Untuk data akun yang kami kelola sendiri, permintaan dapat disampaikan ke :support.',
                ], $t),
            ],
            [
                'heading' => '10. Cookie',
                'body' => self::paragraphs([
                    'IOMS menggunakan cookie yang diperlukan untuk menjalankan Layanan, yaitu cookie sesi untuk menjaga status login dan token untuk melindungi formulir dari pemalsuan permintaan.',
                    'Kami tidak menggunakan cookie periklanan dan tidak melakukan pelacakan lintas situs.',
                ], $t),
            ],
            [
                'heading' => '11. Perubahan kebijakan',
                'body' => self::paragraphs([
                    'Kebijakan ini dapat diperbarui. Versi yang berlaku selalu dipublikasikan pada halaman ini beserta tanggal berlakunya, dan perubahan material diberitahukan kepada kontak Pelanggan yang terdaftar.',
                ], $t),
            ],
            [
                'heading' => '12. Kontak',
                'body' => self::paragraphs([
                    'Pertanyaan mengenai kebijakan ini dapat disampaikan ke :support.',
                ], $t),
            ],
        ];
    }

    public static function refunds(): array
    {
        $t = self::tokens();

        return [
            [
                'heading' => '1. Ringkasan',
                'body' => self::paragraphs([
                    'IOMS adalah layanan langganan yang ditagihkan di muka. Kebijakan ini menjelaskan kapan langganan dapat dibatalkan dan dalam keadaan apa pengembalian dana dapat diberikan.',
                    'Kebijakan ini disusun agar mencerminkan cara IOMS benar-benar bekerja. Kami tidak menjanjikan pengembalian dana yang tidak dapat kami penuhi.',
                ], $t),
            ],
            [
                'heading' => '2. Pembatalan',
                'body' => self::paragraphs([
                    'Pelanggan dapat membatalkan langganan kapan saja melalui halaman Billing pada akunnya atau dengan menghubungi :billing.',
                    'Pembatalan berlaku pada akhir periode yang sudah dibayar. Layanan tetap dapat digunakan sampai periode tersebut berakhir; setelah itu akses dihentikan dan tidak ada penagihan berikutnya.',
                    'Pembatalan tidak menghapus data Pelanggan pada saat pembatalan diajukan. Pelanggan disarankan mengekspor data yang diperlukan sebelum masa akses berakhir.',
                ], $t),
            ],
            [
                'heading' => '3. Kebijakan umum pengembalian dana',
                'body' => self::paragraphs([
                    'Karena langganan ditagihkan di muka untuk satu periode penuh dan Layanan tersedia sepanjang periode tersebut, biaya untuk periode yang sedang berjalan pada umumnya tidak dikembalikan.',
                    'Pembatalan menghentikan penagihan berikutnya, bukan mengembalikan periode yang sedang berjalan.',
                ], $t),
            ],
            [
                'heading' => '4. Keadaan yang memenuhi syarat pengembalian dana',
                'body' => self::paragraphs([
                    'Pengembalian dana diberikan dalam hal terjadi penagihan ganda atau kesalahan penagihan yang berasal dari sistem kami. Dalam hal ini seluruh selisih dikembalikan.',
                    'Pengembalian dana diberikan apabila pembayaran berhasil dilakukan namun workspace tidak dapat kami sediakan, dan persoalan tersebut tidak dapat diselesaikan dalam waktu yang wajar.',
                    'Pengembalian dana diberikan apabila terjadi gangguan Layanan yang berkepanjangan dan bersumber dari pihak kami, dihitung secara proporsional atas periode yang terdampak.',
                    'Permohonan pengembalian dana di luar keadaan di atas akan kami tinjau berdasarkan keadaannya masing-masing.',
                ], $t),
            ],
            [
                'heading' => '5. Keadaan yang tidak memenuhi syarat pengembalian dana',
                'body' => self::paragraphs([
                    'Perubahan rencana atau berkurangnya kebutuhan penggunaan setelah periode berjalan dimulai.',
                    'Penggunaan yang lebih rendah dari yang diperkirakan selama periode langganan.',
                    'Permintaan fitur yang tidak pernah dinyatakan sebagai bagian dari Layanan pada saat pembelian. Cakupan setiap paket dijelaskan pada halaman Pricing, dan IOMS Sandbox tersedia agar produk dapat dinilai sebelum berlangganan.',
                    'Penangguhan atau pengakhiran akibat pelanggaran Syarat & Ketentuan.',
                ], $t),
            ],
            [
                'heading' => '6. Cara mengajukan',
                'body' => self::paragraphs([
                    'Permohonan diajukan ke :billing dengan menyertakan nomor faktur dan penjelasan singkat mengenai keadaannya.',
                    'Kami menanggapi permohonan dalam waktu yang wajar pada hari kerja.',
                    'Pengembalian dana yang disetujui diproses melalui metode pembayaran yang sama dengan pembayaran aslinya. Lama waktu dana diterima kembali bergantung pada penyedia pembayaran dan bank penerbit, dan berada di luar kendali kami.',
                ], $t),
            ],
            [
                'heading' => '7. Perpanjangan',
                'body' => self::paragraphs([
                    'Faktur perpanjangan diterbitkan sebelum periode berikutnya dimulai. Pembatalan sebelum pembayaran perpanjangan dilakukan berarti tidak ada biaya perpanjangan yang timbul.',
                    'Kegagalan pembayaran perpanjangan tidak langsung menghentikan akses: terdapat masa tenggang, dan Pelanggan diberitahu terlebih dahulu.',
                ], $t),
            ],
            [
                'heading' => '8. Kontak',
                'body' => self::paragraphs([
                    'Pertanyaan mengenai kebijakan ini: :billing.',
                    'Pertanyaan yang bersifat umum: :support.',
                ], $t),
            ],
        ];
    }
}
