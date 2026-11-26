# F3.7F — Premium Dashboard, Reporting, Tables & Navigation

Status keseluruhan: **PARTIAL**. Fitur aman selesai dan teruji; filter BM/cabang serta ekspor sensitif memerlukan keputusan domain/izin. Visual acceptance tetap milik operator.

## Tahap 1 — UI global: PASS

- Lima tabel canonical Victory memiliki sorting server-side untuk kolom yang benar-benar mendukungnya: nama, NIP, status, Team/leader, snapshot Sumber Rekrutmen, tanggal pendaftaran/keluar sesuai resource. Dibuat/Diperbarui tersedia sebagai kolom opsional tersembunyi agar layout default tetap bersih.
- Sumber computed diurutkan melalui kolom snapshot database dan ID sebagai tie-breaker. Relasi menggunakan sorting Filament; tidak melakukan sorting koleksi PHP atau join yang menggandakan membership. Kolom computed Riwayat/Pemeriksaan dan multi-role badge tidak diberi sortable palsu.
- Tindakan tabel memakai surface, focus/hover dan tooltip; rejection/exit memakai danger color. Action, ikon, authorization dan workflow existing tetap ada.
- Sidebar hanya kehilangan caption WORKSPACE. Topbar memakai lonceng/badge dan popover berisi ID/status/waktu tanpa biodata sensitif. Polling existing 15 detik serta toast perubahan tetap dipertahankan. Status baca disimpan pada session file per actor, bukan histori baca lintas sesi; maksimum 30 pengajuan terbaru mengikuti snapshot existing. Guest ditolak server-side.
- Master tidak menampilkan kode pada create/edit/list. Create tanpa kode menghasilkan `R` + 16 digit hex, tetap memenuhi constraint kode existing. Edit mempertahankan kode. Kode/snapshot historis tidak diubah.
- Chrome terakhir menguji seluruh kolom sortable yang terlihat pada lima tabel; bukan hanya kolom pertama. Enam kombinasi ukuran/tema tidak mengalami horizontal overflow. Create modal dibuka tanpa submit di main.
- Disposable PostgreSQL `fp_f2_test_f36_complete_20261012`, cluster `7694154787604477244`, role `fp_f36_accept_app`: create/edit master admin, HRD dan RO PASS; BM create ditolak. Satu outer transaction ACTIVE dibatalkan, 0 committed rows. Hanya sequence identity master disposable dapat maju karena semantics PostgreSQL; tidak di-reset. Main tidak menjadi target mutasi.

## Tahap 2 — Reporting: PARTIAL

- Filter nyata: status, Team, Sumber Rekrutmen, nama, NIP exact, tanggal referensi atau tanggal keluar. SQL parameter-bound; wildcard nama di-escape dan NIP memakai exact lookup existing.
- Preset hari ini, 7/30 hari terakhir (inklusif), bulan/tahun, tahun, tanggal/rentang. Timezone FP `Asia/Jakarta`; tanggal masuk tetap reference_date domain existing, bukan tanggal approval atau timestamp yang dibuat-buat. DatePicker existing dipertahankan.
- Pilih Kolom Ekspor menawarkan 11 kolom: 9 default existing + Email dan Alasan keluar. Pilih Semua/Reset, minimal satu kolom, urutan canonical, validasi backend dan kolom Sumber Rekrutmen tunggal. CSV dan XLSX mengikuti query/filter yang sama. Default tidak mengekspor data sensitif.
- NIK/rekening/telepon/alamat **BLOCKED untuk semua role**, termasuk bila memiliki izin full-view. Full-view tidak dianggap izin ekspor sensitif. Backend mengembalikan 403, unknown/empty selection 422. Tidak membuat permission atau grants baru.
- BM/cabang **BLOCKED**: canonical membership/team tidak menyediakan relasi penanggung jawab/cabang yang telah ditetapkan. Tidak menggunakan legacy name/role sebagai mapping tanpa keputusan. Tidak ada filter palsu; UI menjelaskan batas ini.
- Existing row scope dan role policy tetap digunakan. BM basic report download yang sebelumnya sudah disetujui tidak diperluas menjadi sensitive export.
- Formula injection CSV, XLSX inline strings, batas 5.000 rows/8 MiB, private/no-store, authentication dan rate limit 5/minute tetap berlaku. Range autofilter XLSX sesuai pilihan header. Audit operational log tetap mencatat actor/format/bytes/kolom/filter tanpa isi hasil, nama pencarian atau NIP.
- Chrome: pilihan 11/9 kolom, preset today, download CSV/XLSX custom, NIK 403, empty 422 dan enam viewport/tema PASS. Keempat kolom sensitif diuji backend; tidak mengklaim empat endpoint HTTP telah diuji sekaligus.

