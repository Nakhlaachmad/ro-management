# Aktivasi FP di produksi

FP Management diaktifkan dengan opt-in eksplisit `FP_ENABLED=true`.
Gate lama `APP_ENV=local && FP_ENABLED` sudah dihapus, sehingga aplikasi
produksi dapat menjalankan FP setelah migrasi, grant, dan konfigurasi
rahasia selesai diverifikasi.

Hasil pemeriksaan kandidat SQL lokal ada di
[`local-candidate-verification.md`](local-candidate-verification.md).

## Prasyarat

1. Gunakan PostgreSQL. Migrasi FP bersifat fail-closed di luar environment
   `testing`; SQLite hanya dipakai untuk test HTTP terisolasi.
2. Pastikan `database/sql/2026_10_13_fp_annual_email_role.sql` ikut terdeploy.
   Berkas ini sudah dikecualikan dari aturan ignore `*.sql` di `.gitignore`
   dan `.dockerignore`.
3. Migrasi F39 produksi tersedia di
   `database/migrations/2026_11_26_000001_add_f39_production_document_operations.php`.
   Dua migrasi F39 lama (`2026_11_18_*`) kini hanya berlaku untuk stage
   disposable `fp_f39_stage68_*` dan tidak memasang skema di produksi.
4. Set `FP_DB_APP_ROLE` sama dengan role aplikasi produksi (`DB_USERNAME`).
   Migrasi F39 produksi memberikan grant baca pada jurnal F39, grant
   `SELECT/INSERT/UPDATE(next_serial)` pada counter NIP tahunan, dan
   `EXECUTE` pada `fp_victory_user_command` kepada role tersebut.
5. Jalankan migrasi dan verifikasi grant/role sebelum menyalakan FP.
6. Isi konfigurasi rahasia langsung di Dokploy, bukan di `.env` yang di-commit:
   - `FP_NIK_LOOKUP_KEY`
   - `FP_NIK_LOOKUP_KEY_ID`
   - kredensial Nextcloud bila `FP_STORAGE_ADAPTER=nextcloud`
7. Tetapkan timezone bisnis, misalnya `FP_BUSINESS_TIMEZONE=Asia/Jakarta`.
8. Biarkan flag opsional (`FP_USER_MANAGEMENT_ENABLED`,
   `FP_ANNUAL_NIP_EMAIL_ENABLED`, `FP_USER_ROLES_ENABLED`,
   `FP_RECRUITMENT_TEAM_ENABLED`, `FP_ORGANIZATION_ENABLED`,
   `FP_OPTIONAL_ONBOARDING_ENABLED`) dalam keadaan `false` kecuali
   diaktifkan satu per satu oleh operator.

## Langkah deploy

```bash
php artisan config:clear
php artisan migrate --force
php artisan config:cache
php artisan route:clear
php artisan view:clear
```

Set `FP_ENABLED=true` di environment Dokploy, lalu clear/cache ulang konfigurasi.
Ketika `FP_ENABLED=false`, provider FP tidak didaftarkan dan route FP
mengembalikan 404. Ketika `true`, route publik `/fp-onboarding`, endpoint
privat `/fp-private`, dan panel `/fp-dev` tersedia sesuai autorisasi Spatie.

## Verifikasi

```bash
php artisan test
php vendor/bin/pint --test config/fp.php config/app.php \
  app/Providers/Filament/FpDevelopmentPanelProvider.php \
  tests/FpCore/DomainMetadataTest.php \
  tests/FpCore/F39ProductionMigrationTest.php \
  tests/FpCore/IntegratedOfflineTest.php \
  tests/FpCore/SqliteMigrationIsolationTest.php
```

Periksa juga bahwa:

- akun HRD/legacy `ro` masih dapat login SSO dan mengakses panel yang
  diizinkan;
- `bm` tidak otomatis berubah menjadi HRD;
- NIK, rekening, OCR, dan token SSO tidak muncul di log;
- tidak ada URL Nextcloud permanen yang diekspos ke publik;
- NIP baru hanya dibuat saat approval akhir berhasil.

## Catatan keamanan `.env`

`.env` sudah dihapus dari tree Git, tetapi commit lama masih menyimpan
kredensial di history. Semua secret yang pernah ada di berkas tersebut
harus dirotasi. Penghapusan history/force-push perlu koordinasi terpisah
dengan Nakhla karena bersifat destruktif.
