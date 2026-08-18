param(
    [string]$WpRoot = $(if ($env:OMNIWP_WP_ROOT) { $env:OMNIWP_WP_ROOT } else { 'C:\Users\PC\Local Sites\wp\app\public' }),
    [string]$PluginRoot = $(if ($env:OMNIWP_PLUGIN_ROOT) { $env:OMNIWP_PLUGIN_ROOT } else { (Get-Location).Path }),
    [string]$DbHost = $(if ($env:OMNIWP_DB_HOST) { $env:OMNIWP_DB_HOST } else { '127.0.0.1:10005' }),
    [string]$DbName = $(if ($env:OMNIWP_DB_NAME) { $env:OMNIWP_DB_NAME } else { 'local' }),
    [string]$DbUser = $(if ($env:OMNIWP_DB_USER) { $env:OMNIWP_DB_USER } else { 'root' }),
    [string]$DbPassword = $(if ($env:OMNIWP_DB_PASSWORD) { $env:OMNIWP_DB_PASSWORD } else { 'root' }),
    [string]$DbPrefix = $(if ($env:OMNIWP_DB_PREFIX) { $env:OMNIWP_DB_PREFIX } else { 'wp_' })
)

# XAMPP has lived on C: and on D:. Look for both rather than hard-coding the one
# that happened to exist when this was written — it moved once already, and the
# failure when it moves is twenty passing assertions turning red for a reason
# that has nothing to do with the code.
$xamppPhp = @('C:\xampp\php\php.exe', 'D:\XAMPP\php\php.exe') |
    Where-Object { Test-Path -LiteralPath $_ } |
    Select-Object -First 1

# The integration gates need mbstring as well as openssl, and this script used to
# pick an interpreter for them without asking about either.
#
# What that cost, measured rather than imagined: Local's build loads no php.ini,
# so it reports mbstring=0 openssl=0, and it was preferred over XAMPP by the
# ordering below. The provider gate then died on
# `Call to undefined function OmniWP\Address\mb_strtolower()` — which reads
# exactly like a fatal in the address code, and is not one. The openssl argument
# below was already written down for the pure suites; it simply was never applied
# to this half.
function Test-PhpUsable {
    param([string]$Candidate)

    if (-not $Candidate) { return $false }

    # `php -m` rather than `php -r`, and that is not a style preference.
    # Windows PowerShell 5.1 does not escape embedded double quotes when it
    # builds the command line for a native executable, so a probe written as
    # `-r 'echo extension_loaded("mbstring");'` reaches PHP with its quotes
    # mangled and dies with `syntax error, unexpected end of file` — which this
    # function would then read as "extension missing" and report as a blocked
    # environment. A probe that cannot fail honestly is worse than none.
    # `-m` takes no arguments at all, so there is nothing left to quote.
    try {
        $modules = & $Candidate -m
    } catch {
        return $false
    }

    return (($modules -contains 'mbstring') -and ($modules -contains 'openssl'))
}

# An explicit OMNIWP_PHP still wins, but it is probed like anything else — an
# override that quietly lacks an extension produces the same misleading red as
# the default did.
$phpCandidates = @(
    $env:OMNIWP_PHP,
    $xamppPhp,
    'C:\Users\PC\AppData\Roaming\Local\lightning-services\php-8.2.29+0\bin\win64\php.exe',
    'php'
) | Where-Object { $_ }

$integrationPhp = $phpCandidates | Where-Object { Test-PhpUsable $_ } | Select-Object -First 1

if (-not $integrationPhp) {
    Write-Output 'OMNIWP_AUTH_INTEGRATION_BLOCKED'
    Write-Output "reason=no PHP on this machine loads both mbstring and openssl, so the gates would fail on the environment rather than on the code. Probed: $($phpCandidates -join ', '). Set OMNIWP_PHP to a build with both."
    exit 2
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
    Write-Output 'OMNIWP_AUTH_INTEGRATION_BLOCKED'
    Write-Output 'reason=no XAMPP php found and PHPRC is unset, so the pure suites would run without openssl and fail ~20 secret assertions that are not real. Set PHPRC to a directory holding a php.ini that loads php_openssl.dll.'
    exit 2
}

if (-not (Test-Path -LiteralPath $WpRoot)) {
    Write-Output 'OMNIWP_AUTH_INTEGRATION_BLOCKED'
    Write-Output "reason=WordPress root not found: $WpRoot"
    exit 2
}
if (-not (Test-Path -LiteralPath (Join-Path $PluginRoot 'omniwp.php'))) {
    Write-Output 'OMNIWP_AUTH_INTEGRATION_BLOCKED'
    Write-Output "reason=Smart Login plugin root not found: $PluginRoot"
    exit 2
}

$env:OMNIWP_WP_ROOT = $WpRoot
$env:OMNIWP_PLUGIN_ROOT = $PluginRoot
$env:OMNIWP_DB_HOST = $DbHost
$env:OMNIWP_DB_NAME = $DbName
$env:OMNIWP_DB_USER = $DbUser
$env:OMNIWP_DB_PASSWORD = $DbPassword
$env:OMNIWP_DB_PREFIX = $DbPrefix

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

# Exit status alone is not proof a gate ran. WordPress answers an unreachable
# database by printing its die page and stopping with status 0, so the first
# wiring of the two gates below "passed" while emitting a Database Error page and
# not one assertion. Requiring the success marker as well as the status closes
# that: a gate that fell over before reaching its own last line cannot report it.
function Invoke-Gate {
    param(
        [string]$Script,
        [string]$OkMarker
    )

    $output = & $integrationPhp @phpArgs $Script
    $output | ForEach-Object { Write-Output $_ }

    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }

    if (-not ($output -match [regex]::Escape($OkMarker))) {
        Write-Output 'OMNIWP_AUTH_INTEGRATION_FAILED'
        Write-Output "reason=$Script exited 0 but never printed $OkMarker, so it stopped before finishing. Exit status is not proof a gate ran."
        exit 1
    }
}

# Phase 19 and Phase 21. Both gates were written, committed and then referenced by
# nothing — not this script, not run-all.php, not CI — so 537 lines of gate sat on
# disk unable to run. A gate nobody invokes is indistinguishable from a gate that
# passes, which is the same confusion the SKIP-versus-PASS note in CLAUDE.md is
# about. They go here, above the destructive one, because neither writes anything
# the later gates depend on.
Invoke-Gate 'tests/integration/run-sign-in-anywhere-gate.php' 'OMNIWP_DIALOG_INTEGRATION_OK'
Invoke-Gate 'tests/integration/run-account-menu-gate.php' 'OMNIWP_ACCOUNT_MENU_INTEGRATION_OK'

# Phase 15. activate() -> tables -> defaults -> first use -> uninstall.php had never
# run end to end; every other gate here starts from a site somebody installed by hand.
#
# It is DESTRUCTIVE and runs LAST for that reason: it uninstalls twice, once to reach
# clean ground and once as the subject, then reactivates so the gates above still have
# a site to run against next time. Opt in with OMNIWP_DESTRUCTIVE_OK=1; without
# it the gate reports BLOCKED and this script treats that as a skip rather than a
# failure, so the default run stays non-destructive.
& $integrationPhp @phpArgs 'tests/integration/run-install-gate.php'
if ($LASTEXITCODE -eq 2) {
    Write-Output 'OMNIWP_INSTALL_GATE_SKIPPED (set OMNIWP_DESTRUCTIVE_OK=1 to run it)'
    exit 0
}
exit $LASTEXITCODE
