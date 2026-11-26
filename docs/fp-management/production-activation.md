# Aktivasi FP di produksi

FP Management diaktifkan dengan opt-in eksplisit `FP_ENABLED=true`.
Gate lama `APP_ENV=local && FP_ENABLED` sudah dihapus, sehingga aplikasi
produksi dapat menjalankan FP setelah migrasi, grant, dan konfigurasi
rahasia selesai diverifikasi.

## Prasyarat

1. Gunakan PostgreSQL. Migrasi FP bersifat fail-closed di luar environment
   `testing`; SQLite hanya dipakai untuk test HTTP terisolasi.
2. Pastikan `database/sql/2026_10_13_fp_annual_email_role.sql` ikut terdeploy.
   Berkas ini sudah dikecualikan dari aturan ignore `*.sql` di `.gitignore`
   dan `.dockerignore`.
3. Migrasi F39 yang ada di repo masih bertujuan untuk disposable stage
   `fp_f39_stage68_*` dan cluster tertentu. Minta versi produksi dari Nakhla
   beserta konfigurasi role database sebelum menjalankan deploy produksi.
4. Jalankan migrasi dan verifikasi grant/role sebelum menyalakan FP.
5. Isi konfigurasi rahasia langsung di Dokploy, bukan di `.env` yang di-commit:
   - `FP_NIK_LOOKUP_KEY`
   - `FP_NIK_LOOKUP_KEY_ID`
   - kredensial Nextcloud bila `FP_STORAGE_ADAPTER=nextcloud`
6. Tetapkan timezone bisnis, misalnya `FP_BUSINESS_TIMEZONE=Asia/Jakarta`.
7. Biarkan flag opsional (`FP_USER_MANAGEMENT_ENABLED`,
   `FP_ANNUAL_NIP_EMAIL_ENABLED`, `FP_USER_ROLES_ENABLED`,
   `FP_RECRUITMENT_TEAM_ENABLED`, `FP_ORGANIZATION_ENABLED`,
   `FP_OPTIONAL_ONBOARDING_ENABLED`) dalam keadaan `false` kecuali
   diaktifkan satu per satu oleh operator.

## Langkah deploy

```bash
php artisan migrate --force
php artisan config:clear
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