## Tahap 3 — Dashboard: PASS

- Logo/caption FINANCIAL PLANNER WORKSPACE pada area hero diganti jam analog dengan jarum jam/menit/detik dan hari/tanggal/bulan/tahun. Logo sidebar dan file logo dipertahankan; CTA existing tetap ada.
- JavaScript ringan, tanpa library baru. Satu timer 1 detik, pause saat document hidden, resume saat visible, listener/timer dibersihkan saat Alpine unmount. Tidak menyimpan data sensitif browser.
- Ringkasan Team aktif: active FP, masuk dan keluar bulan kalender berjalan. 50 Team pertama ditampilkan dengan keterangan bila lebih banyak; total Team tetap akurat. Tidak membuat aturan scope BM baru; mengikuti akses data global existing yang telah disetujui.
- Breakdown Sumber Rekrutmen bulan berjalan menggunakan snapshot canonical dan reference_date, tanpa double count atau mapping MGM. Periode berganti saat kalender berubah; tidak menghapus histori.
- Filament ChartWidget existing dipakai: enam bulan, masuk/keluar per bulan pada sumbu kiri, jumlah membership masuk kumulatif pada sumbu kanan. Total kumulatif termasuk EXITED, bukan jumlah ACTIVE. Record tanpa tanggal masuk tetap termasuk Total FP, dikecualikan dari grafik. Team transfer sendiri tidak menjadi event perusahaan karena query hanya membaca tanggal masuk/exit existing.
- Polling analytics/chart 60 detik, tanpa query per detik. Tidak ada dependency/install atau migration.
- SQL read-only independen membandingkan jumlah masuk, active per Team, cumulative, dan query bulan berikutnya. Chrome menguji enam ukuran/tema, jam bergerak, canvas chart nyata, actual Alpine cleanup, Membership View read-only/private viewer same-tab dan submission detail.

## Bukti pengujian

| Kelompok | Hasil aktual |
|---|---|
| Current F2/F3 feature + directory/report/role regression | 239 tests / 1.238 assertions PASS, 4 PHPUnit deprecation notices |
| Final focused UI/dashboard/report/infolist regression | 22 tests / 154 assertions PASS |
| PostgreSQL main READ ONLY | 4 tests / 33 assertions PASS, termasuk filter/sort/pagination via CTE tanpa fixture writes |
| Clock Node tests | 3 PASS |
| Disposable mutation checks | 5 PASS; 0 committed rows |
| Stage 1 Chrome | 13 checks PASS, semua sort visible lima tabel |
| Stage 2 Chrome | 12 checks PASS |
| Stage 3 Chrome | 13 checks PASS |
| Final dashboard screenshot pass | 6 viewport/tema PASS |
| Archived F2.1 core tests, bootstrap khusus yang benar | 52 PASS / 2 FAIL; 54 tests / 230 assertions |

