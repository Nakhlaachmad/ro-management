# F3.9 Stage 6–8 regression and implementation status

Final status PARTIAL. Operation journal, additive revision contract and reconciliation foundation are implemented and tested on verified disposable PostgreSQL with synthetic storage. Authenticated DAV remains BLOCKED.

| Gate | Status |
|---|---|
| Journal, idempotency, leases and fencing | PASS disposable |
| Canonical remote revision and immutable history | PASS disposable/fake transport |
| Reconciliation, dry-run and bounded batches | PASS service-level disposable |
| Real PostgreSQL concurrency/failure recovery | PASS for recorded injected cases |
| Backup/restore and migration-ledger rerun | PASS |
| F3.7H PostgreSQL regression | PASS, 11 tests / 153 assertions |
| Existing private HTTP, optional onboarding and reporting tests | PASS, 41 tests / 278 assertions; SQLite used only for this supplementary isolated suite |
| Storage fakes | PASS, 18 tests / 59 assertions |
| Frontend regression | PASS, 32 tests |
| Main/source table equality and historical local SHA | PASS within recorded fingerprint scope |
| 14 protected files / extended source snapshot | See source-integrity.json |
| Real authenticated DAV, password rotation, live certificate tests | BLOCKED/NOT RUN |
| Actual remote browser/current-history reader integration | NOT RUN; active upload/provider wiring remains intentionally absent |
| Full production readiness | PARTIAL |

## File inventory

New source files are app/Application/FP/DocumentOperations.php, app/Infrastructure/FP/NextcloudRevisionStorage.php, app/Console/Commands/F39ReconcileDocuments.php and database/migrations/2026_11_18_000001_add_f39_document_operations.php. New executable disposable harnesses are tests/FpPostgres/F39/accept.php and failure.php. Existing F37HPostgresTest.php changed only to accept an explicitly validated F3.9 disposable database override and a drive-aware test cache path. The original default database remains its previous disposable target.

The six requested reports and sanitized evidence are saved here and copied into repository docs/fp-management/document-storage/disposable-acceptance. Earlier blocked reports were archived in the workspace work directory before replacement. No JWT/SSO/IWACS code, authentication middleware, NIP/lifecycle logic, role grant or password was changed. Active storage config and .env were preserved. No Git commit, deployment, scheduler or Nextcloud activation occurred.

A new retained disposable clone was created for tests. Temporary database-specific HBA access was restored byte-identically after testing. Main database data was read only for integrity snapshots. Historical local files were only hashed. New synthetic operation/revision rows exist only in the clone and scratch storage. Backup files remain in D:/CodexF39Stage68Scratch, with secret-free hashes and restore evidence included here.

## Remaining blockers and limitations

Supply a dedicated Nextcloud account/App Password through secure configuration and resolve the previous Windows TLS preflight before real DAV tests. No credentials were provisioned in this checkpoint. Complete actual remote upload/download, integrity, rotation/outage and browser acceptance before activation approval. Production database adoption and active reader/upload wiring need a later reviewed rollout, since this migration and service explicitly reject main. Quarantined/failed operations require operator review, no automatic remote deletion or rearm is provided, and forward-only migration evidence is preserved.
