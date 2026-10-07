# AGENTS.md

# FP Management System Engineering Instructions

This file defines implementation rules for AI coding agents working on
the existing `RO_management` repository.

------------------------------------------------------------------------

## 1. Mission

Evolve the existing application into the FP Management System described
by:

1.  `prd.md`
2.  `system_design.md`
3.  `architecture.md`
4.  this `agents.md`

**RO = HRD.**

New business terminology, UI labels, classes where appropriate,
documentation, and target permissions must use **HRD**. Legacy `ro` may
exist temporarily only for migration/backward compatibility.

------------------------------------------------------------------------

## 2. Mandatory Pre-Work

Before coding:

1.  read all four project documents;
2.  inspect related existing models;
3.  inspect migrations;
4.  inspect Filament Resources/Pages;
5.  inspect existing SSO/JWT integration before auth changes;
6.  inspect existing tests;
7.  identify migration impact;
8.  implement the smallest coherent change.

Do not rewrite working subsystems unnecessarily.

------------------------------------------------------------------------

## 3. Preserve Existing Stack

Unless explicitly instructed otherwise, retain: - Laravel 10; - PHP
8.1+; - Filament 3; - Spatie Permission; - Sanctum; - existing
SSO/JWT; - Vite; - Docker/Dokploy.

Do not migrate the project to a separate SPA or microservices.

------------------------------------------------------------------------

## 4. Source of Truth Priority

When implementation conflicts arise:

``` text
explicit current user requirement
        >
prd.md
        >
system_design.md
        >
architecture.md
        >
existing implementation
```

However, never destroy production data to force a design. Use
backward-compatible migrations.

------------------------------------------------------------------------

## 5. Non-Negotiable Business Rules

### Public FP

-   no login;
-   no Grade;
-   no Jalur selection;
-   Team only from active Teams;
-   Foto Profil mandatory;
-   KTP mandatory;
-   Ijazah mandatory.

### Pending

A submitted FP becomes `PENDING`, not an active FP.

### HRD Verification

Before final approval HRD must: 1. review/revalidate biodata; 2. review
mandatory documents; 3. select Jalur.

HRD may correct biodata before approval.

Every correction must be auditable.

If biodata changes after verification, reset biodata verification and
require re-verification.

### Documents

Types:

``` text
PROFILE_PHOTO
KTP
IJAZAH
```

### Final Approval

Approval is prohibited unless: - status PENDING; - biodata verified; -
documents verified; - all mandatory documents READY; - Team valid; -
Jalur valid.

### NIP

-   first NIP = `1001`;
-   sequential;
-   allocated only on successful final approval;
-   server-side;
-   unique;
-   concurrency-safe;
-   never exposed to public FP.

Never implement:

``` php
Fp::max('nip') + 1;
```

without safe locking. Preferred design is locked counter/sequence.

### Exit

Exactly:

``` text
< 60 days  = ABNORMAL
>= 60 days = NORMAL
```

Never use `addMonths(2)` or `INTERVAL '2 months'`.

### Jalur

Only:

``` text
HRD
TEAM
FP
```

Rules: - HRD → no reference ID; - TEAM → valid Team reference; - FP →
active FP reference.

Do not treat Jalur as arbitrary text.

### RO

Do not introduce a new actor called RO. Legacy RO means HRD.

------------------------------------------------------------------------

## 6. Document Preview Overlay Rules

A **single reusable Document Viewer Overlay** must serve:

``` text
Pending Verification
Detail FP
```

It supports:

``` text
PROFILE_PHOTO
KTP
IJAZAH
```

Mandatory controls: - previous/next document; - zoom in; - zoom out; -
rotate left/right; - fit-to-screen; - reset; - fullscreen; - close.

For multi-page PDF: - previous page; - next page; - page number/total.

UX: - modal/lightbox; - focus trapped; - Esc closes; - responsive; -
loading state; - error state; - background page cannot be accidentally
interacted with.

Do not create two separate viewers with diverging behavior.

------------------------------------------------------------------------

## 7. Preview Security

Never expose permanent public Nextcloud URLs.

Every HRD preview must be authorized server-side.

Preferred:

``` text
GET protected endpoint
    ↓
Auth
    ↓
Policy
    ↓
DocumentStorage
    ↓
stream document
```

