# Fix uppercase filter hooks script
$files = Get-ChildItem -Path . -Recurse -File -Filter "*.php" | Where-Object {
    $_.FullName -notmatch '\\\.git\\' -and
    $_.FullName -notmatch '\\vendor\\' -and
    $_.FullName -notmatch '\\build\\' -and
    $_.FullName -notmatch '\\node_modules\\'
}

foreach ($file in $files) {
    $content = [System.IO.File]::ReadAllText($file.FullName)
    if ([string]::IsNullOrEmpty($content)) { continue }
    
    $orig = $content
    # Convert apply_filters('OMNIWP_foo', ...) and add_filter('OMNIWP_foo', ...)
    $content = [regex]::Replace($content, "(apply_filters|add_filter|has_filter|remove_filter|do_action|add_action)\(\s*'OMNIWP_([a-z0-9_]+)'", {
        param($match)
        $func = $match.Groups[1].Value
        $hook = $match.Groups[2].Value.ToLower()
        return "${func}( 'omniwp_${hook}'"
    })
    
    if ($content -ne $orig) {
        [System.IO.File]::WriteAllText($file.FullName, $content)
        Write-Host "Fixed filters in: $($file.FullName)"
    }
}
