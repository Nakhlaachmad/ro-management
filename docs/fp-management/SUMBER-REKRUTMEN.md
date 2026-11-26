# Standardisasi Sumber Rekrutmen

## Implementasi

Label UI canonical Victory FP disatukan menjadi **Sumber Rekrutmen**. List membership dan detail membership/submission memakai satu display; setiap baris riwayat membership memakai display yang sama. Satu filter master pada laporan tetap dipertahankan, sekarang memakai label standar. Onboarding publik tetap tidak menampilkan pilihan perekrutan. Dashboard/User tidak memiliki field perekrutan yang perlu ditambahkan.

Data yang sudah tersimpan tidak diubah. `recruitment_path_id`, snapshot kode/nama dan `source_type`/referensi perekrut tetap berbeda secara teknis. Presentation menggabungkan snapshot dalam satu nilai, contoh `A — Reguler / Umum / TEAM · Nama Team`. Ini bukan mapping A menjadi HRD. Rename master tidak mengubah snapshot historis; legacy MGM ditampilkan apa adanya dengan penanda belum direkonsiliasi. Tidak ada query master tambahan pada formatter.

Approval tetap memiliki satu `recruitment_choice`, label standar dan seluruh opsi/binding existing. Validasi, permission, verifikasi, NIP dan transaksi tidak berubah.

CSV/XLSX laporan memakai sembilan kolom, dengan satu `Sumber Rekrutmen`. Baris laporan memakai formatter yang sama. Formula-injection protection, batas ekspor, no-store dan otorisasi tetap dipertahankan. Range autofilter XLSX dan colspan empty state mengikuti jumlah header.

## Batas impor CSV

Tidak ditemukan importer atau halaman preview impor CSV pada aplikasi canonical. Command migrasi MariaDB ke PostgreSQL bukan importer CSV dan tidak diubah/dijalankan. Implementasi ini tidak membuat workflow impor atau menulis record.

`RecruitmentCsvColumns::resolve()` menyediakan resolver alias `Jalur Rekrutmen`, `Sumber Rekrutmen`, `Jalur`, serta nama underscore. Kedua kolom dengan nilai identik menghasilkan satu nilai; nilai berbeda (termasuk kosong versus terisi) ditolak dengan ValidationException untuk ditinjau. Nilai tidak dipetakan menjadi enum atau master secara otomatis.

Resolver sudah diuji offline, **belum wired** ke importer karena alurnya belum tersedia. Kompatibilitas impor/preview end-to-end **NOT RUN**, bukan PASS. Field/pilihan perekrutan tetap tidak ditambahkan pada portal publik. Resources legacy di luar panel Victory dan kontrak integrasi tidak diubah.

## Verifikasi aktual

- Regresi offline akhir: 32 tests / 267 assertions PASS.
- PostgreSQL laporan read-only: 2 tests / 18 assertions PASS; filter canonical dan sembilan kolom diperiksa.
- Chrome nyata: 15 pemeriksaan PASS, termasuk dashboard, list/detail membership dan submission, master, laporan desktop/mobile light-dark, unduhan CSV/XLSX, onboarding tanpa field rekrutmen.
- Semua fingerprint tabel database utama, .env dan 14 protected hashes sama sebelum/sesudah pemeriksaan. Tidak ada migration, grant, backfill atau mutasi bisnis.
- Dua test tambahan untuk single approval field/binding dan kesamaan row export diuji setelah verifikasi browser; hasil akhir tercatat pada laporan test.

Artefak dan screenshot tersanitasi berada di `outputs/RECRUITMENT-STANDARD` pada workspace audit; hasil lama tidak ditimpa.

Status: UI/laporan/ekspor PASS; keseluruhan PARTIAL untuk alur impor CSV yang belum tersedia.

## File dibuat/diubah

- app/Application/FP/FpReports.php
- app/Application/FP/RecruitmentPaths.php
- app/Application/FP/ReportExport.php
- app/Filament/FP/FpForms.php
- app/Filament/FP/RecruitmentChoice.php
- app/Filament/Resources/FpMembershipResource.php
- app/Filament/Resources/FpRecruitmentPathResource.php
- app/Filament/Resources/FpSubmissionResource.php
- app/Filament/Resources/FpRecruitmentPathResource/Pages/ListRecruitmentPaths.php
- app/Providers/Filament/FpDevelopmentPanelProvider.php
- resources/views/filament/fp/reports.blade.php
- tests/FpReports/ReportPostgresTest.php
- tests/FpReports/ReportsTest.php
- app/Application/FP/RecruitmentPresentation.php
- app/Application/FP/RecruitmentCsvColumns.php
- tests/FpReports/RecruitmentStandardTest.php
- docs/fp-management/SUMBER-REKRUTMEN.md
