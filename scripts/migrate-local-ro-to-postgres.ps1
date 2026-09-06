[CmdletBinding()]
param(
    [string] $PostgresHost = '127.0.0.1',
    [int] $PostgresPort = 5432,
    [string] $PostgresDatabase = 'ro_local',
    [string] $PostgresUsername = 'postgres'
)

$ErrorActionPreference = 'Stop'

$projectPath = Split-Path -Parent $PSScriptRoot
$envPath = Join-Path $projectPath '.env'

if (-not (Test-Path -LiteralPath $envPath)) {
    throw 'File .env RO tidak ditemukan. Script dihentikan tanpa perubahan.'
}

function ConvertFrom-DotEnvValue {
    param([string] $Value)

    $trimmed = $Value.Trim()

    if ($trimmed.Length -ge 2 -and (
        ($trimmed.StartsWith('"') -and $trimmed.EndsWith('"')) -or
        ($trimmed.StartsWith("'") -and $trimmed.EndsWith("'"))
    )) {
        $trimmed = $trimmed.Substring(1, $trimmed.Length - 2)
    }

    return $trimmed
}

function ConvertTo-DotEnvValue {
    param([string] $Value)

    if ($Value -match '^[A-Za-z0-9._:/@+,-]*$') {
        return $Value
    }

    $escaped = $Value.Replace('\', '\\').Replace('"', '\"').Replace('$', '\$')

    return '"' + $escaped + '"'
}

function Get-DotEnvValues {
    param([string] $Path)

    $values = @{}

    foreach ($line in [System.IO.File]::ReadAllLines($Path)) {
        if ($line -match '^\s*([A-Za-z_][A-Za-z0-9_]*)=(.*)$') {
            $values[$matches[1]] = ConvertFrom-DotEnvValue $matches[2]
        }
    }

    return $values
}

function Set-DotEnvValues {
    param(
        [string] $Path,
        [hashtable] $Values
    )

    $seen = @{}
    $updated = foreach ($line in [System.IO.File]::ReadAllLines($Path)) {
        if ($line -match '^\s*([A-Za-z_][A-Za-z0-9_]*)=') {
            $key = $matches[1]

            if ($Values.ContainsKey($key)) {
                $seen[$key] = $true
                "$key=$(ConvertTo-DotEnvValue ([string] $Values[$key]))"
                continue
            }
        }

        $line
    }

    foreach ($key in $Values.Keys | Sort-Object) {
        if (-not $seen.ContainsKey($key)) {
            $updated += "$key=$(ConvertTo-DotEnvValue ([string] $Values[$key]))"
        }
    }

    [System.IO.File]::WriteAllLines(
        $Path,
        [string[]] $updated,
        [System.Text.UTF8Encoding]::new($false)
    )
}

function Invoke-Artisan {
    param([string[]] $Arguments)

    & php artisan @Arguments

    if ($LASTEXITCODE -ne 0) {
        throw "Perintah Artisan gagal: php artisan $($Arguments -join ' ')"
    }
}

$current = Get-DotEnvValues $envPath
$requiredMySqlKeys = @('DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME')

foreach ($key in $requiredMySqlKeys) {
    if ([string]::IsNullOrWhiteSpace([string] $current[$key])) {
        throw "Nilai $key pada .env lama tidak tersedia. Script dihentikan tanpa perubahan."
    }
}

$securePassword = Read-Host "Masukkan password PostgreSQL untuk $PostgresUsername (tidak akan ditampilkan)" -AsSecureString
$postgresPassword = [System.Net.NetworkCredential]::new('', $securePassword).Password

if ([string]::IsNullOrEmpty($postgresPassword)) {
    throw 'Password PostgreSQL tidak boleh kosong. Tidak ada perubahan yang dilakukan.'
}

$backupPath = "$envPath.before-postgres-$(Get-Date -Format 'yyyyMMdd-HHmmss')"
[System.IO.File]::Copy($envPath, $backupPath, $false)
$environmentWasUpdated = $false

$env:DB_CONNECTION = 'pgsql'
$env:DB_HOST = $PostgresHost
$env:DB_PORT = [string] $PostgresPort
$env:DB_DATABASE = $PostgresDatabase
$env:DB_USERNAME = $PostgresUsername
$env:DB_PASSWORD = $postgresPassword
$env:LEGACY_MYSQL_HOST = [string] $current['DB_HOST']
$env:LEGACY_MYSQL_PORT = [string] $current['DB_PORT']
$env:LEGACY_MYSQL_DATABASE = [string] $current['DB_DATABASE']
$env:LEGACY_MYSQL_USERNAME = [string] $current['DB_USERNAME']
$env:LEGACY_MYSQL_PASSWORD = [string] $current['DB_PASSWORD']

try {
    Push-Location $projectPath

    Invoke-Artisan @('config:clear')
    Invoke-Artisan @('migrate', '--force')
    Invoke-Artisan @('ro:migrate-mariadb-to-postgres', '--source=legacy_mysql', '--target=pgsql')

    Set-DotEnvValues -Path $envPath -Values @{
        DB_CONNECTION = 'pgsql'
        DB_HOST = $PostgresHost
        DB_PORT = [string] $PostgresPort
        DB_DATABASE = $PostgresDatabase
        DB_USERNAME = $PostgresUsername
        DB_PASSWORD = $postgresPassword
        LEGACY_MYSQL_HOST = [string] $current['DB_HOST']
        LEGACY_MYSQL_PORT = [string] $current['DB_PORT']
        LEGACY_MYSQL_DATABASE = [string] $current['DB_DATABASE']
        LEGACY_MYSQL_USERNAME = [string] $current['DB_USERNAME']
        LEGACY_MYSQL_PASSWORD = [string] $current['DB_PASSWORD']
    }
    $environmentWasUpdated = $true

    Invoke-Artisan @('config:clear')
    Invoke-Artisan @('migrate:status')

    Write-Host "`nMigrasi selesai. File .env lama tersimpan di: $backupPath"
    Write-Host 'Tutup lalu jalankan ulang server RO pada port 8002 sebelum menguji aplikasi.'
}
catch {
    if ($environmentWasUpdated) {
        [System.IO.File]::Copy($backupPath, $envPath, $true)
    }

    Write-Error "Migrasi tidak selesai. File .env dipulihkan ke MariaDB; backup tersedia di: $backupPath"
    throw
}
finally {
    Pop-Location -ErrorAction SilentlyContinue
    Remove-Item Env:DB_CONNECTION -ErrorAction SilentlyContinue
    Remove-Item Env:DB_HOST -ErrorAction SilentlyContinue
    Remove-Item Env:DB_PORT -ErrorAction SilentlyContinue
    Remove-Item Env:DB_DATABASE -ErrorAction SilentlyContinue
    Remove-Item Env:DB_USERNAME -ErrorAction SilentlyContinue
    Remove-Item Env:DB_PASSWORD -ErrorAction SilentlyContinue
    Remove-Item Env:LEGACY_MYSQL_HOST -ErrorAction SilentlyContinue
    Remove-Item Env:LEGACY_MYSQL_PORT -ErrorAction SilentlyContinue
    Remove-Item Env:LEGACY_MYSQL_DATABASE -ErrorAction SilentlyContinue
    Remove-Item Env:LEGACY_MYSQL_USERNAME -ErrorAction SilentlyContinue
    Remove-Item Env:LEGACY_MYSQL_PASSWORD -ErrorAction SilentlyContinue
}
