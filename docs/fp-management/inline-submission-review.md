# F3.7D — Inline FP Submission Review & Fixed Approval Bar

Tanggal: 14 Oktober 2026. **Status: PARTIAL. Implementasi dan acceptance HRD/main serta approval/rejection disposable PASS; browser acceptance empat role belum selesai. VISUAL ACCEPTED belum diberikan.**

## Implementasi selesai

Halaman list `/fp-dev/fp-submissions` tetap tabel existing. Halaman detail `/fp-dev/fp-submissions/{record}` sekarang menampilkan:

1. Header “Detail Pengajuan FP”, status/subheading existing.
2. Foto profil pendaftaran melalui endpoint privat authorized, dengan placeholder bila tidak READY, terhapus atau revisi tidak sesuai.
3. Seluruh biodata existing, status/revisi verifikasi, riwayat membership dan riwayat tindakan. NIP membership pengajuan ini, Jalur/sumber dan identitas pengajuan ditambahkan sebagai informasi existing; tidak menerbitkan atau memodifikasi nilai.
4. KTP dan ijazah di akhir konten, dengan preview aman dan viewer inline pada tab yang sama.

Viewer memanggil route `fp.document`, URL `/fp-private/documents/{uuid}`. Tidak memakai public storage URL dan tidak mengirim private_path ke markup. Dialog menggunakan kembali `resources/js/fp/viewer.js` existing untuk navigasi, zoom, rotate, reset, fullscreen, close dan pemulihan fokus. Halaman detail tidak memerlukan navigasi ke `/fp-private/review/{uuid}`. Route standalone lama tetap tersedia tanpa perubahan.

Bar keputusan menggunakan **action Filament existing** `approve`/`reject`, bukan workflow baru. Action tetap terdaftar dalam cache Filament tetapi tidak diduplikasi pada header detail. Koreksi/verifikasi tetap tersedia pada header sesuai izin existing. Body method tabel list dan `reviewActions()` dibandingkan dengan backup dan **byte-identical**.

Bar `position:fixed; bottom:0; z-index:20`. Batas kiri diukur dari container konten Filament melalui ResizeObserver, tanpa mengubah sidebar. Tinggi bar diukur untuk padding akhir konten; aman terhadap safe-area mobile. Desktop berdampingan, mobile bertumpuk dengan tap area minimal 44 px. Approve memakai Victory Blue termasuk disabled state; reject semantic danger. Modal/notification overlay tetap berada di atas bar. Observer dibersihkan saat komponen dihancurkan.

Guard mount/call server-side, permission, disabled revision checks, modal konfirmasi, typed recruitment choice, engine approval/rejection, allocator NIP, audit dan storage controller tidak diubah. BM/final status mengikuti visibility existing; tidak ada bar mutation untuk BM atau submission final.

## Files aplikasi/test yang dibuat atau diubah

- `C:/laragon/www/RO_management/app/Filament/Resources/FpSubmissionResource/Pages/ViewFpSubmission.php`
- `C:/laragon/www/RO_management/app/Filament/Resources/FpSubmissionResource.php` — infolist saja; list/actions tidak berubah.
- `C:/laragon/www/RO_management/resources/views/filament/fp/submission-review.blade.php` — baru.
- `C:/laragon/www/RO_management/resources/views/filament/fp/submission-document.blade.php` — baru.
- `C:/laragon/www/RO_management/public/fp/victory-premium.css` — tambahan styling scoped detail.
- `C:/laragon/www/RO_management/tests/Feature/InlineSubmissionReviewTest.php` — baru.
- `C:/laragon/www/RO_management/docs/fp-management/inline-submission-review.md` — laporan ini.

Helper/evidence berada dalam workspace `work/f37d` dan `outputs/F3.7D`, bukan dependency runtime aplikasi.

## Hasil aktual

| Pengujian | Hasil |
| --- | --- |
| Offline targeted + regression | **PASS: 27 tests / 251 assertions**; action confirmation/revision, admin/hrd/ro/BM visibility, BM direct action authorization 403, final states, document permission/revision/READY/deletion, design system, directory, reports, annual NIP/email/roles |
| PHP lint / Pint / CSS parser / git diff --check | PASS; git menampilkan warning CRLF pada vite.config.js existing, file tersebut tidak diedit pada fase ini |
| Main real Chrome | **PASS: 9 kelompok checks**, mencakup detail pada 1440/834/390 light/dark, urutan/kelengkapan fields, scroll top/middle/bottom, fixed bar, sidebar, modal penolakan/cancel, KTP/ijazah loaded dan same tab, dokumen bawah tidak tertutup, tanpa overflow atau JS error; FP Aktif/User/onboarding masing-masing HTTP/browser 200 tanpa bar detail |
| Disposable engine/application-role regression | **PASS: 46 checks**, transaksi sintetis ACTIVE di-ROLLBACK; approval/NIP/rejection/audit/idempotency dan constraints existing |
| Disposable real UI workflow | **PASS: 7 checks**; dua fixture baru melalui existing engine, verifikasi biodata/dokumen, approval memakai Jalur existing dan konfirmasi, rejection memakai reason dan konfirmasi; bar hilang pada status final |
| Verifikasi independen disposable read-only | **PASS: 5 checks**; satu APPROVED dengan satu ACTIVE membership/NIP tahunan, satu REJECTED tanpa membership, masing-masing tepat satu audit terminal |
| Main/protected integrity | **PASS**: identity cluster `7694154787604477244`, database `fp_management_synthetic_dev`, port 55432; fingerprint dan jumlah baris semua tabel public sebelum/sesudah identik; .env dan 14 protected hashes cocok dengan manifest original |
| Four-role real-browser acceptance | **PARTIAL / belum selesai**; helper tidak memperoleh bukti valid untuk semua direct mutation 403 BM. Jangan dianggap PASS. Bukti offline role guard PASS terpisah |