Do not trust hidden buttons as authorization.

------------------------------------------------------------------------

## 8. OCR Rules

Tesseract.js is a frontend quality gate.

It is not: - identity verification; - legal document verification; -
security validation.

Run OCR in Web Worker.

### Profile Photo

No OCR.

### KTP

-   quality check;
-   OCR;
-   detect candidate 16-digit NIK.

### Ijazah

-   readability check;
-   OCR readable-content check;
-   no authenticity claim.

Backend must independently validate every uploaded file.

------------------------------------------------------------------------

## 9. File Security

Server must: - validate size; - validate MIME; - validate file
signature; - decode images; - re-encode safely where appropriate; - use
random UUID filenames; - calculate checksum; - store privately.

Never build storage path directly from user-supplied filename/name.

Do not log file content or sensitive OCR text.

------------------------------------------------------------------------

## 10. Biodata Correction Rules

Use a dedicated use case such as:

``` text
UpdateSubmissionBiodataAction
```

It must: 1. authorize HRD; 2. ensure submission is editable; 3. validate
all changed fields; 4. rerun duplicate checks for NIK/phone where
applicable; 5. persist atomically; 6. create sanitized audit record; 7.
reset verification state when required.

Do not put this workflow only inside a Filament callback.

------------------------------------------------------------------------

## 11. Architecture Rules

Preferred flow:

``` text
Controller / Filament
        ↓
Application Action
        ↓
Domain Rule
        ↓
Eloquent / Port
        ↓
Infrastructure
```

Complex business logic does not belong entirely in: - Controllers; -
Filament Resources; - Blade; - JavaScript; - Eloquent model events.

Keep actions cohesive.

Recommended: - `SubmitFpApplicationAction` -
`UpdateSubmissionBiodataAction` - `VerifySubmissionBiodataAction` -
`VerifySubmissionDocumentsAction` - `RejectFpSubmissionAction` -
`ApproveFpSubmissionAction` - `AllocateNipAction` - `ExitFpAction` -
`AssignTeamLeaderAction`

------------------------------------------------------------------------

## 12. Database Rules

-   never edit historical production migrations to change deployed
    schema;
-   create additive migrations;
-   use foreign keys;
-   use indexes;
-   enforce uniqueness at DB level;
-   use transactions for invariants;
-   avoid destructive migration until data is migrated and verified.

Sensitive fields: - encrypt NIK/rekening; - use lookup hash where exact
search/unique check is needed.

No plaintext PII in logs/audit metadata.

------------------------------------------------------------------------

## 13. Transaction & Concurrency Rules

Final approval must be atomic.

Use: - transaction; - submission row lock; - NIP counter row lock; -
unique NIP constraint; - state re-check after locking.

External Nextcloud HTTP calls must not be performed while holding a long
DB transaction.

Use after-commit jobs for external side effects.

------------------------------------------------------------------------

## 14. Queue Rules

Jobs must be: - idempotent; - retryable; - observable; - safe after
duplicate execution.

Use queue for: - Nextcloud final upload; - document processing; - large
export; - cleanup.

Do not claim the queue receives browser upload bytes directly; Laravel
must first receive/store the upload.

------------------------------------------------------------------------

## 15. Legacy Code Rules

Existing system may contain: - `grade`; - Jalur `RO/MGM`; -
`aktif/nonaktif`; - two-month exit logic; - model-event side effects.

These are legacy, not target behavior.

Required: - remove dependency on Grade; - map RO terminology to HRD; -
replace 2-month logic with 60-day logic; - migrate status safely; - move
critical workflows toward explicit Actions.

**Do not guess the meaning of legacy `MGM`.**

------------------------------------------------------------------------

## 16. Authorization

Use: - Spatie Permission; - Laravel Policy/Gate; - Filament action
authorization.

Sensitive actions: - view full NIK; - preview document; - edit pending
biodata; - verify; - approve/reject; - exit FP; - export sensitive data.

Every sensitive server endpoint must authorize independently.

------------------------------------------------------------------------

## 17. Performance

Avoid: - N+1; - unpaginated FP tables; - loading binary documents in
list pages; - synchronous slow Nextcloud operations inside approval; -
OCR on main browser thread.

