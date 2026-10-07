# Product Requirements Document (PRD)

# FP Management System

**Status:** Final baseline pengembangan\
**Basis:** Pengembangan dari existing project `RO_management`\
**Terminologi:** **RO = HRD**. Seluruh fitur baru menggunakan istilah
**HRD**.

------------------------------------------------------------------------

## 1. Product Overview

FP Management System adalah sistem HR internal untuk mengelola siklus
data **Financial Planner (FP)** mulai dari onboarding pada sesi training
massal, validasi ulang biodata oleh HRD, pemeriksaan dokumen, approval,
pembuatan NIP, pengelolaan Team/Jalur, sampai proses FP keluar.

Sistem dikembangkan secara evolusioner dari aplikasi Laravel/Filament
existing, bukan rewrite total.

## 2. Product Goals

1.  Menjadi database utama HR untuk data FP.
2.  Memungkinkan banyak calon FP mengisi biodata secara bersamaan tanpa
    login.
3.  Mengurangi dokumen buruk melalui client-side document quality
    validation.
4.  Memastikan biodata dan dokumen diperiksa ulang oleh HRD sebelum
    approval.
5.  Menghasilkan NIP internal secara otomatis, sequential, dan
    concurrency-safe.
6.  Menyimpan dokumen pada Nextcloud secara private.
7.  Menyediakan lifecycle FP aktif sampai keluar.
8.  Menyediakan audit trail atas tindakan HRD dan perubahan data
    sensitif.

## 3. Actors

### 3.1 Calon Financial Planner

Tidak memiliki akun dan tidak login.

Dapat: - mengisi form biodata; - memilih Team aktif; -
mengunggah/mengambil Foto Profil; - mengunggah/mengambil foto KTP; -
mengunggah/mengambil foto Ijazah; - menerima validasi kualitas dokumen
di browser; - submit data.

Tidak dapat: - memilih Jalur; - melihat NIP; - mengakses panel HRD; -
melihat submission milik orang lain.

### 3.2 HRD

`RO` pada sistem lama = `HRD`.

HRD dapat: - login; - melihat dashboard; - melihat pending submission; -
memvalidasi ulang biodata; - mengoreksi biodata sebelum approval; -
memeriksa Foto/KTP/Ijazah; - reject submission dengan alasan; -
menentukan Jalur; - final approve; - melihat NIP; - mengelola FP; -
mengelola Team dan Ketua Team; - mengelola user HRD; - memproses FP
keluar; - export; - melihat audit trail sesuai authorization.

**Admin = Staff HRD** pada scope awal.

------------------------------------------------------------------------

## 4. Main Navigation HRD

``` text
Dashboard
Pending Verification
User
Team
FP
FP Aktif
FP Keluar Normal
FP Keluar Tidak Normal
```

## 5. Public FP Onboarding

### 5.1 Biodata Fields

-   Nama Lengkap
-   No. Telepon
-   No. Rekening BCA
-   NIK / No. KTP
-   Alamat KTP
-   Alamat Tinggal
-   Tempat Lahir
-   Tanggal Lahir
-   Pendidikan Terakhir
-   Tempat Pendidikan Terakhir
-   Tahun Lulus
-   Team

**Grade dihapus.**\
**Jalur tidak ditampilkan kepada FP.**

### 5.2 Mandatory Attachments

Tiga lampiran wajib:

1.  **Foto Profil**
2.  **KTP**
3.  **Ijazah**

Foto Profil menjadi foto utama FP pada panel HRD setelah data disetujui.

### 5.3 Input Mode

Untuk dokumen yang didukung: - Upload dari perangkat. - Capture melalui
kamera browser.

Kamera menyediakan **guide frame** agar pengguna dapat menempatkan
dokumen dengan benar.

Foto profil tidak memerlukan OCR. Foto profil divalidasi berdasarkan: -
format; - file size; - image decoding; - minimum resolution; - kualitas
gambar dasar.

KTP dan Ijazah menggunakan Smart Document Validation.

------------------------------------------------------------------------

## 6. Smart Document Validation

### 6.1 Client-Side

Gunakan Tesseract.js dan image-quality processing pada frontend.

Pipeline:

``` text
Select/Capture
    ↓
Decode Image
    ↓
Resolution Check
    ↓
Blur / Readability Check
    ↓
Preprocessing
    ↓
OCR Web Worker
    ↓
Document-specific Validation
    ↓
PASS / FAIL
```

