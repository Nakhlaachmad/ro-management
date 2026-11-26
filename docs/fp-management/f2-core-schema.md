# F2.1 Canonical FP Core — source-only implementation

Historical F2.1 scope below. F2-FAST now supplies M6–M8 source and dependent services; none have been executed. See [F2-FAST.md](F2-FAST.md) for the current source and activation status.

Status: code/offline review PASS; PostgreSQL execution and activation NOT RUN.
No database connection, migration, seeder, DDL/DML, privilege provisioning, signer or executor invocation was used in this phase.

## Dependency order and scope

| Migration | New tables / purpose |
|---|---|
| 2026_10_09_000001 | person_identities; nip_sequences singleton initialized to 1001 |
| 2026_10_09_000002 | fp_submissions; revision-based verification and terminal evidence |
| 2026_10_09_000003 | financial_planners; canonical membership snapshots and typed sources |
| 2026_10_09_000004 | team_profiles; deferred same-Team ACTIVE leader FK; profile FK on the new membership table |
| 2026_10_09_000005 | fp_identity_claims; six guard functions and eight triggers, including two deferred constraint triggers |

Only six new tables are defined. No existing table is altered. The sole ALTER TABLE targets the new financial_planners table. Existing users, teams and fps provide referenced legacy keys. Names use the f2_ prefix. PostgreSQL-only driver guards reject other engines. Migration down methods throw without issuing destructive SQL. Migration SQL has not been parsed or installed by PostgreSQL. Deployment must apply each migration transactionally; do not use the consumed F1.4B authorization or executor contract for these new migrations.

## Identity, submission and membership

PersonIdentity uses encrypted NIK plus a globally unique 64-character HMAC-SHA-256 lookup hash, with key ID metadata. NikNormalizer accepts exactly 16 ASCII digits after outer ASCII whitespace trimming; it preserves leading zeroes and rejects internal separators/Unicode digits. No plaintext NIK column exists. HMAC secret and business timezone are configuration references only: no secret or environment file was provisioned. One consistent lookup secret is required across registry creation; key rotation is not implemented and must not introduce multiple hashes for one NIK. Identity identifiers and encrypted/lookup key material are immutable under the core trigger.

FpSubmission starts PENDING. UUID/idempotency key are unique. Partial unique identity index limits PENDING rows; revision CHECKs invalidate stale verification. Biodata changes increase the revision and clear review evidence; identity correction also invalidates document review. Submission timestamp/timezone/reference date are immutable. Stable actor IDs retain evidence when nullable live users FKs are cleared by ON DELETE SET NULL. Phone index is deliberately nonunique. APPROVED requires current verification evidence and exact matching canonical membership by deferred trigger. Actual READY document checks require F2.2.

FpMembership maps to financial_planners, never fps. NEW starts ACTIVE with identity, NIP >=1001, approved submission/evidence, Team and typed source snapshots. LEGACY requires a unique legacy_fp_id but permits unknown identity/status/evidence and NULL NIP. No legacy adoption/backfill takes place. Existing MGM remains unresolved raw legacy data; RO is HRD only in the new domain. source_fp_id is a self FK to financial_planners; source_team_id references teams. The FP source must be ACTIVE at approval. Membership ownership, NIP and snapshots are immutable; ACTIVE may exit, EXITED cannot reactivate. Rejoin needs a new submission and membership/NIP. Legacy rows inserted with unresolved immutable ownership cannot be silently linked later; future adoption must be separately designed before any such insertion.

## Occupancy and approval transaction

Per-table partial uniques are supplemented by one claim row per identity across PENDING submissions and ACTIVE memberships. Composite FKs bind claim ownership. Trigger acquisition requires RESOLVED identity; REVIEW_REQUIRED cannot acquire occupancy. Claims are maintained by SECURITY DEFINER trigger functions with fixed pg_catalog search_path and qualified table references. PUBLIC execution/table rights are revoked in source. No runtime roles/grants were changed. Future runtime grants must exclude direct claim writes, ownership and trigger-disabling capability; the runtime role must remain distinct from the migration owner. Model save/delete guards alone do not prevent query-builder SQL.

