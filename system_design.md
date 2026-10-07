# System Design

# FP Management System

## 1. Baseline

Sistem dikembangkan dari existing `RO_management`.

Target tetap menggunakan Laravel/Filament existing secara evolusioner.

**RO = HRD.**

Perubahan domain utama: - public FP onboarding; - Foto Profil + KTP +
Ijazah; - client-side OCR; - pending submission; - HRD biodata
re-validation; - document verification overlay; - typed Jalur; - NIP
sequential mulai 1001; - 60-day exit rule; - Nextcloud private storage.

------------------------------------------------------------------------

## 2. System Context

``` text
FP Browser
   |
   | Public Onboarding
   | Camera / Upload
   | OCR / Quality Check
   v
Laravel Application
   |
   +------ Database
   |
   +------ Queue Worker
   |          |
   |          +------ Nextcloud
   |
   +------ HRD Filament Panel
              |
              +-- Pending Verification
              +-- FP Master
              +-- Team
              +-- User
```

------------------------------------------------------------------------

## 3. Lifecycle

### 3.1 Submission

``` text
DRAFT(client)
    ↓
PENDING
   ├──────────────→ REJECTED
   ↓
BIODATA REVIEW
   ↓
DOCUMENT REVIEW
   ↓
JALUR SELECTED
   ↓
APPROVED
```

`BIODATA REVIEW`, `DOCUMENT REVIEW`, dan `JALUR SELECTED` dapat
direpresentasikan dengan verification fields/checkpoints, bukan harus
menjadi status database terpisah.

### 3.2 FP

``` text
APPROVED
    ↓
ACTIVE
    ↓
EXITED
   ├── NORMAL
   └── ABNORMAL
```

------------------------------------------------------------------------

## 4. Proposed Database

### 4.1 users

Pertahankan existing user/SSO schema.

Target role:

``` text
hrd
```

Legacy `ro` dimigrasikan secara backward-compatible.

### 4.2 teams

``` text
id
name
code nullable
is_active
leader_fp_id nullable
created_at
updated_at
```

### 4.3 fp_submissions

``` text
id
uuid UNIQUE

full_name
phone
phone_normalized
nik_encrypted
nik_lookup_hash
bank_account_encrypted

address_ktp
address_current
birth_place
birth_date
education_level
education_institution
graduation_year

team_id

status
biodata_verified_at nullable
biodata_verified_by nullable
documents_verified_at nullable
documents_verified_by nullable

submitted_at
reviewed_at nullable
reviewed_by nullable

rejection_reason nullable
created_at
updated_at
```

Indexes: - `status, submitted_at` - `nik_lookup_hash` - normalized phone
sesuai duplicate rule.

### 4.4 fp_documents

``` text
id
uuid UNIQUE
submission_id nullable
fp_id nullable

type ENUM/STRING:
  PROFILE_PHOTO
  KTP
  IJAZAH

storage_disk
storage_path
original_filename
mime_type
size_bytes
checksum_sha256

processing_status
ocr_metadata JSON nullable

created_at
updated_at
```

Processing state:

``` text
PENDING
PROCESSING
READY
FAILED
```

### 4.5 financial_planners / fps

Migration dapat mempertahankan nama table `fps` bila mengurangi risiko.

Target fields:

``` text
id
uuid
nip UNIQUE

canonical biodata...

team_id

recruitment_source_type
recruitment_source_id nullable
recruitment_source_name_snapshot

status ACTIVE|EXITED
start_date

approved_by
approved_at

created_at
updated_at
```

### 4.6 fp_exits

``` text
id
fp_id UNIQUE
exit_date
reason
duration_days
exit_type NORMAL|ABNORMAL
processed_by
processed_at
timestamps
```

### 4.7 nip_sequences

``` text
id
next_value
```

Initial:

``` text
next_value = 1001
```

### 4.8 audit/activity log

Audit critical events: - submission correction; - sensitive field
correction; - document review; - rejection; - approval; - NIP
allocation; - Team leader changes; - FP exit; - sensitive export.

