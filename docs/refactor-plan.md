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
- [x] **Phase 3 — Directory and state machine**
- [x] **Phase 4 — Proof layer: OTP**
- [x] **Phase 5 — Profile boundary**
- [x] **Phase 6 — Provider lifecycle**
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

## Phase 3 — Directory and state machine ✅

**Delivered**

- `IdentityDirectory` — the only resolver. `resolve()`, `resolve_in()`,
  `resolve_any()`, `link()`, `retire()`, `replace_in_channel()`, `owner()`,
  `otp_destination()`
- `AuthAction` — the decision table as data, 16 cells, `decide()` defaulting to
  `REJECT` so a typo in an intent cannot open a door
- `AuthProof` — private constructor, three factories; `SessionIssuer::issue()`
  takes it as the mandatory first argument
- `OpaqueLogin` wired into both `wp_insert_user()` sites
- `Admin\UsersColumn` — identity column plus identity-aware user search
- **Deleted** `IdentityResolver`, and with it the `get_user_by( 'login' )`
  fallback that made the takeover possible
- Rewritten to consume the directory: `RegisterHandler`,
  `PasswordResetHandler`, `LoginHandler`, `ContactVerificationService`,
  `AccountProvisioner`; `RateLimiter` now normalises through `ChannelRegistry`

**Notes from doing the work**

- **The synthetic email was a second instance of the same defect.** It was
  `<phone>@phone.invalid` — derivable from the phone, and core resolves
  `user_email` at `authenticate` priority 20, so it was a typeable identifier
  that bypassed the directory. Worse, it was never updated on a phone change, so
  the retired number stayed reachable through the email path. The local part is
  now the account's opaque token: stable, non-derivable, never needs changing.
  README documents the old format — Phase 7 fixes that.
- `unique_login_from_email()`, `provider_login()` and
  `provider_placeholder_email()` are all deleted. Every `user_login` is opaque
  now, so there is nothing left to derive.
- **`ContactVerificationService` is where RETIRED becomes reachable.** It calls
  `replace_in_channel()`, which retires the old subject before claiming the new
  one. The pre-refactor code only overwrote a meta value, which left the old
  identifier live — the actual root cause.
- **The admin search nearly shipped a real bug.** Appending `OR ID IN (…)` to
  `WP_User_Query::$query_where` looks natural but AND binds tighter than OR, so
  `role_clause AND search_clause OR ID IN (…)` would return identity matches
  regardless of an active role or site filter. Narrowing with `include` instead
  keeps every other filter intact and needs no SQL surgery. Documented
  trade-off: an identity match replaces the name/email matches rather than
  widening them.
- **`OtpService::verified_claim()` is the PROVE boundary for OTP.** It
  deliberately does not consult `ChannelRegistry::enabled()` — a code already
  delivered must stay verifiable even if an admin disables that channel
  mid-flow.
- **`AuthProof::from_password()` trusts its caller**, unlike the other two
  factories. WordPress's own `authenticate` chain is the verifier and it returns
  a `WP_User`, not a token, so there is nothing stronger to check. The OTP and
  OAuth factories require a `VerifiedClaim`, which only the PROVE layer can
  produce.
- `authenticate_by_phone()` is now `authenticate_by_identity()`: it serves every
  self-asserted channel, not just phone.

**Acceptance — all four met**

- Fitness Invariant 1 fully green
- All 16 decision-table cells green; contract suite 38 passed / 1 failed, the
  remainder being `ProfileSeeder` from Phase 5
- `recover × RETIRED` and `login × RETIRED` both resolve to "no account", so the
  previous owner is unreachable
- `SessionIssuer::issue()` requires an `AuthProof`, and `wp_set_auth_cookie()`
  appears in no other file

Integration gate re-run after the rewrite, on WordPress 7.0.2:

```text
SMART_LOGIN_AUTH_INTEGRATION_OK
SMART_LOGIN_GOOGLE_STAGING_SMOKE_OK
SMART_LOGIN_PROVIDER_LINKING_OK
SMART_LOGIN_ZALO_STAGING_SMOKE_OK
```

---

## Phase 4 — Proof layer: OTP ✅

**Delivered**

