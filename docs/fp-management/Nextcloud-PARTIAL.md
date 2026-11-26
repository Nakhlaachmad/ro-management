# NEXTCLOUD — Technical PARTIAL, phase pending

Operator correction: this work is NOT F3.7 UI/UX. The original historical report
below is retained as evidence; its former F3.7 label is superseded by ROADMAP.md.
No Nextcloud activation was performed. F3.7A now covers Design System & Dashboard.

---
# F3.7 — Private Nextcloud Storage

F3.6: **CONDITIONALLY ACCEPTED**, sesuai keputusan operator.
F3.7: **PARTIAL** — source adapter dan kompatibilitas storage diimplementasikan;
upload asynchronous end-to-end belum dapat diaktifkan karena dependency di bawah.

## Spesifikasi yang digunakan

- `outputs/prd.md` §18 Nextcloud, §19 security, §20 performance/reliability: dokumen privat, UUID locator, tidak menunggu operasi external berat pada request.
- `outputs/system_design.md` §14 Nextcloud Integration, §15 Queue: staging privat → metadata → response → after-commit worker → Nextcloud → READY; retry/idempotency.
- `outputs/architecture.md` §16 Port/Adapter, §17 Queue: DocumentStorage port, adapter WebDAV terisolasi, worker retry/backoff, tidak menahan DB lock selama network call.
- `C:/laragon/www/RO_management/docs/fp-management/F2-FAST.md`: adapter existing hanya fake-tested; existing storage_adapter harus dipertahankan.
- Keputusan fase operator sebelumnya: Nextcloud ditunda ke F3.7 sebagai tahap terakhir sebelum deployment.

Tidak ditemukan dokumen terpisah bernama spesifikasi F3.7 dengan acceptance lain.
Implementasi terbatas pada kontrak Nextcloud yang sudah tertulis; tidak menambah
fitur HRD, PDF support, deployment atau migrasi dokumen existing.

## Implementasi aktual

1. `app/Infrastructure/FP/NextcloudStorage.php`: validasi HTTPS endpoint tanpa URL credential/query/fragment; strict UUID locator; TLS verification; no redirects; connect timeout 5s/request timeout 30s; bounded streamed read (16 MiB); sanitasi exception tanpa URL/credential/upstream payload; MKCOL 405 dan DELETE 404 idempotent; upload diverifikasi melalui read-back SHA-256.
2. `app/Infrastructure/FP/DocumentStorageRegistry.php`: resolve adapter per metadata (`local`/`nextcloud`), unknown adapter fail closed; adapter lokal existing tetap digunakan tanpa perubahan locator/data.
3. `app/Http/Controllers/FP/DocumentController.php`: authorization dan READY/deletion guards tetap; metadata menentukan storage; failure menjadi 503; byte count/SHA/no-store tetap.
4. `app/Jobs/FP/DeleteRejectedDocuments.php`: retry/backoff/afterCommit/audit existing tetap; deletion memilih adapter per dokumen, tidak berdasarkan default global saja.
5. `tests/FpStorage/NextcloudStorageTest.php`: HTTP fakes prevent stray requests; upload/readback/corruption, idempotent deletion, redirect/TLS/timeouts, invalid configuration/locator, sanitized failure, size limit, mixed storage registry.
6. `tests/FpCore/IntegratedOfflineTest.php`: hanya request-count fake WebDAV disesuaikan 4 → 5 karena put sekarang memiliki GET read-back, kemudian test melakukan GET tersendiri. Tidak ada ekspektasi SSO/JWT/legacy yang diubah.

Backup source sebelum perubahan: `work/f37/before` pada workspace asli.
Tidak ada config/.env change. Default storage utama tetap local; tidak ada upload,
delete, migrasi atau koneksi Nextcloud nyata.

## Hasil aktual

