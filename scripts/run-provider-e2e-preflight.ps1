param(
    [ValidateSet('google', 'zalo', 'both')]
    [string]$Provider = 'both',
    [string]$SiteUrl = 'https://localtest.local',
    [string]$WpRoot = 'C:\Users\PC\Local Sites\localtest\app\public',
    [string]$PluginRoot = $(if ($env:SMART_LOGIN_PLUGIN_ROOT) { $env:SMART_LOGIN_PLUGIN_ROOT } else { (Get-Location).Path }),
    [string]$WpConfig = 'C:\Users\PC\Local Sites\localtest\app\public\wp-config.php',
    [string]$DbHost = '127.0.0.1:10005',
    [string]$DbName = 'local',
    [string]$DbUser = 'root',
    [string]$DbPassword = 'root',
    [string]$DbPrefix = 'wp_'
)

$configText = if (Test-Path -LiteralPath $WpConfig) { Get-Content -Raw -LiteralPath $WpConfig } else { '' }

foreach ($name in @(
    'SMART_LOGIN_GOOGLE_CLIENT_ID',
    'SMART_LOGIN_GOOGLE_CLIENT_SECRET',
    'SMART_LOGIN_GOOGLE_REDIRECT_URI',
    'SMART_LOGIN_ZALO_APP_ID',
    'SMART_LOGIN_ZALO_APP_SECRET',
    'SMART_LOGIN_ZALO_REDIRECT_URI'
)) {
    $value = [Environment]::GetEnvironmentVariable($name)
    if ([string]::IsNullOrWhiteSpace($value) -and -not [string]::IsNullOrWhiteSpace($configText)) {
        $pattern = "define\s*\(\s*['""]$([regex]::Escape($name))['""]\s*,\s*['""](?<value>[^'""]+)['""]\s*\)"
        $match = [regex]::Match($configText, $pattern)
        if ($match.Success) {
            [Environment]::SetEnvironmentVariable($name, $match.Groups['value'].Value)
        }
    }
}

$curl = Get-Command curl.exe -ErrorAction SilentlyContinue
if (-not $curl) {
    Write-Output 'SMART_LOGIN_PROVIDER_E2E_BLOCKED'
    Write-Output 'reason=curl.exe is required for the HTTPS reachability preflight'
    exit 2
}
& $curl.Source -k -sS -I --max-time 8 $SiteUrl | Out-Null
if ($LASTEXITCODE -ne 0) {
    Write-Output 'SMART_LOGIN_PROVIDER_E2E_BLOCKED'
    Write-Output "reason=site is not reachable over HTTPS: $SiteUrl"
    exit 2
}

$php = if ($env:SMART_LOGIN_PHP) {
    $env:SMART_LOGIN_PHP
} elseif (Test-Path 'C:\Users\PC\AppData\Roaming\Local\lightning-services\php-8.2.29+0\bin\win64\php.exe') {
    'C:\Users\PC\AppData\Roaming\Local\lightning-services\php-8.2.29+0\bin\win64\php.exe'
} else {
    'C:\xampp\php\php.exe'
}
$extensionDirectory = Split-Path -Parent $php
$phpArgs = @()
if ($extensionDirectory -like '*lightning-services*') {
    $phpArgs += @(
        '-d', "extension_dir=$extensionDirectory\ext",
        '-d', 'extension=php_mysqli.dll',
        '-d', 'extension=php_pdo_mysql.dll',
        '-d', 'extension=php_openssl.dll'
    )
}

$env:SMART_LOGIN_E2E_PROVIDER = $Provider
$env:SMART_LOGIN_E2E_SITE_URL = $SiteUrl
$env:SMART_LOGIN_WP_ROOT = $WpRoot
$env:SMART_LOGIN_PLUGIN_ROOT = $PluginRoot
$env:SMART_LOGIN_DB_HOST = $DbHost
$env:SMART_LOGIN_DB_NAME = $DbName
$env:SMART_LOGIN_DB_USER = $DbUser
$env:SMART_LOGIN_DB_PASSWORD = $DbPassword
$env:SMART_LOGIN_DB_PREFIX = $DbPrefix

& $php @phpArgs 'tests/e2e/run-provider-preflight.php'
exit $LASTEXITCODE