- Six `PURPOSE_*` constants → four `INTENT_*` (`register`, `login`, `recover`,
  `add_identity`). `change_phone`, `change_email` and `verify_email` were the
  same intent applied to different channels
- OTP schema: `purpose` → `intent`, `channel` → `transport`, plus a new
  `identity_channel`; index `dest_purpose` → `dest_intent`. DB_VERSION 3 → 4
- `OTP\Channels` → `OTP\Transports`: `TransportInterface`, `TransportRouter`,
  `WebhookTransport`, `MailTransport`. "Channel" now means exactly one thing
  project-wide. Filter `smart_login_otp_channels` → `smart_login_otp_transports`
- `PasswordPolicy` extracted; `smart_login_validate_password` now runs on reset
  as well as registration
- `smart_login_phone_is_valid` reaches Vietnamese numbers
- Placeholders `{{purpose}}`/`{{channel}}` → `{{intent}}`/`{{transport}}`

**Notes from doing the work**

- **`dbDelta` cannot rename columns**, only add them, so the old NOT NULL
  `purpose` column would have survived and broken every insert.
  `Installer::recreate_renamed_tables()` drops the OTP table when the stored
  version is below 4. Safe because the table holds nothing but unexpired
  one-time codes; the worst case is a visitor mid-flow requesting a new one.
  Verified against the live database: `purpose` and `channel` are gone,
  `intent`/`identity_channel`/`transport` are present, and the old
  `dest_purpose` index no longer exists.
- **The mechanical rename introduced two real bugs, and neither test suite
  caught them.** `PendingSession` still returned the key `purpose` while the
  controllers had been switched to read `intent`, so REST verify and resend
  would have broken; and `RestController::session_for()` ended up returning
  `'intent' => $intent` with `$intent` never assigned. Both were found by
  grepping for leftovers rather than by a test. A throwaway dangling-variable
  scan over the four renamed names now reports zero, but the lesson stands:
  a token rename across a session/flow boundary needs the boundary checked
  explicitly, because no unit test crosses it.
- **Two of the project's own fitness rules produced false positives on prose.**
  A `UserManager` docblock saying "Output of `wp_hash_password()`" tripped the
  password-policy rule, and earlier a `MailChannel` docblock quoting the old
  namespace tripped the transport rule. Both patterns now anchor on call or
  statement syntax instead of a bare name. Source-scanning rules have to
  distinguish code from comments, or they train people to add allowlist entries.
- `templates/form-otp.php` was outside the rename script's file list and still
  referenced `OtpService::PURPOSE_REGISTER`. The fitness rule caught it, which is
  the argument for scanning templates as well as classes.
- `WebhookTester` still accepts a posted `channel` field as well as `transport`,
  because the admin JS posts the old name and the two rename independently.

**Acceptance — both met**

- A fictional `zns` transport is registered and exercised entirely from
  `run-core-tests.php`, touching no other file; the suite also asserts that
  exactly four intents exist, which is the property that keeps channels and
  transports independent
- `PasswordPolicy::validate()` is required by a fitness rule at every call site
  that sets a password, and the reset path now runs the filter

Integration gate green on WordPress 7.0.2 with `db_version=4`.

---

## Phase 5 — Profile boundary ✅

**Delivered**

- `ProfileSeeder` — the only writer of profile fields, with an allowlist of keys
  so a typo cannot silently create `biling_phone`
- All **14** `billing_*` write sites routed through it (the original plan said
  three; the fitness rule found fourteen)
- WooCommerce checkout: move ward-name substitution to
  `woocommerce_checkout_posted_data`, which has a return value.
  `woocommerce_after_checkout_validation` passes `$data` by value, so the
  current assignment is discarded
- Add `shipping_phone` support so a recipient phone has somewhere to live
- ~~Merge the synthetic-email mail guard into a single `pre_wp_mail` handler.~~
  **Dropped — the original claim was wrong.** Checked against the installed
  WordPress 7.0.2 source: `pluggable.php:209` fires the `wp_mail` filter, then
  `:233` fires `pre_wp_mail` with the already-filtered `$atts`, and nothing sits
  between them. So `strip_synthetic_recipients` (on `wp_mail`) empties the
  recipient list and `abort_empty_mail` (on `pre_wp_mail`) then short-circuits —
  exactly as intended. The existing split is correct and stays as it is.

