# Verifikasi kandidat SQL lokal

Tanggal verifikasi: 2026-11-26.

## Sumber kandidat

Kandidat ditemukan di `E:\RO_management.zip`, folder `RO_management/`.
Berkas yang dibandingkan:

- `database/sql/2026_10_13_fp_annual_email_role.sql`
- `database/migrations/2026_11_18_000001_add_f39_document_operations.php`
- `database/migrations/2026_11_18_000002_add_f39_reader_diagnostics.php`
- `app/Providers/F39DisposableToolsServiceProvider.php`
- `routes/f39-disposable.php`
- `config/fp.php`
- `config/app.php`

## Hasil

### SQL tahunan

`database/sql/2026_10_13_fp_annual_email_role.sql` dari ZIP **identik byte-for-byte**
dengan berkas yang sudah track di repo.

```text
SHA256 F810C44870BAD16C3D217458F82E6B15D4177F77D3372DC59913CC299B3BA413
```

Namun baris pertama berkas menyatakan:

```text
-- PROPOSED ONLY. NOT EXECUTED. Requires explicit schema/function approval.
```

Artinya kesamaan hash hanya membuktikan kandidat lokal sama dengan salinan
repo. Ini **bukan** persetujuan produksi. Nakhla tetap harus mengonfirmasi
bahwa SQL ini final untuk produksi.

### F39

Dalam ZIP hanya ditemukan artefak F39 disposable:

- `2026_11_18_000001_add_f39_document_operations.php`
- `2026_11_18_000002_add_f39_reader_diagnostics.php`
- `F39DisposableToolsServiceProvider.php`
- `routes/f39-disposable.php`

Isinya sama dengan kode repo sebelum penyesuaian test SQLite. Guard masih
mengikat ke database `fp_f39_stage68_*`, cluster `7694154787604477244`,
host `127.0.0.1`, dan port `55432`. Tidak ada migrasi F39 produksi di kandidat.

`main-activation-proposal.sql` di folder uji lokal juga masih bersifat proposal
dan mengarah ke database sintetis `fp_management_synthetic_dev`, bukan produksi.

### Role database

Berkas `disposable-annual-grants-proposal.sql` dan `role-command-proposal.sql`
di folder uji lokal bertanda `PROPOSED ONLY` / `NOT EXECUTED` dan ditujukan
untuk database disposable. Tidak ada konfigurasi role/grant produksi final
yang bisa dipakai.

### Aktivasi FP

Kandidat ZIP masih memakai gate lama `APP_ENV=local && FP_ENABLED`.
Branch ini sudah menggantinya dengan opt-in eksplisit `FP_ENABLED=true` agar
dapat dipakai di produksi setelah approvasi.

## Keputusan

Kandidat lokal **tidak membuka blokir deploy produksi**. Yang sudah terverifikasi
hanya kesamaan SQL tahunan. Blokir yang masih berlaku:

1. migrasi F39 versi produksi dari Nakhla;
2. konfigurasi role/grant database produksi;
3. konfirmasi tertulis bahwa SQL tahunan final untuk produksi;
4. environment/secret produksi di Dokploy;
5. approval eksekusi produksi.

Tidak ada migrasi, perubahan data, atau deploy produksi yang dilakukan.
