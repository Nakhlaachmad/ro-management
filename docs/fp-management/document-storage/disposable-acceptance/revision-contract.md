# F3.9 remote revision contract

Status PASS for disposable schema/commit invariants with synthetic transport; overall PARTIAL pending real DAV and later application-reader integration.

Reuse fp_document_amendments for completed history. Its existing uuid is the canonical revision UUID, revision is the per-membership/type sequence, and existing ownership/provider/path/MIME/bytes/SHA/actor/time fields remain authoritative. Add operation_uuid, unique and FK-backed, plus integrity_verified_at. Historical local rows retain their original fields and NULL additive fields.

The logical document identity is membership + type. Its opaque folder UUID is UUIDv5 using the immutable membership UUID and KTP/IJAZAH type. It does not replace existing HTTP UUIDs. The remote object path uses only UUIDs:

```text
Victory-FP-Management/documents/{membership-uuid}/{document-folder-uuid}/revision-{revision-uuid}/document.bin
```

The migration extends provider/path constraints to accept either the original local contract or a verified remote contract. Existing local amendment function signature and semantics remain intact. A new BEFORE INSERT guard requires the entire remote revision tuple to match a VERIFYING operation with an unexpired lease and recorded verification. A deferred guard requires canonical-row existence and COMMITTED operation state to agree. A unique operation FK prevents duplicate canonical insertion. Existing unique membership/type/revision and immutable-history triggers remain enforced.

The commit transaction locks membership then operation, rechecks recovery and initiating Admin access, expected sequence and fence, inserts the new revision linked by previous_uuid, updates COMMITTED and appends audit. No DAV request is held inside that transaction. Definite insert/constraint failure rolls back the whole canonical transaction while retaining remote bytes, operation and local staging. A stale sequence quarantines the operation and retains the remote orphan. No historical object is overwritten or deleted.

An ambiguous COMMIT acknowledgement is returned as COMMIT_UNCERTAIN. The operation UUID, revision UUID and remote locator remain fixed. A fresh connection resolves the persisted canonical link. Matching committed metadata returns ALREADY_COMMITTED, and only then may verified operation-owned local staging be cleaned. Cleanup intent is audited before unlinking. Unexpected files and symlinks are retained.

Original fp_documents and historical amendment UUIDs remain readable through the existing authorized controller. The existing current-documents view projects submission revision, so operation sequence checks query the physical amendment table. New remote revisions in the disposable clone are not wired into the active main viewer or production upload flow. New remote-reader HTTP/browser acceptance is NOT RUN. Adapter/hash behavior and existing local current/history HTTP regressions passed separately.

The migration is forward-only, consistent with evidence-preserving project conventions. Rollback acceptance covered installation inside a transaction followed by rollback, and an independently verified backup restore. Laravel down()/migrate:rollback is intentionally unsupported. Production adoption requires a later reviewed migration and reader/registry integration.