**Notes from doing the work**

- **Two directions, not one.** `seed_if_empty()` is identity → profile and never
  overwrites; `set_from_user_input()` is the customer's own form and always wins.
  The address module needs the second — the customer just picked those values, so
  treating them as seeds would make the province and ward unchangeable. Collapsing
  both into one method would have traded one bug for another.
- The plan expected three offending write sites. There were **fourteen**, across
  seven files. The fitness rule found them; reading the code had not.
- **Nothing seeds `shipping_phone`**, and a core test asserts that no file outside
  `ProfileSeeder` ever tries to. That is the field the recipient's number belongs
  in, and it is the customer's alone.
- The checkout fitness rule was rewritten from "never use
  `woocommerce_after_checkout_validation`" to "if you use it, also use
  `woocommerce_checkout_posted_data`". The hook is not the problem — it is the
  only place with a `WP_Error` to add to. Depending on its by-value `$data` was.
- Two assertions I wrote in this phase were themselves wrong (an arithmetic
  expectation, and a grep for a line that had legitimately moved into the filter).
  Both were caught by running them.

**Acceptance — all three met**

- Fitness Invariant 2 green
- A `billing_phone` differing from the login phone survives seeding from every
  identity path, asserted directly in `run-core-tests.php`
- Ward substitution happens on a filter that returns the array, so the order
  stores the ward **name**

**The identity suites are now `required`, not `spec`.** They went green here,
which is earlier than the plan assumed, and leaving a passing suite non-blocking
can only hide the next regression. Note what green does and does not mean: the
two invariants hold and are enforced. Phases 6 and 7 are not encoded as rules, so
they remain genuinely outstanding.

---

## Phase 6 — Provider lifecycle ✅

**Delivered**

- `Auth\IdentityLinkService` — `linked()`, `can_unlink()`, `unlink()`,
  `unlinked_providers()`, `history()`
- **Orphan guard**: an account must keep at least one identity, with an
  explicit `smart_login_allow_orphan_unlink` filter as the only way past it
- Re-authentication by account password, checked *after* the guard
- REST: `POST identities` and `POST identities/unlink`
- Non-JS path: `unlink_identity` form action in `FormController`
- `templates/partials/linked-identities.php` — shows what is linked, masked, with
  an inline password confirmation
- The profile screen now offers only providers that are **not** linked yet
- `AuditLog::PROVIDER_UNLINKED` and `IDENTITY_RETIRED` added

**Notes from doing the work**

- **A password alone is not a way back in.** `user_login` is opaque, so an account
  with zero identities has no identifier left to type and no recovery path. That
  is why the guard refuses rather than confirms, and why the escape-hatch filter
  defaults to false.
- **The guard runs before the password check**, deliberately: prompting for a
  password on an action that cannot succeed wastes the user's time and teaches
  them to type it in response to any prompt.
- **One corner case fails closed and is documented in the code**: an account
  holding two federated identities and no contact channel cannot re-authenticate,
  because its owner never set a password. They must add a password or a contact
  first. An OTP challenge would be the natural alternative, but it would need a
  fifth intent that the decision table does not define, so the closed failure is
  the honest option for now.
- **`IdentityDirectory` and `IdentityRepository` are `final`**, which blocked the
  subclass mock the tests first reached for. Rather than weaken the design for
  testability, `tests/stubs.php` gained a minimal `$wpdb` so the real code path is
  exercised instead of a mock of it. That stub is now available to every future
  repository test.
- A new fitness rule confines `->retire()` to the repository, the directory and
  the link service, so a future feature cannot detach an identity while bypassing
  the guard.

**Acceptance — proven on WordPress 7.0.2 + MySQL, not just in unit tests**

The integration gate creates an account with two identities and then asserts:
a wrong password removes nothing; another account's identity cannot be detached
through your session; the first unlink succeeds; the second is refused with
`smart_login_last_identity`; `can_unlink()` agrees with `unlink()`; and three
further attempts do not wear the guard down. The count never reaches zero.

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
