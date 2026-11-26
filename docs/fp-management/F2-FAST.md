# F2-FAST source package

Feature remains disabled. No migrations, seeders, queue jobs, HTTP routes or services were executed against a database during implementation. Legacy and protected files remain unchanged.

## Source map

- M1–M8: canonical identity/membership + cross-table claims + document, immutable exit and append-only audit constraints. M6 adds receipt fingerprint and stable correction actor/time metadata to the new submissions table. Apply only together on an empty canonical schema after explicit authorization; no legacy adoption or NIP backfill.
- app/Application/FP/FpEngine.php: ten requested domain operations, atomic transactions, sorted identity/profile locks, document/actor checks and counter lock last. Additional explicit Team creation/profile maintenance; no Fp observer.
- app/Infrastructure/FP: secure image sanitization; DocumentStorage; private local and Nextcloud WebDAV adapters. JPEG/PNG only; unsupported PDF rejected. GD required. No external integration was activated or proven.
- app/Http/Controllers/FP, Requests/FpApplicationRequest, Middleware/FpEnabled, routes/fp.php: public onboarding and authenticated private preview. Receipt only opaque UUID/message, never NIP/NIK/history. File hashes are checked on private preview.
- app/Filament/Resources/FpSubmissionResource, FpMembershipResource, FpTeamResource: dedicated HRD workspace. Mutation actions use FpEngine; default generic create/edit/delete not exposed. Existing Team meaning remains; explicit Team creation takes Nama MM and Nama Team, no leader inference.
- FpPolicy/FeatureAccess: active user plus explicit additive f2.* permissions. Permission seeder source creates names only, never automatically assigns legacy roles. Seeder NOT RUN.
- DeleteRejectedDocuments: persistent intent, retryable after-commit deletion outside DB transactions; metadata + audit atomically finalized. fp:retry-deletions redispatches existing failed/requested intents; command NOT RUN.
- resources/views/fp + resources/js/fp: onboarding and shared JPEG/PNG viewer (navigation/zoom/rotate/fit/reset/fullscreen/native modal Esc/focus). Advanced PDF/OCR/camera-guide features from the broader PRD remain future scope and are not represented as implemented.

## Failures and idempotency

Submit retries with the same UUID/body return the same receipt; a changed payload with that UUID is rejected. Duplicate person PENDING/ACTIVE is blocked. Rejoin allocates a new membership/NIP after exit. Query exceptions use FpPersistenceException and never promise that COMMIT did not happen. Files are cleaned only on confirmed callback validation rollback or successful idempotent replay. Unknown persistence retains potentially referenced files and returns a request UUID for HRD reconciliation; no automatic retry/repair. Retained unreferenced staging files may require later confirmed-orphan cleanup; do not delete while persistence is uncertain.

## Safe verification used in this phase

    <php84> vendor/bin/phpunit --no-configuration --bootstrap tests/FpCore/bootstrap.php --do-not-cache-result tests/FpCore
    <node> --test tests/FpFrontend/viewer.test.cjs

Tests/FpCore bootstrap refuses all Eloquent/DB connections. PHP lint/Pint cover F2 files only. Blade compile/token parsing does not render queries. JavaScript tests use DOM/fetch fakes; no actual browser or server was started. Existing SQLite-writing characterization suite NOT RUN; expectations unchanged.

## PostgreSQL tests (source ready, NOT RUN)

Use tests/FpPostgres/phpunit.xml only after separate database-write authorization. This suite is outside default tests/Feature/Unit discovery. It creates schema only in a pre-provisioned empty disposable database; it never drops/resets/provisions roles. Requirements: loopback, database named fp_f2_test_<suffix>, an explicitly supplied role with access to that disposable database, FP_TEST_PORT, FP_TEST_PASSWORD and explicit FP_TEST_WRITE_AUTHORIZATION=I_AUTHORIZE_DISPOSABLE_F2_TEST_DATABASE_WRITES. No .env is loaded or edited by its isolated harness.

The future command is <php84> vendor/bin/phpunit -c tests/FpPostgres/phpunit.xml. Do not execute it under the current source-only permission. It installs 8 original + 8 canonical migrations with transaction/ledger per file, then tests workflow, direct SQL invariants, ownership, audit, stale rejection, and separate-process concurrency. Expected catalog: 21 tables, 16 ledger rows and 13 F2 functions. Those expectations have not been verified. Core test actor deliberately mocks dedicated permission checks; it does not constitute SSO/Spatie HTTP authorization verification. No F1 executor or consumed authorization is used.

## Activation later, not authorized now

FpServiceProvider source is NOT added to the existing config/app.php provider list in this phase. This preserves the authorized new-F2-only boundary. The existing panel discovers new resources, but access is denied by disabled flags. Future activation must register this provider, install/verify M1–M8, configure the business timezone and immutable HMAC secret/key ID, verify GD/private storage/queue, create and assign explicit permissions, and then deliberately change enabled/core_schema_only after review. No such change was performed.

Nextcloud is an adapter with fake tests only. When selecting it, credentials/private WebDAV endpoint and queue operations need separate integration verification; do not switch storage_adapter for existing documents without a read/migration strategy. Default local adapter provides the source vertical slice without external service calls. Full browser/HTTP/Filament behavior and actual database privilege enforcement remain PENDING.