# Authentication integration gate

The pure PHP suite does not prove a real WordPress bootstrap or database
migration. When a local WordPress site and MySQL instance are available, run:

```powershell
.\scripts\run-auth-integration-gate.ps1
```

The gate runs `tests/run-all.php` first (regression, lint and the identity
suites — spec suites report without blocking), then bootstraps WordPress and
checks the DB version, the `smartlogin_identities` and
`smartlogin_identity_history` schema, the `UNIQUE KEY subject_owner` constraint,
`dbDelta` idempotency, a claim/retire/re-claim round trip, and the profile
completeness contract. A successful run ends with:

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

Finally it runs the Phase 9 abuse gate. The pure suite drives every control in
that phase through a stub `$wpdb` that never parses SQL and a stub option layer
that never reaches MySQL, so three things are unproven until this runs: that
`KEY created_at` survives `dbDelta` and is the index MySQL actually picks, that
`OtpRepository::count_recent_all()` is valid SQL whose count moves with a real
row, and that the kill switch round-trips through the real option layer. It also
renders the readiness rows and re-checks `Client::in_cidr()` on the WordPress
runtime's PHP build rather than the one the unit suite happens to use.

```text
SMART_LOGIN_ABUSE_GATE_OK
```

If the runtime is unavailable, the gate exits non-zero and emits
`SMART_LOGIN_AUTH_INTEGRATION_BLOCKED`. That is an environment blocker, not a
production-readiness claim.

The script locates `openssl.cnf` itself for whichever PHP binary it picks. If
your build stores it somewhere unusual, set `OPENSSL_CONF` before running —
without it `openssl_pkey_new()` fails and the Google fixture cannot be built.

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
