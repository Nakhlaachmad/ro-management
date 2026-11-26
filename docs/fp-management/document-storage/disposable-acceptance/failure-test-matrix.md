# F3.9 failure matrix

Status PASS for the recorded disposable/fake-transport cases; real DAV BLOCKED. No fake transport result is classified as real Nextcloud acceptance.

| Required case | Actual result |
|---|---|
| Crash before upload | PASS, persisted PENDING/404 inspection, no mutation during dry-run |
| Crash while uploading | PASS, expired upload lease and missing-object recovery to PENDING |
| Upload succeeded, response timeout | PASS, remote bytes retained, one PUT, later verified commit |
| Upload succeeded, metadata commit rejected | PASS, injected PostgreSQL CHECK failure, no canonical row, stage/remote retained, fresh-connection recovery |
| Duplicate idempotency key | PASS, identical request returns same UUID; changed fingerprint conflicts; unique constraint tested |
| Two workers | PASS, real concurrent PHP/PostgreSQL sessions, exactly one PUT/commit; separate row-lock exclusion test |
| Remote missing | PASS with synthetic transport |
| Hash mismatch | PASS, QUARANTINED without overwrite/delete |
| Outage/auth failure | PASS synthetic classification, no PUT while remote status unknown, recovery after outage |
| TLS certificate failure | PARTIAL, synthetic transport failure and existing verify=true transport assertions; actual bad-certificate server NOT RUN |
| Stale operation | PASS, renewed fence and stale-worker rejection |
| Revision sequence conflict | PASS, first commit wins, second object retained under quarantined operation |
| Authorization denied | PASS, BM denied operation access; Admin/HRD/RO/BM F3.7H regression passed |
| Repeated reconciliation | PASS, no duplicate revision/upload |
| Ambiguous COMMIT | PASS fault injection after actual PDO commit, new connection finds canonical evidence and safely resumes local cleanup |
| Corrupt local stage | PASS, confirmed pre-upload failure becomes FAILED with no PUT |
| Audit/FK/atomicity guards | PASS, direct invalid writes rejected by PostgreSQL |

## Actual commands and counts

Secure runtime forwards DPAPI-decrypted values only through child-process environment. No secret appears in command arguments. Commands run from C:/laragon/www/RO_management using PHP 8.4.26. PHP sys_temp_dir was scoped to D:/CodexF39Stage68Scratch/php-temp.

```text
php tests/FpPostgres/F39/accept.php
php tests/FpPostgres/F39/failure.php
php vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap tests/FpPostgres/bootstrap.php --do-not-cache-result tests/FpPostgres/F37HPostgresTest.php
```

Acceptance harness PASS 15 checks in the final rerun. The initial fresh-install run additionally proved migration transaction rollback; its separate evidence is retained. Failure/concurrency harness PASS 48 checks. These are named assertion checks, not PHPUnit test counts. F3.7H PASS 11 PHPUnit tests / 153 assertions.

Supplementary storage fakes PASS 18 tests / 59 assertions. Isolated application HTTP/onboarding/report regression PASS 41 tests / 278 assertions with four PHPUnit deprecation notices. Frontend PASS 32 Node tests. PHP syntax, four new production PHP files' Pint checks and tracked diff whitespace checks passed.

## Retained failures and limits

Initial F3.7H bootstrap failed because the old test harness recognized only C:/ cache prefixes while the scratch directory used D:/. The test-only prefix was made drive-aware; rerun passed. An early concurrency harness warning polluted worker stdout and was removed. Another harness iteration used the same revision stream for two independent scenarios, correctly producing a revision conflict; separate document types isolated those scenarios. Assertions were not weakened.

Crash cases simulate restart points and lost acknowledgements. They do not kill a production PostgreSQL server or prove real Nextcloud crash behavior. Real DAV, password rotation, remote browser acceptance and certificate-chain integration remain BLOCKED/NOT RUN.
