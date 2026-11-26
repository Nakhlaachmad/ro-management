# F3.9 storage implementation

Overall PARTIAL. This checkpoint delivers transport preparation, architecture audit and approval-time publication to Nextcloud. It does not implement the F3.9 operation journal or immutable revision workflow in the active upload path.

## Implemented

- `config/nextcloud.php` supplies the five requested environment settings with integration disabled by default and no embedded credentials.
- `NextcloudWebDavClient` implements authenticated PROPFIND, MKCOL, immutable conditional PUT and bounded GET. HTTPS, TLS verification, redirects disabled, 5-second connect and 30-second total/read timeouts are enforced. There is no automatic retry.
- A 405 MKCOL requires successful collection PROPFIND, preventing a non-directory from being silently accepted. PROPFIND XML is bounded and parsed with network access disabled and DTD/entities rejected.
- New locators use `documents/{opaque-uuid}/{document-uuid}/revision-{revision-uuid}/document.bin` under the configured root. Staging locators use an operation UUID. No caller-supplied path outside these shapes is accepted.
- Conditional PUT uses `If-None-Match: *`. A 412 replay is accepted only after GET confirms identical byte length and SHA-256. This is object-level behavior, not proof of application-level idempotency or DB commit.
- PUT transport failures and remote 5xx are reported as uncertain. No speculative DELETE occurs. Permanent revision deletion and staging cleanup remain unavailable until durable ownership and rollback records exist.
- Admin-only Filament storage status page and navigation entry provide an explicit, rate-limited connection check. Every action rechecks authorization. No credentials/configuration values are exposed as Livewire public properties. HRD, RO and BM cannot use this page.

## Pending

The existing DocumentStorage/NextcloudStorage/registry still serves the active upload workflow, but it is now switchable through `FP_STORAGE_ADAPTER` (default `local`) and reuses the `NEXTCLOUD_*` service account when legacy overrides are absent. Uploads are staged locally; after approval commits, `PublishApprovedFpDocuments` publishes them to `fp/<Nama>_<NIP>/ktp.jpg` and `ijazah.jpg`. After an HRD document amendment commits, `PublishAmendedFpDocuments` publishes only the latest revision to the same folder. Deployment uses the database queue with a shared-storage worker, and both local and Nextcloud copies are retained. The new F3.9 transport is used only by the health page and offline tests; the F3.9 operation journal/revision path remains unwired. See `nextcloud-ops-runbook.md` for operator commands.

Additive PostgreSQL schema/function changes, persistent operation journal, local staging lifecycle, remote-to-canonical commit orchestration, ambiguous-commit reconciliation, upload/integrity diagnostics, canonical revision UI and migration command with checkpoint/resume are NOT IMPLEMENTED at this checkpoint. HEAD is optional and has not been probed. Quarantine transitions are pending. No claim is made that enabling the flag completes these paths.

Future migration tooling must inventory both original and amendment physical rows, use dry-run by default, verify local and remote size/SHA, persist per-document checkpoint and operation identity, resume without creating new objects, retain local files/history, and produce a failure report. Rollback must preserve original canonical references and all historical binaries. No migration command or main migration was run.
