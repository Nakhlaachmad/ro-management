# U0 / U1: frozen existing platform and integration behavior

Assessment and implementation date: 2026-10-08. Scope: only U0/U1.

## Baseline identity

- Repository HEAD: `6b2da1c2507a57e23a548cf94841b272c4d01b65`.
- Original ZIP SHA-256: `7bc86e299b4cf90dad0df213a7f630854b12558a9431a23ff6dd82060e735789`.
- Existing untracked reference documents (`agents.md`, `architecture.md`, `prd.md`, `system_design.md`) preceded this implementation and were not edited.
- Twenty source/platform files are frozen by SHA-256 in `tests/Fixtures/u0-source-hashes.json`. This covers protected source, whole protected config/routes files, the historical IWACS migration, Composer/npm manifests/locks, Dockerfile and Dokploy compose.
- All installed production/development Composer package versions match the unchanged lockfile, as checked by `tests/Support/verify-baseline.php`.
- No production application file, historical migration, dependency manifest/lock, Dockerfile, runtime or production configuration was changed.

| Component | Frozen version/configuration |
| --- | --- |
| Laravel | 10.50.0 |
| Filament packages | 3.3.48 |
| Livewire | 3.7.10 |
| Sanctum | 3.3.3 |
| Spatie Permission | 6.24.1 |
| Firebase JWT | 7.1.0 |
| Guzzle | 7.10.0 |
| Carbon | 2.73.0 |
| PHPUnit | 10.5.63 |
| Vite / Laravel Vite plugin | 5.4.21 / 1.3.0 |
| PHP Docker vendor/runtime | 8.3 CLI / 8.3 Apache bookworm |
| Node Docker asset stage | 20 bookworm-slim |
| Local verification runtime | PHP 8.3.30; Composer 2.10.2 |
| Already installed host Node | 24.18.0; not modified, and not evidence of a Docker Node upgrade |
| Storage/deployment | `ro_storage` volume; external `dokploy-network`; port 80; `/up` healthcheck |

Docker image tags are floating. No Docker executable/deployed image inventory was available during this implementation. Production image digests, PostgreSQL version, deployed schema and effective environment are **not verified**. Do not claim a byte-identical production image rebuild. Source/lock/test baseline is reproducible; deployment reproducibility needs a separately reviewed sanitized inventory. No real database/service was contacted to fill those gaps.

## Migration-derived schema baseline (not production introspection)

Eight existing migration files define users, password reset tokens, failed jobs, personal access tokens, Spatie permission tables, teams, fps and IWACS user fields. Tests execute these unchanged migrations only inside verified in-memory SQLite.

- `users`: name/email/password plus nullable unique `sso_subject`, integer `profile_version` default 1, boolean `is_active` default true; integration columns remain intact.
- PAT: unique hashed token, polymorphic tokenable reference, abilities, last-used and expires timestamps.
- Permissions/roles: existing Spatie tables and pivots with web guard behavior.
- `teams`: legacy `name` and `team` strings.
- `fps`: legacy biodata, grade A/B, Jalur RO/MGM, entry/exit dates, aktif/nonaktif; Team deletion cascades FP, creator deletion sets null.
- No new domain schema, NIP, Nextcloud, OCR, FP approval/rejoin workflow or status migration was introduced.

## Reproduce safely

Use the existing PHP 8.3 runtime, with `pdo_sqlite`, OpenSSL and the extensions required by the lockfile. Install dependencies only from unchanged locks if missing. No dependency update is part of U0/U1.

```sh
composer install --prefer-dist --no-interaction --no-scripts
php tests/Support/verify-baseline.php
php vendor/phpunit/phpunit/phpunit --filter IsolationTest --do-not-cache-result
php vendor/phpunit/phpunit/phpunit --do-not-cache-result
php vendor/bin/pint --test tests/Feature tests/Support tests/Fixtures/generate.php tests/bootstrap.php tests/CreatesApplication.php tests/Unit/BaselineIntegrityTest.php
git diff --check
```

The install command is documentation/CI only; no install/update was needed during this implementation. Do not bootstrap production with `php artisan test` as a substitute for the isolated entry point. Do not run migration commands against a development or production environment.

