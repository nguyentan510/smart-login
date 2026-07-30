# Authentication integration gate

The pure PHP suite does not prove a real WordPress bootstrap or database
migration. When a local WordPress site and MySQL instance are available, run:

```powershell
.\scripts\run-auth-integration-gate.ps1
```

The gate runs the regression suite first, then bootstraps WordPress and checks
the DB version, `external_identities` schema, repository round-trip, and
profile completeness contract. A successful run ends with:

```text
SMART_LOGIN_AUTH_INTEGRATION_OK
```

The same command then runs the provider qualification fixtures. Successful
provider output includes:

```text
SMART_LOGIN_GOOGLE_STAGING_SMOKE_OK
SMART_LOGIN_PROVIDER_LINKING_OK
SMART_LOGIN_ZALO_STAGING_SMOKE_OK
```

If the runtime is unavailable, the gate exits non-zero and emits
`SMART_LOGIN_AUTH_INTEGRATION_BLOCKED`. That is an environment blocker, not a
production-readiness claim.

Override the defaults with:

```powershell
$env:SMART_LOGIN_WP_ROOT = 'C:\path\to\wordpress\public'
$env:SMART_LOGIN_PLUGIN_ROOT = 'C:\path\to\smart-login'
$env:SMART_LOGIN_DB_HOST = '127.0.0.1:10005'
$env:SMART_LOGIN_DB_NAME = 'local'
$env:SMART_LOGIN_DB_USER = 'root'
$env:SMART_LOGIN_DB_PASSWORD = 'root'
$env:SMART_LOGIN_DB_PREFIX = 'wp_'
```
