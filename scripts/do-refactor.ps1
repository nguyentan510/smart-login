# PowerShell refactor script for OmniWP
$files = Get-ChildItem -Path . -Recurse -File | Where-Object {
    $_.FullName -notmatch '\\\.git\\' -and
    $_.FullName -notmatch '\\vendor\\' -and
    $_.FullName -notmatch '\\build\\' -and
    $_.FullName -notmatch '\\node_modules\\'
}

foreach ($file in $files) {
    $content = [System.IO.File]::ReadAllText($file.FullName)
    if ([string]::IsNullOrEmpty($content)) { continue }
    
    $orig = $content
    $content = $content -replace 'OmniWP', 'OmniWP'
    $content = $content -replace 'OMNIWP', 'OMNIWP'
    $content = $content -replace 'omniwp', 'omniwp'
    $content = $content -replace 'OMNIWP', 'omniwp'
    $content = $content -replace 'OmniWP', 'omniwp'
    $content = $content -replace '_ow_', '_ow_'
    $content = $content -replace '\bsl_', 'ow_'
    
    if ($content -ne $orig) {
        [System.IO.File]::WriteAllText($file.FullName, $content)
        Write-Host "Updated: $($file.FullName)"
    }
}
