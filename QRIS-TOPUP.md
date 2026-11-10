# Dokumentasi QRIS Top Up Saldo

Dokumentasi lengkap fitur **Top up saldo via QRIS** pada WEB_TAGIHAN_DEMO (PWA).  
Berbeda dari bayar tagihan per-item: top up hanya mengirim **nominal**, tanpa baris tagihan (`pwa_qris_item` kosong).

---

## 1. Ringkasan

| Aspek | Keterangan |
|--------|------------|
| Tujuan | User mengisi saldo VA dengan scan QRIS (e-wallet / m-banking) |
| Aktif jika | `BRAND_PAYMENT_QRIS=true` di `.env` Laravel |
| Mode UI | Biasanya `BRAND_PAYMENT_MODEL=2` (saldo): tagihan hanya informasi; isi saldo lewat VA + QRIS |
| Prefix VA routing | `751000` → handler `islamic_center/qris/pushNotif/demoInstallment.php` |
| Format VA | `751000` + **NOCUST 10 digit** (kurang dari 10 → diisi `0` di depan) |
| Minimal nominal | Rp 1.000 |

**Contoh VA**

| NOCUST di DB | VA yang dikirim ke bank / tampilan |
|--------------|-------------------------------------|
| `3271998` (7 digit) | `7510000003271998` |
| `272801009` (9 digit) | `7510000272801009` |
| `1301154344` (10 digit) | `7510001301154344` |

---

## 2. Arsitektur & alur kerja

\[PWA Laravel] --POST /generate-qris--> [QrisGenerateService]
        |                                      |
        |                                      v
        |                              [Server Lazizmu generate QR]
        |                                      |
        |         POST WS save-qris            v
        +--------------------------------> [DEMO_WS / pwa_qris pending]
        |
        |  User scan QR -> bayar e-wallet / m-banking
        v
[Gateway bank] --JWT callback--> [pushNotif.php prefix 751000]
                                         |
                                         v
                              [demoInstallment.php]
                     /--------------+--------------\
                    v               v               v
           UPDATE pwa_qris    Forward DEMO_    POST /push/notify-paid
           paid_flag=1        INSTALLMENT/     (Web Push)
           + log_qris_push    QRIS.php kredit
\
### Langkah singkat (top up)

1. User di PWA klik **Top up QRIS**, isi nominal ≥ 1000.
2. Laravel memanggil server Lazizmu (JWT: `accountNo`, `amount`, `vano`, `transactionId`, …).
3. Response sukses (`responseCode=00`) berisi `rawQrData` + `transactionQrId`.
4. Laravel menyimpan ke DB sekolah lewat WS `save-qris` → baris `pwa_qris` status `pending`, **tanpa** `pwa_qris_item`.
5. UI menampilkan QR; PWA juga bisa poll status (`cek-status-pembayaran` / `qris-status`).
6. Setelah bayar, bank memanggil `pushNotif.php` dengan JWT.
7. Prefix VA `751000` → `demoInstallment.php`:
   - tandai `pwa_qris` jadi `paid` / `paid_flag=1`;
   - tulis audit `pwa_log_qris_push`;
   - forward token ke endpoint installment (kredit saldo VA);
   - jika `newly_paid`, panggil Laravel `/push/notify-paid` (notifikasi Web Push).
8. User melihat notifikasi / saldo bertambah (saldo dibaca dari view VA sekolah, mis. `v_saldo_va`).

---

## 3. Tabel database

Semua tabel PWA memakai prefix `pwa_`.  
Jalankan di **DB sekolah / WS / billing** (sama dengan `TAGIHAN_DB_*` dan `DEMO_WS…/config/database.php`), **bukan** DB Laravel default.

File SQL:

- Gabungan: `database/sql/pwa_extra_tables.sql` atau `DEMO_WS_TAGIHAN_CICILAN/sql/install_missing_tables.sql`
- Khusus QRIS: `DEMO_WS_TAGIHAN_CICILAN/sql/qris_payment_tables.sql`
- Rename dari nama lama: `DEMO_WS_TAGIHAN_CICILAN/sql/rename_tables_to_pwa.sql`

### 3.1 `pwa_qris` — header transaksi QRIS

