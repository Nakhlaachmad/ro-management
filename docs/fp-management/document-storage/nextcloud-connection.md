# F3.9 connection report

2026-11-18, Asia/Jakarta. Status BLOCKED for authenticated connectivity.

An unauthenticated HTTPS GET to `https://cloud.viffx.com/status.php` failed in Windows TLS initialization. Exception chain reached `AuthenticationException` and Windows security-package error `No credentials are available in the security package`. This is client-side evidence and does not establish that the server certificate is invalid. No HTTP status or certificate validation success was obtained.

Further connection attempts stopped. No credentials were supplied, no HTTP fallback or certificate bypass was attempted, and no remote folder/file was created. Authenticated PROPFIND and browser connectivity are NOT RUN.

`config/nextcloud.php` defines the requested environment variables. NEXTCLOUD_ENABLED defaults false, base URL defaults to the requested HTTPS host, username/password default empty, root defaults Victory-FP-Management. Existing `.env` and legacy `FP_NEXTCLOUD_*` configuration remain intact.

Environment template, with credentials intentionally empty:

```dotenv
NEXTCLOUD_ENABLED=false
NEXTCLOUD_BASE_URL=https://cloud.viffx.com
NEXTCLOUD_USERNAME=
NEXTCLOUD_APP_PASSWORD=
NEXTCLOUD_ROOT_FOLDER=Victory-FP-Management
```

The Admin health action can run an explicit read-only PROPFIND with the flag off. Its client cannot PUT. This allows connection testing before activation. With missing credentials it returns a sanitized configuration-required state without sending a request.

Operator setup requires a dedicated service account without administrator privileges and an App Password created from that account's personal Security settings. Use the username returned by Nextcloud, not an assumed display name. Store the password in an approved secret/environment mechanism, outside Git/logs/browser state. Do not paste it into chat. No credential provisioning script or plaintext secret file was created in this phase.

Next actions require correcting the Windows TLS execution environment, then repeating HTTPS preflight with verification enabled. After that, test authenticated DAV and a dedicated synthetic folder with the service account. Leave the feature flag off until all acceptance gates and operator approval pass.

## 23 November 2026 preflight update

The earlier Windows TLS initialization failure did not reproduce. A direct PHP
8.4/Guzzle HTTPS GET to `https://cloud.viffx.com/status.php` returned HTTP 200
with certificate verification enabled and redirects disabled. An unauthenticated
PROPFIND through the actual `NextcloudWebDavClient` reached the DAV endpoint and
returned the sanitized `NEXTCLOUD_AUTHENTICATION_FAILED` state, proving the
endpoint path and error mapping without using real credentials.

At the 23 November checkpoint no Nextcloud credential variables were present
in `.env`. The five documented settings were added to `.env.example` with
`NEXTCLOUD_ENABLED=false` and empty username/password values. On 24 November
operator-provided values were detected locally in `.env` by presence-only
checks; their values are intentionally not recorded here. `.env` remains
untracked. This does not touch IWACS/JWT/SSO configuration or activate remote
storage writes. A read-only CLI check was added for operator setup:

```powershell
php artisan fp:nextcloud-health
```

It resolves the health-only client, prints one sanitized status value and never
enables writes. On 24 November the command returned `CONNECTED` with exit code
0 using the locally supplied credentials.

## 24 November minimal enablement

The operator only required Nextcloud document storage to function. The existing
`NextcloudStorage` adapter was retained and made configurable through
`FP_STORAGE_ADAPTER` (default `local`). The legacy `FP_NEXTCLOUD_*` values remain
as overrides; when absent, they are derived from the same `NEXTCLOUD_*` service
account used by the health client. The local `.env` was switched to
`FP_STORAGE_ADAPTER=nextcloud`; no secret value was committed or displayed.

A real smoke test with synthetic bytes and an opaque path passed:

```text
App\Infrastructure\FP\NextcloudStorage
PUT_READ_HASH_OK
SYNTHETIC_OBJECT_DELETED
```