Mutasi hanya pada `fp_f2_test_f36_complete_20261012`, cluster yang sama, role `fp_f36_accept_app`. Dua submission sintetis baru, dokumen, Team terkait, satu approval/membership/NIP dan satu rejection beserta audit dipertahankan. Tidak dibuat akun/role baru; tidak di-reset/drop/truncate. Tidak ada data bisnis utama yang berubah.

## Temuan dan batas acceptance

- First browser check menemukan bar menutupi sidebar karena class `fi-main-ctn-sidebar-open` tidak ada pada konfigurasi sidebar existing. Implementasi diperbaiki menjadi pengukuran container aktual; keenam viewport/theme kemudian PASS.
- Selector helper `[role=dialog]` tidak cocok dengan markup modal Filament pada detail; diganti `.fi-modal-window:visible`. Modal/cancel dan konfirmasi workflow kemudian benar-benar diuji.
- Fixture PENDING lama pada disposable menghasilkan `The MAC is invalid` dengan key runtime sekarang. Data lama tidak diperbaiki/backfill; dua fixture baru dibuat melalui workflow existing khusus disposable.
- Private preview pada runtime disposable sempat HTTP 503, meski file terbaca dan ukuran/hash cocok di CLI. PHP CLI memiliki `variables_order=GPCS`; server uji sementara kemudian memakai `-d variables_order=EGPCS`. Root cause penuh dan hasil empat-role document checks belum lengkap; bukan PASS. Main HRD KTP/ijazah viewer sudah benar-benar PASS.
- Helper BM sempat menangkap HTTP 200 dan kemudian timeout saat menunggu response mountAction. Source guard tidak diubah atau dibypass. Final helper mencoba fresh component per denial, lalu request signed snapshot/CSRF dari sesi browser. Percobaan terakhir GET login runtime disposable timeout; stdout server mengonfirmasi Maximum execution time of 30 seconds exceeded pada vendor/composer/ClassLoader.php:429. Tidak dilakukan patch vendor/runtime atau bypass guard. BM server-side denial terbukti offline, tetapi acceptance browser BM masih gap.
- Data bisnis utama tidak dipakai untuk approval/rejection sintetis. Main login yang diuji hanya existing HRD; tidak dibuat actor Admin/RO/BM pada main.
- Screenshot memakai mask untuk biodata, input, user-menu dan gambar dokumen; bidang warna abu-abu pada screenshot merupakan sanitasi, bukan isi dokumen.

## Evidence

`C:/Users/HP/Documents/Codex/2026-10-07/saya-ingin-melanjutkan-pengembangan-existing-project/outputs/F3.7D`:

- `browser.json` — 9 kelompok Chrome checks.
- `offline.txt` — 27/251.
- `postgres.json` — ringkasan actual 46 checks engine.
- `workflow-browser.json`, `disposable-after.json`, `disposable-fixtures.json` — actual workflow dan verifikasi independen.
- `before-integrity.json`, `after-integrity.json` — semua fingerprint utama dan .env. `remaining-acceptance.json` mencatat gap browser terakhir.
- `light/dark-desktop/tablet/mobile-top/bottom.png` — screenshots saat scroll.

## Kondisi akhir / langkah berikutnya

Verifikasi listener terakhir hanya menemukan PostgreSQL aktif di 55432. Server uji 8001 tidak lagi aktif; server utama 8000 juga tidak aktif pada pemeriksaan terakhir. Tidak ada perintah penghentian terhadap server utama; hanya proses server 8001 yang dibuat pada fase ini yang dihentikan.

Untuk melihat UI dari VS Code pada project, gunakan PHP 8.4.26:

```powershell
cd C:\laragon\www\RO_management
& "C:\laragon\bin\php\php-8.4.26-Win32-vs17-x64\php.exe" artisan serve --host=localhost --port=8000
```

Buka `/fp-dev/fp-submissions`, lalu detail pengajuan. Sisa acceptance spesifik: selesaikan browser BM direct-action 403 dan document-view checks empat role pada disposable dengan helper response yang teramati. Tidak perlu mengulang implementasi utama atau memodifikasi protected code.

Tidak ada SQL/migration/grants/schema changes, source SSO/JWT/IWACS, role/permission logic, business engine, accepted .env, Nextcloud, deployment atau stage/commit pada fase ini.
