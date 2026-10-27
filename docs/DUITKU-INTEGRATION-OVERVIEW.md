# Penggunaan Duitku Payment Gateway di IOMS

**Untuk:** tim onboarding Duitku — Project Code `D25218`
**Produk:** IOMS (Industrial Operations Platform) — `https://iomsuite.com`
**Versi aplikasi:** 2.134.1 · **Lingkungan pembayaran saat ini:** Sandbox

Dokumen ini menjelaskan bagaimana IOMS memakai Duitku, berdasarkan kode yang berjalan. Hanya fungsi
yang sudah diimplementasikan dan diuji yang dijelaskan sebagai fungsi; batasannya ditulis terpisah di
bagian 9.

---

## 1. Apa yang dijual

IOMS adalah perangkat lunak berlangganan (SaaS) B2B untuk operasional industri: K3/HSE (Permit to
Work, inspeksi, insiden), APD/PPE, data karyawan, dan logistik. Harga dalam Rupiah, ditagih per bulan
atau per tahun, dan dikirim lewat web. Tidak ada barang fisik, tidak ada pengiriman, dan tidak ada
penyimpanan data kartu di IOMS.

Paket publik saat ini (dari tabel `packages`, sumber harga yang dipakai saat checkout):

| Paket | Bulanan | Tahunan |
|---|---|---|
| Starter | Rp189.000 | Rp2.268.000 (12 bulan akses) |
| Professional | Rp555.000 | Rp6.105.000 (12 bulan akses) |
| Business | Rp1.249.000 | Rp14.988.000 (14 bulan akses) |

## 2. Registrasi akun

1. Calon pelanggan membuat akun di `/register` (nama, email, kata sandi, persetujuan Syarat &
   Ketentuan). Yang dibuat hanya akun orang tersebut: belum ada organisasi, langganan, atau tagihan.
2. IOMS mengirim email konfirmasi bertanda tangan digital (tautan kedaluwarsa). Jika server email
   sedang gagal, akun tetap dibuat, pelanggan diberi tahu bahwa email belum terkirim, dan dapat
   mengirim ulang dari area akun. (Inilah perbaikan untuk error HTTP 500 yang ditemui tim Duitku —
   lihat bagian 8.)
3. Pembelian hanya dapat dimulai setelah email terkonfirmasi.

## 3. Pemilihan paket dan checkout

1. Pelanggan memilih paket dan siklus (bulanan/tahunan) serta mengisi data perusahaan.
2. IOMS menerbitkan **invoice** dengan nominal yang **dihitung ulang di server** dari katalog saat itu —
   bukan dari nilai yang dikirim browser.
3. Halaman ringkasan pesanan milik IOMS menampilkan paket, siklus, periode, nomor invoice, dan
   nominal dalam Rupiah sebelum pelanggan meninggalkan situs.
4. IOMS meminta daftar metode pembayaran ke Duitku (`paymentmethod/getpaymentmethod`) dan pelanggan
   memilih salah satu. Pilihan itu divalidasi ulang di server terhadap daftar dari Duitku.

## 4. Inisiasi pembayaran

`POST /webapi/api/merchant/v2/inquiry` dengan:

- `merchantOrderId` = `INV<id invoice>-<waktu>-<acak>` — unik per sesi dan dapat ditelusuri ke invoice;
- `paymentAmount` = nominal invoice (bilangan bulat Rupiah);
- `callbackUrl` = `https://iomsuite.com/webhooks/payment/duitku`;
- `returnUrl` = halaman status pesanan pelanggan (hanya tampilan; tidak mengubah status apa pun);
- `expiryPeriod` = sesuai `PAYMENT_CHECKOUT_EXPIRY_HOURS` (bawaan 24 jam);
- `signature` = `HMAC_SHA256(merchantCode + merchantOrderId + paymentAmount, apiKey)`.

Pelanggan diarahkan ke `paymentUrl` dari Duitku. Satu invoice hanya memiliki **satu sesi aktif**:
kembali ke checkout memakai ulang sesi yang sama, tidak membuat inquiry baru.

## 5. Callback dan verifikasi status

Callback Duitku adalah **satu-satunya** jalan yang dapat membuat invoice menjadi lunas. Halaman
"berhasil" di browser tidak mengaktifkan apa pun.

Urutan pemrosesan di `POST /webhooks/payment/duitku`:

1. **Verifikasi tanda tangan** `HMAC_SHA256(merchantCode + amount + merchantOrderId, apiKey)`,
   dibandingkan secara *timing-safe*, **sebelum** data apa pun dibaca. Salah → `403`, tidak ada
   perubahan.
2. **Catat event** dengan indeks unik `(gateway, event_id)`; event yang sama tidak diproses dua kali.
3. **Cocokkan nominal** dengan invoice dan sesi. Selisih ke arah mana pun → **tidak dilunasi**,
   dicatat, dan dinaikkan ke Master Admin untuk ditinjau.
4. Jika sah: invoice ditandai lunas dan organisasi/langganan diaktifkan atau diperpanjang **dalam satu
   transaksi database**, lalu dicatat di log aktivitas.