Dua failure arsip menuntut string literal `'enabled' => false` pada config/fp.php. Config tersebut sudah menggunakan conditional local env activation sejak F3.6 yang disetujui, dan **tidak diubah F3.7F**. Tidak melemahkan test atau mengubah konfigurasi/baseline agar test ini hijau. Combined run pertama memakai bootstrap yang salah bagi FpCoreNoDatabase; hasil tersebut dipertahankan di regression.txt dan tidak diklaim PASS.

Defect yang ditemukan dan diperbaiki: selector browser sorting memakai role=button; helper menunggu hasil Livewire, bukan fixed delay; limiter menolak request keenam (429, limiter tidak diubah); CSS legacy menyembunyikan clock pada mobile; sortable sempat ikut dipasang pada TextEntry detail, lalu dihapus dan ditambah test infolist. Kegagalan bootstrap disposable sebelum koneksi ditangani pada helper Windows absolute-cache prefix existing, tanpa app/host repair. Catatan kegagalan tidak dihapus.

## Integritas dan batas

Before/after semua fingerprint tabel main sama, termasuk users, memberships, submissions, audit, ledger dan NIP counters. Identitas tetap `fp_management_synthetic_dev`, `127.0.0.1:55432`, cluster `7694154787604477244`. .env dan 14 protected hashes sama. Tidak ada migration, grants, reset, backfill, main business mutation, credential/role changes, Nextcloud activation, stage/commit atau deployment. F3.6 tetap CONDITIONALLY ACCEPTED; acceptance operasional main untuk role lain tetap F3.8, tidak diberi PASS fiktif.

## Evidence

Artefak tersanitasi berada di workspace audit `outputs/F3.7F`: before/after-integrity.json, final-regression.txt, final-targeted-tests.txt, postgres-reports.txt, disposable.json, clock-tests.txt, stage1/2/3-browser.json, final-screens.json, archived-f2-core.txt dan failure records. Screenshot tahap 1/2 dan final-dashboard tersedia untuk 1440/834/390 light/dark. Screenshot tidak menampilkan biodata pribadi/credentials/dokumen.

Langkah keputusan yang tersisa: tetapkan role/izin ekspor sensitif per kolom dan relasi BM/cabang yang sah. Jangan mengaktifkan keduanya lewat tebakan atau grant otomatis. Review visual oleh operator belum diberikan.

## File dibuat/diubah

- app/Application/FP/FpReports.php
- app/Application/FP/RecruitmentPaths.php
- app/Application/FP/ReportExport.php
- app/Application/FP/ReportFilters.php
- app/Filament/FP/SubmissionUpdates.php
- app/Filament/Pages/FP/Dashboard.php
- app/Filament/Pages/FP/Reports.php
- app/Filament/Resources/FpMembershipResource.php
- app/Filament/Resources/FpRecruitmentPathResource.php
- app/Filament/Resources/FpSubmissionResource.php
- app/Filament/Resources/FpTeamResource.php
- app/Filament/Resources/VictoryUserResource.php
- app/Http/Controllers/FP/ReportController.php
- app/Providers/Filament/FpDevelopmentPanelProvider.php
- public/fp/victory-premium.css
- resources/views/filament/fp/dashboard.blade.php
- resources/views/filament/fp/reports.blade.php
- resources/views/filament/fp/submission-updates.blade.php
- resources/views/filament/fp/theme-head.blade.php
- app/Filament/FP/MembershipSorting.php
- app/Application/FP/ReportPeriods.php
- app/Application/FP/ReportColumns.php
- app/Application/FP/FpDashboardAnalytics.php
- app/Filament/Widgets/FpFlowChart.php
- public/fp/victory-clock.js
- resources/views/filament/fp/clock.blade.php
- resources/views/filament/fp/dashboard-widgets.blade.php
- tests/Feature/PremiumOperationsTest.php
- tests/Feature/PremiumReportingTest.php
- tests/Feature/PremiumDashboardTest.php
- tests/FpReports/PremiumReadOnlyPostgresTest.php
- tests/FpFrontend/clock.test.cjs
- docs/fp-management/premium-operations.md