Future approval action must acquire all affected identity locks in ascending ID order before updating submission; then lock Team profiles, source memberships, submission/documents and counter in the blueprint order. In a single transaction: change verified PENDING submission to APPROVED (releases PENDING claim), insert exact matching NEW ACTIVE membership (acquires ACTIVE claim), and advance locked singleton counter by exactly one. Deferred checks validate the final relationship and advance. Copy bank-account ciphertext using raw attributes; decrypting/re-encrypting via Eloquent would change ciphertext and fail the exact snapshot comparison. Counter is locked and compared, never MAX+1; no NIP is allocated by migration beyond defining the initial next value. Rollback makes an uncommitted number available again; committed NIPs are never reused. Rejected submission releases its claim. Actual concurrent execution and deadlock behavior remain unverified.

Counter guard prohibits deletion/reset/jump and requires a matching NEW membership allocation. A legacy NIP that collides with the counter causes failure; no allocator skipping or legacy reconciliation is silently introduced. Production migration/adoption must inventory real legacy NIPs and decide conflict handling before activation.

## Team and lifecycle

FpTeam maps to team_profiles, an additive profile keyed by legacy team_id. Existing teams are not activated or renamed automatically; teams.name is not interpreted as leader. Composite deferred FK references financial_planners(team_id,active_leader_key), where active_leader_key is generated only for ACTIVE memberships. Exiting a leader therefore requires clearing leader_fp_id in the same transaction. Team/profile adoption must precede canonical workflows. No Team observer, legacy Fp observer, UI, public route, policy or existing authentication code was changed.

## Activation dependencies and unresolved decisions

config/fp.php is hard-disabled and marks core_schema_only. This is documentation/config gating, not permission to expose direct model writes. No service/controller activates the core. Before activation F2.2 must provide fp_documents, fp_exits and fp_audit_events, enforce actual READY documents, complete exit evidence and append-only audit, then implement HRD policies and atomic actions. Exit is structurally possible in source now but cannot safely be exposed before these dependencies. Birth/phone/business rules need full workflow validators. Approved timezone and operator-managed HMAC secret/key ID are still needed; no defaults were guessed. Controlled legacy identity/key reconciliation, Team adoption and permission design remain future work. Filament 3 forms/resources and legacy workflows remain untouched.

## Verification and test boundaries

PASS: PHP 8.4.26 syntax checks for 22 new PHP files; Pint on those files only; standalone PHPUnit 11.5.57 with 31 tests/176 assertions; unchanged original hashes and git diff --check. Tests instantiate models/read metadata and anonymous migration SQL constants but never call up/down. Bootstrap rejects Eloquent/DB facade connections, does not boot Laravel or load Dotenv, and fails on attempted connection access.

Use only the standalone command for this offline suite:

    <accepted-php> vendor/bin/phpunit --no-configuration --bootstrap tests/FpCore/bootstrap.php --do-not-cache-result tests/FpCore

The existing full characterization suite was NOT RUN because its SQLite tests write data. Its tests/expectations were not changed. Source-contract assertions for PostgreSQL guards are not proof that constraints/triggers execute correctly. tests/FpCore/pending-postgres-cases.json records 30 NOT RUN cases, including concurrency, direct SQL transitions, NULL semantics, allocator rollback/serialization, source/leader checks, role privileges and populated legacy compatibility. They require separately authorized disposable PostgreSQL tests; the matrix is not an executed integration suite. PostgreSQL migration parsing, execution, final catalog, grants and populated production migration safety are NOT RUN.

## Final source review corrections

1. f2_core_membership_guard uses explicit base-column ROW comparisons. It never reads active_leader_key in its BEFORE trigger; PostgreSQL computes generated columns after BEFORE triggers.
2. f2_fp_identity_claims_submission_fk uses ON UPDATE NO ACTION DEFERRABLE INITIALLY DEFERRED. PENDING identity correction may temporarily change the parent pair; AFTER claim maintenance removes the old claim and inserts the new one, and the FK checks final ownership. ON DELETE RESTRICT remains. Membership claim ownership FK remains immediate because membership ownership is immutable.
3. FpMembership::sourceTeam and FpSubmission::team reference Team/id, matching their FKs to teams.id. Submission creation still validates an active profile through its trigger. It no longer depends on an optional profile for a LEGACY historical source Team. Main membership team() still references its mandatory profile when team_id is non-NULL.