Committed RSA fixture keys were generated locally solely for this suite. They are intentionally public test material, not application credentials. Never deploy or trust these keys. `tests/Fixtures/generate.php` can regenerate the matching fixtures using its test-only OpenSSL config; do not regenerate them as an automatic test step.

## Isolation evidence and guarantees

1. PHPUnit uses `tests/bootstrap.php`, which forcibly overrides inherited DB/cache/session/mail/log settings before Laravel configuration loading.
2. The app's environment directory is redirected to a temporary test directory; repository `.env` and `.env.testing` are not read.
3. Config/route/package/service/view cache paths are directed to test-only temporary paths. Cached config/routes are refused. Windows absolute paths are handled in the test harness only.
4. Before provider boot and before `RefreshDatabase` runs, only one connection is registered: SQLite `:memory:` with foreign keys enabled. `PRAGMA database_list` must show exactly an empty-file `main` database.
5. A query guard refuses persistent database attachment, `VACUUM INTO`, or changes to the database configuration. Normal mysql/pgsql connections are absent.
6. Laravel HTTP client rejects unfaked requests. Integration URLs use synthetic `.test` hosts and service responses are faked. No real IWACS, Keycloak, JWKS/discovery or global logout call is required.
7. Cache/session are array-only; mail is array; logs use null channel; credentials/app key are synthetic. Production replay cache/session data are not flushed or touched.
8. `IsolationTest` verifies the guard independently before any database-writing test in CI. A hostile inherited-environment smoke run is also included in implementation verification.

Tests are trusted code: this harness blocks accidental production configuration/network access through the mechanisms used by existing integration code. It is not an OS sandbox for malicious tests that instantiate their own PDO/Guzzle clients or deliberately remove safeguards.

CI now tests the existing PHP 8.3 series, installs locked dependencies without app scripts, omits repository environment/key generation, verifies U0 integrity, proves isolation, then runs the suite. CI did not execute remotely during this local implementation.

## Characterization coverage

| Suite | Contracts characterized |
| --- | --- |
| IsolationTest | In-memory proof; mysql denial; attachment denial; stray HTTP denial |
| JwtCharacterizationTest | Valid RS256; signatures/malformed/algorithm rejection; required claims/types; issuer/audience; exp/nbf/lifetime; leeway restoration/clamp; JTI hash/TTL/replay; four legacy roles; inactive/mismatch/global-admin rules; session rotation |
| KeycloakCharacterizationTest | JWKS selection/cache/rotation; discovery frequency; malformed/failing endpoints and synthetic transport failures; issuer/audience/time/signature/header; scope sources; API 401/403 headers and controller reachability |
| UserSyncCharacterizationTest | Create/link/update/baseline; password behavior; normalization; all four roles; inactive transition; version duplicate/stale/conflict; validation; exact success/error bodies; uppercase role failure |
| IwacsOutboundCharacterizationTest + existing IwacsRoClientTest | Service client credentials; method/URL/headers; payload/version/source_email; encoded subject; Idempotency-Key; conflict retry ceiling/new key; password retry; permanent delete; lookup/logout ticket; disabled/missing identity failures |
| LogoutCharacterizationTest + existing RoLogoutResponseTest | Global ticket success/failure; local-only unlinked user; session/token invalidation; built-in Filament logout; menu POST rendering; guest HTML failure; redirect response |
| AuthAndRoutesCharacterizationTest + existing PanelAccessTest | Exact route methods/paths/names/middleware/subject constraint; web/local Filament authentication; Sanctum session/PAT; inactive differences; role/resource/Gate/permission rules |
| LegacyFpCharacterizationTest | Weekend create/update validation; exit-date overwrite; reactivation; nonaktif create; real dashboard stats with synthetic records; calendar-month/reverse-date behavior; scope SQL/SQLite limitation; Team cascade |
| BaselineIntegrityTest | Frozen source/platform byte hashes |

## Existing behavior / bugs: preserved, not fixed