Satu baris = satu kali generate QR (top up atau bayar tagihan).

| Kolom | Tipe | Nullable | Keterangan |
|-------|------|----------|------------|
| `id` | BIGINT UNSIGNED AI | NO | PK |
| `custid` | VARCHAR(64) | NO | ID customer sekolah (`scctcust.CUSTID`) |
| `nocust` | VARCHAR(64) | NO | NIS / userlogin **tanpa** prefix bank, tanpa padding (contoh: `3271998`) |
| `namacust` | VARCHAR(191) | YES | Nama siswa |
| `vano` | VARCHAR(64) | YES | VA penuh: `751000` + NOCUST **10 digit** (contoh: `7510000003271998`). Dipakai routing callback |
| `amount` | DECIMAL(18,2) | NO | Nominal QRIS (saat paid bisa di-update dari callback) |
| `qris_id` | VARCHAR(128) | YES | `transactionQrId` dari gateway |
| `qris_content` | TEXT | YES | String EMV QR (`rawQrData`) untuk ditampilkan / di-scan |
| `transaction_id` | VARCHAR(64) | YES | ID transaksi generate (client/server) |
| `account_no` | VARCHAR(64) | YES | Nomor rekening merchant QRIS |
| `mitra_customer_id` | VARCHAR(191) | YES | ID mitra di gateway |
| `description` | VARCHAR(255) | YES | Top up: biasanya mengandung `Top up` / `TOPUP\|…` |
| `status` | ENUM | NO | `pending` \| `paid` \| `expired` \| `failed` (default `pending`) |
| `paid_flag` | TINYINT(1) | NO | `0` belum lunas, `1` sudah lunas |
| `request_payload` | MEDIUMTEXT | YES | JSON JWT payload yang dikirim ke Lazizmu |
| `response_payload` | MEDIUMTEXT | YES | JSON response generate dari Lazizmu |
| `created_at` | DATETIME | YES | Waktu generate |
| `updated_at` | DATETIME | YES | Terakhir diubah |
| `paid_at` | DATETIME | YES | Waktu ditandai lunas (dari callback) |

**Index:** `custid`, `nocust`, `status`, `qris_id`, `vano`.

**Ciri top up (bukan bayar tagihan):**

- Tidak ada baris di `pwa_qris_item` untuk `payment_id` ini, **atau**
- `description` mengandung `top up` / `topup` / `TOPUP`.

Query history di Laravel memakai kedua kriteria itu.

### 3.2 `pwa_qris_item` — detail tagihan (opsional)

Untuk **bayar tagihan per AA**. Pada **top up murni**, tabel ini **kosong** (tidak di-insert).

| Kolom | Tipe | Nullable | Keterangan |
|-------|------|----------|------------|
| `id` | BIGINT UNSIGNED AI | NO | PK |
| `payment_id` | BIGINT UNSIGNED | NO | FK → `pwa_qris.id` (ON DELETE CASCADE) |
| `aa` | BIGINT UNSIGNED | NO | ID baris tagihan (AA) |
| `billcd` | VARCHAR(64) | YES | Kode tagihan |
| `nama_tagihan` | VARCHAR(255) | YES | Nama tagihan |
| `amount` | DECIMAL(18,2) | NO | Nominal dibayar untuk baris ini |
| `is_cicil` | TINYINT(1) | NO | `1` jika cicilan |
| `sisa_sebelum` | DECIMAL(18,2) | YES | Sisa tagihan saat generate |
| `created_at` | DATETIME | YES | |

### 3.3 `pwa_log_qris_push` — audit callback

Setiap (atau hampir setiap) hit `pushNotif` untuk transaksi ini dicatat di sini (best-effort).

