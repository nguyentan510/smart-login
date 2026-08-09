<#
.SYNOPSIS
    Run the aggregate test suite on a PHP that can actually run it.

.DESCRIPTION
    CLAUDE.md says `php tests/run-all.php`. On this project's Windows machines
    there is frequently no `php` on PATH at all, and the binary nearest to hand
    is Local's, which ships **no php.ini** - so it comes up without `openssl` or
    `mbstring` and turns roughly five suites red for a reason that has nothing to
    do with the code.

    That failure is worse than no run at all. It looks exactly like a regression,
    in code nobody touched, and a phase that is meant to record a red count as
    evidence records a number with environment noise mixed into it.

    So this script picks a binary by **asking it what it can do**, not by
    recognising where it came from. A vendor check is a proxy for the property
    that matters; the property itself is one `-r` away, and a machine that later
    fixes Local's PHP with `PHPRC` starts passing this check without anybody
    editing a list in here.

    When nothing on the machine qualifies it **blocks, and says which extension
    was missing from which candidate**, rather than running anyway.

    ASCII only, deliberately. Windows PowerShell 5.1 reads a .ps1 as ANSI unless
    it carries a BOM, so a single em-dash in a comment is a parse error in the
    whole file. This was not a hypothesis.

.PARAMETER Php
    An explicit binary, overriding discovery. Still capability-checked: an
    override says which PHP to use, it does not promise that one works.

.PARAMETER Suite
    A single suite file relative to the plugin root, instead of the aggregate
    runner. Example: -Suite tests/identity/run-account-menu-tests.php

.PARAMETER Strict
    Forward --strict, which refuses to tolerate a `spec` suite.

    A switch rather than a pass-through for arbitrary arguments: `--` is not a
    stop-parsing token for a script invoked with `powershell -File`, so
    `-- --strict` binds to nothing and dies with "the parameter name '' is
    ambiguous". `--strict` is also the only flag run-all.php reads
    (tests/run-all.php:28), so there is nothing else to forward.

.EXAMPLE
    powershell -File scripts/run-tests.ps1

.EXAMPLE
    powershell -File scripts/run-tests.ps1 -Strict

.NOTES
    The integration gates are NOT run here. They need a database, a real
    WordPress, and a host/port that changes when the Local site is recreated.
    scripts/run-auth-integration-gate.ps1 owns all of that.
#>
param(
    [string]$Php = $env:SMART_LOGIN_PHP,
    [string]$Suite = 'tests/run-all.php',
    [switch]$Strict
)

$ErrorActionPreference = 'Stop'

# The plugin root is derived from this file, not from the working directory. A
# script that only works when invoked from one folder is a script somebody will
# invoke from another.
$PluginRoot = Split-Path -Parent $PSScriptRoot

# What the pure suites need beyond a bare interpreter. Both are absent from
# Local's PHP, and SecretBox is the reason openssl is on this list.
#
# `sodium` is deliberately NOT here. It was believed to be required for some
# time; the codebase contains no sodium_* call, and the suite is green on a
# build without it.
$Required = @('openssl', 'mbstring')

function Test-PhpCandidate {
    param([string]$Path)

    # `php` on PATH resolves through the shell rather than the filesystem, so it
    # is looked up rather than Test-Path'd. Both cases report 'not found' so the
    # blocked message distinguishes "no such binary" from "binary is unusable".
    if ($Path -eq 'php') {
        if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
            return @{ Ok = $false; Reason = 'not on PATH' }
        }
    } elseif (-not (Test-Path -LiteralPath $Path)) {
        return @{ Ok = $false; Reason = 'not found' }
    }

    # Single quotes inside the PHP snippet, never double. PowerShell strips
    # double quotes when it builds the native command line, so `echo "|", $e`
    # reaches PHP as a bare `|` and every candidate fails to parse it - which
    # reads as "no usable PHP on this machine" while the machine has one.
    $probe = 'echo PHP_VERSION; foreach ([' +
        (($Required | ForEach-Object { "'$_'" }) -join ',') +
        "] as `$e) { if (!extension_loaded(`$e)) { echo '|', `$e; } }"

    try {
        $out = & $Path -r $probe 2>$null
    } catch {
        return @{ Ok = $false; Reason = 'would not run' }
    }

    if ($LASTEXITCODE -ne 0 -or -not $out) {
        return @{ Ok = $false; Reason = 'would not run' }
    }

    $parts = ($out -join '') -split '\|'
    $missing = @($parts | Select-Object -Skip 1 | Where-Object { $_ })

    if ($missing.Count -gt 0) {
        $joined = $missing -join ', '
        return @{ Ok = $false; Reason = "missing $joined"; Version = $parts[0] }
    }

    return @{ Ok = $true; Version = $parts[0] }
}

# Ordered by how likely each is to be the one that works, not by preference.
# XAMPP has lived on C: and on D: - it moved once already, and hard-coding the
# drive that happened to exist is how twenty assertions go red for no reason.
$candidates = @()

if ($Php) {
    $candidates += $Php
} else {
    $candidates += @(
        'C:\xampp\php\php.exe',
        'D:\XAMPP\php\php.exe',
        'php'
    )
    $local = Join-Path $env:APPDATA 'Local\lightning-services'
    if (Test-Path -LiteralPath $local) {
        $candidates += (Get-ChildItem $local -Directory -Filter 'php-*' -ErrorAction SilentlyContinue |
            Sort-Object Name -Descending |
            ForEach-Object { Join-Path $_.FullName 'bin\win64\php.exe' })
    }
}

$chosen = $null
$chosenVersion = ''
$rejected = @()

foreach ($candidate in $candidates) {
    $result = Test-PhpCandidate -Path $candidate

    if ($result.Ok) {
        $chosen = $candidate
        $chosenVersion = $result.Version
        break
    }

    $reason = $result.Reason
    $rejected += "  $candidate : $reason"
}

if (-not $chosen) {
    $needed = $Required -join ', '
    Write-Output 'SMART_LOGIN_TESTS_BLOCKED'
    Write-Output "reason=no PHP on this machine loads all of: $needed"
    Write-Output 'Running anyway would fail roughly five suites on the environment, not on the code.'
    Write-Output ''
    Write-Output 'Candidates tried:'
    $rejected | ForEach-Object { Write-Output $_ }
    Write-Output ''
    Write-Output 'Fix it any of these ways:'
    Write-Output '  - install XAMPP (its php.ini already loads both), or'
    Write-Output '  - set PHPRC to a directory holding a php.ini that sets extension_dir'
    Write-Output "    to the binary's ext\ and enables openssl and mbstring, or"
    Write-Output '  - point SMART_LOGIN_PHP at a binary that already has them.'
    exit 2
}

$target = Join-Path $PluginRoot $Suite

if (-not (Test-Path -LiteralPath $target)) {
    Write-Output 'SMART_LOGIN_TESTS_BLOCKED'
    Write-Output "reason=suite not found: $target"
    exit 2
}

Write-Output "php      $chosen"
Write-Output "version  $chosenVersion"
Write-Output "suite    $Suite"
Write-Output ''

Push-Location $PluginRoot
try {
    if ($Strict) {
        & $chosen $target '--strict'
    } else {
        & $chosen $target
    }
} finally {
    Pop-Location
}

exit $LASTEXITCODE