Use: - eager loading; - pagination; - indexes; - thumbnail/metadata
where appropriate; - Web Worker; - Queue.

------------------------------------------------------------------------

## 18. Required Tests

### Exit Boundary

``` text
59 days => ABNORMAL
60 days => NORMAL
61 days => NORMAL
```

### Approval

-   cannot approve unverified biodata;
-   cannot approve unverified documents;
-   cannot approve missing Foto;
-   cannot approve missing KTP;
-   cannot approve missing Ijazah;
-   cannot approve without Jalur;
-   cannot use inactive FP as Jalur;
-   first NIP = 1001;
-   double approval creates only one FP;
-   concurrent approvals have unique NIPs.

### Biodata

-   HRD can correct pending biodata;
-   invalid correction rejected;
-   duplicate NIK rejected;
-   correction after verification resets verification;
-   audit entry created.

### Documents

-   unauthorized preview forbidden;
-   authorized HRD preview succeeds;
-   invalid file rejected;
-   public user cannot access document;
-   viewer source endpoint does not expose Nextcloud credentials.

### Public

-   no login required;
-   public response never includes NIP;
-   all three attachments required.

------------------------------------------------------------------------

## 19. Frontend Viewer Testing

At minimum verify: - opens from Pending; - opens from Detail FP; - Foto
renders; - KTP renders; - Ijazah renders; - zoom; - rotate; - fit; -
reset; - fullscreen; - close; - document navigation; - PDF page
navigation; - keyboard close; - loading/error state.

------------------------------------------------------------------------

## 20. Coding Standards

-   clear domain names;
-   small cohesive methods;
-   type declarations where consistent;
-   enums for bounded values when appropriate;
-   avoid magic strings;
-   dependency injection for external services;
-   no unnecessary repository abstraction over Eloquent;
-   Laravel Pint;
-   Clean Code/SOLID pragmatically, not ceremonially.

------------------------------------------------------------------------

## 21. Security Checklist

Before completing a task:

-   [ ] authorization enforced server-side;
-   [ ] no secret committed;
-   [ ] no plaintext NIK/rekening in logs;
-   [ ] no permanent public document URL;
-   [ ] file content validated server-side;
-   [ ] public endpoint rate-limited;
-   [ ] NIP absent from public payload;
-   [ ] DB invariant has constraints;
-   [ ] failure paths tested;
-   [ ] audit does not leak sensitive values.

------------------------------------------------------------------------

## 22. Verification Commands

Run relevant commands:

``` bash
composer install
npm ci
php artisan test
./vendor/bin/pint --test
npm run build
```

For local/test migration verification:

``` bash
php artisan migrate:fresh --seed
```

Never run destructive migration commands against production without
explicit operational approval.

------------------------------------------------------------------------

## 23. Definition of Done

An implementation is complete only when:

1.  business rule is correct;
2.  migration is safe;
3.  authorization is enforced;
4.  tests pass;
5.  error/failure paths are handled;
6.  audit requirements are met;
7.  PII is protected;
8.  frontend build succeeds;
9.  documentation is updated;
10. legacy compatibility impact is understood.

## 24. Protected Integration Boundary --- IWACS / JWT / SSO

Existing IWACS/JWT/SSO integration is a **protected integration
boundary**. The FP Management System must evolve around this integration
and must not casually refactor, rename, replace, or break its external
contracts.

### 24.1 Protected Integration Code

Treat the following files/areas as protected:

``` text
app/Http/Controllers/JwtSsoController.php
app/Http/Controllers/GlobalLogoutController.php
app/Http/Controllers/Api/UserSyncController.php
app/Services/Sso/**
app/Services/Api/IwacsRoClient.php
app/Services/Api/KeycloakAccessTokenValidator.php
app/Http/Responses/RoLogoutResponse.php
config/api.php
scripts/configure-local-iwacs-ro-sync.ps1
```

Also protect the existing SSO/IWACS-specific sections and contracts in:

``` text
config/services.php
routes/web.php              # existing /sso/* routes
routes/api.php              # existing /v1/integrations/iwacs/* routes
database/migrations/2026_09_03_233000_add_iwacs_sync_fields_to_users_table.php
```