| Kolom | Tipe | Nullable | Keterangan |
|-------|------|----------|------------|
| `id` | BIGINT UNSIGNED AI | NO | PK |
| `payment_id` | BIGINT UNSIGNED | YES | `pwa_qris.id` (null jika transaksi tidak ketemu) |
| `event_type` | VARCHAR(32) | NO | Mis. `push_notif`, `already_paid`, `not_found`, `error` |
| `qris_id` | VARCHAR(128) | YES | Dari callback |
| `transaction_id` | VARCHAR(64) | YES | |
| `vano` | VARCHAR(64) | YES | VA dari callback |
| `custid` | VARCHAR(64) | YES | |
| `nocust` | VARCHAR(64) | YES | Biasanya tanpa leading zero |
| `amount` | DECIMAL(18,2) | YES | Nominal callback |
| `paid_flag` | TINYINT(1) | YES | Flag setelah proses |
| `processed` | VARCHAR(32) | YES | `new_processing` \| `already_processed` \| `not_found` \| `no_change` \| `error` |
| `scctva_status` | VARCHAR(32) | YES | Untuk jalur bayar tagihan: `inserted` / `failed` / `skipped` (top up demoInstallment biasanya skip insert scctva) |
| `response_code` | VARCHAR(8) | YES | `00` sukses / `01` gagal (response ke gateway) |
| `response_message` | VARCHAR(255) | YES | |
| `http_code` | SMALLINT UNSIGNED | YES | |
| `request_payload` | MEDIUMTEXT | YES | Snapshot request / JWT decoded |
| `response_payload` | MEDIUMTEXT | YES | JSON yang dikembalikan ke gateway |
| `source` | VARCHAR(128) | YES | File handler, mis. `demoInstallment.php` |
| `ip_address` | VARCHAR(45) | YES | |
| `user_agent` | VARCHAR(255) | YES | |
| `created_at` | DATETIME | YES | |

### 3.4 Tabel terkait (bukan khusus QRIS, tapi dipakai alur top up)

| Tabel | Peran |
|-------|--------|
| `pwa_push_subscriptions` | Endpoint Web Push per browser/HP; diisi saat user login & izinkan notifikasi |
| `scctcust` / `v_saldo_va` (DB sekolah) | Master siswa & saldo VA — **bukan** tabel PWA; saldo setelah top up dibaca dari sini |
| Endpoint installment `…/DEMO_INSTALLMENT/QRIS.php` | Menerima forward token dari `demoInstallment.php` untuk **kredit saldo** di sisi keuangan sekolah |

---

## 4. Cara kerja detail per komponen

### 4.1 Generate (Laravel PWA)

| Item | Nilai |
|------|--------|
| Route | `POST /generate-qris` |
| Controller | `App\Http\Controllers\TagihanController::buatQRIS` |
| Service | `App\Services\QrisGenerateService` |
| Gate | `config('brand.payment_qris')` harus `true` |

**Body request (JSON / form):**

| Field | Wajib | Keterangan |
|-------|-------|------------|
| `custid` | Ya | ID siswa |
| `nocust` | Ya | NIS (akan dinormalisasi: buang prefix & leading zero) |
| `namacust` | Ya | Nama |
| `amount` | Ya | Integer ≥ 1000 |
| `description` | Tidak | Default: `Top up saldo {nama}` |

**Yang dikirim ke Lazizmu (JWT payload):**

- `accountNo`, `amount`, `mitraCustomerId`, `transactionId`, `tipeTransaksi`
- `vano` = `formatNova(nocust)` → `751000` + pad 10 digit

**Setelah sukses:** Laravel memanggil WS `path=save-qris` agar baris tersimpan di `pwa_qris` (best-effort; error disimpan di log, response ke user tetap sukses jika generate QR sudah OK).

### 4.2 Simpan di WS (`save-qris`)

| Item | Nilai |
|------|--------|
| File | `DEMO_WS_TAGIHAN_CICILAN/controllers/TagihanController.php` → `saveQRIS()` |
| Model | `DEMO_WS_TAGIHAN_CICILAN/models/QrisPayment.php` → `saveGenerated()` |

Insert `pwa_qris` dengan `status=pending`, `paid_flag=0`.  
Untuk top up, loop item kosong → **tidak** insert `pwa_qris_item`.  
Jika description tidak mengandung kata “top”, ditambah prefix `TOPUP|` agar history mudah difilter.

### 4.3 Callback bayar (`pushNotif`)

| Item | Nilai |
|------|--------|
| Entry | `islamic_center/qris/pushNotif.php` |
| Routing | 6 digit pertama `vano` = `751000` → `pushNotif/demoInstallment.php` |
| Config DB | `islamic_center/config/connectTagihanCicilan.php` → DB yang sama dengan `pwa_qris` |

