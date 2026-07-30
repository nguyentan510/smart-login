# Identity refactor — execution plan

Normative spec: [`identity-model.md`](identity-model.md).

This file is the single progress tracker. It is versioned with the code
deliberately: a session-scoped task list would become a second source of truth
that drifts, which is the exact failure mode this refactor removes.

The project has never run in production, so there is no data migration burden.
Phases are units of **review and test gating**, not of migration safety.

---

## Progress

- [x] **Phase 0 — Foundation and contract**
- [x] **Phase 1 — Identity core (pure, no DB)**
- [x] **Phase 2 — Persistence**
- [ ] **Phase 3 — Directory and state machine**
- [ ] **Phase 4 — Proof layer: OTP**
- [ ] **Phase 5 — Profile boundary**
- [ ] **Phase 6 — Provider lifecycle**
- [ ] **Phase 7 — Release preparation**

Phases 0–3 are the core and should run without interruption. Phases 4–7 are
independent and may be reordered or dropped.

---

## Phase 0 — Foundation and contract ✅

**Delivered**

- `git init` on `main`, `.gitignore`, `.distignore`
- `composer.json` (dev-only tooling; classmap, not PSR-4 — filenames are
  `class-*.php`), `phpcs.xml`
- `docs/identity-model.md` — normative specification
- `docs/refactor-plan.md` — this file
- `tests/harness.php` — shared assertion helpers, `sl_*` prefixed to avoid
  colliding with the existing runner
- `tests/run-lint.php` — portable syntax lint (uses `PHP_BINARY`, replaces the
  bash loop that only ran on Linux)
- `tests/run-all.php` — aggregating runner; identity suites are marked
  `spec` so they report progress without failing the required gate
- `tests/identity/run-contract-tests.php` — red
- `tests/identity/run-fitness-tests.php` — red
- CI updated to run the aggregate runner

**Acceptance:** required suites green (regression + lint); identity suites red
with an actionable per-item list. Confirmed.

---

## Phase 1 — Identity core (pure, no DB) ✅

**Delivered**

| Class | File |
| --- | --- |
| `Claim` | `includes/Identity/class-claim.php` |
| `VerifiedClaim` | `includes/Identity/class-verified-claim.php` |
| `Resolution` | `includes/Identity/class-resolution.php` |
| `IdentityRecord` | `includes/Identity/class-identity-record.php` |
| `ChannelRegistry` | `includes/Identity/class-channel-registry.php` |
| `OpaqueLogin` | `includes/Identity/class-opaque-login.php` |
| `IdentityChannel` (interface) | `includes/Identity/Channels/class-identity-channel.php` |
| `PhoneChannel` | `includes/Identity/Channels/class-phone-channel.php` |
| `MailChannel` | `includes/Identity/Channels/class-mail-channel.php` |
| `FederatedChannel` | `includes/Identity/Channels/class-federated-channel.php` |

Plus `tests/identity/run-core-tests.php` — 101 assertions, wired into
`run-all.php` as **required**, so Phase 1 is protected from Phase 2 onward.

**Notes from doing the work**

- The interface file is `class-identity-channel.php`, not
  `class-channel-interface.php` as this plan first stated. The autoloader derives
  the filename from the class name, so an interface named `IdentityChannel` can
  only live in that file.
- `FederatedChannel` is concrete and parameterised, so Google and Zalo cost
  **zero** classes between them: `new FederatedChannel( 'google', 'Google' )`.
- `MailChannel`, not `EmailChannel`: the OTP delivery namespace already owns that
  class name and the two would collide on disk. The stored channel id stays
  `email`. Phase 4 renames `OTP\Channels` to `OTP\Transports` to retire the
  ambiguity.
- Value objects use private properties with accessors rather than the public
  typed properties used by `AuthContext` / `ProviderIdentity`. Deliberate: a
  mutable subject could be swapped after verification.
- `MailChannel::is_valid()` rejects synthetic `@phone.invalid` addresses. They are
  well-formed but unreachable, so they must never become a claimable identity.
