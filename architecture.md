# Architecture

# FP Management System

## 1. Architecture Style

Target: **Laravel Modular Monolith**.

Existing Laravel + Filament application remains the deployment unit.

Reasons: - business domain tightly related; - existing project already
Laravel/Filament; - approval and NIP need strong transaction
consistency; - microservices add unnecessary operational complexity.

**RO = HRD.**

------------------------------------------------------------------------

## 2. High-Level Architecture

``` text
+---------------------------------------------------+
| FP Browser                                        |
|                                                   |
| Public Form                                       |
| Camera                                            |
| Tesseract.js Worker                               |
| Image Quality Validation                          |
+-------------------------+-------------------------+
                          |
                        HTTPS
                          |
+-------------------------v-------------------------+
| Laravel Application                               |
|                                                   |
| Public Onboarding                                 |
| HRD Filament                                      |
| Application Actions                               |
| Domain Rules                                      |
| Policies                                          |
+------------+--------------------+-----------------+
             |                    |
             v                    v
       +-----------+        +-----------+
       | Database  |        | Queue     |
       +-----------+        +-----+-----+
                                  |
                                  v
                            +-----------+
                            | Nextcloud |
                            +-----------+

Existing SSO/JWT ----------------> Identity Integration
```

------------------------------------------------------------------------

## 3. Modules

``` text
Identity
Onboarding
FP
Team
RecruitmentSource
Document
Exit
Reporting
Audit
NextcloudIntegration
SsoIntegration
```

Modules are logical boundaries inside one Laravel application.

------------------------------------------------------------------------

## 4. Recommended Code Structure

``` text
app/
├── Application/
│   ├── Onboarding/
│   ├── FP/
│   ├── Team/
│   ├── Document/
│   └── Reporting/
├── Domain/
│   ├── FP/
│   ├── Recruitment/
│   ├── Document/
│   └── Team/
├── Infrastructure/
│   ├── Nextcloud/
│   ├── Sso/
│   └── Persistence/
├── Filament/
│   ├── Resources/
│   ├── Pages/
│   ├── Widgets/
│   └── Components/
├── Http/
│   ├── Controllers/
│   ├── Requests/
│   └── Middleware/
├── Jobs/
├── Models/
├── Policies/
└── Providers/

resources/
├── js/
│   └── fp-onboarding/
│       ├── camera.js
│       ├── image-quality.js
│       ├── ocr-worker.js
│       ├── ktp-validator.js
│       └── document-viewer.js
└── views/
```

Avoid forcing every class into abstraction. The objective is clear
responsibility, not folder complexity.

------------------------------------------------------------------------

## 5. Dependency Direction

``` text
HTTP / Filament / UI
        ↓
Application Use Cases
        ↓
Domain Rules
        ↓
Ports / Interfaces
        ↓
Infrastructure
```

UI must not own core business rules.

------------------------------------------------------------------------

## 6. Core Use Cases

Recommended actions:

``` text
SubmitFpApplicationAction
UpdateSubmissionBiodataAction
VerifySubmissionBiodataAction
VerifySubmissionDocumentsAction
RejectFpSubmissionAction
ApproveFpSubmissionAction
AllocateNipAction
ValidateRecruitmentSourceAction
ExitFpAction
AssignTeamLeaderAction
StoreFpDocumentAction
ExportFpReportAction
```

------------------------------------------------------------------------

## 7. Public Frontend

Use Laravel-integrated frontend rather than separate Next.js/React
application unless a future requirement changes this.

Possible: - Blade + Alpine; - Livewire; - custom JS modules.

### OCR

Tesseract.js runs in Web Worker.

Image-quality module executes before OCR.

Profile photo bypasses OCR.

### Camera

Use browser MediaDevices API where supported.

Always provide upload fallback.

------------------------------------------------------------------------

## 8. HRD Frontend

Continue using **Filament 3**.

Pages/resources:

``` text
Dashboard
PendingVerification
UserResource
TeamResource
FpResource
FP Active
FP Normal Exit
FP Abnormal Exit
```

Pending Verification should be optimized for review rather than generic
CRUD.

------------------------------------------------------------------------

## 9. Shared Document Viewer Architecture

