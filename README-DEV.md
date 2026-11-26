# Victory HRD F3.6 — local development

Open `C:\laragon\www\RO_management` in VS Code. In its PowerShell terminal:

```powershell
.\start-dev.ps1
```

Open http://localhost:8000/fp-dev/login. Ctrl+C stops this Artisan invocation and its owned PHP server. Run the same command again to restart. Windows PowerShell5.1 and PowerShell7 are supported.

PHP: `C:\laragon\bin\php\php-8.4.26-Win32-vs17-x64\php.exe`. Database: existing PostgreSQL18.6 at127.0.0.1:55432, fp_management_synthetic_dev, cluster7694154787604477244. PostgreSQL stays at E:\FPManagementDev\postgres. Startup does not start/repair PostgreSQL or touch the Odoo service. If PostgreSQL is stopped, the script stops for explicit recovery.

The actual command is original `php artisan serve --host=localhost --port=8000 --tries=1 --no-reload`. Port8000 must be free; there is no alternate-port or retry fallback. `--no-reload` preserves the child environment. The process-only PHP INI overlay enables GD and EGPCS; Laragon php.ini remains unchanged.

Runtime: `C:\laragon\var\fp-management-dev`, outside the web root. Existing APP_KEY/HMAC are copied as byte-identical CurrentUser DPAPI ciphertext; only the existing Windows operator can decrypt them. Database credentials remain encrypted at E:\FPManagementDev\postgres\secrets. No secrets are written to plaintext files or command arguments. The application process only receives fp_dev_app; the migration credential is limited to the read-only preflight process.

`.env` and Laravel source are unchanged. Runtime configuration is selected via the child process APP_CONFIG_CACHE and assembled in memory, skipping .env and retaining normal package defaults. It restores the existing local F3.6 flags/providers; no config:clear/config:cache or cache deletion runs. Sessions/cache/views/packages use the private runtime storage. Queue sync, mail array, logging null. Private documents are the15 database-referenced local images copied with matching hashes; orphan files are excluded. No original source, storage, cache or encrypted key file is deleted.

Before startup, source/environment hashes, database identity, accepted counts/fingerprints and private-document hashes are checked read-only. A discrepancy causes STOP; do not reset data, regenerate keys/baseline, run migrations/fixtures or apply grants to bypass it. Intentional future domain-data changes need a separately reviewed baseline decision. User authentication/permissions remain existing. No admin fixture or password reset is performed.

For preflight without serving: `.\start-dev.ps1 -Check`. Close a prior development invocation with Ctrl+C before starting another. PostgreSQL is not stopped with the application. Production, SSO/JWT/IWACS and Nextcloud settings are not modified.
