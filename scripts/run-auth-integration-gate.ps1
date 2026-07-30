param(
    [string]$WpRoot = $(if ($env:SMART_LOGIN_WP_ROOT) { $env:SMART_LOGIN_WP_ROOT } else { 'C:\Users\PC\Local Sites\wp\app\public' }),
    [string]$PluginRoot = $(if ($env:SMART_LOGIN_PLUGIN_ROOT) { $env:SMART_LOGIN_PLUGIN_ROOT } else { (Get-Location).Path }),
    [string]$DbHost = $(if ($env:SMART_LOGIN_DB_HOST) { $env:SMART_LOGIN_DB_HOST } else { '127.0.0.1:10005' }),
    [string]$DbName = $(if ($env:SMART_LOGIN_DB_NAME) { $env:SMART_LOGIN_DB_NAME } else { 'local' }),
    [string]$DbUser = $(if ($env:SMART_LOGIN_DB_USER) { $env:SMART_LOGIN_DB_USER } else { 'root' }),
    [string]$DbPassword = $(if ($env:SMART_LOGIN_DB_PASSWORD) { $env:SMART_LOGIN_DB_PASSWORD } else { 'root' }),
    [string]$DbPrefix = $(if ($env:SMART_LOGIN_DB_PREFIX) { $env:SMART_LOGIN_DB_PREFIX } else { 'wp_' })
)

$integrationPhp = if ($env:SMART_LOGIN_PHP) {
    $env:SMART_LOGIN_PHP
} elseif (Test-Path 'C:\Users\PC\AppData\Roaming\Local\lightning-services\php-8.2.29+0\bin\win64\php.exe') {
    'C:\Users\PC\AppData\Roaming\Local\lightning-services\php-8.2.29+0\bin\win64\php.exe'
} elseif (Test-Path 'C:\xampp\php\php.exe') {
    'C:\xampp\php\php.exe'
} else {
    'php'
}

$purePhp = if (Test-Path 'C:\xampp\php\php.exe') {
    'C:\xampp\php\php.exe'
} else {
    $integrationPhp
}

if (-not (Test-Path -LiteralPath $WpRoot)) {
    Write-Output 'SMART_LOGIN_AUTH_INTEGRATION_BLOCKED'
    Write-Output "reason=WordPress root not found: $WpRoot"
    exit 2
}
if (-not (Test-Path -LiteralPath (Join-Path $PluginRoot 'smart-login.php'))) {
    Write-Output 'SMART_LOGIN_AUTH_INTEGRATION_BLOCKED'
    Write-Output "reason=Smart Login plugin root not found: $PluginRoot"
    exit 2
}

$env:SMART_LOGIN_WP_ROOT = $WpRoot
$env:SMART_LOGIN_PLUGIN_ROOT = $PluginRoot
$env:SMART_LOGIN_DB_HOST = $DbHost
$env:SMART_LOGIN_DB_NAME = $DbName
$env:SMART_LOGIN_DB_USER = $DbUser
$env:SMART_LOGIN_DB_PASSWORD = $DbPassword
$env:SMART_LOGIN_DB_PREFIX = $DbPrefix

# The aggregate runner, so the identity suites report alongside the regression
# suite. Spec suites are non-blocking until Phase 7 promotes them.
& $purePhp 'tests/run-all.php'
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

$phpArgs = @()
$extensionDirectory = Split-Path -Parent $integrationPhp

# OPENSSL_CONF must be located for ANY php build, not just Local's. Without it
# openssl_pkey_new() fails with "configuration file routines::no such file" and
# the provider gate reports a blocker for an entirely avoidable reason.
if (-not $env:OPENSSL_CONF) {
    foreach ($candidate in @(
        (Join-Path $extensionDirectory 'extras\ssl\openssl.cnf'),
        (Join-Path $extensionDirectory 'extras\openssl\openssl.cnf'),
        'C:\xampp\php\extras\ssl\openssl.cnf',
        'C:\xampp\apache\conf\openssl.cnf'
    )) {
        if (Test-Path -LiteralPath $candidate) {
            $env:OPENSSL_CONF = $candidate
            break
        }
    }
}

if ($extensionDirectory -like '*lightning-services*') {
    $phpArgs += @(
        '-d', "extension_dir=$extensionDirectory\ext",
        '-d', 'extension=php_mysqli.dll',
        '-d', 'extension=php_pdo_mysql.dll',
        '-d', 'extension=php_openssl.dll'
    )
}

& $integrationPhp @phpArgs 'tests/integration/run-wordpress-gate.php'
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

& $integrationPhp @phpArgs 'tests/integration/run-provider-gates.php'
exit $LASTEXITCODE