Implement **one reusable viewer** consumed by: - Pending Verification; -
Detail FP.

``` text
DocumentViewer
├── image renderer
├── PDF renderer
├── zoom controller
├── rotation controller
├── fit controller
├── fullscreen controller
├── page controller
└── document navigation
```

Required controls:

``` text
Previous Document
Next Document
Zoom -
Zoom +
Rotate Left
Rotate Right
Fit
Reset
Fullscreen
Close
```

PDF:

``` text
Previous Page
Next Page
Page X / N
```

### Overlay UX

Use modal/lightbox overlay: - high z-index; - focus trap; - background
interaction disabled; - Esc closes; - loading/error states; - responsive
desktop/tablet; - toolbar remains accessible.

Do not create separate behavior between Pending and Detail FP.

------------------------------------------------------------------------

## 10. Document Delivery Architecture

Never expose permanent public Nextcloud paths.

Preferred:

``` text
HRD Browser
     |
     v
Laravel Protected Preview Endpoint
     |
Auth + Policy
     |
DocumentStorage
     |
Nextcloud
```

Laravel may stream document bytes or create a short-lived protected URL
if the storage integration safely supports it.

Headers must prevent unsafe inline execution for unsupported content.

------------------------------------------------------------------------

## 11. Data Architecture

Database is source of truth for: - FP; - submission; - employment
state; - NIP; - Team; - Jalur; - document metadata; - audit.

Nextcloud is source of binary document content.

Use relational integrity and indexes.

------------------------------------------------------------------------

## 12. NIP Architecture

Use transactional counter:

``` text
Approval
 ↓
lock submission
 ↓
verify invariants
 ↓
lock sequence
 ↓
NIP = next_value
 ↓
insert FP
 ↓
increment sequence
 ↓
approve submission
 ↓
commit
```

No `MAX(nip)+1`.

Unique database constraint remains mandatory.

------------------------------------------------------------------------

## 13. Approval Invariant

`ApproveFpSubmissionAction` must enforce:

``` text
PENDING
AND biodata verified
AND documents verified
AND PROFILE_PHOTO READY
AND KTP READY
AND IJAZAH READY
AND valid Team
AND valid Jalur
```

UI state is not trusted.

------------------------------------------------------------------------

## 14. Recruitment Source Architecture

Typed reference:

``` text
HRD  -> null
TEAM -> team_id
FP   -> fp_id
```

Validation belongs in application/domain layer.

Store display snapshot for historical reporting.

------------------------------------------------------------------------

## 15. Exit Architecture

Calculate once during exit operation:

``` text
duration_days
exit_type
```

Rule:

``` text
>= 60 NORMAL
< 60 ABNORMAL
```

Do not query dynamically using 2 calendar months.

------------------------------------------------------------------------

## 16. Nextcloud Port/Adapter

Application depends on:

``` php
interface DocumentStorage
{
    public function put(string $path, mixed $stream): StoredDocument;
    public function read(string $path): mixed;
    public function delete(string $path): void;
}
```

Infrastructure provides:

``` text
NextcloudDocumentStorage
```

Benefits: - testability; - isolation of WebDAV/API details; - future
storage replacement.

------------------------------------------------------------------------

## 17. Queue Architecture

Separate web request from slow external work.

``` text
Web Request
   ↓
Temporary Private Storage
   ↓
DB Commit
   ↓
afterCommit
   ↓
Queue
   ↓
Nextcloud
```

Worker should have: - retry; - exponential backoff; - idempotency; -
failure logging.

------------------------------------------------------------------------

## 18. Security Architecture

Trust:

``` text
Browser = untrusted
Laravel = security boundary
Database = trusted internal
Nextcloud = controlled external dependency
```

Frontend OCR never grants trust.

### Sensitive Data

-   encryption;
-   lookup hash;
-   masking;
-   authorization;
-   no plaintext logs.

### Documents

-   private;
-   UUID;
-   protected preview;
-   MIME/signature validation;
-   no direct public URL.

------------------------------------------------------------------------

## 19. Authentication & Authorization

Preserve existing SSO/JWT unless separately changed.

Target terminology/role:

``` text
hrd
```

