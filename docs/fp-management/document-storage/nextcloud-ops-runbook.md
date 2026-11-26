# F3.9 operator runbook

This runbook covers the minimal Nextcloud publication path used by the active
FP workflow. It does not activate the wider F3.9 revision workflow;
`NEXTCLOUD_ENABLED` remains `false`.

## Deployment prerequisites

1. Run the additive migration that creates Laravel's `jobs` table:

   ```bash
   php artisan migrate
   ```

   The migration is idempotent: an existing `jobs` table is left unchanged.

2. Set the queue driver in the deployment environment:

   ```dotenv
   QUEUE_CONNECTION=database
   ```

   `docker-compose.dokploy.yml` now overrides both `web` and `queue` to
   `database`. The worker shares the web storage volume because publication
   reads locally staged documents before writing the readable Nextcloud copy.

3. The worker command is:

   ```bash
   php artisan queue:work database --queue=default --tries=5 --timeout=180 --sleep=3
   ```

   `config/queue.php` uses `retry_after=300`, above the worker timeout, so a
   slow Nextcloud request is not reclaimed while still running.

The local development launcher still uses `QUEUE_CONNECTION=sync`; no existing
SSO/JWT/IWACS configuration is changed.

## Normal approval flow

- Public/panel uploads are staged in private local storage.
- HRD approval commits the membership and NIP first.
- `PublishApprovedFpDocuments` is dispatched `afterCommit`.
- The job verifies size and SHA-256, then publishes to
  `fp/<NamaFP>_<NIP>/ktp.jpg` and `ijazah.jpg`.
- Both copies are retained: local for the authorized viewer/DB reference,
  Nextcloud as the readable operational copy.

## Approval intake and optional documents

- The approval modal has exactly two inputs: **Nama MM** and **Sumber
  Rekrutmen**. `Nama FP` and legacy reference controls are not exposed. Stale
  or injected `source_fp_id`, `source_type`, `reference_type` and
  `source_team_id` values are discarded server-side before approval.
- Public onboarding does not require KTP or ijazah. An applicant can advance
  from **Dokumen** to **Konfirmasi** and submit with no documents. The document
  step states that HRD/admin fills skipped documents before or after approval.
- On the submission detail page, HRD/admin uploads or replaces KTP/Ijazah
  directly from each supporting-document card. On a `PENDING` submission the
  card uses `FillSubmissionDocument`; on an `APPROVED` submission with a
  membership it uses `AmendFpDocument`. The table also has a legacy
  **Isi / ganti dokumen** action. `RO`/`BM` cannot see or execute either path;
  both actions are independently authorized server-side.
- The public receipt contains only `receipt` and `message`. `receipt` is the
  submission UUID and can be pasted into the **Validasi Pendaftar** search. No
  NIP, NIK, Jalur or other PII is returned to the public applicant.

## Amendment flow

- HRD document amendment still keeps the immutable local history and audit row.
- After commit, `PublishAmendedFpDocuments` publishes only the **latest**
  revision for that membership and document type.
- `NextcloudStorage::putCurrent()` compares the existing remote hash. Identical
  retries are skipped; changed bytes are replaced with `If-Match` when an ETag
  is available.
- Older revisions are never pushed to the readable folder.

## Failure detection

Publication failures log a warning with IDs and exception class only. NIP, NIK,
file bytes, URLs and credentials are never logged.

```text
FP Nextcloud publication failed
FP Nextcloud publication dispatch failed
FP Nextcloud publication retry failed
FP Nextcloud amendment publication failed
FP Nextcloud amendment publication dispatch failed
FP Nextcloud amendment publication retry failed
```

Also inspect the failed-job table:

```bash
php artisan queue:failed
php artisan queue:monitor database:default
```

## Operator retry

Approval publication:

```bash
php artisan fp:publish-approved-documents <submission-id-or-uuid>
```

Latest amendment publication:

```bash
php artisan fp:publish-amended-documents <amendment-id>
```

Both commands reuse the same idempotent jobs and print one sanitized status:
`PUBLISHED`, `SKIPPED_LOCAL_ADAPTER`, `FAILED: <exception class>`,
`SUBMISSION_NOT_FOUND`, `AMENDMENT_NOT_FOUND`, `NOT_APPROVED`,
`MEMBERSHIP_NOT_FOUND`, or `FP_DISABLED: <exception class>`.

If the first attempt fails because of a transient Nextcloud error, wait for the
job backoff or run the retry command. A partial folder is safe to retry.

## Connectivity

```bash
php artisan fp:nextcloud-health
```

`CONNECTED` proves authenticated DAV access, not full F3.9 acceptance.
Never paste Nextcloud credentials into chat, tickets, logs or Git.