Do not store plaintext NIK/rekening in log payload.

------------------------------------------------------------------------

## 5. Public Submission Flow

``` text
GET Form
  ↓
Fetch Active Teams
  ↓
User fills biodata
  ↓
Add Profile Photo
  ↓
Add KTP
  ↓
Add Ijazah
  ↓
Frontend Validation
  ↓
POST Submission
  ↓
Backend Validation
  ↓
Persist PENDING
  ↓
Process Documents
  ↓
Nextcloud
```

Sensitive state must not be persisted permanently in browser
localStorage.

------------------------------------------------------------------------

## 6. Client Document Validation

### Profile Photo

Checks: - allowed image type; - maximum size; - decode; - minimum
resolution; - basic blur/quality threshold.

No OCR.

### KTP

``` text
Decode
 ↓
Blur Check
 ↓
Preprocess
 ↓
Tesseract.js Worker
 ↓
Normalize OCR Candidate
 ↓
Find 16-digit NIK
 ↓
PASS/FAIL
```

OCR should tolerate common recognition noise conservatively. It must not
silently rewrite identity data.

### Ijazah

``` text
Decode/Render
 ↓
Blur/Readability
 ↓
OCR
 ↓
Enough readable text?
 ↓
PASS/FAIL
```

This does not authenticate the document.

------------------------------------------------------------------------

## 7. HRD Pending Verification

Pending detail is an operational workspace.

Recommended layout:

``` text
Header
├── Submission status
├── Submitted time
└── actions

Profile Summary
├── Profile Photo
├── Name
├── Team
└── verification state

Biodata
├── field/value
├── edit/correct
└── verification control

Documents
├── Profile Photo
├── KTP
└── Ijazah

Approval
├── Jalur
├── Reject
└── Final Approve
```

HRD must review biodata before approval.

### Biodata Correction

Use an application action:

``` text
UpdateSubmissionBiodataAction
```

It: 1. checks authorization; 2. locks/fetches pending submission; 3.
validates new values; 4. rechecks duplicate-sensitive fields; 5. writes
changes; 6. records audit diff safely.

If HRD changes a field after marking biodata verified, recommended
behavior: - reset `biodata_verified_at/by`; - require verification
again.

------------------------------------------------------------------------

## 8. Document Preview Overlay

Overlay is shared by:

``` text
Pending Verification
Detail FP
```

Supported types:

``` text
PROFILE_PHOTO
KTP
IJAZAH
```

### 8.1 Overlay State

``` text
documentId
documentType
currentDocumentIndex
zoom
rotation
fitMode
fullscreen
page
pageCount
loading
error
```

### 8.2 Controls

Required: - previous document; - next document; - zoom out; - zoom in; -
rotate left; - rotate right; - fit-to-screen; - reset; - fullscreen; -
close.

PDF: - previous page; - next page; - page counter.

Keyboard recommendations: - `Esc` close; - `+/-` zoom; - arrow keys
navigate where not conflicting; - focus trap while modal open.

### 8.3 Overlay Security

Browser must not receive raw permanent Nextcloud credential/path.

Flow:

``` text
HRD Browser
    |
GET /hrd/documents/{uuid}/preview
    |
Laravel Auth + Policy
    |
DocumentStorage
    |
Nextcloud
    |
stream/protected response
```

Alternative temporary signed URL is acceptable only if: - short TTL; -
unguessable; - scoped; - does not expose reusable credential.

### 8.4 UI Reuse

Create one reusable viewer component instead of separate implementations
for Pending and Detail FP.

For Filament, implement as custom modal/component where necessary.

------------------------------------------------------------------------

## 9. Verification Invariants

Final approval requires:

``` text
submission.status == PENDING
biodata_verified_at != null
documents_verified_at != null
PROFILE_PHOTO READY
KTP READY
IJAZAH READY
jalur valid
team valid
```

