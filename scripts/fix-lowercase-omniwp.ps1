# Fix lowercase OMNIWP strings script
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
    $content = $content -replace "OMNIWP_settings", "omniwp_settings"
    $content = $content -replace "OMNIWP_db_version", "omniwp_db_version"
    $content = $content -replace "OMNIWP_account_menu", "omniwp_account_menu"
    $content = $content -replace "OMNIWP_login_url", "omniwp_login_url"
    $content = $content -replace "OMNIWP_login_redirect", "omniwp_login_redirect"
    $content = $content -replace "OMNIWP_step", "omniwp_step"
    $content = $content -replace "OMNIWP_button", "omniwp_button"
    $content = $content -replace "OMNIWP_vouchers", "omniwp_vouchers"
    $content = $content -replace "OMNIWP_cleanup", "omniwp_cleanup"
    $content = $content -replace "OMNIWP_ts", "omniwp_ts"
    $content = $content -replace "OMNIWP_website", "omniwp_website"
    $content = $content -replace "OMNIWP_trust_proxy_headers", "omniwp_trust_proxy_headers"
    $content = $content -replace "OMNIWP_trusted_proxy_cidrs", "omniwp_trusted_proxy_cidrs"
    $content = $content -replace "OMNIWP_provider_callback", "omniwp_provider_callback"
    $content = $content -replace "OMNIWP_phone_is_valid", "omniwp_phone_is_valid"
    $content = $content -replace "OMNIWP_captcha_missing", "omniwp_captcha_missing"
    $content = $content -replace "OMNIWP_captcha_failed", "omniwp_captcha_failed"
    $content = $content -replace "OMNIWP_too_fast", "omniwp_too_fast"
    $content = $content -replace "OMNIWP_bad_stamp", "omniwp_bad_stamp"
    $content = $content -replace "OMNIWP_identify_limit", "omniwp_identify_limit"
    $content = $content -replace "OMNIWP_unavailable", "omniwp_unavailable"
    $content = $content -replace "OMNIWP_wrong_intent", "omniwp_wrong_intent"
    $content = $content -replace "OMNIWP_otp_used", "omniwp_otp_used"
    $content = $content -replace "OMNIWP_breaker_sms", "omniwp_breaker_sms"
    $content = $content -replace "OMNIWP_transport_down", "omniwp_transport_down"
    $content = $content -replace "OMNIWP_google_claims", "omniwp_google_claims"
    $content = $content -replace "OMNIWP_field_secrets", "omniwp_field_secrets"
    $content = $content -replace "OMNIWP_provider_secrets", "omniwp_provider_secrets"
    $content = $content -replace "OMNIWP_captcha_secret", "omniwp_captcha_secret"
    $content = $content -replace "OMNIWP_account_page", "omniwp_account_page"
    $content = $content -replace "OMNIWP_dialog_benefits", "omniwp_dialog_benefits"
    $content = $content -replace "OMNIWP_external_identities", "omniwp_external_identities"
    $content = $content -replace '\$OMNIWP_', '$omniwp_'
    $content = $content -replace '\$OMNIWP', '$omniwp'
    
    if ($content -ne $orig) {
        [System.IO.File]::WriteAllText($file.FullName, $content)
        Write-Host "Fixed: $($file.FullName)"
    }
}