KTP: - OCR mencari kandidat NIK; - kandidat NIK harus 16 digit; -
dokumen terlalu blur/tidak terbaca ditolak sebelum upload.

Ijazah: - fokus pada readability; - tidak mengklaim atau menentukan
keaslian/legalitas ijazah.

Tesseract.js adalah **early quality gate**, bukan security boundary.

### 6.2 Backend

Laravel tetap melakukan authoritative validation: - file size; - MIME
allowlist; - magic byte/signature; - safe filename; - image decode; -
re-encode/compression bila sesuai; - checksum; - metadata; - private
storage.

------------------------------------------------------------------------

## 7. Submission Lifecycle

Setelah submit:

``` text
PENDING
   ↓
HRD Biodata Verification
   ↓
HRD Document Verification
   ↓
Jalur Selection
   ↓
FINAL APPROVAL
   ↓
NIP Generation
   ↓
ACTIVE
```

Alternative:

``` text
PENDING → REJECTED
```

Pending submission **sudah disimpan secara teknis di database**, tetapi
belum menjadi master FP.

------------------------------------------------------------------------

## 8. HRD Biodata Re-Validation

Sebelum approve, HRD **wajib melakukan validasi ulang biodata**.

Pending Verification menampilkan: - seluruh biodata; - Foto Profil; -
KTP; - Ijazah; - status dokumen; - Team; - metadata submission.

HRD dapat mengoreksi biodata sebelum approval.

Perubahan oleh HRD wajib: - melalui server-side validation; - tercatat
pada audit trail; - menyimpan actor dan timestamp; - tidak membocorkan
plaintext NIK/rekening ke log.

Perubahan sensitif seperti NIK harus menjalankan kembali
duplicate/integrity validation.

Final approval tidak boleh tersedia sampai validasi wajib selesai.

------------------------------------------------------------------------

## 9. Document Preview Overlay

### 9.1 Locations

Document Preview Overlay **wajib tersedia pada**:

1.  **Pending Verification**
2.  **Detail FP**

### 9.2 Supported Documents

Overlay digunakan untuk: - Foto Profil - KTP - Ijazah

### 9.3 Required Viewer Features

Viewer wajib mendukung: - Zoom In - Zoom Out - Rotate Left/Right -
Fit-to-Screen - Fullscreen - Reset View - Close - navigasi Foto → KTP →
Ijazah tanpa harus menutup overlay.

Untuk PDF multi-page: - Previous Page - Next Page - current page
indicator.

### 9.4 Security

Viewer tidak boleh menggunakan public Nextcloud URL.

Dokumen diakses melalui authorization-protected application endpoint
atau temporary protected mechanism.

Setiap request preview harus memverifikasi bahwa user adalah HRD
authorized.

------------------------------------------------------------------------

## 10. Final Approval

Sebelum final approval, sistem wajib memastikan:

-   submission masih `PENDING`;
-   biodata telah direview;
-   tiga lampiran tersedia;
-   dokumen mandatory berstatus siap;
-   Jalur valid;
-   Team valid;
-   tidak terdapat duplicate/integrity conflict.

Final approval adalah operasi atomic.

``` text
Validate
   ↓
Lock Submission
   ↓
Validate Jalur
   ↓
Lock NIP Counter
   ↓
Generate NIP
   ↓
Create Master FP
   ↓
Link Documents
   ↓
Mark APPROVED
   ↓
Audit
   ↓
COMMIT
```

------------------------------------------------------------------------

## 11. NIP

Aturan final: - mulai dari `1001`; - increment satu per final approval
sukses; - urutan berdasarkan transaksi approval HRD yang berhasil; -
hanya dibuat backend; - unique; - concurrency-safe; - hanya dapat
dilihat HRD; - tidak dikirim ke FP.

Contoh:

``` text
FP A → 1001
FP B → 1002
FP C → 1003
```

Database wajib mempunyai `UNIQUE(nip)`.

------------------------------------------------------------------------

## 12. Jalur

Jalur dipilih HRD pada proses approval.

Tipe:

### HRD

Rekrutmen umum atau walk-in interview.

### TEAM

Jalur berasal dari Team tertentu.

### FP

Jalur berasal dari FP aktif yang telah terdaftar.

Data konseptual:

``` text
source_type: HRD | TEAM | FP
source_id: nullable
source_name_snapshot: string
```

Rules: - HRD → `source_id = null` - TEAM → `source_id = team_id` - FP →
`source_id = fp_id` - source FP harus aktif pada saat approval.

Jalur bukan free-text biasa.