Do **not** modify, rename, remove, relocate, or change the
behavior/contracts of these areas unless the current task absolutely
requires it **and explicit approval is obtained first**.

If a requested feature appears to require changing this protected
boundary, stop before editing it and report:

1.  the exact file/contract that would change;
2.  why the FP feature cannot be implemented without the change;
3.  affected external/internal integration;
4.  backward-compatibility risk;
5.  security risk;
6.  alternative implementation that preserves the existing contract;
7.  migration/rollback strategy.

### 24.2 High-Risk Shared Files

The following files are not completely frozen, but changes to them can
affect authentication, authorization, SSO, deployment, or multiple
modules:

``` text
app/Models/User.php
app/Providers/Filament/AdminPanelProvider.php
app/Http/Kernel.php
config/auth.php
config/sanctum.php
database/seeders/RolePermissionSeeder.php
.env.example
.env.dokploy.example
Dockerfile
docker-compose.dokploy.yml
```

Modify these files only when necessary.

Before modifying a high-risk shared file:

1.  inspect its existing behavior and callers;
2.  explain why the FP feature requires the change;
3.  preserve existing IWACS/JWT/SSO behavior;
4.  keep the change minimal;
5.  add/update regression tests where feasible;
6.  report the change explicitly in the implementation summary.

### 24.3 External Contract Compatibility

Do not change existing integration semantics without explicit approval.

In particular:

-   do not remove legacy roles `ro` or `bm` from SSO/access handling
    merely because the new business terminology uses HRD;
-   do not automatically map `bm` to HRD;
-   do not rename JWT/SSO claims;
-   do not change JWT issuer semantics;
-   do not change JWT audience semantics;
-   do not change JWKS/discovery behavior;
-   do not change `sso_subject` semantics;
-   do not change `profile_version` synchronization semantics;
-   do not change global logout behavior;
-   do not change existing SSO endpoint paths;
-   do not change existing IWACS integration endpoint paths;
-   do not change IWACS request/response contracts;
-   do not replace the existing SSO flow with local authentication;
-   do not expose JWT, SSO, Keycloak, or IWACS secrets;
-   do not edit historical migrations that have already been deployed.

New functionality may consume existing authenticated `User` data, but
must not redefine the upstream identity contract unless separately
approved.

### 24.4 RO vs HRD Compatibility Rule

The statement:

``` text
RO = HRD
```

is a **business/domain/UI terminology rule for the new FP Management
System**.

It does **not** mean every technical occurrence of `ro` in the
repository should be renamed.

Legacy/external integration identifiers may remain `ro` when required
for compatibility.

Therefore:

``` text
Business/UI terminology:
RO -> HRD

Existing external integration contract:
RO/ro -> preserve when required by IWACS/JWT/SSO compatibility
```

Never perform a repository-wide/global search-and-replace of:

``` text
RO -> HRD
ro -> hrd
```

Each occurrence must first be classified as either:

-   business/domain terminology that should migrate to HRD; or
-   integration/legacy contract that must remain compatible.

### 24.5 Migration Rules for Integration-Related Schema

Never edit an already-deployed historical migration to change the
integration schema.

For example, do not rewrite:

``` text
database/migrations/2026_09_03_233000_add_iwacs_sync_fields_to_users_table.php
```

If FP Management requires additional `users` fields, create a **new
additive migration**.

Preserve existing integration fields and semantics such as:

``` text
sso_subject
profile_version
is_active
```

unless a separately approved integration migration explicitly changes
them.

### 24.6 Regression Protection

Changes near authentication/integration boundaries must verify, where
applicable:

-   existing SSO login still succeeds;
-   invalid JWT is still rejected;
-   JWT replay protection remains active;
-   user synchronization still behaves as before;
-   inactive-user behavior remains correct;
-   existing HRD/legacy authorized users can still access the intended
    Filament panel;
-   global logout still works;
-   protected API endpoints still enforce their expected authentication;
-   new FP functionality does not expose SSO/JWT secrets.

Do not weaken existing authentication tests merely to make new FP tests
pass.

### 24.7 Default Decision Rule

When there are two technically valid approaches, prefer:

``` text
new FP code + adapter/extension
```

over:

``` text
refactor existing IWACS/JWT/SSO integration
```

unless refactoring the integration has been explicitly approved as a
separate scope.