Server enforces all invariants even if UI button is disabled.

------------------------------------------------------------------------

## 10. Approval Transaction

``` php
DB::transaction(function () {
    // 1. lock pending submission
    // 2. assert status PENDING
    // 3. assert biodata verified
    // 4. assert documents verified and READY
    // 5. validate Jalur
    // 6. lock NIP sequence
    // 7. allocate NIP
    // 8. create FP
    // 9. associate document metadata
    // 10. increment sequence
    // 11. mark submission APPROVED
    // 12. audit
});
```

Nextcloud network calls should not be performed while holding DB locks.

------------------------------------------------------------------------

## 11. NIP Concurrency

Do not use:

``` php
Fp::max('nip') + 1;
```

Use:

``` text
SELECT nip_sequence FOR UPDATE
 ↓
read next_value
 ↓
insert FP
 ↓
increment next_value
 ↓
commit
```

Also:

``` text
UNIQUE(nip)
```

Double approval is prevented by locking submission + checking status.

------------------------------------------------------------------------

## 12. Jalur Validation

``` text
HRD
  source_id = null

TEAM
  source_id exists in teams

FP
  source_id exists in FP
  FP.status = ACTIVE
```

Store:

``` text
source_name_snapshot
```

so historical reporting survives rename/status changes.

------------------------------------------------------------------------

## 13. Exit Calculation

Use canonical date-only calculation.

``` text
duration_days = difference(start_date, exit_date)

if duration_days >= 60:
    NORMAL
else:
    ABNORMAL
```

Persist calculated `duration_days` and `exit_type` as exit snapshot.

Do not use `addMonths(2)`.

------------------------------------------------------------------------

## 14. Nextcloud Integration

Port:

``` php
interface DocumentStorage
{
    public function put(string $path, mixed $stream): StoredDocument;
    public function read(string $path): mixed;
    public function delete(string $path): void;
}
```

Adapter:

``` text
NextcloudDocumentStorage
```

Path:

``` text
fp/{uuid}/profile/{document_uuid}.jpg
fp/{uuid}/ktp/{document_uuid}.jpg
fp/{uuid}/ijazah/{document_uuid}.pdf
```

------------------------------------------------------------------------

## 15. Queue

Use queue for: - document finalization; - Nextcloud upload/retry; -
heavy compression if needed; - export; - cleanup.

Queue jobs must be idempotent.

Recommended document flow:

``` text
HTTP upload
 ↓
Private temporary storage
 ↓
DB metadata
 ↓
response
 ↓
afterCommit job
 ↓
Nextcloud
 ↓
READY
```

------------------------------------------------------------------------

## 16. Security

### PII

NIK/rekening: - encrypted value; - deterministic/exact lookup hash where
needed; - masked in table; - reveal only to authorized HRD.

### Documents

-   private;
-   UUID filename;
-   no raw user path;
-   MIME + signature validation;
-   safe response headers;
-   authorization on every preview.

### Public Form

-   rate limit;
-   CSRF;
-   server validation;
-   duplicate protection;
-   generic errors for identity duplicates.

------------------------------------------------------------------------

## 17. Migration from Existing System

Use additive migrations.

Sequence:

1.  add submission/document/sequence schema;
2.  add new FP fields;
3.  add HRD role terminology;
4.  implement new services;
5.  migrate compatible legacy data;
6.  switch application reads/writes;
7.  verify;
8.  remove obsolete columns only in later release.

Legacy concerns: - `grade` obsolete; - Jalur `RO/MGM` obsolete; - `RO`
maps to HRD; - `MGM` has no confirmed mapping and must not be guessed; -
existing 2-month classification must become 60-day rule.

------------------------------------------------------------------------

## 18. Testing

### Unit

-   NIK validation;
-   Jalur validation;
-   59/60/61-day classification;
-   NIP allocator.

### Feature