A stream-reading defect in `NextcloudStorage::read()` was fixed: an empty
network chunk before EOF no longer fails a successful response. The regression
is covered by `tests/FpStorage/NextcloudStorageTest.php`.

This enables the existing private document workflow through Nextcloud. It does
**not** activate the F3.9 operation journal, immutable remote revision paths,
reconciliation or the new viewer route; those remain PARTIAL and unchanged.

## 24 November approval-time readable folder layout

The operator clarified that documents must enter Nextcloud only **after HRD
approves the submission**, because the folder name includes the NIP allocated
by that approval. Under the configured root `Victory-FP-Management` the layout
is now:

```text
Victory-FP-Management/fp/<Nama dari biodata>_<NIP>/ktp.jpg
Victory-FP-Management/fp/<Nama dari biodata>_<NIP>/ijazah.jpg
```

`FpDocumentPath::approvedFolder()` sanitizes `full_name` before appending the
validated numeric NIP: control characters and path separators are removed,
whitespace is collapsed, dots are not allowed as standalone segments, and the
segment is length-bounded. The NIP is the allocation made inside the approval
transaction; it is never accepted from the public form.

`Uploads::stage()` now always stages sanitized documents in private local
storage. The private DB locator keeps its existing UUID path, so deployed
constraints and historical documents stay valid and the pending-verification
viewer keeps working through `DocumentStorageRegistry`. After approval commits,
`PublishApprovedFpDocuments` reads the staged bytes, verifies their SHA-256,
and publishes them with an idempotent HEAD check followed by a conditional PUT
(`If-None-Match: *`). Existing objects are never overwritten, and retries do
not resend MKCOL/PUT for files that are already present. Rejected submissions still delete
only their local staged copies through `DeleteRejectedDocuments`.

Real synthetic verification against the operator Nextcloud returned:

```text
FOLDER=FP39 Approval Smoke 83b4eb_1001
PATH=fp/FP39 Approval Smoke 83b4eb_1001/ktp.jpg
EXISTS_BEFORE=NO
PUT_ONCE=CREATED
EXISTS_AFTER=YES
EXISTS_AFTER_DELETE=NO
```

`Uploads::stage()` was also verified with two generated FP images while the
configured adapter was `nextcloud`; both records stayed `local` until approval:

```text
KTP_ADAPTER=local
IJAZAH_ADAPTER=local
STAGED_LOCAL_HASH_OK
STAGED_OBJECTS_DISCARDED
```

## Retention and retry

The operator decision is to keep both copies: the private local copy remains
the viewer/DB-referenced source, and Nextcloud keeps the readable operational
copy. No local deletion is scheduled and no DB metadata migration is attempted.
This avoids the deployed `F2` immutability trigger and preserves the existing
authorized viewer path.

Publication failures are retryable and observable. The job logs a warning with
submission/membership IDs and exception class only; NIP, NIK, file bytes and
credentials are never logged. An operator can retry immediately with:

```bash
php artisan fp:publish-approved-documents <submission-id-or-uuid>
```

The command calls the same idempotent job, so a partially published folder is
safe to retry. A transient Nextcloud outage during the first end-to-end smoke
was recovered with this retry path; no document was duplicated or overwritten.

## Amendment publication

HRD document amendments now publish the latest revision to the same
`nama_FP_NIP` folder after commit. `PublishAmendedFpDocuments` ignores stale
revisions; `NextcloudStorage::putCurrent()` skips identical bytes and replaces
changed bytes with an ETag guard when available. The immutable local history
and authorized viewer are unchanged.

Deployment queue setup, monitoring and retry commands are documented in
[`nextcloud-ops-runbook.md`](nextcloud-ops-runbook.md).

`NEXTCLOUD_ENABLED` remains false for the F3.9 revision workflow; this change
only affects the existing private document workflow and its approval-time and
amendment-time publication jobs.
