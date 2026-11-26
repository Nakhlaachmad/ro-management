# F3.9 security tests

Status PARTIAL. All new WebDAV tests use synthetic bytes and HTTP fakes that prohibit stray network requests. Fakes do not prove real Nextcloud interoperability.

| Verification | Actual outcome |
|---|---|
| Storage tests, old adapter plus new transport | PASS, 18 tests / 59 assertions |
| Admin status authorization, missing config and sanitized auth failure | PASS, 3 tests / 13 assertions |
| Final focused F3.7H/local HTTP and new Admin status regression | PASS, 71 tests / 392 assertions; 4 PHPUnit deprecations |
| Frontend regression, all six existing suites | PASS, 35 tests |
| Tracked diff whitespace and FP panel provider syntax | PASS; Git emitted existing CRLF normalization warning for vite.config.js |
| Code formatting, five new PHP files | PASS |
| HTTPS preflight | FAIL in client Windows TLS initialization; authenticated checks BLOCKED |
| Disposable PostgreSQL F3.9 operations | NOT RUN, no F3.9 operation schema or configured disposable credentials |
| Browser HTTP acceptance | NOT RUN, local startup preflight remains blocked by existing environment hash difference |

New transport tests verify authenticated PROPFIND, mandatory certificate validation/no redirects, timeouts, opaque paths, conditional PUT, exact readback, replay mismatch, upload timeout uncertainty, no automatic retry/DELETE, sanitized 401/403/404/503/redirect responses, malicious XML rejection, oversize response rejection, and write denial for health-only clients.

The first new status-page test run had one test-harness error from calling an unavailable Livewire introspection method. It was corrected to use Livewire's actual Drawer utility. Both the focused status-page rerun and final 71-test combined run passed. This test harness failure was not an application authentication failure. The combined run covers OptionalOnboardingRevisionTest, PrivateDocumentHttpRegressionTest, PublicOnboardingFieldsTest, PackageFixesTest and F39StorageStatusTest. The full PostgreSQL F37HPostgresTest was not run.

## Requested external acceptance cases

| Case | Status |
|---|---|
| 1 Valid HTTPS | FAIL locally; server validity unverified |
| 2 Invalid credentials | PASS fake status mapping; real NOT RUN |
| 3 Unauthorized DAV | PASS fake status mapping; real NOT RUN |
| 4 Folder creation | PASS fake; real NOT RUN |
| 5 Upload | PASS fake transport only; real NOT RUN |
| 6 Download | PASS fake transport only; real NOT RUN |
| 7 Checksum | PASS fake transport; real NOT RUN |
| 8 Missing object | PASS fake; real NOT RUN |
| 9 Timeout | PASS fake uncertainty handling; real NOT RUN |
| 10 Duplicate upload | PASS fake conditional/replay check; end-to-end NOT RUN |
| 11 Concurrent upload | NOT RUN |
| 12 DB rollback | NOT RUN |
| 13 Ambiguous commit recovery | NOT RUN, ledger pending |
| 14 Historical retrieval | Existing HTTP regression retained; new remote revision path NOT RUN end-to-end |
| 15 IDOR | Existing policy scope preserved; new remote/browser NOT RUN |
| 16 Roles | PASS focused health-action tests; new remote document/browser NOT RUN |
| 17 Password rotation | NOT RUN |
| 18 Outage | PASS fake status mapping; real NOT RUN |
| 19 Recovery | NOT RUN end-to-end |
| 20 Reconciliation | NOT RUN, operation journal pending |