Legacy:

``` text
ro
```

Migration should allow safe transition.

Use: - Spatie Permission; - Policy/Gate; - Filament authorization.

Hiding UI elements is not sufficient authorization.

------------------------------------------------------------------------

## 20. Deployment Architecture

Recommended production:

``` text
Reverse Proxy / Nginx
        |
Laravel PHP-FPM
        |
+-------+---------+
|                 |
Database          Redis
                  |
                  Queue Worker
                       |
                       Nextcloud
```

Existing Docker/Dokploy deployment can be retained.

Pipeline: 1. tests; 2. frontend build; 3. image build; 4. backup; 5.
deploy; 6. migrations; 7. restart workers; 8. health check; 9. smoke
test.

------------------------------------------------------------------------

## 21. Migration Strategy

Do not rewrite old migrations already used in production.

Use expand/migrate/contract:

### Expand

Add new tables/columns without breaking old app.

### Migrate

Backfill and introduce new code.

### Switch

Move reads/writes to new design.

### Contract

Remove obsolete columns only after verification.

Legacy issues: - grade removed; - Jalur `RO/MGM` replaced; - RO maps to
HRD; - MGM mapping unknown; - 2-month rule replaced with 60 days.

Do not invent MGM mapping.

------------------------------------------------------------------------

## 22. Architectural Decisions

Maintain ADRs:

``` text
ADR-001 Modular Monolith
ADR-002 Filament for HRD
ADR-003 Client OCR as Quality Gate
ADR-004 Nextcloud Private Document Storage
ADR-005 Transactional Sequential NIP
ADR-006 60-Day Exit Classification
ADR-007 Typed Recruitment Source
ADR-008 Sensitive Data Encryption + Lookup Hash
ADR-009 Shared Protected Document Preview Overlay
ADR-010 Mandatory HRD Biodata Re-Validation
```

## 23. Final Identity and Membership Architecture

The domain distinguishes **Person Identity** from **FP Membership**.

``` text
PersonIdentity (NIK)
       |
       +-- Submission(s)
       +-- FP Membership #1 -- Exit
       +-- FP Membership #2 -- ACTIVE
```

NIK lookup belongs to the identity boundary. FP lifecycle and NIP belong
to membership.

### Returning FP

Resolve identity by normalized NIK lookup key. Block another `PENDING`;
block registration while an `ACTIVE` membership exists; allow prior
`EXITED`; expose historical memberships to authorized HRD; create a new
membership and allocate a new NIP on successful rejoin approval. Never
reactivate an old exited membership.

### NIP Ownership

NIP belongs to an FP membership, not globally to a person.

``` text
identity A
  membership 1 -> NIP 1021 -> EXITED
  membership 2 -> NIP 1438 -> ACTIVE
```

Historical NIP is immutable and never reused. Legacy membership may have
`nip = NULL`.

### Exit Reference

For the new workflow, the exit reference date is the business-calendar
date of the originating submission's `submitted_at`. `approved_at` is
audit data, not the 60-day start.

### Recruitment Source Referential Integrity

Use `source_type`, `source_team_id`, `source_fp_id`, and
`source_name_snapshot`, with typed foreign keys and database/application
invariants instead of one unconstrained polymorphic `source_id`.

### Rejected Document Lifecycle

Rejection persists audit data but immediately creates post-commit
deletion intent for all binary documents. External Nextcloud deletion
runs asynchronously with retry/idempotency and operational failure
visibility.

### Legacy Team Merge

Automatic merge is deterministic, not fuzzy. Prefer stable identifiers;
otherwise use conservative normalized exact-name equality.

### Additional ADRs

``` text
ADR-011 NIK-based Person Identity Registry
ADR-012 FP Membership History and Returning FP
ADR-013 New NIP for Every Successful Rejoin
ADR-014 submitted_at as New-Workflow Exit Reference Date
ADR-015 Immediate Post-Commit Deletion of Rejected Documents
ADR-016 Typed Jalur Foreign Keys
ADR-017 Conservative Automatic Legacy Team Merge
ADR-018 Protected IWACS/JWT/SSO Integration Boundary
ADR-019 No Fabricated Legacy Verification Evidence
```
