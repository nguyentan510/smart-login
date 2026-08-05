param(
    [string]$WpRoot = $(if ($env:SMART_LOGIN_WP_ROOT) { $env:SMART_LOGIN_WP_ROOT } else { 'C:\Users\PC\Local Sites\wp\app\public' }),
    [string]$PluginRoot = $(if ($env:SMART_LOGIN_PLUGIN_ROOT) { $env:SMART_LOGIN_PLUGIN_ROOT } else { (Get-Location).Path }),
    [string]$DbHost = $(if ($env:SMART_LOGIN_DB_HOST) { $env:SMART_LOGIN_DB_HOST } else { '127.0.0.1:10005' }),
    [string]$DbName = $(if ($env:SMART_LOGIN_DB_NAME) { $env:SMART_LOGIN_DB_NAME } else { 'local' }),
    [string]$DbUser = $(if ($env:SMART_LOGIN_DB_USER) { $env:SMART_LOGIN_DB_USER } else { 'root' }),
    [string]$DbPassword = $(if ($env:SMART_LOGIN_DB_PASSWORD) { $env:SMART_LOGIN_DB_PASSWORD } else { 'root' }),
    [string]$DbPrefix = $(if ($env:SMART_LOGIN_DB_PREFIX) { $env:SMART_LOGIN_DB_PREFIX } else { 'wp_' })
)

# XAMPP has lived on C: and on D:. Look for both rather than hard-coding the one
# that happened to exist when this was written — it moved once already, and the
# failure when it moves is twenty passing assertions turning red for a reason
# that has nothing to do with the code.
$xamppPhp = @('C:\xampp\php\php.exe', 'D:\XAMPP\php\php.exe') |
    Where-Object { Test-Path -LiteralPath $_ } |
    Select-Object -First 1

$integrationPhp = if ($env:SMART_LOGIN_PHP) {
    $env:SMART_LOGIN_PHP
} elseif (Test-Path 'C:\Users\PC\AppData\Roaming\Local\lightning-services\php-8.2.29+0\bin\win64\php.exe') {
    'C:\Users\PC\AppData\Roaming\Local\lightning-services\php-8.2.29+0\bin\win64\php.exe'
} elseif ($xamppPhp) {
    $xamppPhp
} else {
    'php'
}

# The pure suites need openssl, because SecretBox does. A XAMPP build has it
# compiled in; Local's ships no php.ini at all, so run-all.php's child processes
# come up without it and roughly twenty assertions fail across four suites —
# every one about encrypted secrets, and every one looking exactly like a real
# regression in code nobody touched.
#
# Passing -d here would not help: run-all.php spawns each suite with
# escapeshellarg( PHP_BINARY ) and the file, nothing else. So prefer a XAMPP
# build when there is one, and tell the operator what to do when there is not.
$purePhp = if ($xamppPhp) {
    $xamppPhp
} else {
    $integrationPhp
}

if (-not $xamppPhp -and -not $env:PHPRC) {
    Write-Output 'SMART_LOGIN_AUTH_INTEGRATION_BLOCKED'
    Write-Output 'reason=no XAMPP php found and PHPRC is unset, so the pure suites would run without openssl and fail ~20 secret assertions that are not real. Set PHPRC to a directory holding a php.ini that loads php_openssl.dll.'
    exit 2
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
        'C:\xampp\apache\conf\openssl.cnf',
        'D:\XAMPP\php\extras\ssl\openssl.cnf',
        'D:\XAMPP\apache\conf\openssl.cnf'
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
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

# Phase 9. The pure suite drives every abuse control through a stub wpdb that
# never parses SQL, so the site-wide counter, the new index and the halt option
# are unproven until they run here.
& $integrationPhp @phpArgs 'tests/integration/run-abuse-gate.php'
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

# Phase 10. The pure suite stubs wp_remote_request() and never loads wp-admin,
# so the sanitize callback's rejection, add_settings_error(), the signature over
# what WP_Http would really send, and the delivery tab rendering the automation
# section are all unproven until they run here.
& $integrationPhp @phpArgs 'tests/integration/run-delivery-gate.php'
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

# Phase 15. activate() -> tables -> defaults -> first use -> uninstall.php had never
# run end to end; every other gate here starts from a site somebody installed by hand.
#
# It is DESTRUCTIVE and runs LAST for that reason: it uninstalls twice, once to reach
# clean ground and once as the subject, then reactivates so the gates above still have
# a site to run against next time. Opt in with SMART_LOGIN_DESTRUCTIVE_OK=1; without
# it the gate reports BLOCKED and this script treats that as a skip rather than a
# failure, so the default run stays non-destructive.
& $integrationPhp @phpArgs 'tests/integration/run-install-gate.php'
if ($LASTEXITCODE -eq 2) {
    Write-Output 'SMART_LOGIN_INSTALL_GATE_SKIPPED (set SMART_LOGIN_DESTRUCTIVE_OK=1 to run it)'
    exit 0
}
exit $LASTEXITCODE
