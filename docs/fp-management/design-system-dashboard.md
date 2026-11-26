# F3.7A — Design System & Dashboard

Status: **PASS untuk scope F3.7A**. Browser utama memakai akun HRD existing;
acceptance operasional Admin/RO/BM utama tetap NOT RUN di F3.8, bukan PASS.

## Roadmap yang berlaku

- F3.6 CONDITIONALLY ACCEPTED; bukti/failure/NOT RUN tidak diubah.
- F3.7: Premium Corporate × Modern SaaS UI/UX; tahap ini hanya design system,
  dashboard, sidebar, topbar.
- F3.8: acceptance operasional F3.6 yang tertunda.
- Nextcloud: technical PARTIAL, fase lanjutan belum ditetapkan. Adapter existing
  dipertahankan; tidak ada aktivasi/config/SQL/development Nextcloud pada F3.7A.

`docs/fp-management/ROADMAP.md` dikoreksi. Laporan Nextcloud lama dipertahankan
sebagai evidence pada `Nextcloud-PARTIAL.md`, dengan keterangan salah penempatan
fase yang telah dikoreksi. `nextcloud-report-correction.md` menjadi pointer ke roadmap
yang berlaku; original workspace evidence Nextcloud tidak dihapus.

## Files created/modified

Semua path di bawah relatif terhadap `C:/laragon/www/RO_management`:

| File | Perubahan |
|---|---|
| app/Providers/Filament/FpDevelopmentPanelProvider.php | Native darkMode, dark logo, presentational render hooks; navigation/authorization/middleware/resources unchanged |
| public/fp/victory-premium.css | Reusable tokens dan scoped panel styling; loaded after existing victory.css |
| public/images/logolight.png | Logo operator untuk latar terang panel FP |
| public/images/logodark.png | Logo operator untuk latar gelap panel FP |
| public/images/logo-black.png | Logo operator untuk header onboarding publik |
| resources/views/components/fp/ui/stat-card.blade.php | Reusable KPI card dengan accessible link dan escaped content |
| resources/views/components/fp/ui/workspace.blade.php | Topbar workspace identity |
| resources/views/components/fp/ui/theme-toggle.blade.php | Native Filament theme-changed event, accessible label/pressed state; UI preference only |
| resources/views/filament/fp/theme-head.blade.php | Explicit stylesheet tags, avoiding string-built HTML |
| resources/views/filament/fp/dashboard.blade.php | Bento layout dan information hierarchy; existing summary/query and action permission checks retained |
| resources/views/filament/fp/submission-updates.blade.php | Compact responsive notification presentation; wire:poll.15s, row links/data unchanged |
| tests/Feature/DesignSystemTest.php | Component escaping/accessibility, native theme/logo configuration, existing web guard |
| docs/fp-management/ROADMAP.md | Corrected phase assignments |
| docs/fp-management/Nextcloud-PARTIAL.md | Preserved technical evidence, phase pending |
| docs/fp-management/nextcloud-report-correction.md | Correction pointer, replacing misleading current phase label |
| docs/fp-management/design-system-dashboard.md | This report |

Original source backups: `work/f37a/before` on the preserved Codex workspace.
Existing `victory.css`, business services, models, policies, role mapping,
authentication/SSO/JWT/IWACS, SQL and migrations were not modified.

## Design system

- Light: forest green/ivory/white, blue/gold restrained accents, black official logo.
- Dark: deep forest surfaces, readable ivory text, muted border, inverted official logo.
- Shared tokens: surface/ink/muted/border/focus, 16px card radius, 10–12px controls,
  restrained shadow, clear spacing and typography hierarchy. Existing Inter/Plus
  Jakarta Sans when available; system fallback, no new remote fonts/dependencies.
- Native button/input/table/modal controls remain in place; shared palette/radius
  styles do not remove actions or change events. No full page layout redesign.
- Header has workspace identity, existing submission notification, explicit theme
  toggle and existing user menu. Mobile hides duplicate workspace label, retains
  hamburger/notification/theme/user controls.
- Sidebar active state is clear; official logo follows theme; existing ten links
  (seven primary plus operational) retain authorization and destination.
