# F3.9 operation journal

Checkpoint 2026-11-18, Asia/Jakarta. Foundation implemented and tested on disposable PostgreSQL. Overall F3.9 PARTIAL, real DAV acceptance BLOCKED.

## Dependency map and implementation

| Concern | Existing dependency | Additive implementation |
|---|---|---|
| Ownership | financial_planners UUID/id, submission FK, existing active-Admin role | Operations reference the same membership and actor, preserving ownership |
| Metadata/history | fp_documents originals, append-only fp_document_amendments, fp_current_documents | Reuse amendment history, add nullable operation link and integrity verification time |
| Storage | Existing DocumentStorage port and provider registry | NextcloudRevisionStorage implements the same port without changing active bindings |
| Transport | NextcloudWebDavClient | Reuse verified HTTPS, no redirects/retries, immutable conditional PUT and bounded GET |
| Upload validation | SecureImage | Sanitize synthetic JPEG/PNG before operation creation; hash sanitized bytes |
| Authorization | Existing Admin amendment contract | Recheck active Admin against existing users/roles before operation access and canonical commit |
| Audit | Existing immutable-history function | New append-only operation events; deferred constraint requires transition audit in the same transaction |
| Download | Existing DocumentController and global document-view policy | Existing current/historical HTTP behavior retained; new adapter not wired into the main viewer |
| Migrations | Timestamped PostgreSQL/Laravel migrations | One additive, explicitly disposable-guarded migration, ledger installed once |

A separate journal is required because mutable work-in-progress states cannot be stored in immutable completed-revision records. There is no second revision-history table or second storage abstraction.

The journal persists operation UUID/type, scoped idempotency digest, request fingerprint, membership/type, expected/target sequence, preallocated revision UUID, actor, provider/opaque path, sanitized MIME/size/SHA-256, state/timestamps, attempt count, lease owner/expiry, fencing token and allowlisted error code. No credentials or biodata are stored in it. The staging filename is derived solely from the operation UUID in a private operator-supplied directory.

Idempotency scope is actor + membership + document type + operation type + key digest. An identical request returns the same operation. A changed fingerprint conflicts. Membership locking serializes creation checks. Claims use FOR UPDATE SKIP LOCKED, database-clock 120-second leases and monotonically increasing fencing tokens. No remote I/O occurs in a database transaction. Every later update rechecks lease owner, fence and expiry.

## Implemented transition matrix

Each state change and claim includes an event in its short database transaction. Remote effects occur after that transaction. All unlisted state transitions are rejected by the database guard. Some additional review transitions are allowed by the SQL guard but have no automatic service entry point.

| Source | Target | Trigger/preconditions | DB boundary | Remote effect | Retry/recovery |
|---|---|---|---|---|---|
| absent | PENDING | Active Admin, safe image, expected sequence, unique scoped key | Journal insert + CREATED event | None | Duplicate identical request returns existing UUID |
| PENDING | UPLOADING | Exclusive fenced claim | State + audit commit | GET existence; PUT only after confirmed 404 | Same immutable path; no blind replay |
| UPLOADING | UPLOADED | PUT returned and lease valid | State + audit commit | None in DB | GET still required |
| PENDING/UPLOADING/UPLOADED | VERIFYING | Recovery claim or verified upload phase | State + audit commit | Bounded GET | Unknown outcome retains object and staging |
| VERIFYING | VERIFYING | Matching size/hash or remote unavailable | Verification/error + event | None in DB | Outage/auth errors release lease and preserve uncertainty |
| VERIFYING | PENDING | GET confirms missing | State + audit commit | None | Explicit worker must check existence again before PUT |
| VERIFYING | FAILED | Confirmed missing object and invalid local staging before PUT | State + audit commit | None | Terminal operator review |
| VERIFYING | QUARANTINED | Hash mismatch | State + audit commit | No move/delete | Preserve locator and evidence |
| VERIFYING | QUARANTINED | Concurrent sequence conflict at canonical commit | Membership/operation lock + audit | None | Retain orphan under the operation UUID |
| VERIFYING | COMMITTED | Verified bytes, active Admin provenance, current sequence, valid fence | Revision + operation + audit atomically | None | Fresh-connection reconciliation after lost acknowledgement |
| COMMITTED | COMMITTED | Matching canonical UUID on reconciliation | Read-only check; append cleanup intent only if local cleanup is needed | No remote change | No duplicate revision or PUT |

FAILED and QUARANTINED require operator review. No automatic rearm, scheduler or remote deletion is installed. Lease expiry cannot cancel an in-flight DAV request. Fencing prevents a stale worker from committing metadata, while immutable conditional PUT protects the remote object. Slow multi-request uploads can outlive a lease and require reconciliation instead of returning success.

## Files

- app/Application/FP/DocumentOperations.php
- app/Infrastructure/FP/NextcloudRevisionStorage.php
- app/Console/Commands/F39ReconcileDocuments.php
- database/migrations/2026_11_18_000001_add_f39_document_operations.php
- tests/FpPostgres/F39/accept.php and failure.php
- tests/FpPostgres/F37HPostgresTest.php, test-target override and Windows scratch-drive support only

Implementation is intentionally guarded to database names fp_f39_stage68_<digits> on cluster 7694154787604477244. It cannot be used as a production rollout migration. Main storage remains local and NEXTCLOUD_ENABLED defaults false.