| Pengujian | Hasil | Scope |
|---|---|---|
| Adapter/storage registry | 9 tests / 26 assertions PASS | Unit + fake WebDAV, no real network/DB |
| Existing Nextcloud regression | 3 tests / 6 assertions PASS | Fake WebDAV, strict no-database bootstrap |
| Disposable PostgreSQL audit guard | PASS | fp_f2_test_f36_complete_20261012, fp_f36_accept_app, cluster 7694154787604477244; NULL-actor worker audit rejected dengan SQLSTATE 23514/f2_audit_actor_ck; transaction rolled back, audit rows fingerprint unchanged |
| Browser local-storage regression | 10 checks PASS | Actual Chrome, HRD utama login/viewer/NIP/Email/report read paths; no business record mutations |
| Formatting/lint/diff | PASS | Pint changed files, controller lint, git diff --check |
| Real Nextcloud integration | NOT RUN | Tidak ada staging endpoint/user/app-password configured |
| Queued upload/retry/READY end-to-end | BLOCKED | Audit worker contract dan asynchronous queue belum tersedia |

Browser F3.6 evidence byte comparison tetap identik; hasil F3.7 disalin ke file
`browser-local-storage-regression.json` terpisah. Failure evidence/baseline tidak
dihapus atau diganti untuk meloloskan pemeriksaan.

## Dependency teknis yang terbukti

`f2_core_audit_completeness` mewajibkan event DOCUMENT_CHANGED untuk perubahan
processing_state/storage_adapter/private_path/content, dalam transaksi mutation.
`f2_audit_actor_ck` mengharuskan actor_id positif untuk semua event selain SUBMITTED.
Submission publik tidak memiliki HRD actor yang dapat dipakai secara sah untuk
worker otomatis; menggunakan ID HRD acak akan memalsukan provenance audit.

Guard dibuktikan pada application role disposable: INSERT DOCUMENT_CHANGED
dengan actor_id NULL ditolak oleh constraint tersebut. Tabel audit dipertahankan.
Tidak ada guard yang dinonaktifkan atau historical migration yang diedit.

Dengan demikian **jalur otomatis public staging → async Nextcloud → READY belum
diimplementasikan/diaktifkan**, dan put sinkron existing tidak dipilih sebagai
solusi produksi. Storage default tetap local, sehingga perilaku aktif F3.6 utuh.

Kebutuhan lanjutan yang spesifik:
- Keputusan kontrak audit untuk processing oleh worker sistem (event/ownership,
  provenance worker dan transaksi completeness), kemudian SQL additive/guard
  proposal yang ditinjau dan diuji disposable. Tidak ada SQL utama dijalankan;
  setiap perubahan utama tetap memerlukan persetujuan SQL spesifik.
- Staging Nextcloud privat dan app-password melalui secret configuration aman;
  jangan kirim credential plaintext di laporan/chat. Uji external dilakukan hanya
  pada folder synthetic staging, bukan dokumen utama.
- Queue asynchronous yang didukung; konfigurasi utama saat ini sync. Jangan
  menyatakan afterCommit saja membuat queue sync menjadi asynchronous.

Sisa acceptance akun/transaksi nyata F3.8 bukan dependency teknis adapter F3.7.

## Integritas utama

Database tetap 19 ledger / 24 tabel / 18 function / 26 trigger; users 4,
managed 2, user audit 4, singleton NIP 1005, submissions 6. Fingerprint 22 tabel
historis, ACL existing, 14 protected files, 2 file DPAPI dan 15 dokumen cocok.
.env feature flag activation hash tetap sama. Tidak ada migration, grants,
account/FP/NIP mutation, source protected/SSO/JWT/IWACS/RO change, staging/commit,
production/Odoo access atau deployment pada pekerjaan F3.7 ini.

## Penutupan dan backlog

Project docs `conditional-acceptance.md` dan `operational-acceptance-backlog.md` mencatat penutupan
serta F3.8-01 sampai F3.8-10, dengan status/dependency/acceptance/evidence.
NOT RUN tidak berubah menjadi PASS. F3.7 belum complete/production-ready.