------------------------------------------------------------------------

## 13. Team

HRD dapat: - membuat Team; - edit Team; - aktif/nonaktifkan Team; -
melihat anggota; - menentukan Ketua Team; - mengganti Ketua Team.

Ketua Team dipilih dari FP aktif.

Public form hanya menampilkan Team aktif.

------------------------------------------------------------------------

## 14. FP Master

### Semua FP

Seluruh FP yang sudah approved: - aktif; - keluar normal; - keluar tidak
normal.

Pending/rejected tidak termasuk master FP.

### FP Aktif

FP dengan status `ACTIVE`.

### Detail FP

Detail FP menampilkan: - Foto Profil; - NIP; - status; - Team; -
Jalur; - biodata lengkap; - employment information; - Foto/KTP/Ijazah; -
riwayat yang relevan.

Lampiran ditampilkan sebagai thumbnail/card dan dibuka melalui
**Document Preview Overlay**.

------------------------------------------------------------------------

## 15. FP Exit

HRD memasukkan: - tanggal keluar; - alasan keluar.

Business rule final:

``` text
duration_days < 60  → ABNORMAL
duration_days >= 60 → NORMAL
```

Hari dihitung dari canonical FP start/reference date sampai tanggal
keluar.

Boundary: - hari 59 → tidak normal; - hari 60 → normal; - hari 61 →
normal.

Status utama: - `ACTIVE` - `EXITED`

Exit type: - `NORMAL` - `ABNORMAL`

------------------------------------------------------------------------

## 16. Dashboard

Widget minimum: - FP Aktif; - Pending Verification; - FP Keluar
Normal; - FP Keluar Tidak Normal; - FP baru periode berjalan; -
distribusi FP per Team.

Quick Actions: - Review Pending; - Kelola Team; - Export; - Kelola User.

------------------------------------------------------------------------

## 17. Export

CSV/Excel.

Filter: - hari ini; - 7 hari; - 30 hari; - bulan; - tahun; - custom
range.

Export data sensitif harus authorization-protected dan dicatat pada
audit trail.

------------------------------------------------------------------------

## 18. Nextcloud

Nextcloud berfungsi sebagai document storage, bukan master database.

Direkomendasikan:

``` text
fp/{uuid}/profile/{document_uuid}.jpg
fp/{uuid}/ktp/{document_uuid}.jpg
fp/{uuid}/ijazah/{document_uuid}.pdf
```

Jangan menggunakan nama FP sebagai unique folder identifier.

------------------------------------------------------------------------

## 19. Security Requirements

-   server-side validation;
-   CSRF;
-   rate limiting;
-   authorization Policy/Gate/Permission;
-   encrypted sensitive data;
-   exact lookup hash untuk NIK bila encrypted;
-   private document storage;
-   random filename;
-   safe image re-encoding;
-   audit trail;
-   secrets via environment;
-   PII tidak dicatat plaintext pada logs;
-   NIP tidak tersedia pada public payload.

------------------------------------------------------------------------

## 20. Performance & Reliability

Sistem harus dirancang untuk training massal.

-   OCR berjalan di browser;
-   OCR memakai Web Worker;
-   request tidak menunggu external storage operation yang berat;
-   queue untuk pekerjaan asynchronous;
-   pagination;
-   indexes;
-   database transaction;
-   row locking untuk approval/NIP;
-   idempotent queue jobs;
-   retry untuk Nextcloud.

------------------------------------------------------------------------

## 21. Acceptance Criteria

1.  FP dapat submit tanpa login.
2.  Foto Profil, KTP, Ijazah wajib.
3.  Dokumen blur dapat ditolak di browser.
4.  NIK bukan 16 digit ditolak.
5.  Submission masuk `PENDING`.
6.  HRD dapat melihat seluruh biodata sebelum approve.
7.  HRD dapat mengoreksi biodata sebelum approve.
8.  Koreksi HRD masuk audit trail.
9.  Overlay tersedia pada Pending Verification.
10. Overlay tersedia pada Detail FP.
11. Foto/KTP/Ijazah dapat dibuka melalui overlay.
12. Overlay mendukung zoom, rotate, fit-to-screen, fullscreen, reset,
    close.
13. Viewer mendukung multi-page PDF bila diperlukan.
14. HRD tidak dapat approve tanpa Jalur.
15. HRD tidak dapat approve bila mandatory document belum siap.
16. NIP pertama = 1001.
17. Concurrent approval tidak menghasilkan NIP duplikat.
18. NIP tidak tampil ke FP.
19. Hari ke-59 = keluar tidak normal.
20. Hari ke-60 = keluar normal.
21. Jalur FP hanya menunjuk FP aktif.
22. Ketua Team hanya FP aktif.
23. Dokumen tidak dapat diakses tanpa authorization.