**Urutan di `demoInstallment.php`:**

1. `di_mark_qris_paid` — cari `pwa_qris` by `qris_id` atau `vano`, update `paid` + `paid_flag=1` + `paid_at`.
2. Jika hasil `newly_paid` → `di_notify_webpush` ke `WEBPUSH_NOTIFY_URL` (Laravel `/push/notify-paid`).
3. Forward GET token ke `DEMO_INSTALLMENT/QRIS.php` (kredit saldo).
4. Tulis `pwa_log_qris_push`.

**Penting:** Kredit saldo VA terjadi di sisi **installment / keuangan sekolah**, bukan dengan UPDATE langsung di tabel PWA. PWA hanya menandai QRIS paid + notifikasi + menampilkan saldo dari view sekolah.

### 4.4 Polling status (PWA menunggu bayar)

- Laravel: `cek-status-pembayaran` (bisa baca langsung `pwa_qris` via `TAGIHAN_DB_*`).
- WS: `path=qris-status` dengan `qris_id` / `transaction_id` / `vano`.

Kriteria lunas: `paid_flag = 1` atau `status = 'paid'`.

### 4.5 History top up

| Item | Nilai |
|------|--------|
| Route | `GET /history-topup-qris` |
| Filter | `nocust` (atau `vano LIKE %nocust%`) **dan** paid/success **dan** (description like topup **atau** tidak punya item di `pwa_qris_item`) |

---

## 5. Konfigurasi

### 5.1 Laravel `.env`

```env
BRAND_PAYMENT_MODEL=2
BRAND_PAYMENT_QRIS=true

QRIS_SERVER_URL=http://103.23.103.43/qris/lazizmu_diy/server.php
QRIS_JWT_SECRET=TokenJWT_BMI_ICT
QRIS_ACCOUNT_NO=5080010295
QRIS_MITRA_CUSTOMER_ID="ISLAMIC CENTER SMG451061"
QRIS_TIPE_TRANSAKSI=MTR-GENERATE-QRIS-DYNAMIC

# DB sekolah (pwa_qris, push_subscriptions, …)
TAGIHAN_DB_HOST=...
TAGIHAN_DB_DATABASE=...
TAGIHAN_DB_USERNAME=...
TAGIHAN_DB_PASSWORD=...

WS_TAGIHAN_URL=https://.../DEMO_WS_TAGIHAN_CICILAN/

# Web Push (notifikasi setelah paid)
VAPID_PUBLIC_KEY=...
VAPID_PRIVATE_KEY=...
VAPID_SUBJECT=mailto:admin@example.com
WEBPUSH_NOTIFY_SECRET=rahasia-sama-dengan-islamic-center
```

Sumber config: `config/brand.php` (`payment_qris`, `qris.*`), `config/services.php` (`tagihan_db`, `webpush`).

### 5.2 WS `DEMO_WS_TAGIHAN_CICILAN/config/qris.php`

Samakan `QRIS_*` dengan Laravel jika generate juga pernah dipanggil langsung lewat WS.

### 5.3 `islamic_center/.env` (callback)

```env
WEBPUSH_NOTIFY_URL=https://DOMAIN-LARAVEL-ANDA/push/notify-paid
WEBPUSH_NOTIFY_SECRET=rahasia-sama-dengan-laravel
```

URL forward installment di `demoInstallment.php` (variabel `$forwardBase`) harus mengarah ke endpoint kredit saldo client yang benar.

---

## 6. File penting

| Path | Peran |
|------|--------|
| `app/Http/Controllers/TagihanController.php` | `buatQRIS`, history, status, `formatNova` / pad 10 digit |
| `app/Services/QrisGenerateService.php` | Panggil Lazizmu + susun `vano` |
| `resources/views/index3.blade.php` | UI Top up QRIS, History, poll status, subscribe push |
| `DEMO_WS_TAGIHAN_CICILAN/models/QrisPayment.php` | Insert/update `pwa_qris` (+ item jika bill) |
| `DEMO_WS_TAGIHAN_CICILAN/controllers/TagihanController.php` | `generate-qris`, `save-qris`, `qris-status` |
| `islamic_center/qris/pushNotif.php` | Router callback by prefix VA |
| `islamic_center/qris/pushNotif/demoInstallment.php` | Paid + log + forward + webpush (prefix `751000`) |
| `app/Http/Controllers/WebPushController.php` | `POST /push/notify-paid` |
| `app/Services/WebPushService.php` | Kirim ke `pwa_push_subscriptions` |
| `public/sw.js` | Tampilkan notifikasi di browser |

