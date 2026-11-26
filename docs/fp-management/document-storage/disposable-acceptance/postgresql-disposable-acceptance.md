# F3.9 PostgreSQL disposable acceptance

2026-11-18. Status PASS for the tested disposable foundation. Overall F3.9 PARTIAL.

## Verified identity and provenance

Host 127.0.0.1, port 55432, cluster 7694154787604477244, schema public. Source disposable fp_f37g_phase3_20261021 had 33 base tables and 23 migration entries. It belongs to the existing synthetic-development environment whose provisioning manifest records no legacy imports. No real identity document was used by the tests; test images were generated with GD.

Target fp_f39_stage68_20261118 was created separately, owned by existing fp_dev_migrator. Main fp_management_synthetic_dev was accessed only through explicit read-only fingerprint transactions. The Odoo service/cluster was not modified.

## Backup and restore

Backup retained at D:/CodexF39Stage68Scratch/stage68-before-attempt2.dump, 293051 bytes, SHA-256 6dd47daf2832072b2441077210619c5ecfeb0a97c23cde8e2e4c58c63ff0c69e. pg_restore --list validated the archive. pg_restore --single-transaction --no-owner restored it into the new empty target. Every row fingerprint across all 33 restored tables matched the source before migration. See evidence/prepare.json.

The first restore attempt was rejected by pg_hba.conf for the new database name. Its failed transaction left the target empty. The failed backup/restore artifacts were retained. Narrow temporary HBA entries were added only for the new target and existing dev roles, with scoped filesystem permission. After acceptance, the original pg_hba.conf was restored byte-for-byte and reloaded. evidence/hba-restoration.json confirms equality. The target database and synthetic test evidence remain retained, with the temporary access entries removed.

## Migration and transaction acceptance

One migration was created, 2026_11_18_000001_add_f39_document_operations.php. It requires a PostgreSQL enclosing migration transaction, the verified cluster ID and an F3.9 disposable database-name guard. It adds two tables, extends existing amendment metadata/constraints, and adds immutable/deferred audit and canonical-commit guards. No main migration ran.

- PASS installation inside a transaction followed by rollback, proving new journal tables disappear without changing historical rows.
- PASS committed installation with one migration ledger entry.
- PASS rerun through the actual Laravel Migrator, which reports no pending installation and leaves one ledger entry.
- PASS two real PHP worker processes and separate PostgreSQL sessions, exactly one PUT and canonical commit.
- PASS SKIP LOCKED claim exclusion, lease fencing, unique idempotency scope and ownership FK.
- PASS repeatable-read snapshot isolation and fresh-connection visibility after commit.
- PASS forced canonical insert failure, transaction rollback and retained remote/local evidence.
- PASS lost COMMIT acknowledgement injection and fresh-connection recovery.
- PASS deferred constraints reject unaudited transitions and COMMITTED without a revision.

The target now has 35 base tables. Original rows in all 33 restored tables match the pre-install source projection. For amendments, the comparison excludes newly inserted remote operation rows and compares original columns. For the migration ledger, only the new F3.9 entry is excluded. No historical row was backfilled.

## Main and historical evidence

All 33 main table fingerprints match before/after. All 33 source-disposable table fingerprints match. Twenty-one referenced historical local files match their immutable database SHA-256 and byte size, with zero mismatch. Protected-source comparison is recorded in source-integrity.json. Fingerprints cover table data, not sequence counters or all PostgreSQL catalog state. Test-only identity sequences in the clone may advance through rolled-back tests.

Actual authenticated DAV, browser remote-version access, server-kill recovery and Laravel down() rollback were NOT RUN. The migration remains forward-only. See evidence/integrity.json and the failure matrix for precise boundaries.
