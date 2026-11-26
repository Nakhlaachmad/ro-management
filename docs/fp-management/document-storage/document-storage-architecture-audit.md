# F3.9 architecture audit

Date 2026-11-18, Asia/Jakarta. Repository `C:/laragon/www/RO_management`.

Status PASS for source inspection. Live PostgreSQL schema inspection NOT RUN.

## Existing architecture

| Concern | Finding |
|---|---|
| Canonical identity | Original `fp_documents.id` is a database key, `uuid` is the private HTTP locator. Amendments have their own immutable `id` and `uuid`, linked by `previous_uuid`. The current-documents view exposes amendment IDs as negative IDs. A negative view ID is not a new physical document ID. |
| Ownership | Original rows link to submission and optional membership. Amendments link membership/submission through FKs. No Nextcloud-owned identity registry exists. |
| Current paths | Original and amendment `private_path` follow `fp/{uuid}/{uuid}.jpg` or `.png`, on each row's `storage_adapter`. The local adapter uses `storage_path('app/fp-private')`; runtime storage overrides can relocate this root. |
| Historical paths | Original records and append-only `fp_document_amendments` retain their paths. `DocumentController` resolves current UUID, then original/historical UUID, then amendment UUID. PROFILE_PHOTO remains archived under F3.7H, outside the active KTP/IJAZAH viewer. |
| Version model | Original revision follows submission document revision. Amendment revision increments per membership and type. `fp_current_documents` chooses newest amendment, but its exposed revision uses submission revision. Reconciliation must query the physical amendment table for the amendment revision. |
| Replacement | `AmendFpDocument` authorizes active Admin before staging, sanitizes via `Uploads`/`SecureImage`, then calls PostgreSQL `fp_amend_membership_document` in a transaction. The function locks membership, checks expected revision and appends a new immutable row. |
| Upload port | `DocumentStorage` already defines put/read/delete/adapter. `PrivateLocalStorage`, `NextcloudStorage`, and `DocumentStorageRegistry` implement per-record provider resolution. Reuse this port for final integration. |
| Integrity | Safe-image processing records MIME, size, SHA-256. Existing Nextcloud put performs GET readback. HTTP controller compares both size and SHA-256 before returning bytes. |
| Authorization | `FeatureAccess::require(..., 'f2.documents.view')` protects retrieval. Existing Victory roles have global view scope, including BM. BM cannot amend documents. Preserve this approved scope. |
| Viewer | Protected application HTTP endpoint supplies bytes with MIME, inline disposition, private/no-store, nosniff and sandbox CSP. Browser receives no DAV endpoint or credentials. |
| Audit | Original document updates use document audit/completeness guards. Amendment records carry actor/time/previous UUID and are append-only evidence. Access controller currently has no separate read-access audit write. Do not invent historical read evidence. |
| Rollback | Definite validation/server rejection discards operation staging. Ambiguous database/COMMIT failure retains evidence and returns persistence uncertainty. `Uploads::discard` currently calls the selected storage delete without a persistent operation ledger. |
| Cleanup | Rejection job selects storage per record, updates deletion state and audit, retries after commit. It is incompatible with F3.9's restriction that remote DELETE is confined to verified operation staging. Keep F3.9 off this path until the contracts are reconciled. |

## Source-proven integration gaps

1. F3.7H amendment table accepts only `storage_adapter='local'` and the old path pattern. Original document guards also require the old path pattern. Historical migrations must remain intact.
2. The amendment function accepts exactly five metadata keys. There is no persisted operation UUID, remote revision UUID or upload reconciliation ledger. An operation UUID cannot currently be linked atomically to canonical metadata.
3. There is no proven remote-upload/DB-COMMIT reconciliation path. Do not wire new remote uploads into `Uploads::stage` until this exists.
4. The old adapter can overwrite a remote path and delete permanent objects. The new F3.9 transport uses conditional immutable PUT and exposes no DELETE until ownership/rollback evidence can authorize cleanup.
5. Private-image validation remains JPEG/PNG. Introducing PDF would require a separate validation/viewer change outside this initial implementation.

## Required additive design before activation

Add a persisted operation ledger with operation UUID, actor, ownership, document/type, expected/new revisions, opaque remote locator, hash/size and lifecycle states. Enforce unique operation/object and atomic canonical revision linkage. Acquire a durable operation claim before remote PUT. After remote verification, lock/recheck canonical revision and commit the amendment plus audit and operation link together. Reconcile unknown COMMIT outcomes by operation UUID using a fresh database connection. Keep remote objects on uncertainty. No retry may allocate a replacement operation automatically.

Extend existing constraints/function through a new migration, preserving local/history behavior. Define system-actor audit provenance for public onboarding before introducing background workers. Reuse DocumentStorage and the registry, selecting provider from immutable metadata. Preserve historical Nextcloud endpoint configuration independently of the new account/root. These migrations, operation orchestration and provider integration are pending.