Generated-column timing reference: https://www.postgresql.org/docs/18/ddl-generated-columns.html
FK deferral reference: https://www.postgresql.org/docs/18/sql-createtable.html

Application claim writes remain forbidden by the planned runtime grants and model API; PUBLIC REVOKE is not a substitute for revoking explicit/default grants or ownership. No GRANT statement is introduced. Normal DML source invariants assume triggers remain enabled and the runtime role lacks claims DML/TRUNCATE/ownership privileges; they do not constrain a PostgreSQL superuser. The encrypted casts provide ciphertext on model writes; SQL text fields alone cannot prove encryption or normalize the NIK. Business identity uniqueness assumes one managed HMAC secret.

## Exact F2.2 schema handoff (planned only)

Create these files in dependency order; none exists or was implemented by this review:

- database/migrations/2026_10_09_000006_create_fp_documents_and_ready_guards.php
- database/migrations/2026_10_09_000007_create_fp_exits_and_exit_guards.php
- database/migrations/2026_10_09_000008_create_fp_audit_events_and_completeness_guards.php
- app/Models/FpDocument.php, app/Models/FpExit.php, app/Models/FpAuditEvent.php
- app/Domain/FP/DocumentType.php, DocumentProcessingState.php, DocumentDeletionState.php, ExitType.php, AuditEventType.php
- tests/FpCore/F2EvidenceSourceTest.php for offline mapping/source coverage; tests/Feature/FpEvidence/PostgresEvidenceInvariantTest.php for separately authorized PostgreSQL integration verification (do not run under current no-write scope).

M6 constraints/functions: f2_fp_documents_uuid_uq; f2_fp_documents_submission_type_uq on (submission_id,type); f2_fp_documents_type_ck for PROFILE_PHOTO/KTP/IJAZAH; f2_fp_documents_revision_ck; f2_fp_documents_storage_ck for nonempty private locator/valid SHA256/positive bytes; f2_fp_documents_state_ck; f2_fp_documents_submission_fk; f2_fp_documents_membership_owner_fk composite (submission_id,membership_id) -> financial_planners(submission_id,id). Use f2_core_document_guard to lock the parent and prevent stale/rejected READY promotion or unauthorized logical replacement after approval. Binary deletion after rejection must retain metadata/audit. Deferred f2_core_ready_documents_final must require exactly the three current-revision READY, verified, privately stored document rows for APPROVED/NEW membership and matching membership links. Attach to submission, membership and document changes so direct document DML cannot invalidate approval unnoticed. Existing VERIFIED revision flags do not prove any of this.

M7: f2_fp_exits_membership_uq/FK; f2_fp_exits_duration_ck (exit_date-reference_date >=0 and exact duration); f2_fp_exits_type_ck (NORMAL>=60 calendar days, ABNORMAL<60); nonblank reason/actor/time evidence CHECKs and nullable live user FK preserving actor snapshot. f2_core_exit_guard must reject record UPDATE/DELETE except narrowly allowed clearing of deleted live actor links, and compare immutable reference snapshot. Deferred f2_core_exit_final attached to membership and exit records must require one matching exit for NEW EXITED, and require EXITED state for every recorded exit; it must not fabricate legacy missing evidence. Leader clear plus state/claim/exit remain one transaction.

M8: f2_fp_audit_events_uuid_uq; event/typed owner/revision uniqueness where appropriate; typed entity ownership FKs; actor evidence and event type CHECKs; explicitly allowlisted sanitized payload without NIK/bank/file/token content. f2_core_audit_append_only must reject UPDATE/DELETE (use stable actor snapshot or RESTRICT live actor FK rather than mutable SET NULL here). Deferred f2_core_audit_completeness must bind APPROVED/NEW membership to an APPROVED audit event, EXITED/new exit to EXITED event, and submission/review/revision/rejection changes to their corresponding event evidence. Attach checks to every relevant mutation source and bind exact identity/submission/membership, actor, timestamp and revision; a generic event with the right label is insufficient.

Constraint/function names above are the handoff contract, not installed objects. Detailed column layouts must follow the accepted blueprint. Application actions, authorization and storage/job adapters remain later implementation scope; merely creating these three tables cannot satisfy transaction completeness or make the core production-ready.