------------------------------------------------------------------------

## 22. Out of Scope

-   payroll;
-   attendance;
-   performance appraisal;
-   full ATS;
-   face recognition;
-   legal authenticity verification;
-   native mobile app;
-   microservices.

## 23. Definition of Done

Feature selesai bila: - acceptance criteria terpenuhi; - authorization
benar; - migration aman; - tests tersedia; - failure path ditangani; -
audit event tersedia; - tidak ada PII leakage; - production build
sukses; - dokumentasi diperbarui.

## 24. Final Decision Register

These decisions supersede earlier ambiguous interpretations.

### Legacy MGM

Legacy `MGM` is not automatically mapped to `HRD`, `TEAM`, or `FP`.
Preserve it as an unresolved legacy recruitment source until
HRD/business owner defines a mapping.

### Canonical Start / Exit Reference Date

For FP memberships created through the new workflow, the canonical
reference for exit classification is the calendar date of the successful
submission's `submitted_at`, using one configured business timezone.

``` text
duration_days = calendar_date(exit_date) - business_date(submitted_at)
< 60  -> ABNORMAL
>= 60 -> NORMAL
```

`approved_at` remains an audit timestamp, not the 60-day reference. For
legacy FP without new-workflow `submitted_at`, do not fabricate one;
preserve validated historical `tanggal_masuk`/legacy reference data.

### Legacy NIP

Existing/legacy FP do not receive NIP automatically. Legacy `nip` may
remain `NULL`. Sequence `1001+` is allocated only on successful final
approval in the new workflow.

### Legacy Team Automatic Merge

Automatic merge is allowed only by deterministic canonical matching.
Prefer a stable legacy Team identifier/code. If only names exist,
normalize case, trim whitespace, and collapse repeated whitespace, then
merge exact canonical matches only. Do not fuzzy-merge semantically
similar names.

### NIK Identity and Returning FP

NIK identifies a person, not one FP membership period. A person may have
multiple FP memberships over time.

-   Existing `PENDING` for the same identity blocks another concurrent
    pending submission.
-   Existing `ACTIVE` membership blocks a new FP registration.
-   Prior `EXITED`, whether `NORMAL` or `ABNORMAL`, allows a new
    submission.
-   Prior `REJECTED` allows a fresh submission.

When prior exited membership exists, Pending Verification must clearly
show **Returning FP / Pernah menjadi FP**. HRD must be able to inspect
previous NIP, Team, Jalur, start/reference date, exit date, duration,
exit type, and exit reason where available.

### Rejoin and NIP

A successful rejoin creates a new FP membership. It never reactivates or
overwrites an old membership. Every successful rejoin final approval
receives a new NIP from the current sequence. Previous NIPs remain
attached to historical memberships and are never reused. At most one
`ACTIVE` FP membership may exist for one identity.

### Jalur Referential Integrity

Use typed nullable references:

``` text
recruitment_source_type: HRD | TEAM | FP
source_team_id nullable -> teams.id
source_fp_id nullable -> fps.id
source_name_snapshot
```

Invariant:

``` text
HRD  -> source_team_id NULL,     source_fp_id NULL
TEAM -> source_team_id NOT NULL, source_fp_id NULL
FP   -> source_team_id NULL,     source_fp_id NOT NULL
```

A Jalur `FP` source must be `ACTIVE` at final approval.

### Rejected Submission Documents

Keep the rejected submission record, rejection reason, actor, timestamp,
and audit history. After the reject transaction commits, immediately
schedule deletion of binary `PROFILE_PHOTO`, `KTP`, and `IJAZAH` files.
Nextcloud deletion runs through an idempotent retryable after-commit
job; it must not run inside the DB transaction. Deletion state/failure
must be observable. The FP may submit a fresh form again.

### Legacy `bm`

Do not remove or automatically map `bm` to HRD in the integration layer.

### Team Leader

A Team leader must be an `ACTIVE` FP and belong to the same Team. When
the leader exits, clear `leader_fp_id`; never auto-select a replacement.

### Legacy Compliance

Do not fabricate verification/approval evidence for legacy FP. Missing
historical documents, `verified_at`, `verified_by`, approval timestamps,
or NIP remain explicitly legacy/unverified.