---

## 7. Status & flag

| `status` | `paid_flag` | Arti |
|----------|-------------|------|
| `pending` | 0 | QR sudah dibuat, menunggu bayar |
| `paid` | 1 | Callback sukses / ditandai lunas |
| `expired` | 0 | (jika di-set) QR kedaluwarsa |
| `failed` | 0 | (jika di-set) gagal |

Callback hanya meng-update baris yang masih `paid_flag = 0` (anti double-process). Hasil: `newly_paid` | `already_paid` | `not_found` | `error`.

---

## 8. Verifikasi & troubleshooting

### Cek tabel

```sql
SHOW TABLES LIKE 'pwa\_qris%';
SHOW TABLES LIKE 'pwa_log_qris_push';

SELECT id, nocust, vano, amount, status, paid_flag, description, qris_id, paid_at
FROM pwa_qris
ORDER BY id DESC
LIMIT 20;

SELECT * FROM pwa_log_qris_push ORDER BY id DESC LIMIT 20;
```

### Cek generate

- Log Laravel: `QRIS Lazizmu response` → `http:200`, `responseCode":"00"`.
- Ada baris baru `pwa_qris` `pending` dengan `vano` 16 digit (`751000` + 10).

### Cek callback

- Setelah bayar: `pwa_qris.paid_flag = 1`, `paid_at` terisi.
- Ada baris `pwa_log_qris_push`.
- Log Laravel: `webpush notifyPaid` dengan `"sent": ≥ 1` jika ada subscription.

### Masalah umum

| Gejala | Cek |
|--------|-----|
| Tombol Top up QRIS tidak ada | `BRAND_PAYMENT_QRIS=true` + `php artisan optimize:clear` |
| Generate gagal | `QRIS_*` di `.env`, koneksi ke `QRIS_SERVER_URL` |
| Bayar sukses tapi status tetap pending | Callback tidak masuk / prefix VA bukan `751000` / tabel masih nama lama (`mst_qris`) |
| Paid tapi saldo tidak naik | Forward `$forwardBase` ke `DEMO_INSTALLMENT/QRIS.php` / config installment |
| Notifikasi tidak muncul (log `sent>0`) | Pengaturan baterai/autostart Chrome di HP; bukan gagal server |
| History kosong | Description tidak mengandung topup **dan** ada item di `pwa_qris_item` (bukan top up murni) |

---

## 9. Checklist deploy fitur top up

- [ ] Tabel `pwa_qris`, `pwa_qris_item`, `pwa_log_qris_push` ada di DB sekolah  
- [ ] `BRAND_PAYMENT_QRIS=true`  
- [ ] `QRIS_*` sama antara Laravel (dan WS jika dipakai)  
- [ ] `TAGIHAN_DB_*` mengarah ke DB yang sama dengan callback `connectTagihanCicilan`  
- [ ] `pushNotif.php` mengarahkan prefix `751000` ke `demoInstallment.php`  
- [ ] `$forwardBase` di `demoInstallment.php` benar untuk kredit saldo  
- [ ] `WEBPUSH_NOTIFY_URL` + `WEBPUSH_NOTIFY_SECRET` selaras Laravel ↔ islamic_center  
- [ ] Uji: generate → scan/bayar → `paid_flag=1` → saldo naik → (opsional) notifikasi

---

## 10. Referensi terkait

- Setup keseluruhan PWA: [`docs/SETUP-MASTER.md`](SETUP-MASTER.md) (§6b tabel, §7 callback)  
- SQL install: `DEMO_WS_TAGIHAN_CICILAN/sql/install_missing_tables.sql`  
- SQL rename nama lama → `pwa_`: `DEMO_WS_TAGIHAN_CICILAN/sql/rename_tables_to_pwa.sql`