-   public submission;
-   three mandatory attachments;
-   HRD biodata correction;
-   verification reset after correction;
-   reject;
-   approve;
-   approval blocked without biodata verification;
-   approval blocked without document verification;
-   approval blocked if document not READY;
-   overlay preview authorization;
-   NIP hidden from public flow;
-   FP detail document access.

### Concurrency

-   two HRD approve same submission;
-   multiple submissions approved concurrently;
-   no duplicate NIP.

### Storage

Use fake `DocumentStorage` in regular tests and staging Nextcloud
integration tests.

------------------------------------------------------------------------

## 19. Observability

Structured events: - `fp_submission_created` - `fp_biodata_corrected` -
`fp_biodata_verified` - `fp_documents_verified` -
`fp_submission_rejected` - `fp_approved` - `nip_allocated` -
`document_processing_failed` - `nextcloud_retry_exhausted` - `fp_exited`

Use request/correlation ID.

No sensitive plaintext in logs.

## 20. Final Identity, Rejoin, and Legacy Design

### Person Identity vs FP Membership

NIK identifies a person while an FP row represents one membership
period.

``` text
person_identities
-----------------
id
nik_encrypted
nik_lookup_hash UNIQUE
created_at
updated_at
```

``` text
PersonIdentity
├── fp_submissions (many)
└── FP memberships (many)
    └── fp_exit (zero/one per membership)
```

Use encrypted NIK plus a keyed deterministic lookup value such as HMAC
for exact matching. Do not use a plain public hash as the sensitive
identity lookup mechanism.

Registration rules:

``` text
existing PENDING -> BLOCK another pending
existing ACTIVE  -> BLOCK new registration
historical EXITED only -> ALLOW and mark Returning FP
historical REJECTED -> ALLOW fresh submission
```

### Returning FP Workflow

``` text
Public Submission
    ↓
Resolve PersonIdentity
    ↓
Historical EXITED membership?
    ├── no  -> normal pending
    └── yes -> Returning FP context
                 ↓
             HRD sees history
                 ↓
             normal verification
                 ↓
             final approval
                 ↓
             NEW membership + NEW NIP
```

Historical membership remains immutable lifecycle history.

### Canonical Start Date

For new-workflow memberships:

``` text
membership_reference_date = business_date(fp_submission.submitted_at)
```

Retain the source submission relationship or a durable reference-date
snapshot so exit calculation is reproducible. `approved_at` is not the
60-day start. Legacy rows keep their validated historical
`tanggal_masuk`/legacy reference rather than receiving fabricated
`submitted_at`.

### Recruitment Source Physical Schema

Prefer:

``` text
recruitment_source_type
source_team_id nullable FK
source_fp_id nullable FK
source_name_snapshot
```

Use CHECK constraints where supported plus application validation. Jalur
`FP` must reference an ACTIVE membership. Legacy `MGM` remains
unresolved and is not automatically converted.

### Rejection and Document Deletion

``` text
lock submission
 ↓
assert PENDING
 ↓
status = REJECTED
rejection_reason / rejected_by / rejected_at / audit
 ↓
COMMIT
 ↓
afterCommit DeleteRejectedSubmissionDocumentsJob
 ↓
Nextcloud delete with retry
 ↓
metadata -> DELETED
```

Keep the submission audit row; delete its binary documents. The deletion
job must be idempotent and observable.

### Team Canonicalization

Automatic legacy Team merge must be deterministic. Prefer stable
IDs/codes. Otherwise normalize case, trim, collapse repeated whitespace,
and merge only exact canonical matches. Never fuzzy-merge similar names.

### Team Leader Invariant

``` text
leader.status == ACTIVE
leader.team_id == team.id
```

When a leader exits, clear the Team leader reference. Do not auto-assign
a replacement.

### Legacy Data

-   legacy NIP may be `NULL`;
-   never auto-backfill legacy NIP;
-   never fabricate verification/approval evidence;
-   `MGM` remains unresolved;
-   `bm` remains integration-compatible;
-   legacy FP remains available for history and returning-FP detection.
