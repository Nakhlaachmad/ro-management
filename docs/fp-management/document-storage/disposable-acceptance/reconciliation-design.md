# F3.9 reconciliation implementation

Status PASS for disposable orchestration and fault injection. Real remote acceptance BLOCKED. Overall PARTIAL.

DocumentOperations provides process, reconcile, inspect and batch. Reconcile and batch default to dry-run. Batch limit is 1–100, default 25. Dry-run performs no claims, state/audit writes, staging deletion or PUT. Apply takes a fenced claim. No scheduler was installed.

An explicit CLI entry point is provided:

```text
php artisan fp:f39-reconcile --actor=<active-admin-id> --staging=<private-stage-directory> --limit=25
php artisan fp:f39-reconcile --actor=<active-admin-id> --staging=<private-stage-directory> --operation=<operation-uuid> --apply
```

Configure the Laravel connection to the approved disposable target through secure process environment first. The command uses a read-only WebDAV client even with --apply, so reconciliation never PUTs. Missing credentials produce a sanitized failure. The command is not scheduled, and its database guard rejects main. Service-level dry-run/apply was tested with fake transport; actual authenticated CLI DAV execution is BLOCKED.

| Evidence | Result | Persisted behavior |
|---|---|---|
| GET 404 | REMOTE_MISSING | Reconcile returns PENDING after a fenced check; no upload by reconciliation |
| Matching GET size/SHA | COMMITTED | Atomic new canonical revision, operation and audit |
| Different GET size/SHA | INTEGRITY_MISMATCH | QUARANTINED, object preserved |
| Timeout/outage/TLS transport failure | REMOTE_UNKNOWN | Nonterminal state and sanitized error retained; no speculative PUT or DELETE |
| Auth failure | AUTHENTICATION_FAILED | Nonterminal state retained; no fallback or credential exposure |
| Matching canonical operation/revision UUID | ALREADY_COMMITTED | No duplicate canonical row/upload; verified local cleanup can resume |
| Inconsistent canonical linkage | INVARIANT_WARNING | No automatic repair |
| Active competing lease | BUSY_OR_TERMINAL | No second upload |
| Failed/quarantined operation | OPERATOR_REVIEW_REQUIRED | No automatic retry |
| Expected sequence changed | REVISION_CONFLICT | Quarantine the uncommitted operation and retain orphan reference |

The operation journal itself is the orphan registry for objects created by known operations. Quarantine is a database state, without a destructive remote move. Discovery/adoption of unknown external objects is outside this implementation. Remote DELETE is deliberately unavailable because no staging-deletion capability has been proven safe. Existing historical deletion jobs are not connected to this new adapter.

Tests covered upload-completed/response-timeout recovery, metadata rollback after upload, a PDO fault that throws after successful COMMIT, fresh-connection recovery, stale fencing, repeated reconciliation, two simultaneous PHP workers with real PostgreSQL sessions and exactly one remote PUT, FK/unique/deferred guards and PostgreSQL repeatable-read snapshots. The remote transport in these tests stores synthetic bytes locally and is not authenticated Nextcloud.

All recovery transitions have actor/fence/reason/time audit. Cleanup attempts on already committed operations record DEFERRED COMMITTED-to-COMMITTED intent before the filesystem effect. Unavailable cleanup leaves evidence available for a later retry. TLS failure tests inject the transport classification; they do not prove certificate rejection against a live bad-certificate server.
