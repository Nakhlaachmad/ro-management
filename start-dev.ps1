param([switch]$Check)
$ErrorActionPreference='Stop'
$devRepo=$PSScriptRoot
$devRuntime='C:\laragon\var\fp-management-dev'
$devPhp='C:\laragon\bin\php\php-8.4.26-Win32-vs17-x64\php.exe'
if([IO.Path]::GetFullPath($devRepo).TrimEnd('\') -ne 'C:\laragon\www\RO_management'){throw 'STOP unexpected project location'}
if(!(Test-Path -LiteralPath "$devRuntime\baseline.json")){throw 'STOP runtime baseline missing'}
$devBaseline=Get-Content -LiteralPath "$devRuntime\baseline.json" -Raw|ConvertFrom-Json
foreach($devEntry in $devBaseline.protected.PSObject.Properties){
 if((Get-FileHash -LiteralPath (Join-Path $devRepo $devEntry.Name)).Hash.ToLowerInvariant() -ne $devEntry.Value){throw 'STOP protected source hash'}
}
foreach($devEntry in $devBaseline.environment.PSObject.Properties){
 if((Get-FileHash -LiteralPath (Join-Path $devRepo $devEntry.Name)).Hash.ToLowerInvariant() -ne $devEntry.Value){throw 'STOP environment/dependency hash'}
}
foreach($devEntry in $devBaseline.runtime.PSObject.Properties){
 if((Get-FileHash -LiteralPath (Join-Path $devRuntime $devEntry.Name)).Hash.ToLowerInvariant() -ne $devEntry.Value){throw 'STOP runtime/DPAPI hash'}
}
if(Test-Path -LiteralPath "$devRepo\storage\framework\maintenance.php"){throw 'STOP existing maintenance state; no deletion'}
foreach($devName in @('never-routes.php','never-events.php')){if(Test-Path "$devRuntime\$devName"){throw 'STOP unexpected compiled route/event cache; no deletion'}}
$devPg=@(Get-NetTCPConnection -State Listen -ErrorAction Stop|Where-Object {$_.LocalPort -eq 55432})
if($devPg.Count -ne 1 -or $devPg[0].LocalAddress -ne '127.0.0.1'){throw 'STOP PostgreSQL dedicated endpoint not ready; no automatic start'}
function Read-DevCipher([string]$Path){
 if(!(Test-Path -LiteralPath $Path)){throw 'STOP existing DPAPI file missing; no generation'}
 $devSecure=(Get-Content -LiteralPath $Path -Raw).Trim()|ConvertTo-SecureString
 return [Net.NetworkCredential]::new('',$devSecure).Password
}
$devVariables=[Environment]::GetEnvironmentVariables('Process')
$devValues=@{
 FP_LARAGON_DEV='1';APP_ENV='local';APP_DEBUG='false';APP_NAME='Victory HRD Local';APP_URL='http://localhost:8000';APP_BASE_PATH=$devRepo;
 DB_CONNECTION='pgsql';DB_HOST='127.0.0.1';DB_PORT='55432';DB_DATABASE='fp_management_synthetic_dev';DB_USERNAME='fp_dev_app';DB_URL='';DATABASE_URL='';PGCONNECT_TIMEOUT='5';
 APP_CONFIG_CACHE='../../var/fp-management-dev/runtime-config.php';APP_ROUTES_CACHE='../../var/fp-management-dev/never-routes.php';APP_EVENTS_CACHE='../../var/fp-management-dev/never-events.php';
 APP_SERVICES_CACHE='../../var/fp-management-dev/storage/framework/services.php';APP_PACKAGES_CACHE='../../var/fp-management-dev/storage/framework/packages.php';
 LARAVEL_STORAGE_PATH="$devRuntime\storage";VIEW_COMPILED_PATH="$devRuntime\storage\framework\views";PHP_INI_SCAN_DIR="$devRuntime\ini";PHP_CLI_SERVER_WORKERS='1';
 SESSION_DRIVER='file';CACHE_DRIVER='file';QUEUE_CONNECTION='sync';MAIL_MAILER='array';LOG_CHANNEL='null';
 APP_KEY=(Read-DevCipher "$devRuntime\private\app-key.dpapi");FP_NIK_LOOKUP_KEY=(Read-DevCipher "$devRuntime\private\nik-hmac.dpapi");
 DB_PASSWORD=(Read-DevCipher 'E:\FPManagementDev\postgres\secrets\application.dpapi');
}
foreach($devKey in $devValues.Keys){$devVariables[$devKey]=$devValues[$devKey]}
# No preflight, owner/admin/test credentials reach the HTTP server.
foreach($devKey in @('PGPASSWORD','ACTIVATION_PASSWORD','FP_TEST_PASSWORD','FP_TEST_USER','FP_TEST_WRITE_AUTHORIZATION','FP_PREFLIGHT_PASSWORD','FP36_APP_PASSWORD')){$devVariables.Remove($devKey)}
function Start-DevPhp([string[]]$Arguments,[switch]$Preflight){
 $devInfo=[Diagnostics.ProcessStartInfo]::new();$devInfo.FileName=$devPhp;$devInfo.WorkingDirectory=$devRepo;$devInfo.UseShellExecute=$false;$devInfo.CreateNoWindow=$true
 $devInfo.RedirectStandardOutput=$true;$devInfo.RedirectStandardError=$true;$devInfo.EnvironmentVariables.Clear()
 foreach($devKey in $devVariables.Keys){$devInfo.EnvironmentVariables[$devKey]=[string]$devVariables[$devKey]}
 if($Preflight){$devInfo.EnvironmentVariables['FP_PREFLIGHT_PASSWORD']=Read-DevCipher 'E:\FPManagementDev\postgres\secrets\migrator.dpapi'}
 # Fixed arguments only, with Windows-native quoting; compatible with Windows PowerShell5.1 and PowerShell7.
 $devInfo.Arguments=($Arguments|ForEach-Object {'"'+$_.Replace('"','\"')+'"'}) -join ' '
 return [Diagnostics.Process]::Start($devInfo)
}
function Safe-DevOutput([string]$Line){
 foreach($devKey in @('APP_KEY','FP_NIK_LOOKUP_KEY','DB_PASSWORD')){if($devValues[$devKey]){$Line=$Line.Replace([string]$devValues[$devKey],'[REDACTED]')}}
 return $Line
}
$devPreflight=Start-DevPhp -Preflight -Arguments @("$devRuntime\preflight.php")
$devOut=$devPreflight.StandardOutput.ReadToEndAsync();$devErr=$devPreflight.StandardError.ReadToEndAsync();$devPreflight.WaitForExit()
Write-Output (Safe-DevOutput ($devOut.GetAwaiter().GetResult()+$devErr.GetAwaiter().GetResult()))
if($devPreflight.ExitCode -ne 0){throw 'STOP read-only preflight failed; no server start'}
$devPreflight.Dispose();$devPreflight=$null
if($Check){return}
if(@(Get-NetTCPConnection -State Listen -ErrorAction Stop|Where-Object {$_.LocalPort -eq 8000}).Count){throw 'STOP port8000 occupied; stop the identified development instance first'}
$devProcess=$null
try{
 Write-Output 'Artisan Serve: http://localhost:8000/fp-dev/login -- Ctrl+C to stop.'
 $devProcess=Start-DevPhp -Arguments @('artisan','serve','--host=localhost','--port=8000','--tries=1','--no-reload')
 $devStdout=$devProcess.StandardOutput.ReadLineAsync();$devStderr=$devProcess.StandardError.ReadLineAsync()
 while(!$devProcess.HasExited -or $devStdout -or $devStderr){
  foreach($devStream in @('out','err')){
   $devRead=if($devStream -eq 'out'){$devStdout}else{$devStderr}
   if($devRead -and $devRead.IsCompleted){
    $devLine=$devRead.GetAwaiter().GetResult()
    if($null -eq $devLine){if($devStream -eq 'out'){$devStdout=$null}else{$devStderr=$null}}
    else{Write-Output (Safe-DevOutput $devLine);if($devStream -eq 'out'){$devStdout=$devProcess.StandardOutput.ReadLineAsync()}else{$devStderr=$devProcess.StandardError.ReadLineAsync()}}
   }
  }
  Start-Sleep -Milliseconds 50
 }
 $devProcess.WaitForExit();if($devProcess.ExitCode -ne 0){throw "STOP Artisan exit $($devProcess.ExitCode); no automatic retry"}
}finally{
 if($devProcess){
  $devOwned=@(Get-CimInstance Win32_Process -Filter "ParentProcessId=$($devProcess.Id)"|Where-Object {$_.ExecutablePath -eq $devPhp -and $_.CommandLine -match 'localhost:8000'})
  foreach($devChild in $devOwned){Stop-Process -Id $devChild.ProcessId -ErrorAction SilentlyContinue}
  if(!$devProcess.HasExited){$devProcess.Kill()}
  $devProcess.Dispose()
 }
 $devVariables=$null;$devValues=$null
 Write-Output 'Development invocation stopped; no database stop or data change.'
}