- Nothing is wired into `Plugin::boot()` yet. The core is dormant until Phase 3,
  so runtime behaviour is unchanged and regression risk is zero.
- `ChannelRegistry::is_enabled()` prefers a `channels_enabled` setting and falls
  back to deriving from the legacy `id_mode` / `google_enabled` / `zalo_enabled`
  flags. Phase 4 introduces the setting and removes the fallback.

**Acceptance:** identity core green (101 passed), all Phase 1 items in the
contract suite green, regression suite still 163, zero DB access. Confirmed.

---

## Phase 2 — Persistence ✅

**Delivered**

- `smartlogin_identities` + `smartlogin_identity_history` via `dbDelta()`;
  `SMART_LOGIN_DB_VERSION` → `3`
- `IdentityRepository` — find / for_user / claim / retire / relink / set_primary /
  count_for_user, all atomic at the storage layer
- `IdentityHistory` — append-only, closed event vocabulary
- `Installer::pending_schema_changes()` — dbDelta dry run
- `wp_smart_login_external_identities` dropped; `ExternalIdentityRepository`
  deleted
- `uninstall.php`: both new tables plus the two ward-code meta keys
- Both integration suites ported to the new tables

**Notes from doing the work**

- **`AccountProvisioner` had to be ported in this phase, not Phase 3.** Deleting
  `ExternalIdentityRepository` while a caller still referenced it would leave the
  tree fatalling on any provider login. Only its persistence dependency moved;
  the resolve logic is untouched and still awaits the Phase 3 state machine.
- **A Phase 1 bug surfaced here.** The schema has `created_at DATETIME NOT NULL`
  with no default, but `IdentityRecord::to_row()` did not emit it, so every
  insert would have failed. Fixed, and `run-core-tests.php` now asserts both the
  presence of the column and that `to_row()` key count matches
  `IdentityRepository::FORMATS`.
- **No `email` column on `identities`.** The superseded table had `email` and
  `email_verified`; carrying them forward would recreate the
  multiple-representations problem. An email address is an identity in the
  `email` channel, not an attribute of a federated one. Provider claims stay in
  `meta_json` as forensic context. Phase 3 decides when a verified provider email
  earns its own row.
- **The repository owns history rather than callers.** Retiring without a trace
  would make `Resolution::RETIRED` unreachable, and RETIRED is what keeps the
  takeover defect unrepresentable. Pairing them means no caller can forget.
- **Two allowlisted `external_identities` references remain**, in
  `Installer::drop_legacy_tables()` and `uninstall.php`. They are the migration
  itself, not a dependency on it. Delete both once no install can carry the table.
- **The gate script had an environment gap.** It set `OPENSSL_CONF` only for
  Local's lightning-services PHP; on any other build `openssl_pkey_new()` failed
  and the provider gate reported a blocker for an avoidable reason. It now probes
  several locations for any binary, and runs `tests/run-all.php` rather than only
  the regression suite.

**Acceptance — measured on WordPress 7.0.2 + MySQL, not assumed**

```text
SMART_LOGIN_AUTH_INTEGRATION_OK        db_version=3
SMART_LOGIN_GOOGLE_STAGING_SMOKE_OK
SMART_LOGIN_PROVIDER_LINKING_OK
SMART_LOGIN_ZALO_STAGING_SMOKE_OK
```

- `subject_owner` verified to be a real UNIQUE index over two columns
- a second user claiming an owned subject loses, and the owner is unchanged
- retire reports the previous owner, ends ownership, writes exactly one history
  row, and `last_retired_owner()` still recovers the prior owner for policy
- a retired subject can then be claimed by a different user
- `meta_json` and `is_primary` round-trip
- the superseded table is gone
- `pending_schema_changes()` is empty, so `dbDelta` issues no `ALTER TABLE` on a
  healthy install

---

## Phase 3 — Directory and state machine

**Build**

