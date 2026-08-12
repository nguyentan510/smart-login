# Provider browser E2E qualification

This gate is for real Google/Zalo staging credentials. It does not use the mock
HTTP fixtures from the integration suite.

## Preflight

Configure credentials in **Smart Login → Đăng nhập nhanh → Thiết lập**. The
encrypted Settings values are the normal admin path; deployment constants or
process environment values remain supported and take precedence. Copy the
exact HTTPS callback shown by the provider card into the provider console,
configure both WordPress Home URL and Site URL as HTTPS, enable the provider,
start the WordPress/MySQL site, then run:

```powershell
.\scripts\run-provider-e2e-preflight.ps1 -Provider both
```

Required marker:

```text
OMNIWP_PROVIDER_E2E_PREFLIGHT_OK
```

Missing credentials, disabled providers, an unavailable site, an invalid
callback, or a failed WordPress/MySQL bootstrap returns:

```text
OMNIWP_PROVIDER_E2E_BLOCKED
```

## Browser evidence

For each provider:

1. Open the Smart Login page in a clean browser session.
2. Select the provider button using `[data-sl-provider="<provider>"][data-sl-provider-mode="login"]`.
3. Confirm the provider consent screen uses the expected staging application.
4. Complete consent with a staging account.
5. Confirm callback returns to the configured HTTPS domain.
6. Confirm a WordPress auth cookie is issued and the user lands on Profile.
7. Confirm a new provider-only user sees required contact onboarding.
8. Repeat login and confirm no duplicate WordPress user or external identity.
9. Confirm `provider_login` and `provider_linked` audit events contain no token or secret.

Real provider qualification is not complete until both the preflight marker and
the browser/database evidence above are captured.