| ID | Observed behavior | Classification |
| --- | --- | --- |
| C01 | API JWT is accepted without `exp` or `sub` when signature/issuer/audience are valid | Security review needed; validator does not mandate these claims |
| C02 | API scopes include roles from every `resource_access` client, even an unrelated client | Security authorization review needed |
| C03 | Guest HTML POST global logout fails with `Route [login] not defined`; JSON authentication paths behave differently | Existing routing/auth redirect bug |
| C04 | Inactive users can authenticate through generic web guard and valid Sanctum PAT; Filament and SSO deny panel/login | Existing boundary inconsistency; authorization review |
| C05 | Roles like uppercase `RO` pass case-insensitive normalization validation but are not lowercased before Spatie lookup; create returns 500 and transaction rolls back | Existing validation bug |
| C06 | Seeded permissions use spaces (`create fp`), whereas FP gates use dots (`fp.create`); no equivalent grants are defined by the existing seeder | Existing authorization mismatch; no grants repaired |
| C07 | Built-in Filament `/logout` invalidates only local session and redirects to portal login without issuing a global ticket | Existing behavior; global-session guarantees need review |
| C08 | Menu renders a POST form to `/sso/global-logout`, correctly; it is not a GET link despite MenuItem using `url()` | Corrected assessment inference; actual vendor rendering characterized |
| C09 | JTI is consumed before identity/role lookup, including unsuccessful login; TTL uses configured replay TTL | Existing semantics |
| C10 | Discovery fetched for every validation; JWKS cached; unknown kid causes one forced refresh | Existing cache/discovery semantics |
| C11 | Duplicate sync uses profile version, not event ID/payload; explicit baseline can decrease version and replace password | Existing synchronization semantics |
| C12 | Malformed three-part non-JSON SSO token maps to `jwt_configuration_error`; wrong segment count/algorithm maps to `invalid_token` | Existing exception mapping |
| C13 | Whitespace name becomes null in HTTP middleware; creation can report missing fields instead of `invalid_name` | Existing middleware/validation interaction |
| C14 | Status→nonaktif overwrites requested exit date with today; nonaktif creation does not set it; reactivation retains old exit date | Existing FP model-event behavior |
| C15 | Dashboard counts all non-null exit dates, including active FP, and uses Carbon 2 absolute full months; a 59-day interval can be normal | Existing legacy behavior; no 60-day rule introduced |
| C16 | Legacy SQL scopes use calendar two-month PostgreSQL/MySQL expressions; SQLite branch uses unsupported DATE_ADD syntax | Existing portability limitation; SQL shape/SQLite failure characterized |
| C17 | Team deletion cascades FP history | Existing data-loss risk; unchanged |

Legacy MGM remains unresolved. Fixtures preserve MGM without mapping it. The helper explicitly creates synthetic role fixtures (including bm); this does not establish which roles exist in any deployed DB.

## Remaining untested behavior and gates before U2/U3

- No real IWACS/Keycloak/SSO credentials/endpoints used; no production/staging end-to-end result claimed.
- Real browser navigation, SameSite/Secure cookies, CSRF enforcement, HTTPS reverse proxy and Dokploy persistence are not verified by PHP/Livewire tests. Laravel test mode skips standard CSRF checks; menu markup and route methods are tested.
- Concurrent PostgreSQL/MySQL row locks and simultaneous first-user creation are not established by SQLite. Real Redis/shared-cache atomic JTI consumption, TTL and cross-deployment continuity remain untested.
- Driver-specific exit scope execution and exact PostgreSQL/MySQL calendar arithmetic are untested. SQLite tests freeze generated SQL and the current unsupported SQLite behavior rather than adding a production compatibility fix.
- Dashboard all-FP stats are executed; every custom widget/filter/table/page/action and complete UserResource create/edit compensation sequence are not exhaustively exercised.
- Timeout/retry timing, CA/TLS, DNS, external idempotency guarantees and remote session revocation are not proven by HTTP fakes.
- No Docker rebuild, image digest verification, frontend build or remote CI run occurred in this U0/U1 implementation. Historical audit results are not substituted for current deployment evidence.

Before any dependency change: review C01–C07, decide a separate approved remediation scope where needed, and provision separately authorized disposable PostgreSQL/Redis/browser staging verification. Never weaken characterization expectations to pass an upgrade. Hash equality for platform manifests intentionally prevents upgrades during U0/U1; any later approved dependency baseline change must explicitly review that integrity fixture, while protected integration source remains frozen.

U0/U1 deliverables are ready for review. U2 and every framework/runtime/dependency upgrade remain unapproved and unstarted.