IOMS juga dapat menanyakan status ke Duitku (`transactionStatus`) untuk rekonsiliasi; hasilnya hanya
dibaca, tidak pernah melunasi invoice.

## 6. Pembaruan invoice dan langganan

| Kejadian | Invoice | Langganan |
|---|---|---|
| Callback sukses (`resultCode 00`), nominal cocok | `paid`, `paid_at` diisi | Pelanggan baru: organisasi dibuat dan aktif. Pelanggan lama: periode diperpanjang |
| Callback gagal (`01`) | tetap belum lunas; alasan dicatat | tidak berubah |
| Kode yang tidak dikenal | dianggap *pending* | tidak berubah |

Untuk pelanggan yang sudah berlangganan, harga yang disepakati disimpan pada langganannya, sehingga
perubahan katalog tidak mengubah harga pelanggan berjalan tanpa keputusan operator.

## 7. Transaksi gagal, pending, kedaluwarsa, ganda, dan terlambat

| Kasus | Perilaku yang sudah diimplementasikan dan diuji |
|---|---|
| Gagal | Dicatat beserta alasannya; tidak ada akses yang diberikan; pelanggan dapat mencoba lagi |
| Pending | Tidak ada perubahan sampai callback final tiba |
| Kedaluwarsa | Setelah masa sesi lewat, sesi itu tidak dipakai lagi; percobaan berikutnya membuat sesi baru untuk invoice yang **sama** (bukan invoice kedua) |
| Callback ganda / dikirim ulang | Ditolak oleh indeks unik event dan pemeriksaan "sudah lunas"; tidak ada aktivasi ganda (diuji dengan tiga kiriman identik) |
| Callback terlambat | Tetap diproses jika tanda tangan dan nominal sah, karena `merchantOrderId` selalu dapat dipetakan ke invoice |
| Nominal tidak cocok | Tidak dilunasi; ditandai untuk peninjauan manual |
| Pesanan dibuat sebelum perubahan harga | Sejak v2.131.0 invoice yang belum dibayar dan belum memiliki sesi aktif mengikuti harga katalog terbaru di semua halaman dan pada permintaan ke Duitku |

## 8. Perbaikan yang dilakukan untuk onboarding ini (v2.131.0)

1. **HTTP 500 saat registrasi.** Penyebab: email konfirmasi dikirim secara sinkron setelah akun
   tersimpan, dan kegagalan SMTP tidak ditangani. Sekarang kegagalan email dicatat di log server,
   registrasi selesai, dan pelanggan diberi tahu dengan jujur. Diuji dengan server SMTP yang menolak
   koneksi, termasuk melalui browser.
2. **Tanda tangan daftar metode pembayaran** diperbarui ke HMAC-SHA256 (skema SHA-256 biasa sudah
   dinyatakan usang oleh Duitku), sehingga daftar metode tidak lagi kosong.
3. **Return URL** untuk pelanggan baru kini menuju halaman status pesanannya, bukan halaman login.
4. **Konsistensi harga**: pesanan lama yang belum dibayar mengikuti katalog yang berlaku (bagian 7).
5. **Identitas merek**: situs publik menampilkan IOMS sebagai merek produk. Identitas badan hukum tidak
   lagi dicantumkan di halaman publik kecuali sengaja dikonfigurasi, dan tetap tercantum sebagai
   penerbit pada invoice.

## 9. Batasan dan hal yang belum dapat dinyatakan

- **Belum diuji melawan server Sandbox Duitku yang sebenarnya dari lingkungan pengembangan ini**:
  jaringan ke `sandbox.duitku.com` diblokir di sana. Semua pengujian otomatis memalsukan respons
  Duitku di batas HTTP, sehingga perakitan permintaan dan tanda tangan tetap diuji. Satu transaksi
  sandbox nyata dari server staging tetap diperlukan (lihat `docs/PAYMENTS-DUITKU.md` §6).
- **Refund** tidak dilakukan lewat API; refund diproses dari dashboard Duitku.
- **Recurring/auto-debit** tidak digunakan; setiap periode diterbitkan sebagai invoice baru.
- **Pembayaran ganda pada dua sesi berbeda** untuk satu invoice (pelanggan membayar sesi lama dan sesi
  baru) hanya melunasi invoice sekali; dana kedua harus dikembalikan secara manual dari dashboard.
- **Produksi**: belum diaktifkan. Peralihan dari Sandbox ke Production hanya dilakukan setelah
  persetujuan merchant dan kredensial produksi tersedia.

## 10. Kontak

Email dukungan: `support@iomsuite.com` · Tagihan: `billing@iomsuite.com`
Alamat usaha dan nomor telepon ditampilkan di `https://iomsuite.com/contact` dan, sejak v2.134.1, di
footer setiap halaman publik, dari konfigurasi yang sama (`IOMS_SUPPORT_PHONE`, `IOMS_LEGAL_ADDRESS`).
Nilai yang belum dikonfigurasi tidak ditampilkan, bukan diisi perkiraan (lihat `docs/ADR/071-...`).