The default objective is:

``` text
Add FP Management capabilities
+
Preserve existing integration contracts
+
Minimize blast radius
```

## 25. Final Domain Decision Register

These rules are locked unless explicitly changed by the business owner.

### Legacy MGM

Never auto-map `MGM` to `HRD`, `TEAM`, or `FP`. Preserve it as
unresolved legacy recruitment-source data.

### Exit Reference Date

For new-workflow memberships:

``` text
reference date = business calendar date of submission.submitted_at
< 60 calendar days  = ABNORMAL
>= 60 calendar days = NORMAL
```

Do not use `approved_at` or two calendar months. Use the configured
business timezone. For legacy records, do not fabricate `submitted_at`;
preserve validated historical reference data.

### NIK / Person Identity

NIK identifies a person identity, not one membership. Prefer a dedicated
identity registry with encrypted NIK and a keyed deterministic lookup
value (e.g. HMAC). Never log plaintext NIK.

### Registration / Rejoin

``` text
same identity has PENDING -> block another pending
same identity has ACTIVE  -> block new registration
same identity only EXITED -> allow new submission
prior REJECTED            -> allow fresh submission
```

Both NORMAL and ABNORMAL exited FP may rejoin. Pending Verification must
mark returning FP and expose prior history to authorized HRD.

### Rejoin Creates New Membership and New NIP

Never reactivate/overwrite an old exited membership. Successful rejoin
final approval creates a new membership and allocates a new NIP from the
current sequence. Previous NIPs remain immutable and are never reused.
At most one ACTIVE membership may exist per identity.

### Legacy NIP

Never auto-allocate or backfill NIP for legacy FP. Legacy `nip` may be
`NULL`.

### Returning FP HRD Context

Show previous NIP, Team, Jalur, start/reference date, exit date,
duration, exit type, and exit reason where available. Historical
membership remains read-only history unless a separate authorized
correction workflow exists.

### Jalur Physical References

``` text
source_type
source_team_id nullable
source_fp_id nullable
source_name_snapshot
```

Use typed FKs/invariants. Jalur FP must reference an ACTIVE FP
membership.

### Rejected Submission Documents

Keep the rejected submission audit record. After commit, schedule
idempotent deletion of PROFILE_PHOTO, KTP, and IJAZAH from Nextcloud. Do
not perform external deletion inside the DB transaction. Retry/failure
must be observable. Rejected applicants may submit a fresh form.

### Team Automatic Merge

Automatic legacy Team merge is allowed only through deterministic
canonical matching: stable ID/code first; otherwise case normalization,
trim, repeated-whitespace collapse, and exact canonical equality. Never
fuzzy-merge similar names.

### Team Leader

Leader must be ACTIVE and belong to the same Team. When leader exits,
clear the leader reference and do not auto-select a replacement.

### Legacy Compliance

Never fabricate biodata/document verification, approval
timestamps/actors, or NIP for legacy data.

### Legacy `bm`

Do not remove or automatically map `bm` to HRD in the integration layer.
Protected Integration Boundary rules take precedence over terminology
cleanup.

## 26. Required Tests for Final Decisions

``` text
returning FP with previous NORMAL exit can submit
returning FP with previous ABNORMAL exit can submit
ACTIVE identity cannot submit as new FP
duplicate PENDING identity is blocked
returning FP history is visible only to authorized HRD
rejoin approval creates a new membership
rejoin approval allocates a new NIP
old NIP remains unchanged
old membership remains EXITED
only one ACTIVE membership exists per identity
legacy FP is not auto-assigned a NIP
legacy MGM is not auto-mapped
rejected submission record remains
rejected document deletion is dispatched after commit
document deletion job is idempotent
Team leader must belong to same Team
exiting Team leader clears leader reference
59 days from submitted_at = ABNORMAL
60 days from submitted_at = NORMAL
61 days from submitted_at = NORMAL
```

## 27. Integration Boundary Overrides Terminology Refactors

`RO = HRD` in the business domain never authorizes renaming an existing
external SSO/JWT/IWACS contract.

When domain cleanup and integration compatibility conflict:

``` text
preserve external integration contract
+
adapt the FP domain around it
```

Stop and request explicit approval before changing protected integration
code.
