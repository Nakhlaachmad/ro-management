param(
    [string]$RoPath = 'C:\laragon\www\RO_management',
    [string]$IwacsPath = 'C:\laragon\www\viffx_office_portal',
    [string]$KpiPath = 'C:\laragon\www\vif-kpi'
)

$ErrorActionPreference = 'Stop'

function Get-EnvValue([string]$Path, [string]$Name) {
    $match = Get-Content -LiteralPath $Path | Where-Object { $_ -match ("^{0}=" -f [regex]::Escape($Name)) } | Select-Object -Last 1
    if ($null -eq $match) { return '' }
    return ($match -replace ("^{0}=" -f [regex]::Escape($Name)), '')
}

function Set-EnvValue([string]$Path, [string]$Name, [string]$Value) {
    $lines = [System.Collections.Generic.List[string]](Get-Content -LiteralPath $Path)
    $prefix = "$Name="
    $found = $false
    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i].StartsWith($prefix, [System.StringComparison]::Ordinal)) {
            $lines[$i] = "$prefix$Value"
            $found = $true
        }
    }
    if (-not $found) { $lines.Add("$prefix$Value") }
    [System.IO.File]::WriteAllLines($Path, $lines, [System.Text.UTF8Encoding]::new($false))
}

$roEnv = Join-Path $RoPath '.env'
$iwacsEnv = Join-Path $IwacsPath '.env'
$kpiEnv = Join-Path $KpiPath '.env'
foreach ($path in @($roEnv, $iwacsEnv, $kpiEnv)) {
    if (-not (Test-Path -LiteralPath $path)) { throw "File konfigurasi tidak ditemukan: $path" }
}

# The existing KPI service token has registrations.write and is used only to
# prove the local RO flow. Production must use a separate ro-service secret.
$kpiClientId = Get-EnvValue $kpiEnv 'IWACS_REGISTRATION_CLIENT_ID'
$kpiClientSecret = Get-EnvValue $kpiEnv 'IWACS_REGISTRATION_CLIENT_SECRET'
$discovery = Get-EnvValue $kpiEnv 'IWACS_REGISTRATION_DISCOVERY_URL'
$outboundClientId = Get-EnvValue $iwacsEnv 'KEYCLOAK_ADMIN_CLIENT_ID'
$outboundClientSecret = Get-EnvValue $iwacsEnv 'KEYCLOAK_ADMIN_CLIENT_SECRET'
$portalUrl = Get-EnvValue $iwacsEnv 'APP_URL'
$ssoIssuer = Get-EnvValue $iwacsEnv 'SSO_ISSUER'
if ([string]::IsNullOrWhiteSpace($ssoIssuer)) { $ssoIssuer = $portalUrl }
if ([string]::IsNullOrWhiteSpace($kpiClientId) -or [string]::IsNullOrWhiteSpace($kpiClientSecret) -or [string]::IsNullOrWhiteSpace($discovery) -or [string]::IsNullOrWhiteSpace($outboundClientId) -or [string]::IsNullOrWhiteSpace($outboundClientSecret)) {
    throw 'Credential layanan lokal belum lengkap. Tidak ada konfigurasi yang diubah.'
}

Set-EnvValue $roEnv 'IWACS_RO_SYNC_ENABLED' 'true'
Set-EnvValue $roEnv 'IWACS_RO_SYNC_API_URL' 'http://127.0.0.1:8000/api/v1/integrations/ro/users'
Set-EnvValue $roEnv 'IWACS_RO_LOOKUP_API_URL' 'http://127.0.0.1:8000/api/v1/integrations/ro/users/lookup'
Set-EnvValue $roEnv 'IWACS_RO_SYNC_DISCOVERY_URL' $discovery
Set-EnvValue $roEnv 'IWACS_RO_SYNC_CLIENT_ID' $kpiClientId
Set-EnvValue $roEnv 'IWACS_RO_SYNC_CLIENT_SECRET' $kpiClientSecret

$iwacsPublicKey = Join-Path $IwacsPath 'storage\app\keys\iwacs-public.pem'
$roKeyDirectory = Join-Path $RoPath 'storage\app\keys'
$roPublicKey = Join-Path $roKeyDirectory 'iwacs-public.pem'
if (-not (Test-Path -LiteralPath $iwacsPublicKey)) { throw 'Public key IWACS tidak ditemukan. Tidak ada konfigurasi SSO RO yang diubah.' }
New-Item -ItemType Directory -Force -Path $roKeyDirectory | Out-Null
Copy-Item -LiteralPath $iwacsPublicKey -Destination $roPublicKey -Force
Set-EnvValue $roEnv 'PORTAL_APP_URL' $portalUrl
Set-EnvValue $roEnv 'SSO_EXPECTED_ISSUER' $ssoIssuer
Set-EnvValue $roEnv 'SSO_EXPECTED_AUDIENCE' 'ro'
Set-EnvValue $roEnv 'SSO_PUBLIC_KEY_PATH' 'storage/app/keys/iwacs-public.pem'
Set-EnvValue $roEnv 'SSO_TOKEN_TTL' '120'
Set-EnvValue $roEnv 'SSO_LEEWAY_SECONDS' '5'
Set-EnvValue $roEnv 'SSO_REPLAY_TTL' '120'

Set-EnvValue $iwacsEnv 'API_RO_SYNC_DISPATCH_ENABLED' 'true'
Set-EnvValue $iwacsEnv 'PUBLIC_REGISTRATION_ENABLED' 'true'
Set-EnvValue $iwacsEnv 'RO_SYNC_API_URL' 'http://127.0.0.1:8002/api/v1/integrations/iwacs'
Set-EnvValue $iwacsEnv 'RO_SYNC_API_AUDIENCE' 'ro-service'
Set-EnvValue $iwacsEnv 'RO_SYNC_CLIENT_ID' $outboundClientId
Set-EnvValue $iwacsEnv 'RO_SYNC_CLIENT_SECRET' $outboundClientSecret

Push-Location $RoPath
try { php artisan optimize:clear | Out-Null } finally { Pop-Location }
Push-Location $IwacsPath
try { php artisan optimize:clear | Out-Null } finally { Pop-Location }

Write-Output 'Konfigurasi sinkronisasi RO lokal aktif. Tidak ada secret yang ditampilkan.'
