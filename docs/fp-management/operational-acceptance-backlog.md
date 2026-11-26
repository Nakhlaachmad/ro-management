# F3.8 — Operational Acceptance Backlog

F3.6 CONDITIONALLY ACCEPTED berdasarkan keputusan operator; backlog ini tidak
mengubah hasil test F3.6. Tidak ada eksekusi/backfill/reset password atau transaksi
fiktif pada database utama yang diotorisasi oleh pencatatan backlog.

| ID | Pekerjaan | Dependency | Acceptance criteria | Status |
|---|---|---|---|---|
| F3.8-01 | Login Admin utama | Akun admin sah dan akses operator; tidak membuat fixture permanen | Login browser nyata, session, dashboard; tidak mengubah password/role | NOT RUN — menunggu akun sah |
| F3.8-02 | Login RO utama | Akun ro sah dan akses operator | Login browser nyata dan panel sesuai role | NOT RUN — menunggu akun sah |
| F3.8-03 | Login BM utama | Akun bm sah dan akses operator | Login nyata; full-view, mutation 403, scoped CSV/XLSX download | NOT RUN — menunggu akun sah |
| F3.8-04 | Menu/CRUD/authorization/download semua role utama | 01–03; aksi bisnis yang sah dan izin scoped jika mutating | Navigation/direct URL/Livewire/service sesuai final matrix; admin-only permission management; target managed/non-SSO; laporan scope sama, formula-safe/no-store | PARTIAL — HRD utama dan empat role disposable PASS; role lain utama NOT RUN |
| F3.8-05 | Submission/approval/rejection/NIP utama | Peserta nyata yang sah, dokumen sah, HRD review, transaksi bisnis/operator consent | Email valid lowercase; duplicate/idempotency; review evidence; Jalur aktif; approved NIP YY1001 sesuai counter; rejected tidak mendapat NIP; histori utuh | NOT RUN pada utama — transaksi bisnis nyata belum tersedia |
| F3.8-06 | Notifikasi/audit/dokumen setelah transaksi nyata | 05; private storage tersedia | Polling tanpa refresh; tepat audit terminal; document authorization/integrity/no-store; guest denied | PARTIAL — viewer utama dan workflow disposable PASS; pascatransaksi utama NOT RUN |
| F3.8-07 | Logout utama dan private-session invalidation | Akun sah; assessment remember_token dan persetujuan sempit jika SQL auth write diperlukan | Browser logout, session invalidation, private endpoint denied; protected SSO redirect preserved | NOT RUN utama; disposable PASS |
| F3.8-08 | SSO-linked target denial pada accepted environment | Existing approved linked target; tidak create/enroll fixture | Non-mutating denial HTTP/Livewire terhadap legacy/SSO target; no protected integration change | NOT RUN utama; disposable denial evidence dipertahankan |
| F3.8-09 | Kelengkapan UI desktop/mobile utama | Akun sah untuk tiap role | Navigation, User search/filter/pagination/detail/forms, review/date picker, Team selector, viewer seluruh role; no unintended writes | PARTIAL — subset utama dan disposable evidence tersedia |
| F3.8-10 | Performance cold/warm dan pagination multi-page final | Environment terisolasi, dataset sah/synthetic disposable; server ownership pasti | Durasi cold/warm dan jumlah page aktual dicatat; tidak memakai record count sebagai bukti pagination browser | PARTIAL — tutup berdasarkan evidence aktual yang tersedia, sisa measurement NOT RUN |

Semua mutasi acceptance harus memakai transaksi bisnis nyata yang sah. Jangan
menambah akun sintetis permanen, submission/approval fiktif, mengubah password/
role akun existing, atau menghapus histori. Hash baseline dan failure evidence
tidak diubah. Dependency akun operasional F3.8 bukan technical blocker F3.7.

Referensi bukti:
Evidence root absolut: `C:/Users/HP/Documents/Codex/2026-10-07/saya-ingin-melanjutkan-pengembangan-existing-project/outputs`.
- `FP-MAIN-ACTIVATION/report.md`, `activation-result.json`, `browser-main.json`, `browser-disposable.json`, `post-integrity.json`.
- `FP-EXECUTION-20261013/report.md`, `browser-results.json`, `concurrency-results.json`.
- `F3.6F-R1/acceptance-report.md`, `F3.6I-R4/acceptance-report.md`, `F3.6I-R5/targeted-retest-report.md`, `F3.6I-R6/stabilization-report.md`.

Pekerjaan yang belum PASS pada bukti lama tidak berubah menjadi PASS melalui
penutupan fase ini. Item yang ditemukan kemudian harus ditambahkan di sini.