- `IdentityDirectory::resolve( Claim ): Resolution`
- `AuthAction` — the decision table from spec §5
- `AuthProof` with a private constructor; `SessionIssuer::issue()` requires it
- `OpaqueLogin` wired into both `wp_insert_user()` call sites
- Admin: "Định danh chính" column + `user_search_columns` so support can search
  by phone
- **Delete** `IdentityResolver`, including the `get_user_by( 'login' )` fallback
- Rewrite to consume the directory: `RegisterHandler`, `PasswordResetHandler`,
  `LoginHandler`, `AccountProvisioner`, `ContactVerificationService`

Commit per handler, not one large commit. The decision-table tests must be green
before the first handler is touched.

**Acceptance**

- Fitness tests for Invariant 1 green
- All 16 decision-table cells green
- `recover × RETIRED` cannot reach the previous owner
- No code path issues a session without an `AuthProof`

---

## Phase 4 — Proof layer: OTP

**Build**

- Replace six `PURPOSE_*` constants with `channel` + `intent` columns
- OTP verification returns a `VerifiedClaim`
- Rename `OTP\Channels` → `OTP\Transports`
  (`WebhookTransport`, `MailTransport`, `TransportRouter`)
- Apply `smart_login_validate_password` on **reset** as well as registration
- Fix `smart_login_phone_is_valid`, currently dead on the default `84` country
  code because the Vietnamese branch returns before the filter

**Acceptance**

- Adding a fictional transport in a test changes no file outside that class
- Password policy enforced on both registration and reset

---

## Phase 5 — Profile boundary

**Build**

- `ProfileSeeder::seed_if_empty()` — the only writer of `billing_*`
- Remove the three sites that overwrite `billing_phone`
- WooCommerce checkout: move ward-name substitution to
  `woocommerce_checkout_posted_data`, which has a return value.
  `woocommerce_after_checkout_validation` passes `$data` by value, so the
  current assignment is discarded
- Add `shipping_phone` support so a recipient phone has somewhere to live
- Merge the synthetic-email mail guard into a single `pre_wp_mail` handler.
  `pre_wp_mail` fires **before** the `wp_mail` filter, so the current split
  never triggers for the case it was written for

**Acceptance**

- Fitness tests for Invariant 2 green
- A `billing_phone` that differs from the login phone survives both a profile
  save and an address-book save
- An order stores the ward **name**, not the ward code

---

## Phase 6 — Provider lifecycle

**Build**

- List linked providers (`find_by_user` exists but was never called)
- Unlink, requiring re-authentication
- **Guard: refuse to unlink the last identity that can still sign the user in**
- Show linked state in the UI instead of unconditional "link" buttons

**Acceptance**

- No sequence of unlink operations can orphan an account

---

## Phase 7 — Release preparation

**Build**

- `readme.txt`, `languages/smart-login.pot`, `CHANGELOG.md`, `LICENSE`
- README fixes: theme overrides target `form-auth.php`, not `form-login.php`
  (the shims are never loaded); the "dataset not bundled" claim contradicts a
  tracked, complete `data/`; hook list corrections
- Address REST `ETag` keyed on `filemtime( data/provinces.php )`. It currently
  uses `SMART_LOGIN_VERSION`, so regenerating the dataset does not invalidate a
  24-hour cache — exactly the case the README tells operators to expect
- Remove dead code; drive `phpcs` to zero and promote it to a required gate
- Flip identity suites from `spec` to required in `tests/run-all.php`

**Acceptance**

- Both gates in `docs/` emit their success markers
- `php tests/run-all.php --strict` green

---

## Risks

| Risk | Mitigation |
| --- | --- |
| Phase 3 rewrites five handlers — large, hard to review | One commit per handler; decision table green first |
| `dbDelta` + `UNIQUE` on `VARCHAR(191)` utf8mb4 is 764/767 bytes | Already the width in use; the idempotency test catches divergent environments early |
| Opaque `user_login` hinders admin support | Identity column + `user_search_columns` hook, both in Phase 3 |
| Fitness greps produce false positives on legitimate code | Per-file allowlist declared inside the test, forcing any exception to be justified in writing |