- Dashboard retains all six counts and unresolved legacy explanation, Team action
  permission check, directory links, public form and authorized report shortcut.
  New hero link is read-only access to the existing pending queue.

## Tests actually run

| Mechanism | Result | Scope |
|---|---|---|
| Offline | 24 tests / 211 assertions PASS | 3 new UI component/panel tests plus existing directory, role, report, annual NIP/Email regression; isolated test runtime |
| PostgreSQL read-only | 4 tests / 19 assertions PASS | Statistics versus directory counts, ACTIVE/exit/unresolved filters; actual fp_dev_app, read-only transactions |
| Real Chrome/Playwright | 24 checks PASS | Login/dashboard, loaded CSS/grid/icon sizing, 1440/834/390 responsiveness, sidebar open/close, dark theme/logo desktop, 10 navigation endpoints HTTP 200, User form/role controls without save, theme toggle, 44px touch target, keyboard focus |
| Contrast, real computed styles | 8 checks PASS | Heading/body/primary/secondary button, light and dark; stable-state ratios 5.42–13.95; basic check, not full WCAG certification |
| Integrity | PASS | 22 historical-table fingerprints, current catalog/counts, ACL, 14 protected files, .env, 2 DPAPI files, 15 private documents unchanged |
| Lint/format/diff | PASS | PHP lint, Pint only affected PHP files, git diff --check |

Total PHPUnit: **28 tests / 230 assertions PASS** across separate offline and
read-only PostgreSQL suites. No create/edit/approval/rejection/exit action was
persisted during browser testing. Existing main server remained available.

Browser role scope is HRD existing, not four-role operational acceptance. Existing
four-role offline policy regression passed, and provider navigation source was
compared unchanged. Admin/RO/BM browser acceptance on main remains F3.8.

## Browser evidence and limitations

Evidence root:
`C:/Users/HP/Documents/Codex/2026-10-07/saya-ingin-melanjutkan-pengembangan-existing-project/outputs/F3.7A`.

- `before-desktop-light.png`, `before-mobile-light.png`: original UI.
- `after-desktop-light.png`, `after-desktop-dark.png`: final desktop.
- `after-tablet-light.png`, `after-tablet-dark.png`: final tablet.
- `after-mobile-light.png`, `after-mobile-dark.png`: final mobile, sidebar closed
  after its transform animation completes.
- `after-tablet-navigation.png`, `after-mobile-navigation.png`: sidebar open.
- `after-user-form-light.png`: existing form with sensitive controls masked.
- `after-browser.json`: 24 actual browser checks.
- `contrast-and-final-screenshots.json`: measured stable-state contrast.
- `integrity-database.json`, `integrity-private.json`: separate UI-phase evidence;
  accepted previous activation evidence not replaced.

Screenshots mask account identity and form/table sensitive fields. No password,
NIK, bank account, token or private document content is included.

Initial rendering failed because a newly edited HTML string produced an incomplete
CSS tag; fixed via dedicated Blade head view. That attempt's screenshots remain
in `failed-attempt1`, not PASS. A subsequent tablet helper tried to click a button
under the sidebar overlay; changed to the supported overlay click and state check.
Its screenshots remain in `attempt2`. Stable state now waits for sidebar animation.

The first contrast measurement immediately during the 150ms theme background
transition measured 2.756 for a button and is retained in `contrast-attempt1.json`.
Stable-state measurements after 250ms pass; no contrast failure was silently
relabeled. Reduced-motion CSS disables transitions. This is basic accessibility
coverage, not complete screen-reader/WCAG acceptance.

## Integrity and boundaries

Main database remains 19 ledger / 24 tables / 18 functions / 26 triggers; users 4,
managed 2, User audit 4, submissions 6, historical NIP counter 1005. No migration,
SQL/grant, .env change, account/role/data mutation or protected integration change.
Logo hashes match originals exactly, aspect ratio unchanged:
- black: d044c733d11962ee21ee63fee8ed8b378bd12e863556d35f56d9d849112d3d3c
- invert: 44901defe3c6327c346a9cd4f5d51c9d1d7144e88867886b0391da6d8595bdc6

No deployment, git stage/commit, Nextcloud activation or further page redesign.
F3.7A is ready for operator design review; later UI work remains outside this run.
