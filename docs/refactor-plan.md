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
- [x] **Phase 7 — Release preparation**
- [x] **Phase 8 — Account surface**
- [x] **Phase 9 — Abuse boundary**
- [x] **Phase 10 — Delivery routing and the automation bus**
- [x] **Phase 11 — Mail templates**
- [x] **Phase 12 — The provider surface**
- [x] **Phase 13 — The mail surface**
- [x] **Phase 14 — The email identity**
- [x] **Phase 15 — The unreleased install**
- [x] **Phase 16 — The sign-in card**
- [x] **Phase 17 — The account card**
- [x] **Phase 18 — The rendered surface**
- [x] **P1–P6 — The backlog after Phase 18** *(see below)*
- [x] **Phase 19 — Sign-in on every page**

Phases 0–3 are the core and should run without interruption. Phases 4–7 are
independent and may be reordered or dropped.

Phase 8 is a second body of work on top of a finished refactor: the identity
model is right, the screen that exposes it is not. Its sub-phases are ordered by
risk, not by visibility — the user-facing redesign is deliberately last.

Phase 9 is a third: the identity model is right, the screen is right, and neither
counts anything across the whole site. Its ordering is not preference — three of
its sub-phases are blocked on another, and shipping them out of order converts a
security control into an outage.

Phase 10 is a fourth: everything above is right and none of it can be reached by
anything the site owner runs elsewhere. Its ordering is also not preference —
10.6 is the visible sub-phase and it is last, because the tab redesign that keeps
being asked for is a presentation of a routing model that does not exist yet.

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

## Phase 7 — Release preparation ✅

**Delivered**

- `readme.txt`, `CHANGELOG.md`, `LICENSE`, `languages/smart-login.pot`
  (445 strings, 36 translator notes)
- `bin/build-pot.php` — POT generation without wp-cli, which is not installed
  here and should not be a prerequisite for shipping translations
- README corrected on four counts: overrides target `form-auth.php`; the dataset
  **is** bundled; the placeholder email is no longer phone-derived; the webhook
  token list uses `{{intent}}` / `{{transport}}`
- Address REST `ETag` now keyed on `filemtime( data/provinces.php )`
- `tests/run-phpcs.php` wired into the aggregate runner; CI installs phpcs
- `LoginHandler::attempt()` lost its `$remember` parameter — it was never used

**phpcs: 1845 → 66 → 22, and the remainder is a written-down deferral**

The plan said "drive phpcs to zero". The honest outcome is different and worth
stating plainly.

| Step | Violations |
| --- | --- |
| First run, whole repo | 1845 |
| Scoped to shipped code (`tests/`, `scripts/` are not distributed) | 1542 |
| After `phpcbf` | 1117 |
| After excluding sniffs that do not apply, each with a reason | 66 |
| After fixing the real ones | 22 |

Every **security, correctness, database and compatibility** sniff is at zero.
What remains is documentation completeness — missing `@param` tags and class
docblocks — deferred in `phpcs.xml` with the reasoning next to it. The suite is
registered as `spec`, so it reports in full without blocking, which is exactly
the mechanism Phase 0 built for a standard the code has not met yet.

Two exclusions are decisions rather than deferrals, and are argued in the file:
`WordPress.Files.FileName` assumes `Snake_Case` class names and would rename 57
files to `class-otpservice.php` *and* require changing the autoloader; and hooks
like `wp_login` and `woocommerce_*` are deliberately unprefixed because they
belong to somebody else.

**A bug introduced and caught during this phase**

The mechanical rename of `$default` to `$fallback` desynchronised two function
signatures from their bodies. `Settings::get()` and `Flow::old()` then read a
variable that no longer existed, which PHP treats as `null` — so `php -l` passed,
and every configuration default silently became `null`. It was found by reading
the diff, not by a test, because nothing covered the fallback path.

`run-core-tests.php` now covers it. The wider lesson is recorded here because it
has now happened twice in this refactor: a blind `str_replace` across a file can
break a signature/body pair without any syntax error, and neither the linter nor
an untargeted test suite will notice.

**Acceptance**

- `php tests/run-all.php` green (spec suite reports, does not block)
- Integration gate green on WordPress 7.0.2

---

## Postscript: a fatal that four gates missed

After the merge, `/my-account/` fatalled on every load:

```
Uncaught Error: Class "SmartLogin\Identity\IdentityResolver" not found
  templates/form-auth.php:79
```

Phase 3 deleted `IdentityResolver` and cleaned up its callers in `includes/` —
but never grepped `templates/`. Five references survived across two files.

Why each gate let it through, which is the useful part:

| Gate | Why it missed |
| --- | --- |
| `php -l`, 139 files | Syntax only. PHP resolves class names at **run** time |
| Contract suite | Asserted the class was *gone*; never that nothing *referenced* it |
| 163 regression tests | Inspect template source as strings, never execute it |
| Integration gate | Exercises REST and provisioning; renders no template |

Two gates were added, and both were demonstrated to fail before the fix rather
than assumed to work:

1. **Fitness**: every `SmartLogin\…` reference — `use` statements and inline
   calls — must resolve to a file, using the autoloader's own naming rule. Run
   against the broken tree it named both files exactly.
2. **`tests/identity/run-template-tests.php`**: renders all 11 templates with
   fixtures, failing on a throw, on a PHP notice, or on empty output. Verified by
   temporarily renaming a method to one that does not exist — which the fitness
   rule *cannot* catch, since the class still exists. That gap is the reason the
   smoke test exists as well as the rule.

The general lesson, now recorded because it has bitten three times in this
refactor: rules of the form "the old thing is gone" are half a rule. The other
half is "nothing points at the old thing", and neither half is worth much for
code that no test ever executes.

## Phase 8 — Account surface

Normative spec: [`account-surface.md`](account-surface.md) — the problem, the
ownership boundary between Smart Login and WooCommerce, the section contract, the
layout, the CSS inventory and the field-level corrections all live there.

Execution briefs: [`account-surface/`](account-surface/), one file per
sub-phase. **Status lives here and only here** — the briefs carry no checkboxes,
so a sub-phase cannot be marked done in one file and open in another. That is the
same argument this tracker opens with, applied one level down.

Short version: the identity model is right and the screen exposing it is not. The
profile-status notice and the provider block each exist in two templates, one
maintained and one not, which is why the live page renders a blue box containing
the single word "Địa chỉ" and offers "link Google" to accounts that already have
Google linked.

---

### Sub-phases

- [x] **8.0** [Guard rails](account-surface/8.0-guard-rails.md) — the
      duplication rule, proven red before the duplication is removed
- [x] **8.1** [Stop the data loss](account-surface/8.1-stop-data-loss.md) — four
      live defects, JS only, ships alone. `SMART_LOGIN_AUTH_INTEGRATION_OK` on
      WordPress 7.0.2, fifteen server-side checks, three of four client checks
      measured in a browser; two unrelated gate defects found and written down
- [x] **8.2** [Section contract](account-surface/8.2-section-contract.md) — seven
      partials and a renderer; live before/after diff shows only the declared
      deltas. The Woo template is 330 lines → 65, and is now executed by a test
      for the first time
- [x] **8.3** [Owned save path](account-surface/8.3-owned-save-path.md) — profile
      editing without WooCommerce. 16 checks green with WooCommerce genuinely
      absent; the account-surface suite is now `required`, not `spec`
- [x] **8.4** [Layout](account-surface/8.4-layout.md) — the redesign. Four
      carded sections in wireframe order; the form is 680px in a 680px column
      instead of a 460px strip inside it
- [x] **Removal by request** — Mã giới thiệu and Tìm nhanh địa chỉ deleted from
      the whole codebase, including `data/search-index.php` (312 KB) and the
      build step behind it. Three fitness rules keep them gone
- [x] **8.5** [Address boundary](account-surface/8.5-address-boundary.md) — one
      picker, two hosts. Already unified where it counts; the plan's step 2
      would have been a regression and was dropped. The boundary is now
      asserted instead, and the ward select explains itself
- [x] **8.6** [Interface language](account-surface/8.6-interface-language.md) —
      **declined in writing for 1.0.1.** The plugin targets Vietnamese sites and
      Vietnamese msgids are the honest encoding of that. The delay was not free:
      the sweep grew 445 → 605 strings while the decision stayed open, which is
      the effect the brief predicted and the reason it is closed rather than
      deferred again. The `.pot` is regenerated and current

---

**Ordering rationale.** 8.1 ships alone and early because data loss outranks
everything. 8.0 precedes 8.2 because a guard rail proven after the fact proves
nothing. 8.2 changes no output, so it can be reviewed on behaviour rather than
appearance. 8.3 is what makes the plugin whole without WooCommerce, and must land
before 8.4 so the redesign has somewhere to live other than the Woo page. 8.5 and
8.6 are independent and may be reordered or dropped.

---

## Phase 9 — Abuse boundary

Normative spec: [`abuse-boundary.md`](abuse-boundary.md) — the single-axis
problem, the budget/breaker distinction, the fail-open/fail-closed asymmetry, the
ownership boundary and the defaults table all live there.

Execution briefs: [`abuse-boundary/`](abuse-boundary/), one file per sub-phase.
**Status lives here and only here.**

Short version: every control the plugin has is scoped to one destination or one
IP, so an attacker rotating both meets no ceiling. `handle_identify()` sends an
SMS to any number on earth with no account and no challenge; `Phone::is_valid()`
accepts any country code outside `84`; and the enumeration branch passes through
no limiter at all while the README says it does.

### Sub-phases

- [x] **9.0** [Guard rails](abuse-boundary/9.0-guard-rails.md) — landed red as
      intended: `4 passed, 11 failed, 0 pending`, no production file touched.
      Needed two harness changes first — a `token_get_all()` method-body
      extractor for the ordering and per-callback rules, and a real filter
      registry in the stubs — the latter proven byte-identical across the other
      eight suites. Rule 6 shows **1 of 11** REST callbacks reaching the guard
- [x] **9.1** [Site budget](abuse-boundary/9.1-site-budget.md) — the missing
      axis, plus the phase's single DB version bump (4 → 5, `KEY created_at`).
      Kill switch is an option not a transient, and the halted path sheds load:
      one option read instead of three counting queries. Behaviour pinned in the
      **required** suite rather than the spec one, so it blocks now — 201 → 217
- [x] **9.2** [Country allowlist](abuse-boundary/9.2-country-allowlist.md) —
      cheapest control in the phase, removes most of the pumping incentive. An
      empty setting means *the default code only*, so no migration. The Phase 4
      "exactly one return path through the filter" rule caught the first version
      of this change adding a second exit — fixed by structure, not by weakening
      the rule. 217 → 226
- [x] **9.3** [Delivery limits](abuse-boundary/9.3-delivery-limits.md) — clamped
      timeout, real backoff, circuit breaker; queueing rejected with reasons.
      Breaker landed in `TransportRouter`, not `WebhookTransport`, so it covers
      SMTP too and leaves the admin's "Gửi thử" button outside it. Half-open is a
      count, not a flag, so concurrent probes cannot race. Rule 3c had to be
      rewritten: it was pinned to one spelling of the clamp. 226 → 238
- [x] **9.4** [Identify limit](abuse-boundary/9.4-identify-limit.md) — closes the
      enumeration oracle and makes the README true. The future-proofing half of
      rule 4 found a **second** free oracle: `PasswordResetHandler::start()`
      resolves and returns without issuing a code when the subject is unknown, so
      it never reached any limiter. Both doors now spend one budget. 238 → 245.
      **The four release blockers are done.**
- [x] **9.5** [Trusted proxy](abuse-boundary/9.5-trusted-proxy.md) — CIDR
      allowlist, not a boolean; readiness fails on the spoofable configuration.
      **Reversed a decision the brief had made**: `smart_login_trust_proxy_headers`
      no longer grants trust on its own, because an escape hatch that reopens the
      hole is not one. Managed deployments pair it with the new
      `smart_login_trusted_proxy_cidrs`. 245 → 266, the phase's largest jump —
      CIDR parsing is where the sharp edges are
- [x] **9.6** [Login IP ceiling](abuse-boundary/9.6-login-ip-ceiling.md) —
      password spraying. Needed a **new** guard rail first: 9.0's eight rules
      covered 9.1–9.9 but not this. A success clears the account counter and
      deliberately **not** the address one, since one hit among a thousand
      guesses is what a successful spray looks like. 266 → 272
- [x] **9.7** [REST guard parity](abuse-boundary/9.7-rest-guard-parity.md) — the
      JS sent no stamp, so the existing check was inert rather than lax. One
      shared gate in `check_permission()` rather than eleven copies, and rule 6
      rewritten to check reachability instead of repetition. **The suite went
      fully green here and is now `required`.** 280 → 285
- [x] **9.8** [Adaptive captcha](abuse-boundary/9.8-adaptive-captcha.md) —
      invisible under normal load, and invisible means **no third-party script
      registered**, not merely not shown. The provider secret path was extracted
      into `Security\SecretBox` rather than duplicated, keeping the stored record
      shape byte-identical so existing provider secrets still open. A new
      `secret` field type makes the next one a registry row. 285 → 301
- [x] **9.9** [Audit and visibility](abuse-boundary/9.9-audit-and-visibility.md) —
      write cap, consumption on the readiness row, resume button, and the live
      retention bug fixed. Corrected 9.1's own claim: a halted site was still
      writing one audit row per blocked request, so the cap is the other half of
      the kill switch rather than polish beside it. Full dashboard deliberately
      not built — see the Outcome. 272 → 280
- [x] **9.10** [Housekeeping](abuse-boundary/9.10-housekeeping.md) — the cache
      premise held when measured, so `/address/*` now answers 304 (0 bytes
      instead of 7 KB on a `wards` replay), verified over HTTP against the live
      site. The shim templates **stay**: they forward to `form-auth`, so they are
      compatibility surface rather than dead code — the opposite of what the
      first review of them concluded

**Integration gate.** `tests/integration/run-abuse-gate.php` covers what the stub
`$wpdb` cannot: the DB 5 index under `dbDelta`, `count_recent_all()` as real SQL,
the halt option round trip, the readiness rows, and `in_cidr()` on the runtime's
own PHP. Wired into `scripts/run-auth-integration-gate.ps1`. Green on WordPress
7.0.2 / PHP 8.2.29 as of 9.5.

---

**Ordering rationale.** 9.0 first, for the reason the Postscript below gives.
9.1–9.4 are the release blockers for any site sending paid SMS, and 9.2 is
sequenced early inside that group because it is an afternoon's work that removes
most of the attacker's payoff. **9.5 must precede 9.6**: a per-IP login ceiling on
a site behind a CDN with proxy trust off locks out every visitor sharing an edge
address, so shipping 9.6 first is an outage, not a control. **9.8 is blocked on
9.1 and 9.3** — it reads budget pressure and breaker state, and its own outbound
call must inherit 9.3's timeout discipline. 9.9 and 9.10 are independent and may
be reordered or dropped.

**9.1 owns the only `SMART_LOGIN_DB_VERSION` bump.** Anything else wanting a
schema change folds into it rather than bumping again.

---

## Phase 10 — Delivery routing and the automation bus

Normative spec: [`delivery-routing.md`](delivery-routing.md) — the routing
decision, the two roles of one endpoint, the signed envelope, the security
position and the ownership boundary all live there.

Execution briefs: [`delivery-routing/`](delivery-routing/), one file per
sub-phase. **Status lives here and only here.**

Short version: one line decides how every code travels —
`( false !== strpos( $destination, '@' ) ) ? 'email' : 'sms'`
(`class-transport-router.php:41`). The shape of the destination is the whole
routing policy, so a site cannot point either channel at an automation platform,
and the `smart_login_otp_transports` filter registers transports that nothing
will ever route to. Separately, nineteen audit event constants exist and none of
them leaves the site; `smart_login_otp_sent` fires without the code, so external
automation can neither send an OTP nor react to one.

### Sub-phases

- [x] **10.0** [Guard rails](delivery-routing/10.0-guard-rails.md) — landed red
      as intended: `3 passed, 3 failed, 6 pending`, no production file touched,
      every other suite at its previous count. The brief predicted rule 5 would
      *pass*; it would have passed for want of a subject, so it became PENDING —
      a rule that passes because the thing it checks does not exist states the
      opposite of the truth. Rule 1 needed splitting in two: the allowlist form
      cannot fail on the routing authority itself, so a direct assertion on
      `transport_for()` sits beside it. **Corrected 10.2 before executing it** —
      there are already two secret stores and neither is keyed by field path, so
      that brief's "no migration" was false
- [x] **10.1** [Routing table](delivery-routing/10.1-routing-table.md) — splits
      `transport_for()` into `channel_for()` plus a table lookup, with the
      fallback beside the setting it backs. Shipped invisible: every required
      suite came back at its previous count, and the defaults reproducing the old
      `'@'` answer is asserted directly rather than inferred. Two rules from
      different phases constrained it from both sides — the abuse suite forbids
      reading an undeclared key by literal, and this suite's own rule 1 would
      have gone red on the `ROUTES` constant because it was pinned to a spelling.
      Rule 1 is now behavioural, for the reason 9.3 had to rewrite rule 3c.
      11 → 17
- [x] **10.2** [Generic secret storage](delivery-routing/10.2-secret-storage.md)
      — one option keyed by registry path, following `ProviderCredentials`'
      shape; the legacy location is a constant map consulted by the reader, so
      `store_secret()` stays branch-free and rule 2 stays satisfiable. Clearing
      had to reach the pre-10.2 copy or the secret **came back on the next read**.
      The fallback test passed while asserting nothing until the new location was
      emptied first — the exact failure the brief was rewritten to prevent,
      reproduced by the test written to prevent it. The rename sweep found
      `uninstall.php` orphaning the captcha secret (fixed) and gating everything
      on a flat key (recorded, not fixed). **Suite promoted to `required`:**
      17 → 22 passed, 0 failed
- [x] **10.3** [Automation transport](delivery-routing/10.3-automation-transport.md)
      — the signed envelope, HTTPS refused at save, its own breaker for free from
      the router. **The sub-phase that lets the plaintext code leave the site**;
      the spec's security section is the argument and the brief is the controls.
      Committed first with this row **unticked**: the gate was written but the
      Local site was stopped, and BLOCKED is not a pass. Ticked only once
      `SMART_LOGIN_DELIVERY_GATE_OK` came back — along with the other five
      markers, since 10.1, 10.2, 10.3 and 10.7 all touch code the earlier gates
      exercise. 10.0's rule 4 was wrong: it named the signer as the sender, when
      the signer signs and sends nothing. Structural half is now "one sender";
      the half that matters is asserted on the bytes `WP_Http` would really
      transmit, reached through `pre_http_request`. 22 → 35
- [x] **10.4** [Event bus](delivery-routing/10.4-event-bus.md) — non-blocking
      fan-out hooked at the single `AuditLog::record()` funnel, so a new event
      constant is busable without a second edit. Off by default; never carries
      the code — asserted with `array_key_exists`, since a masked one is still a
      field a receiver could come to depend on; second breaker, which is the
      whole point. **Rule 4 forced the sender out into `AutomationEndpoint`**:
      the bus needed to post a signed envelope and a second `wp_remote_request()`
      would have been a second place to forget the signature. Second time a
      structural rule was right about the shape and wrong about the file, and
      both times the code moved rather than the rule. Shares the audit log's
      hourly cap on purpose — an HTTP call costs more than an `INSERT`, so a bus
      below the cap would rebuild 9.9's amplification defect and aim it at
      someone else's server; the cost is that disabling the audit log disables
      the bus, which is in the help text. 35 → 45, **no PENDING left**
- [x] **10.5** [Readiness and cost](delivery-routing/10.5-readiness-and-cost.md)
      — readiness asks the router which transport serves each enabled channel
      instead of constructing the two it used to assume, and the spend estimate
      counts by identity channel instead of `transport = 'sms'`, which read zero
      the moment a site routed phone at automation while the messages and the
      bill kept going. **The brief planned to compensate at read time and that
      was the wrong end**: `OtpService` was storing an empty `identity_channel`
      whenever no handler passed a claim, so it now derives one at insert and the
      counter is a plain `WHERE`. `MailTransport::is_available()` deliberately
      **not** tightened — the router consults it to decide whether to attempt a
      send, so a stricter version would refuse mail on hosts where `wp_mail()`
      works; the dishonesty was readiness treating it as proof, which is now a
      WARN naming what can actually be stood behind. Phase 9's rule 8 caught this
      sub-phase reading a key built by concatenation. 61 → 66 admin, 45 → 46
      delivery
- [x] **10.6** [Delivery tab](delivery-routing/10.6-delivery-tab.md) — the
      original request, and last because a screen can only present a routing
      model that exists. Twenty-eight controls on one page became four screens
      of 9 / 13 / 6 / 7, each a real tab slug because saving is tab-scoped;
      `tabs()` stays flat and `tab_parents()` carries the hierarchy, since
      nesting the registry would teach `posted_fields()` about depth for no
      gain. Three existing assertions had to be rewritten and two came out
      stronger — the credential-leak rule now checks the three siblings it used
      to exempt, and the tab-strip rule renders the navigation once per tab
      instead of once in total. **The gate passed while covering less**: it
      rendered only `delivery`, which after the move claims nine fields instead
      of thirty-five, so it now renders all four. 301 → 316 regression, 66 → 93
      admin. Appearance is not asserted — no wp-admin session, and one was not
      created
- [x] **10.7** [Consume ordering and the worker-hold ceiling](delivery-routing/10.7-consume-ordering.md)
      — **numbered last, sequenced first**, the way 9.10 landed before 9.8. Two
      defects already in the tree, neither about routing, both made worse by
      10.3 adding a transport the site does not operate. `consume_open_codes()`
      ran *before* the send, so a gateway failure destroyed a code the user was
      holding and the screen then told them it had already been used — the red
      run printed the whole defect as `consume → insert → delete`. And
      `wp_mail()` was uncapped at PHPMailer's 300s while the HTTP send has been
      capped at 15s since 9.3, which the breaker does not cover because it
      bounds frequency, not duration. Needed `add_action`/`remove_action` in the
      stub registry, which surfaced a redeclaration in `admin-stubs.php` — the
      same removal 9.0 made there for `add_filter`. 3 → 11 in the delivery
      suite; every required suite unchanged, checked against a worktree of
      `83ac1e4`

---

**Ordering rationale.** 10.0 first, for the reason the Postscript gives. **10.7
runs second, despite its number** — it repairs two defects that are in the tree
today and that 10.3 makes worse by adding a third transport; sub-phase numbers
here are allocation order, not execution order, as 9.10 landing before 9.8
already established. **10.2 must precede 10.3** — 10.3 declares the plugin's second `secret` field, and
against today's code that field would lose its value with no error anywhere.
**10.1 must precede 10.3**: routing has to exist before there is anywhere for a
new transport to be routed from, and keeping them apart is what lets 10.1 be
verified as a no-op. **10.5 must not precede 10.3**, since the defects it repairs
are introduced by it. 10.4 is independent of 10.5 and 10.6 and may be dropped
without affecting them.

**10.6 is last on purpose.** It is the visible sub-phase and the one that was
asked for first. Every earlier attempt to design that tab was an attempt to
design the routing model, which is why the answer kept coming out as more
headings on the same page.

**No `SMART_LOGIN_DB_VERSION` bump.** Nothing in this phase changes the schema;
10.5 reuses the `identity_channel` column already present, with the
derive-when-empty fallback `OtpService:336-345` established.

**Phase 11 has its own spec now** — see below. It was recorded here from 10.2
onwards as "email template groups, not started".

---

## Phase 11 — Mail templates

Normative spec: [`mail-templates.md`](mail-templates.md) — the three kinds of
mail, the per-intent keying, the token-scoping argument and what is deliberately
excluded all live there.

Execution briefs: [`mail-templates/`](mail-templates/), one file per sub-phase.
**Status lives here and only here.**

Short version: the plugin sends three kinds of mail and can template one of
them. `email.subject` / `email.body` serves all four intents, so a password
reset arrives worded identically to a login code; `{{intent}}` can be
interpolated but never branched on. The two admin alerts
(`class-rate-limiter.php:267`, `class-circuit-breaker.php:163`) compose their own
text inline and cannot be reworded, redirected or switched off. And there is no
layout at all — with `email.is_html` on, the body *is* the whole document.

### Sub-phases

- [x] **11.0** [Guard rails](mail-templates/11.0-guard-rails.md) — landed red as
      intended: `2 passed, 2 failed, 7 pending`, no production file touched,
      every other suite at its previous count. **The brief was wrong twice in
      one paragraph, in opposite directions**: rule 3 would have passed
      vacuously and became PENDING, and rule 6 was listed as PENDING when it is
      assertable today — a rule that arrives with the feature it guards cannot
      catch that feature breaking it. Rule 1 could not be a regex: two files name
      `wp_mail()` inside strings where naming it is the whole point, so it
      tokenises and finds real call sites instead of text
- [x] **11.1** [Template registry](mail-templates/11.1-template-registry.md) —
      one row per message, fields generated from it, resolution in one place.
      Overrides default to **empty**, because pre-filling every box would kill
      the fallback and turn one wording into five copies to maintain. **The
      brief's fallback order could not work**: `email.subject` ships with a
      non-empty default, so the shared level always matched and no per-message
      default was ever reachable — the whole sub-phase would have shipped as a
      no-op with its tests passing, since rule 2 only asks that a message
      resolves to *something*. The middle level now compares against the field's
      registry default, and each of the three is asserted separately. Found by
      asserting behaviour, not plumbing. 2 → 18; regression 317 → 322 and admin
      95 → 101, because generated fields are fields
- [x] **11.2** [HTML layout](mail-templates/11.2-html-layout.md) — table-based
      and inline-styled on purpose, because Outlook ignores `<style>` blocks;
      wraps once, theme-overridable, leaves plain text byte-identical. **The
      brief missed what made HTML useless**: the shipped bodies are plain text
      whose blank lines are their only structure, so wrapping them unchanged
      renders one run-on paragraph in a nice frame — which is what turning HTML
      on already did. The accent colour is validated against a hex pattern, not
      escaped, because it lands in a `style` attribute where
      `red;background:url(…)` survives `esc_attr()` intact. The template suite
      caught the new file having neither a fixture nor a written exclusion, and
      got a decision rather than an exemption. 28 → 35, **no PENDING left**
- [x] **11.3** [Admin alerts](mail-templates/11.3-admin-alerts.md) — the two
      hard-coded messages join the registry and gain an off switch. The reason
      off is allowed is 10.4: both events already reach an automation endpoint
      through the bus, so a configured site received each twice and could silence
      neither. `Mailer` is the second and last sender — `MailTransport` could
      have taken them and it would have been worse, since it is routed, breaker-
      guarded and answerable for delivery, and the breaker is what sends one of
      them. Switching a mail off leaves the **audit record** written, asserted:
      the log is evidence, the mail is a notification. 18 → 28, rule 1 green
- [x] **11.4** [Mail screen](mail-templates/11.4-mail-screen.md) — a second-level
      tab under Gửi mã, grouped the way an administrator thinks rather than the
      way the registry stores. **The empty box was the whole problem**: eight
      blank overrides look like eight emails with no subject, and the first
      administrator to tidy that pastes the default into all of them and kills
      the inheritance 11.1 built. An empty box now shows what it will actually
      send, resolved through the same call the transport makes. Token scoping is
      asserted by *counting* — `{{ceiling}}` present for the budget alert and
      absent beside the four OTP bodies. **The gate got weaker the same way as in
      10.6**: it enumerated four screens and there are five, so it would have
      gone on passing while ignoring the one screen whose fields are generated.
      101 → 110 admin

---

**Ordering rationale.** 11.0 first. **11.1 before everything else**, since it is
the model the other three present, extend and edit. 11.2 and 11.3 are
independent of each other and either may be dropped. **11.4 is last** and is the
visible one — the same trap as 10.6, where every attempt to design the screen was
really an attempt to design the model.

**No schema change.** Every new value is a registry path in the existing option.

**Deliberately excluded: a login-alert email.** 10.4 delivers it better —
`AuditLog::LOGIN_SUCCESS` is one tick from an automation endpoint that can mail,
message or ticket it, with the site owner choosing wording, channel and
recipient. A login-alert mail here would mean the plugin growing recipients,
throttling and a "was this you" flow, none of which is a template. A decision,
not an oversight.

**Phase 12, not started:** the provider surface and the shared channel card.
Split out of Phase 10 on purpose — it touches the same admin screens but has
nothing to do with delivery routing, and folding it in would dilute an otherwise
single-subject phase. Four items, from a wireframe review:

1. ~~**A defect, not a redesign.**~~ **Done, ahead of the rest of the phase.**
   The badge read `ProviderCredentials::is_configured()` — credentials only —
   while what decides whether a provider runs is `is_available()` =
   `enabled && is_configured`, so a provider with credentials saved and
   `Kích hoạt` left off showed a green **Sẵn sàng** with no button anywhere.

   It asks `ProviderRegistry::available()` now rather than recomputing the
   condition, and there are three states instead of two: the one it could not
   express was *configured but switched off*, which is the one an administrator
   actually hits. The guard rail asserts agreement with `available()` across all
   four combinations rather than matching a string, so the badge cannot drift
   from the runtime whatever it says. Phase 9's rule 8 caught the fix reading a
   key built by concatenation.
2. Master toggle into the card header; `providers.auto_link_email` above the grid
   where its scope is visible, not below it as a trailing form row.
3. Promote `ProviderCards` to a reusable channel card. **If Phase 12 is
   reordered before 10.6, this item must lead it** — 10.6 builds four screens on
   that component, and building them first means writing the card twice.
**Fixed after Phase 10 closed, in its own commit with its own guard rail.** The
gate now reads `advanced.delete_data_on_uninstall`, and `tests/run-tests.php`
asserts that every `$smart_login_settings[...]` subscript chain in `uninstall.php`
names a path `FieldRegistry` declares — the abuse suite's rule 8 could never see
this file, because it scans `Settings::get()` calls and `uninstall.php` runs
without the plugin loaded.

The first attempt kept a fallback to the flat key and the new rule flagged it
immediately. Correct: a rule cannot tell a deliberate legacy read from the typo
it exists to catch, and the fallback protected against nothing anyway, since
`Installer::maybe_upgrade()` migrates the shape on every load long before an
uninstall could run.

**Behaviour change worth naming:** an install with that box ticked now actually
loses its tables, options and user meta on uninstall. That is what the setting
has always claimed to do.

Original finding, kept for the record:

**Found in 10.2, deliberately not fixed there:** `uninstall.php:12` gated the
whole routine on `$smart_login_settings['delete_data_on_uninstall']` — a **flat**
key. The setting has been `advanced.delete_data_on_uninstall` since the settings
rewrite, and `Installer::migrate_flat_keys()` lists that exact pair
(`class-installer.php:149`), so the stored option is nested and the gate reads
`null` on every install that has ever been migrated. The opt-in therefore never
opens: an administrator who ticks *Xoá dữ liệu khi gỡ* gets nothing deleted.

Same defect family as the one CLAUDE.md already records for
`Installer::cleanup()`'s flat retention keys — that instance was fixed and this
one was not. Left out of 10.2 because it is not about secret storage and because
making an uninstall routine actually destroy data deserves its own guard rail and
its own commit, not a line inside someone else's.

4. A `Kiểm tra` tab that runs a real OAuth round trip through
   `OAuthTransactionStore` and reports the provider's own error. The only item
   here needing genuinely new code, and the reason the phase is separate: a
   redirect URI cannot be verified remotely, so the only honest test is the real
   one.

**Phase 12 has its own spec now** — see below. The four items above are what the
wireframe review produced; item 1 shipped early, item 3 is not being built, and
the reasons are recorded in the spec rather than left as a silent omission.

---

## Phase 12 — The provider surface

Normative spec: [`provider-surface.md`](provider-surface.md) — where the two
controls belong, why the connection test has to be a real round trip, and why the
shared channel card is not being built.

Execution briefs: [`provider-surface/`](provider-surface/), one file per
sub-phase. **Status lives here and only here.**

### Sub-phases

- [x] **12.0** [Guard rails](provider-surface/12.0-guard-rails.md) — three rules,
      landed red, into the admin suite rather than a fourth suite of its own.
      Rule 3 must report PENDING rather than passing for want of a subject: the
      mistake 10.0 made and 11.0 repeated
- [x] **12.1** [Card layout](provider-surface/12.1-card-layout.md) — the master
      switch moves beside the badge that reports on it; `auto_link_email` moves
      above the grid it governs. Presentation only, so the acceptance is that
      **no count moves** except the two assertions it turns green. The hidden
      companion input travels with the checkbox, or a provider can never be
      switched *off*
- [x] **12.2** [Connection test](provider-surface/12.2-connection-test.md) — a
      third transaction mode that stops after the exchange. **The whole design is
      one sentence staying true**: a test round trip must never issue a session,
      create a user or link an identity. `callback()` reaches
      `SessionIssuer::issue()` today, so a diagnostic reusing that path would sign
      the administrator in and provision an account, and nobody would notice
      because signing in successfully is what success looks like

---

**Item 3 is not being built, and this is the reason.** The wireframe proposed
promoting `ProviderCards` into a shared channel card because 10.6 was going to
build four screens on it. **10.6 shipped without it** — the five delivery screens
are `form-table`s, qualified against a real WordPress and merged — so the premise
expired. Building it now would mean retrofitting five working screens onto a
component with no defect driving it and no second consumer asking for it;
`sl-provider-card` still has exactly one caller. A shared abstraction with one
caller is a rename with extra steps. Reversible the day a second surface needs a
card with a status to tell the truth about.

---

## Phase 13 — The mail surface

Normative spec: [`mail-surface.md`](mail-surface.md) — why show/hide is correct here
and was not in 10.6, why copy-to-edit needs a way back, and why the layout gains
tokens rather than settings.

Execution briefs: [`mail-surface/`](mail-surface/), one file per sub-phase.
**Status lives here and only here.**

Short version: Phase 11 gave every message a template and a layout to live in,
and put all twenty resulting fields on one screen — six of them 8-row textareas.
That is the wall Phase 10 removed from the delivery tab, rebuilt one phase later
on a different screen. Meanwhile the layout has no preheader, no button, and
renders the six digits an OTP mail exists for as running text mid-paragraph.

### Sub-phases

- [x] **13.0** [Guard rails](mail-surface/13.0-guard-rails.md) — landed red at 39 passed / 2 failed. The brief predicted three failures and got two: rule 2 passes today, because the flat form-table already renders every input, so it is a property to **preserve** rather than reach. `sl_capture()` moved into the harness, since two suites now render screens. Four rules.
      Rule 4 passes today and must keep passing, which is why it lands now
      rather than with 13.3: a rule arriving alongside the feature it guards
      cannot catch that feature breaking it
- [x] **13.1** [Message list](mail-surface/13.1-message-list.md) — twenty
      fields in one column became a six-row table with one editor open. The
      alerts lost their section heading and joined the list, so 11.4's heading
      assertion was updated **and** a new one added: dropping a heading must
      not drop the messages under it. The state column is asserted by flipping
      it, because state that only ever reads one way could be hard-coded and no
      test would notice. 121 → 123 admin, 39 → 43 mail. A generated
      table with an inheritance column, one panel open at a time. **Every panel
      still renders**; rendering only the open one is the obvious optimisation
      and would silently stop five messages being saved
- [x] **13.2** [Copy and revert](mail-surface/13.2-copy-and-revert.md) — the
      cheap half of the rule is counting copy against revert; the half that
      could go wrong silently is **which** text is copied. The button carries
      `MailRegistry::resolve()`, not the row default — identical until the
      shared pair has been edited, which is exactly the state an administrator
      reaching for this button is already in. Asserted in both directions.
      43 → 49 mail, no failures left. Originally: the
      copy button undoes 11.4 on its own, so it ships as a pair and the second
      half is not optional
- [x] **13.3** [Layout and tokens](mail-surface/13.3-layout-and-tokens.md) —
      expansion runs **after** the placeholders, so a URL written with
      `{{site_url}}` inside a button token is already substituted and nothing
      has to parse nested braces. The prettier markup would have been the
      broken one: a span per digit copies as `4 8 2 9 1 3` on a phone, which
      defeats the block's only purpose, so it is one run with `letter-spacing`
      and an assertion that fails on the change that looks like an
      improvement. 49 → 56 mail. Originally:
      `{{code_block}}`, `{{button:url|label}}`, a preheader on the registry row,
      type and dark mode. Both tokens opt-in, and the shipped bodies are
      deliberately **not** rewritten to use them

---

**Ordering rationale.** 13.0 first. 13.1 before 13.2, because the buttons live
in the panels the list opens. 13.3 is independent of both and may be dropped.

**Scope decided with the user:** a better default layout plus two tokens, not
eight more appearance settings and not a layout picker. Most combinations of
eight appearance settings look worse than the default, and a picker is three
templates to maintain for a choice made once.

## Phase 14 — The email identity

Normative spec: [`email-identity.md`](email-identity.md) — the two stores holding
one fact, the per-provider decision, the one-writer argument and the predicate that
must not be re-derived from `user_email` all live there.

Execution briefs: [`email-identity/`](email-identity/), one file per sub-phase.
**Status lives here and only here.**

Short version: a provider login writes its verified address into
`wp_users.user_email` and links only the federated row, so *"this account owns this
address"* is stored in two places that disagree — and the disagreement is
observable. Typing that address the next day gets three different answers: the
identify screen spends a registration OTP and then refuses with *"Tài khoản đã tồn
tại"*, forgot-password says the address was never registered, and `wp-login.php`
correctly finds the account and asks for a password that is a 64-character string
its holder has never seen. `link()` already documents the gap and defers it to
"Phase 3" (`class-account-provisioner.php:194-198`); Phase 3 did not, and this is
that phase.

### Sub-phases

- [x] **14.0** [Guard rails](email-identity/14.0-guard-rails.md) — landed red at
      `41 passed, 2 failed, 1 pending`, no production file touched, every other
      suite byte-identical against a stash of `HEAD`. **The brief was wrong about
      where the rules belong**: it put all three doors in the integration gate, but
      the expensive symptom is assertable against the stub and now runs on every
      commit. The rule also went green for the wrong reason first — the stub leaves
      the email channel disabled, so the refusal it caught was
      *"Số điện thoại không hợp lệ"* — the third time this project has recorded a
      rule passing for want of a subject, and the first time it was caught in the
      same sitting. `actual: 5` is the keeper: five writes have already happened
      before the flow reaches the wall. Ticked only once the two gate doors had actually
      run — they were written unrun for want of a WordPress, and when one arrived they
      turned out to be **vacuous**, which 14.4's row records
- [x] **14.1** [Owned-email OTP](email-identity/14.1-owned-email-otp.md) — refuse at
      step one instead of step three, and the gate doors stay red on purpose so the
      guard cannot be mistaken for the cure. 42 → 44, both halves green.
      **14.0's second rule was measuring the wrong thing**: it forbade *any* write and
      the guard legitimately writes an audit row, so it now counts inserts into the
      OTP table — the harm is a code being spent, not a record being kept. Refined in
      both directions rather than assumed, by stashing `includes/`. The guard refuses
      exactly the set `create_verified_user()` already refused at `:116`, which is
      what makes it safe on every signup's happy path, and it **outlives its cause**:
      after 14.4 the state it detects stops being producible and it becomes the only
      thing watching for the two stores drifting apart again. `REGISTER_REFUSED` is
      deliberately sampleable, and 10.4's bus fans it out
- [x] **14.2** [One writer](email-identity/14.2-one-writer.md) — three sites wrote
      three different subsets of one fact; `UserManager::adopt_verified_email()` is
      the only one able to now. Invisible: every other suite at its previous count,
      44 → 48 in the contract suite. Takes a `VerifiedClaim`, so the type is the
      gate. **The docblock claimed the write order was asserted, so it had to be** —
      the directory write can lose a race and `user_email` must not have moved when
      it does; proven able to fail by reversing the two blocks before committing the
      correct one. Returns `true|WP_Error` rather than the brief's `bool`, to keep
      `wp_update_user()`'s own error from flattening. Rule 2 deliberately does
      **not** catch `AccountProvisioner:171`, whose partial write is the phase's
      defect — left to the gate doors and to 14.4, recorded so the gap is a decision.
      Sweep found one stale claim: `create_verified_user()` says README documents
      these meta keys as a public contract, and it does not
- [x] **14.3** [Password step](email-identity/14.3-password-step.md) — a way forward
      for somebody with no password to type. Offered unconditionally: this is where a
      "has set a password" marker would have been spent, and it is not worth a second
      source of truth. 91 → 96 templates, proven able to fail by stashing the
      template. **No controller code was needed**: `handle_forgot()` already reads
      `$post['identity']`, so the screen posts the existing `forgot` action with the
      identifier it holds — no new intent, no new grant, and nothing new to meter,
      because nothing new sends. **The brief's label promised a login the flow does
      not deliver** — reset ends by asking the visitor to sign in with the new
      password, so the shipped wording says that. The old *Quên mật khẩu?* link was
      replaced rather than joined; it asked for an identifier that had just been
      typed. Found on the way past: the `.pot` is stale by 76 strings since Phase 8.6,
      regenerated in its own commit rather than buried in this one
- [x] **14.4** [Provider email row](email-identity/14.4-provider-email-row.md) —
      the sub-phase that makes the three doors agree, verified on WordPress 7.0.2 with
      all six gate markers green. Contract 48 → 50, abuse 28 → 30. **Both doors were
      vacuous and the gate passing is what exposed it**: an email row left by an earlier
      run pointed at a deleted user, resolved KNOWN, and satisfied the decision whether
      or not the code did anything — proven by reverting the provisioner and watching
      the gate pass anyway. The doors now pin the owner id to the account that run
      provisioned, the email rows join the gate's cleanup, and the sequence that counts
      is red-without / green-with on a clean table. An integration assertion against a
      shared database is green-by-default unless it names the thing it just made. **Rule 8 caught the first version**, a concatenated settings path,
      for the fourth time it has caught a sub-phase; fixed with a literal map, and
      rule **8b** added because the map opens a hole in the rule that just caught me.
      The Zalo hypothesis resolved from code rather than a live response and is
      stronger than a guess: `ZaloProvider` reads `email_verified` from a field the
      Graph profile is not documented to send, so the condition cannot be met there
      whatever the flag says. Adoption is non-fatal by design, and the
      `auto_link_email` branch adopts too. One lint failure on a generated data file
      did not reproduce across four runs or under `php -l` — environmental, chased
      rather than dismissed
- [x] **14.7** [Release on delete](email-identity/14.7-release-on-delete.md) —
      **numbered last, sequenced before 14.5**, the way 10.7 ran second. `wp_delete_user()`
      left identity rows live: the subject stayed claimed by an account that no longer
      existed, so `create_verified_user()` refused that number or address as already
      registered **for ever**, while login failed closed — a denial, not a takeover,
      which is why it survived eleven phases of green suites.
      `IdentityRepository::retire_all_for_user()` has existed since Phase 2 with a
      default reason of literally `'user_deleted'`; it was written for this and never
      wired up, its only callers two teardown lines in a gate. **The defect was a
      missing caller, not a wrong one**, so the rules ask whether anything calls it —
      a fourth variant of Phase 7's "the old thing is gone is half a rule". Found by
      running the thing: 14.4's leftover rows are what exposed it. Fitness 28 → 30,
      gate red then green on WordPress 7.0.2. Multisite `remove_user_from_blog` is
      excluded in writing

- [x] **14.5** [Backfill](email-identity/14.5-backfill.md) — green on WordPress 7.0.2
      with all six markers and `db_version=6`. **The gate found two defects before it
      would pass.** The cursor outlived the migration and nothing would have resumed it:
      batching was built for repeated passes while `maybe_upgrade()` runs one and then
      bumps the version, so any site larger than a batch would have reported success
      having done a fraction of the work — fixed by clearing the cursor on a short batch
      and by letting a surviving cursor drive another pass. Found by the assertion that
      the *upgrade path* reaches the migration, not just that the migration works: the
      same gap as 14.7, one sub-phase later. And the bump turned `run-abuse-gate.php`
      red on a pinned literal `5`; it compares against the constant now, and **the sweep
      for the old value should have preceded the bump** — a sixth instance of the failure
      CLAUDE.md records five of. On the real site it adopted two genuine Gmail accounts,
      recorded in the brief as what happened rather than what was expected. Originally:
      DB_VERSION 5 → 6 with
      **no schema change**, because `maybe_upgrade()` is the only trigger available.
      Calls the same writer rather than bespoke SQL, batched, idempotent, and it
      widens how a set of existing accounts can be reached — which is stated in the
      brief as a trade rather than implied away
- [x] **14.6** [Security section](email-identity/14.6-security-section.md) — 96 → 101
      templates, red before green, all six gate markers green. The predicate asks the
      directory and **the fixture is the case a synthetic-email predicate gets wrong**:
      the stub user's address is real, which is the Google-first shape, so the assertion
      is what stops a later change quietly taking the wrong turn the spec recorded before
      implementation began. **One reduction from the brief, deferred in writing rather
      than omitted**: the contact branch gains a sentence and not a link, because the
      plugin has no addressable URL for its own recovery screen from another page —
      `Flow::url()` appends a step to the current page, `wp_lostpassword_url()` is the
      wp-admin leak this suite already forbids, and there is no login-page setting to
      read. Making it a link is a configuration decision, not markup. One branch feeds
      both surfaces, so Phase 8.2's guarantee still holds. Originally: stop
      rendering a box that cannot be filled. `save_password()` is **unchanged**: on
      an account with a verified email, planting a password without re-auth creates a
      login route that did not exist, so a borrowed session would gain something
      rather than nothing

---

**Ordering rationale.** 14.0 first, for the reason the Postscript gives. 14.1 next
and alone, because it is the only sub-phase that helps before any model change and
it is independent of all of them.

**14.3 must precede 14.4.** Granting the identity row routes provider-first
accounts to the password step; reaching it before that step can offer an OTP trades
a false message for a true one that helps just as little. Same hard sequencing as
9.5 before 9.6, and for the same kind of reason — shipping in the other order
converts a fix into a different dead end.

**14.7 must precede 14.5**, and its number says nothing about that — 10.7 established
that these numbers are allocation order. The backfill hands email rows to a whole
population of existing accounts, and running it while `wp_delete_user()` still stranded
rows would have knowingly multiplied a defect found two hours earlier.

**14.5 must not precede 14.4**, since it migrates existing accounts into a state
14.4 defines. **14.6 is last** and is the visible one: it was the original report,
and its branch depends on what 14.4 and 14.5 make true. The trap 10.6 and 11.4 both
named applies here too — every earlier attempt to design that section was really an
attempt to decide whether an email is an identity.

**14.5 owns the only `SMART_LOGIN_DB_VERSION` bump.** Anything else wanting one
folds into it.

**Rejected, and recorded so it is not re-proposed:** a `smartlogin_password_set`
marker meta. It answers a question the directory should answer, it cannot be
reconstructed for existing accounts — a provider-first account that later verified
an email is indistinguishable by channel from one that registered with a password —
and after 14.3 nothing needs the answer. The narrow alternative to 14.4, having the
identify and recovery screens explain that an address belongs to a provider account,
is rejected in the spec: it reveals the login *method* to an anonymous visitor, a
stronger oracle than the one 9.4 metered, and not retractable once shipped.

---

## Phase 15 — The unreleased install

Normative spec: [`unreleased-install.md`](unreleased-install.md) — the decision that
this plugin upgrades from nothing, the table of what goes and what each surface
serves, and the rule the phase establishes.

Execution briefs: [`unreleased-install/`](unreleased-install/), one file per
sub-phase. **Status lives here and only here.**

Short version: this file has said since Phase 0 that the project has never run in
production and carries no migration burden — and then eleven phases wrote migration
code anyway, each for the handful of development installs that existed at the time.
Roughly 400 lines exist to carry a past no site outside this machine has had. They go,
the database is wiped, and `SMART_LOGIN_DB_VERSION` resets to `1`. From here a 1.0.x
install is **reinstallable, not upgradable**, by decision rather than by accident.

The architecture and all ten suites stay. The four defects found finishing Phase 14
were missing wiring, not wrong structure, and every one was caught by the model and
the suites around it.

### Sub-phases

- [x] **15.0** [Guard rails](unreleased-install/15.0-guard-rails.md) — landed red:
      fitness 30 → 10 failed, one rule per surface, and the install gate stopping at
      `options survived uninstall: smart_login_account_page`. **Not the leak the brief
      named** — it predicted 14.5's backfill cursor and found instead a page cache
      `AccountForm` has written since Phase 8 and `uninstall.php` never deleted, which
      is the argument for a query over a list made by the rule on its first run. It also
      found that **a fresh install prints a WordPress database error**:
      `recreate_renamed_tables()` runs `SHOW COLUMNS` on a table that does not exist,
      on every install this plugin will ever have. Two of my own assertions were wrong
      in the same direction and were fixed towards the truth: `channels.enabled` is
      declared null on purpose, and `Settings::get()` cannot tell that from an unknown
      path; and deleting the fixture user after uninstall fired 14.7's hook at dropped
      tables. Originally: an install gate
      that runs `activate()` → use → `uninstall.php` in one pass and then asserts that
      **no option, table or user meta carrying this plugin's prefixes survives** — a
      query, not a list, because a list needs keeping in step with the code. Plus one
      deletion rule per surface, red until 15.2–15.3
- [x] **15.1** [Fresh database](unreleased-install/15.1-fresh-database.md) — smaller
      than planned: 15.0's gate uninstalls to reach clean ground, so running it had
      already done the wipe. What was left was what the gate does not own — twelve
      `sl_gate_*` fixture users from runs whose cleanup predates 14.4's. Each was
      re-read and confirmed before deletion, and the three real accounts plus six manual
      test accounts were deliberately left alone. The installed copy pulled, so the site
      and the working tree stopped disagreeing about the version. Originally: wipe what
      the plugin owns on the Local site, read the counts before removing them, and let
      the gate run against empty ground. 14.4's vacuous doors are the argument
- [x] **15.2** [Delete the migrations](unreleased-install/15.2-delete-migrations.md) —
      `class-installer.php` 411 → 254 lines, fitness 30 → 36, install gate green at
      `db_version=1`. **A fresh install stopped printing a database error**:
      `recreate_renamed_tables()` ran `SHOW COLUMNS` on a table that did not exist, on
      every install this plugin would ever have had, and it was fixed by deleting the
      code rather than guarding it — the better fix, available exactly once. A **second**
      pinned version number went red two phases running, this time Phase 2's
      `>= 3` floor; it asserts a positive integer now. Phase 2's `external_identities`
      allowlist said in writing it should go once nothing carried the table, so it did.
      14.5's gate assertions went with their code and left a note pointing at the defect
      they recorded. Originally:
      five functions and the version reset. `maybe_upgrade()` **stays**, emptied: the
      mechanism is how the next schema change arrives, only its contents were about the
      past
- [x] **15.3** [Delete the legacy reads](unreleased-install/15.3-delete-legacy-reads.md)
      — fitness 36 → 40, all seven gate markers green. **The brief's one claim of
      verification was false**: it said the admin JS posts `transport` "verified by grep,
      not assumed", and the JS posted `channel` — deleting the server's acceptance would
      have quietly broken the Gửi thử button into testing the wrong transport. The
      attribute, the JS and the read were renamed together and `SMART_LOGIN_VERSION`
      bumped to 1.0.2 so a cached `admin.js` cannot post a field nothing reads. 10.2's
      pre-move secret fixture was replaced, not deleted, keeping the half still true of
      every install. **One unreproduced gate failure is recorded rather than explained
      away** — a hypothesis was tested and rejected, and what is left is a correlation
      with no mechanism. Originally: the secret fallbacks, the webhook tester's old field name, and the two shim
      templates the README already documents as unused

- [x] **15.4** [Truth pass](unreleased-install/15.4-truth-pass.md) — three false
      statements the plugin was shipping, corrected and turned into rules: a README
      naming two templates 15.3 had deleted, a `readme.txt` Stable tag behind the
      constant, and a comment claiming README documents meta keys as a public contract.
      CLAUDE.md opens on this failure having happened twice; this was the third, and the
      first the project's own change created. Fitness 40 → 44. **The catalogue rule was
      wrong twice in the same direction** — comparing whole files compared the creation
      date, comparing below the header compared source line references and announced
      itself as `689 committed, 689 produced` and still stale; it compares the sorted
      msgid set now. **The README rule pushed the docs shorter rather than being gamed**:
      a true historical sentence still named deleted files, so the sentence went to the
      changelog where it belongs. `Mail templates` promoted to `required` — green since
      13.3 and left `spec` for four phases, against the agreement's own rule. Coding
      standards is now the only `spec` suite

---

**Ordering rationale.** 15.0 first, and for once the reason is not only the Postscript:
its install gate is new coverage of a path nothing has ever exercised, and it has to
exist before the code it covers is edited. **15.1 must precede 15.2** — deleting the
migrations while the database still holds state only they can explain would leave a
site nothing can repair. 15.3 is independent of 15.2 and may be dropped.

**The rule this phase establishes:** migration code is written when there is something
to migrate, and not before. The eleven-phase habit was to write the upgrade path
alongside the change, which felt careful and was not — every one of those paths ran on
this machine and nowhere else, and 14.5's cursor defect is what untested migration code
is worth.

---

## Phase 16 — The sign-in card

Normative spec: [`sign-in-card.md`](sign-in-card.md) — the findings, the eight
decisions, the ownership boundary held over from 8.2 and the one deferral written
down where it is decided all live there.

Execution briefs: [`sign-in-card/`](sign-in-card/), one file per sub-phase.
**Status lives here and only here.**

Short version: the "Đăng nhập & liên hệ" card answers one question twice.
`IdentityLinkService::linked()` returns every identity record and has never
filtered by channel, so the account's own email prints once whole in the contact
row and once masked in the list below it — two rows a member reads as two
addresses. Phase 8 named that defect in writing and 8.4 fixed it; 14.4 and 14.5
handed an `email` row to nearly every account and it came back, through code that
was already there. The payload has carried the `federated` flag that separates
the two kinds since Phase 6 and no template has ever read it.

### Sub-phases

- [x] **16.0** [Guard rails](sign-in-card/16.0-guard-rails.md) — landed red as
      intended: `2 passed, 6 failed, 0 pending`, no file outside `tests/`
      touched. The address turned out to appear **three** times, not two — the
      third is the unlink form's hidden `subject` input, which is the address
      travelling as the subject of a retire operation. Rule 4 found a second
      component with the reported one's exposure, `.sl-combo__input`, that no
      screenshot shows. **One of my own rules never ran while looking as though
      it had**: a PHP close tag inside a `//` comment ended PHP mode, so two
      rules were echoed as HTML and the suite still exited red on a third
- [x] **16.1** [One value, one place](sign-in-card/16.1-one-value-one-place.md) —
      the list filters on `federated`; three occurrences of the address become
      one, and the third leaves with the control it belonged to. **The brief had
      the subtitle in the wrong sub-phase**: a federated-only list under "Cách
      đăng nhập của bạn" is actively false rather than merely redundant, so the
      heading changed here and its removal waited for the grid. Found on the way:
      the verified badge was claiming verification from a `user_meta` read while
      `has_contact_identity()` documents at length that only the directory knows;
      and `smart-login.js:564` had never been reachable, because the badge it
      unhides did not exist for the one case it was written for
- [x] **16.2** [A provider row a person can read](sign-in-card/16.2-provider-row.md)
      — `Google 1171••••••` names the account instead of the OIDC subject, from
      `meta_json` that has held the claims since Phase 2 with nothing reading
      them. Rule 6 arrives with the feature it guards, so it was **stashed and
      re-run red** rather than assumed. All three fallback levels asserted
      separately, for the reason 11.1 recorded; the masked-subject assertion is
      made on the markup, since `masked` stays in the payload for the REST route
- [x] **16.3** [The card's geometry](sign-in-card/16.3-card-geometry.md) — one
      grid, so the three rows finally share a label column; suite fully green and
      **promoted to `required`** here rather than left for later. Committed first
      with this row **unticked**, the way 10.3 did: the cause was a hypothesis
      inferred from a screenshot, and a guard that is correct either way is not
      the same as a cause that was measured. Ticked once it was — on the live
      site, in the active theme, the guard's `box-sizing` disabled and
      `width: 100%` restored: `border-box → content-box`, 180px → 557px, and the
      button's right edge **21px outside** the panel it confirms from. The defect
      reproduces, and the +21px reconciles with the predicted 34px once the
      panel's own 12px padding is counted. **Two acceptance checks were not run**
      — the 480px pass and the keyboard-only pass — and are written down as not
      run rather than dropped

---

**Ordering rationale.** 16.0 first, for the reason the Postscript gives.

**16.1 must precede 16.3.** 16.3 merges two grids into one; doing that before
16.1 decides which rows survive is laying out rows that are about to be deleted,
and the label column's width is a function of the rows that remain.

**16.2 must not precede 16.1** — both edit the same `linked()` payload and the
same partial, and keeping them apart is what lets 16.1's acceptance be *unchanged
suite counts*. It is otherwise independent and may be dropped; the row would stay
unreadable but not wrong.

**16.3 is last and is the visible one** — the same trap 10.6, 11.4 and 14.6 each
named. The button through the side of its panel is what was reported, and it is
the smallest of the four things wrong with that card.

**No `SMART_LOGIN_DB_VERSION` bump, and no schema change.** Every value this
phase renders is already stored; `meta_json` has held the provider claims since
Phase 2 and nothing has read them.

**No change to `unlink()`, its orphan guard or the REST routes.** The phase is
presentation plus one computed display key.

---

## Phase 17 — The account card

Normative spec: [`account-card.md`](account-card.md) — the eight findings, the
eight decisions, the four deferrals and the one stated cost all live there.

Execution briefs: [`account-card/`](account-card/), one file per sub-phase.
**Status lives here and only here.**

Short version: Phase 16 fixed what one card *said*. This phase is about what the
whole surface is *made of*. The stylesheet declares six tokens and all six are
colours, so the account card carries nine font sizes, ten spacing values and two
negative margins that exist to cancel a distance something above them emitted.
Three row-level actions are three different elements at three weights. And two
things on screen are claims the code does not back: the card headed "Địa chỉ
giao hàng" writes `billing_*` and nothing else, and the proposed "đổi lần cuối"
row has no stored fact behind it at all.

### Sub-phases

- [x] **17.0** [Guard rails](account-card/17.0-guard-rails.md) — landed red as
      intended: `9 passed, 25 failed, 0 pending`, no file outside `tests/`
      touched but the one row in `run-all.php`. **There are four password
      writers, not three** — and the fourth is the one that must *not* record:
      `AccountProvisioner` writes a random string nobody has ever seen, so a
      date there would put "đổi lần cuối 2 năm trước" on the card of somebody
      who has never had a password. `sl_require_companion()` grew the allowlist
      `sl_forbid_pattern()` has carried since Phase 4. The nine passes are all
      halves that stop other halves passing vacuously
- [x] **17.1** [The provider's own mark](account-card/17.1-provider-marks.md) —
      one helper, two call sites, against an asset that had been on the interface
      since Phase 12. Found on the way: `form-auth.php` was the **only** place
      applying `smart_login_provider_icon_svg`, so a site filtering in an
      official brand asset would have got it on the sign-in screen and the
      plugin's own drawing in the account card
- [x] **17.2** [The scale](account-card/17.2-the-scale.md) — six spacing steps
      and five type steps, thirty literals removed from the region, both negative
      margins gone. **The measurement corrected the finding it was meant to
      confirm**: the button was 45px against the input's 47 and *shorter*, not
      50px and taller — a `button` takes `line-height: normal` from the UA
      stylesheet, which beats inheritance. Right defect, wrong number, wrong
      direction; the spec was corrected rather than left describing something
      that does not happen
- [x] **17.3** [One shape for one class of action](account-card/17.3-one-action-shape.md)
      — `.sl-action`, the invitation becomes a row, and width intent moves onto
      the element as `.sl-btn--inline` (the base keeps `width: 100%`: **20 of 27**
      call sites want it). **Rule 3's second half was narrowed here** before the
      markup was bent to satisfy it — it had flagged two controls that are not
      row actions. And a defect no suite could see: `.screen-reader-text` is a
      *theme* convention this plugin has depended on since 8.4 without declaring,
      so on a theme without it the profile card reads "Họ tên * (bắt buộc)". The
      rule for it was written **after** the defect, declared as such, with its
      red in the commit
- [x] **17.4** [The address the card names](account-card/17.4-the-address.md) —
      option (a). **A Phase 8.5 assertion had to be reversed, not deleted**:
      "the profile form never touches shipping", written with a reason that is
      true and a cost this phase pays on purpose. Replaced by its inverse with
      the history and the cost in the comment above it. Three docs corrected.
      `tests/integration/run-wordpress-gate.php` **was not run** — no WordPress
      bootstrap in this environment — and that is the one acceptance item this
      sub-phase did not meet
- [x] **17.5** [The notice says why](account-card/17.5-the-notice-says-why.md) —
      the reason has been translated since Phase 8 and one screen read it. The
      two branches turned out to be one: they differed in a class, a heading and
      a link's wording, and carried three duplicated blocks between them
- [x] **17.6** [The password remembers when](account-card/17.6-password-age.md) —
      four writers, three recorders, one written-down exemption. Recorded at the
      writers rather than through a hook, because `apply_password_hash()` writes
      through `$wpdb` and fires nothing. `''` is a designed answer and has its
      own assertion: it is the state of every account that exists today
- [x] **17.7** [The fraction](account-card/17.7-the-fraction.md) —
      `fields_in_scope()` lists what the account is asked for, filled or not, so
      the denominator is counted where the five settings are already read.
      Splitting the settings lookup from the missing test made an oddity visible
      and it is written down rather than tidied: `required_in_profile` puts the
      address in scope without `enabled`
- [x] **17.8** [One glyph set, one owner](account-card/17.8-card-icons.md) —
      `headings()` becomes `sections_meta()`, label and mark in one array, drawn
      by one partial. Suite fully green and **promoted to `required`** here
      rather than left for later. The template suite caught the new partial
      having no fixture, which is the mechanism 8.2 built it for

---

**Ordering rationale.** 17.0 first, for the reason the Postscript gives.

**17.2 must precede 17.3 and 17.8.** Both write new CSS. Writing it in literals
and converting it afterwards is two edits to the same lines, and the second edit
is the one nobody reviews.

**17.1 is first among the fixes because it is the cheapest** — one helper and two
call sites, against an asset that already exists. It is otherwise independent and
may be dropped.

**17.4 is the only sub-phase that changes stored data**, and the only one whose
acceptance names `tests/integration/`. Its cost is stated in the spec rather than
discovered later: a customer holding a deliberately different shipping address
loses it on the next save.

**17.8 is last and is the most visible** — the same trap 10.6, 11.4, 14.6 and
16.3 each named. Four identical dots are the least consequential thing wrong with
this surface.

**No `SMART_LOGIN_DB_VERSION` bump, and no schema change.** One new user meta key
and one set of mirrored Woo profile fields, both written through paths that
already exist.

---

## Phase 18 — The rendered surface

Normative spec: [`rendered-surface.md`](rendered-surface.md) — the three
findings, the five decisions and the three deferrals live there.

Execution briefs: [`rendered-surface/`](rendered-surface/), one file per
sub-phase. **Status lives here and only here.**

Short version: three phases in a row have written an acceptance item that says
*measured* and then not measured it — 8.4 claimed it, 16.3 and 17.4 wrote down
that they could not, 17.3 simply did not. The project keeps promising a reading
it has no tool to take.

And when somebody does look, it pays. Phase 17 needed a throwaway renderer in a
scratch directory to see the card at all, and that renderer found two defects in
one afternoon that no suite could have found: `.screen-reader-text` had been a
theme dependency since 8.4, and the input/button height read out of the source
was wrong in magnitude *and* direction. The tool was deleted with the session.
That is the actual finding.

A probe written for this plan found two more, both invisible to every existing
rule: the OTP code box has **no accessible name** — a placeholder and nothing
else, twice — and `.sl-action` declares a height floor with no width one, so
"Đổi" measures **20 × 32** against WCAG 2.2 AA's 24 × 24.

### Sub-phases

- [x] **18.0** [Guard rails](rendered-surface/18.0-guard-rails.md) — landed red
      at `9 passed, 4 failed`. The rules were the specification 18.1 was built
      against, which is the point of writing them first. **Two false positives
      were designed out before the rule landed**: rule 2's first draft reported
      the three gender radios, which are named by the `<label>` wrapped around
      them, and a rule with three false positives is a rule people learn to
      ignore
- [x] **18.1** [The renderer](rendered-surface/18.1-the-renderer.md) —
      `php tests/visual/render.php account`, stylesheet inlined so the file opens
      from anywhere. The fixtures were extracted to `tests/template-fixtures.php`
      so the picture and the smoke test cannot drift — and **that extraction
      broke 16.0's rule 5**, which reads the fixture source. Sixth time in this
      project a rename crossed a boundary no test covers, and the first time a
      guard rail caught it in the same run
- [x] **18.2** [A name for every control](rendered-surface/18.2-accessible-names.md)
      — the OTP box had `placeholder="Mã OTP"` and nothing else, twice. A visible
      label, and the placeholder removed rather than kept beside it: it said the
      same thing, and `maxlength` already carries the length
- [x] **18.3** [A floor under the targets](rendered-surface/18.3-target-size.md)
      — measured either side: Đổi **20 × 32 → 24 × 32**, `.sl-row` heights
      unchanged at 36, contact card unchanged at 304px, controls under the floor
      2 → 0. Suite promoted to `required` here
- [x] **18.4** [The measurements nobody took](rendered-surface/18.4-the-measurements.md)
      — the readings taken at 375, 480 and 1400: no overflow at any width, input
      and button both 47px in all four rows, 31 controls in a keyboard walk whose
      order matches reading order. **16.3's and 17.3's outstanding items are
      closed**, and 8.4's original claim finally has a number behind it. Two new
      findings, neither fixed here

---

**Ordering rationale.** 18.0 first, for the reason the Postscript gives — and
here it does double duty: the rules are the specification the renderer is built
against, so writing them first is design rather than only discipline.

**18.1 must precede 18.3 and 18.4.** Both have acceptance items that are
readings, and there is nothing to read from until the renderer exists. This is
the first phase in the project where that dependency is stated instead of
quietly skipped.

**18.2 and 18.3 are independent of each other** and either may be dropped. 18.2
is a defect with a known fix; 18.3 is a defect with a known fix and a number.

**18.4 is last and produces no code.** If a measurement finds something, it
becomes the next phase's material — fixing a defect in the commit that found it
is how a measurement stops being trustworthy.

**No new toolchain, decided rather than defaulted.** Playwright would make 18.4
an automated gate and would put a `package.json` and a node install into a repo
that has stayed PHP-only, CI included. The renderer produces the page; the
browser readings are taken by opening it and recorded as numbers. Worse than an
assertion, enormously better than the standing alternative, which is "not run"
three phases deep.

**No production behaviour changes beyond two defects:** one label and one CSS
floor.

### What 18.4 found, and did not fix

Both are open, and both are the shape of defect this phase exists to surface —
invisible to every string-matching rule, obvious the moment somebody looks at a
page.

- **The plugin declares a focus style for its inputs and not for its controls.**
  Four `:focus` rules in the stylesheet, all of them on `.sl-input`,
  `.sl-otp-digit` or `.sl-action--danger`. `.sl-btn` and plain `.sl-action` fall
  back to the browser's ring, which a theme carrying `*:focus { outline: none }`
  removes. Same shape as 17.3's `.screen-reader-text`: the plugin already
  declined to trust themes for one property and did not for its neighbour.
- **With JavaScript off, "Đổi" is a dead control with no explanation.** The
  contact editor is `hidden` and opened by a listener.
  `partials/address-fields.php` has a `<noscript>` doing exactly this job for
  the ward select; the contact card has none. Ten phases old, and found on the
  first reading of a page that carries no JavaScript.

---

## P1–P6 — The backlog after Phase 18

Six items rather than a phase, and documented here rather than in a spec plus
six briefs — a deliberate compression at the owner's request, keeping what
protects the work (a landed rule where one was cheap, one commit per item with
its evidence, a green suite) and dropping the paperwork that would have
outweighed the changes.

- [x] **P1** — **`readme.txt` was promising a control that does not exist**, for
      the third time in this project's history: it told site owners the plugin
      "không bao giờ ghi đè lựa chọn của khách" about delivery data, while 17.4
      overwrites `shipping_*` on every save. And `CHANGELOG.md` was three
      releases behind `readme.txt` — its top entry was `[1.0.1]` against a
      shipped 1.0.4. Version to **1.1.0**, 1.0.2–1.0.4 backfilled, and an Upgrade
      Notice that names the overwrite *and* the way out of it (turn the card off
      in the `Hồ sơ & Địa chỉ` tab — checked against `FieldRegistry`, not
      assumed)
- [x] **P2** — 18.4's two findings, both "the plugin leaves it to somebody
      else": no focus outline of its own on `.sl-btn` / `.sl-action`, which a
      theme carrying `*:focus { outline: none }` erases; and no `<noscript>` on
      the contact card, where "Đổi" is a listener. Rules 6 and 7, landed red
- [x] **P3** — the oldest open deferral closed. `Flow::login_url()` gives the
      plugin an addressable URL for its own sign-in screen, so the security
      card's recovery route is a link. **No new setting**, which the deferral had
      assumed was needed: a filter, then the page hosting the shortcode, then
      `''` and the sentence it had before. `SitePage` is `AccountForm`'s cached
      lookup generalised, so there is one of it
- [x] **P4** — **the integration gate runs.** The WordPress this environment was
      missing is Local by Flywheel, MySQL on port 10005 rather than 3306.
      `SMART_LOGIN_AUTH_INTEGRATION_OK`, WordPress 7.0.2. 17.4's unverified meta
      writes now have three assertions in the gate, including that a deliberately
      different shipping address **is** overwritten — proved able to fail by
      removing the shipping half of the loop and watching it go red
- [x] **P5** — one scale for the whole stylesheet. 51 declarations converted,
      **no value changed**: expanding every `var()` back gives a file byte-equal
      to the previous one, so the screens Phase 16 measured cannot have moved.
      The 40 genuinely off-scale declarations are pinned as a baseline that may
      fall and must not rise, with a second half that fails when the baseline
      goes stale. Six sign-in surfaces added to the renderer
- [x] **P6** — the two copy answers given at the start of Phase 17 and never
      applied: "Họ tên" → "Họ và tên" across five call sites, and the street
      field gets the example WooCommerce's checkout has shown since Phase 5.
      Rule 9 writes down the placeholder rule this project had been following
      without stating: format or example, never a repeat of the label

**Not done, and why.** The `Coding standards` suite stays at its baseline —
18 errors, 22 warnings, 16 files, all documentation sniffs deferred in
`phpcs.xml` since Phase 7. Driving it to zero would re-litigate a settled
decision, so it was listed as P7 explicitly in order to be declined.

---

## Housekeeping — packaging the release archive

A clean-up sweep asked for on 2026-08-07. The sweep itself found almost nothing
to clean, which is the useful result: no `TODO`/`FIXME` markers anywhere, no
`var_dump`/`error_log`/`console.log` in shipped code, no stray `*.log`, `*.bak`
or editor droppings, no CRLF and no trailing whitespace in any tracked file, no
unreferenced class under `includes/`, and no unreferenced template — all 26 are
reached by slug through `TemplateLoader`. The 34 MB working folder is 17 MB of
`vendor/`, and everything heavy left on disk was already ignored.

Building the archive is what found the defect:

- [x] **H1** — **`sl_plugin_sources()` scanned the release staging directory.**
      `tests/harness.php:124` skipped `.git`, `tests`, `scripts`, `docs`,
      `data`, `vendor`, `node_modules` and `.github` — but not `build` or
      `dist`. Staging the plugin into `dist/smart-login/` puts a second copy of
      every shipped file on disk, so a rule phrased "no file **outside**
      `UserManager` pairs a `user_email` write with `META_EMAIL_VERIFIED`" fails
      against the copy, naming a path the reader cannot fix. Six required
      suites went red at once:

          FAIL      Identity contract          ← blocking
          FAIL      Identity fitness           ← blocking
          FAIL      Abuse boundary             ← blocking
          FAIL      Delivery routing           ← blocking
          FAIL      Mail templates             ← blocking
          FAIL      Account card               ← blocking

      Fixed by adding the two names, and verified the way that matters: the
      suite is green **with `dist/` still on disk**, not green because the
      directory was deleted. `tests/run-lint.php:16` had carried `build` and
      `dist` in its own skip list all along — the harness had simply never been
      given the same list, so this is a copied-pattern gap rather than a
      judgement call.

      Then the same gap a third time, one layer down. `phpcs.xml` scans `.` and
      excludes `vendor`, `data`, `tests` and `scripts` — not `build` or `dist`,
      so the staged copy doubled the baseline this project compares against:

          A TOTAL OF 40 ERRORS AND 44 WARNINGS WERE FOUND IN 32 FILES

      against a documented 18 / 22 / 16. A release in progress read as a
      regression of exactly 100%. Both patterns added; back to 18 / 22 / 16 with
      `dist/` still present. Three separate walkers, three separate skip lists,
      one of them right — which is the argument for the shared list this repo
      would normally reach for, and is written down here rather than built
      because the three consumers want different shapes (a `phpcs.xml` pattern,
      a basename array, a second basename array).
- [x] **H2** — **`CLAUDE.md` and `.gitattributes` were shipping to users.**
      Neither is in `.distignore`, so the working agreement itself would have
      landed in the plugin archive. Both excluded. The archive is 173 files,
      418 KB, one `smart-login/` root.
- [x] **H3** — the archive is written with forward-slash entry names.
      PowerShell 5.1's `Compress-Archive` emits `smart-login\includes\…`, which
      the ZIP spec does not permit and not every extractor tolerates. Built
      through `ZipArchive.CreateEntry` instead, and the entry names asserted
      after the fact rather than assumed.

**Not done, and why.** `P8`–`P10` have commits but no rows in this tracker,
which is the one thing this file exists to prevent. Reconstructing their status
from commit messages is a separate job with its own reading, and guessing at it
here would put a second source of truth in the file that forbids one.

---

## Zalo Login — the v4 transport

Reported from a live sign-in on 2026-08-07: **"Zalo không trả về access token."**
Every required suite was green at the time, and had been for the whole life of
the defect.

The message was accurate and useless. It is the branch after a token exchange
that returned HTTP 200 with valid JSON, so nothing upstream had rejected
anything — Zalo had refused in the body and the code read the refusal as a
success with a field missing.

- [x] **Z1** — **the app secret was a body field; Zalo v4 wants a header.**
      `class-zalo-provider.php:97` posted `app_secret` in the form body. Zalo
      reads it from a `secret_key` request header and answers an unauthenticated
      exchange with `{"error":-124,"error_name":"Invalid app secret key"}` under
      HTTP 200. Moved to the header and dropped from the body, which is also one
      fewer place a secret can be logged. Two independent implementations agree
      on the placement — `SocialiteProviders/Zalo` and the Zalo v4 update in
      `AspNet.Security.OAuth.Providers#733` — and neither the plugin's docs nor
      its tests said anything about it, which is the CLAUDE.md rule about
      documentation not being evidence, arriving from the other direction.
- [x] **Z2** — **the access token travelled in the profile query string.**
      `graph.zalo.me/v2.0/me` reads it from an `access_token` header. In the URL
      it was both wrong and a credential written into every log between here and
      Zalo. `email` was dropped from the requested fields at the same time: a v4
      user access token does not reach it, so the parameter was asking for
      something Zalo will not grant. The mapping still reads `email`, so a site
      that re-adds the field through `smart_login_zalo_profile_url` is unchanged.
- [x] **Z3** — **a refusal in the body was thrown away.** `json_response()`
      treated any 2xx-with-JSON as success, so the one sentence that names the
      cause never left the method. It now fails closed on a non-zero `error` and
      keeps `provider_error` / `provider_error_name` on the `WP_Error`, and
      `ProviderAuthController::callback()` writes them into the audit log entry
      it was already recording. The visitor still reads one sentence; the
      operator gets Zalo's own words. `! empty()` rather than `isset()` is
      deliberate — Graph v2.0 returns `"error":0` on success.

**Why the gates missed it.** `tests/integration/run-provider-gates.php` answered
on a URL match: anything posted to `/v4/access_token` got a token back. A
fixture that accepts every request shape tests the URL and nothing else, and
this one had been green across both placements. It now answers the way Zalo
answers — no `secret_key` header, no token, `{"error":-124}` under HTTP 200 —
and rejects a Graph call whose token is in the query string. The five request-shape
rules also live in `tests/run-tests.php`, where they cost nothing to run, because
this class of defect should not need a WordPress and a database to catch.

**Landed red first.** Pure suite, before any production file moved:

```text
  FAIL  Zalo token exchange sends the app secret as a secret_key header
         expected: 'zalo-secret-belongs-in-a-header'
         actual:   ''
  FAIL  Zalo token exchange does not carry the secret in the body
         expected: false
         actual:   true
  FAIL  Zalo profile call sends the token as an access_token header
         expected: 'zalo-access-token'
         actual:   ''
  FAIL  Zalo profile call keeps the token out of the query string
         expected: false
         actual:   true
  FAIL  a rejected exchange keeps what Zalo said
331 passed, 5 failed
```

and the integration gate, against the corrected fixture:

```text
SMART_LOGIN_PROVIDER_GATES_FAILED
reason=Zalo callback fixture failed: smart_login_zalo_token
```

**Green after.** `336 passed, 0 failed`; `SMART_LOGIN_ZALO_STAGING_SMOKE_OK`;
every required suite in `run-all.php` PASS; coding standards unchanged at its
documented baseline, `18 ERRORS AND 22 WARNINGS ... IN 16 FILES`.

**Verified against Zalo, 2026-08-07.** A real sign-in through Zalo QR completed
and issued a session. No test in this repo speaks to Zalo — both fixtures model
Zalo's documented behaviour rather than this plugin's, which is a strictly
better model and still a model, so this round trip is the evidence and the
fixtures are the regression cover.

**The second cause, which only Z3 could show.** With the header in place the
message changed from *"không trả về access token"* to *"Zalo từ chối yêu cầu
đăng nhập."*, and the audit row Z3 added said why:

```text
{"provider":"zalo","reason":"smart_login_zalo_token",
 "provider_error":-14004,"provider_error_name":"Invalid secret key"}
```

The stored App Secret was the **App ID** — byte-identical, confirmed by reading
both out of the live site (`hash_equals( $app_id, $secret ) === true`). Zalo had
been evaluating the header correctly and refusing correctly. Two defects sat on
top of each other: one in this code, one in the configuration, and the second
was unreachable until the first was gone.

Two things this cost, worth recording because both are cheap to repeat:

- A first probe reported `secret length = 0` and `source = missing`. That was an
  artefact of the probe, not a fact about the site: `SecretBox::key()` derives
  from `wp_salt()`, and a bootstrap that defines DB constants and skips
  `wp-config.php` decrypts nothing. Anything reading a sealed value needs the
  site's real salts or it will confidently report an empty secret.
- The plugin directory had been a junction to a working copy elsewhere. When it
  was replaced by a real directory under `wp-content/plugins/`, PHP's cached
  path resolution still pointed at the old target, and the site fatalled with
  `is_readable()` returning true one line above a `require_once` that could not
  open the same file. Restarting PHP is the whole fix; no file was lost.

**Open.** Nothing stops the same App-ID-as-App-Secret entry from being saved
again — the two values sit next to each other in Zalo's dashboard, are the same
shape, and nothing complains until a visitor presses the button. A save-time
rule in `Settings::sanitize()` and a `Readiness` warning for sites that already
hold such a pair are specified and not yet built.

---

## Linking a provider — a correct refusal nobody could see

Reported on 2026-08-07: pressing **Liên kết** on the account page "redirects and
loads" and never opens Zalo's QR screen.

Nothing was broken in the control. The href was rebuilt as the signed-in
administrator and every guard `start()` applies was run against it — nonce
verifies, provider found, `is_available()` true, `linking` true. Zalo showed no
QR because the visitor had signed in with Zalo minutes earlier and the session
was still live, so the permission step approved silently and bounced straight
back.

The refusal came from the callback, and it was right. `resolve()` was run
against the real subject, as administrator:

```text
zalo subject held by user 585 (sl_1d391c4359d98cf67e28a787)
linking as user 1 (admin)

resolve() -> WP_Error smart_login_provider_conflict
            "Tài khoản nhà cung cấp đã liên kết với người dùng khác."

identity rows 8 -> 8      (nothing written)
users         7 -> 7
```

The first Zalo sign-in had provisioned its own account, so the identity belonged
to user 585 and not to `admin`. The plugin declined to move it. Everything after
that was the problem.

- [x] **L1** — **the only silent refusal was the one visitors meet.** Three of
      `callback()`'s four failure branches recorded `PROVIDER_FAILED`; the one
      guarding `resolve()` did not. Three presses left an empty audit log and
      the diagnosis had to be rebuilt against the database by hand. All four now
      record, and each carries whether it was a linking attempt. The rule is
      source-level — `callback()` ends in `exit()`, so no suite here can call it
      — and asks the weaker question it can answer: is there a record before each
      refusal. It found exactly one gap.
- [x] **L2** — **the refusal was delivered to a screen the visitor cannot be
      on.** `fail()` always redirected to `failure_url()`, which is My Account
      with `smart_login_step=login`. A signed-in visitor sees the dashboard, so
      the sentence explaining the refusal went nowhere. The account partial built
      its link with an empty return url, so the transaction had nowhere to send
      anyone back to; it now carries `$sl_redirect`, and `failure_url()` takes an
      optional return url, validated there rather than trusted. Applied to
      linking only — a failed *sign-in* still belongs on the sign-in screen,
      because the page that visitor was heading for cannot serve them.
- [x] **L3** — **the App-Secret-is-the-App-ID pair can no longer be saved.**
      `Settings::sanitize()` refuses a secret equal to the provider's public id
      and leaves the stored one alone, preferring the id submitted in the same
      save over the stored one — that is the save where the mistake is easiest
      to make. `Readiness` reports the pair as a warning for sites already
      holding one, which is every site this rule cannot reach. Google gets the
      same rule rather than a comment saying it should.

**Landed red first**, four suites, before any production file moved:

```text
  FAIL  a secret equal to the stored app id is refused
         expected: 'a-secret-that-is-not-the-id'
         actual:   'zalo-app-from-settings'
  FAIL  a secret equal to the app id in the same save is refused
  FAIL  the rule covers Google too
  FAIL  a linking failure returns to the page it started on
         expected: 'https://example.test/my-account/'
         actual:   'https://example.test/?smart_login_step=login'
  339 passed, 4 failed

  FAIL  every refusal in callback() writes an audit row first
         expected: 0
         actual:   1
  Identity fitness: 45 passed, 1 failed, 0 pending

  FAIL  the invitation carries a return url back to the account page
  Account card: 40 passed, 1 failed, 0 pending

  FAIL  a secret equal to the app id is reported
         expected: 'warn'
         actual:   'ok'
  Admin screens: 138 passed, 1 failed, 0 pending
```

**Green after.** `343 passed, 0 failed`; fitness `46 passed`; account card
`41 passed`; admin `139 passed`; every required suite in `run-all.php` PASS;
`SMART_LOGIN_ZALO_STAGING_SMOKE_OK`; coding standards back at its documented
baseline, `18 ERRORS AND 22 WARNINGS ... IN 16 FILES` — it went to 19 on a
`@param` this work introduced, which is the reason the baseline is compared
rather than assumed.

**Known limitation, written down rather than discovered.** `$sl_redirect` comes
from `AccountForm::redirect_url()`, which is `get_permalink()`. Inside a
WooCommerce endpoint that resolves to the My Account page, not to
`/my-account/edit-account/`, so a refused link returns to the account page and
not to the exact tab it started on. The notice arrives, which is the defect
being fixed; the last hop would take either a WooCommerce-aware branch or a
request-URI read, and neither was worth adding without a rule over it.

**Not this project's to fix.** The account that holds the contested identity is
a real account, and moving an identity between accounts is a merge. The site
owner deletes or unlinks the holder; the plugin refuses, says so, and now says
so where it can be read.

---

## Removing Zalo Login

Asked for on 2026-08-07, after the research below was put in front of the
decision and the answer came back unchanged: remove it entirely.

**Why, in one sentence the code can back up.** `AccountProvisioner`'s auto-link
branch requires `email_verified && '' !== email`, and Zalo v4 grants neither — so
every Zalo sign-in by an existing customer fell through to
`create_provider_user()` and made them a second account. Not an edge case: the
default path.

- [x] **R1** — **a rule that can answer "did we get all of it".** A removal
      crosses every boundary a rename does, and this repo has been bitten five
      times by exactly that. The rule walks `sl_plugin_sources()` plus the two
      shipped assets and names every survivor. It landed red with **18 files**,
      including `smart-login.php` and `templates/partials/account/providers.php`
      — both of which a hand-grep of `includes/` had missed. One allowlist entry,
      and it is not an exception being excused: `class-transport-router.php`
      mentions Zalo **ZNS**, an OTP transport, which a grep-and-delete removal
      would have taken with it.
- [x] **R2** — **the code.** `ZaloProvider` deleted; the provider registry, the
      identity channel, the three `FieldRegistry` rows, the admin card, the
      secret absorber, the connection-test branch and the deployment constants
      all follow. Two things were deliberately *not* deleted: `FederatedChannel`
      and `ProviderMark` are parameterised and belong to no provider.
- [x] **R3** — **the ternary that was one provider away from being wrong.**
      `ProviderCredentials` read `'google' === $provider ? … : …` four times, so
      *every* id that was not `google` collected Zalo's constants. With Zalo gone
      that is not a dead branch, it is a trap for the next provider. Replaced by
      a `PROVIDERS` map; an unknown provider now resolves to nothing, asserted
      directly.
- [x] **R4** — **the leftovers, cleaned by property rather than by name.**
      Removing a provider strands its settings block and its sealed secret, and
      a secret with no interface left to clear it outlives the feature it
      belonged to. `maybe_upgrade()` — empty of migrations since Phase 15, with a
      comment saying the next one goes here in the same commit as the change that
      needs it — now drops any provider block `ProviderCredentials` does not
      ship. Written that way on purpose: a cleanup that names the provider it was
      written for is correct exactly once.
- [x] **R5** — **the gate rewritten, not deleted.** `run-provider-gates.php` had
      80 Zalo references asserting the behaviour being removed. Deleting them
      would delete the policy; they now assert the new one — the registry offers
      only Google, and an install seeded with a leftover block and a sealed
      secret comes out of `maybe_upgrade()` with both gone and Google untouched.
      That last one is the only test the migration has, and the migration is new
      code that had never run anywhere.

**Measured before the decision, not argued.** On this install, one account is
stranded by the removal:

```text
accounts holding a zalo identity: 2
  user 585  sl_1d391c4359d98cf67e28a787  channels=[zalo]  synthetic_email=yes  -> LOCKED OUT
  user 560  sl_gate_guard_uxqhck0w       channels=[zalo]  synthetic_email=no   -> still has a way in
accounts with no way in once zalo is removed: 1
```

`can_unlink()` refuses to drop an account's last identity because `user_login` is
opaque — an account with none cannot be signed into or recovered. The stranded
one here is a test account, and the query to run before any real upgrade is in
`CHANGELOG.md` rather than in this file, because it is the site owner who needs
it.

**Identity rows are deliberately untouched.** They are customer data, deleting
them is not reversible, and an account whose only identity was Zalo needs a human
decision rather than an upgrade hook running while nobody is watching. The rows
render as an unknown channel, which `run-account-card-tests.php` already asserts
does not error.

**Green.** Every required suite in `run-all.php` PASS;
`SMART_LOGIN_PROVIDER_REMOVAL_OK` and `SMART_LOGIN_AUTH_INTEGRATION_OK` from the
integration gates; `db_version=2` observed on the live database, so the migration
ran rather than being assumed.

**The coding-standards baseline moved, and this is the note that says so.** It
was `18 ERRORS AND 22 WARNINGS ... IN 16 FILES` and is now **`18 ERRORS AND 20
WARNINGS ... IN 16 FILES`** — two warnings left with `class-zalo-provider.php`.
Down is still a change, and a baseline nobody restates is a baseline that has
stopped meaning anything.

---

## Phase 19 — Sign-in on every page

Normative spec: [`sign-in-anywhere.md`](sign-in-anywhere.md) — the eleven
findings, the seven decisions and the four deferrals live there.

Execution briefs: [`sign-in-anywhere/`](sign-in-anywhere/), one file per
sub-phase. **Status lives here and only here.**

Short version: the request was a login popup with a backdrop, openable anywhere,
addressable as `#login`. The backdrop is the cheapest part of it.

What the request actually asks for is that registration and onboarding finish
where the visitor already is — and **that property does not exist on any
surface today**. `PostAuthRedirector::redirect()` ignores `redirect_to` for a new
user and sends them to `profile_url()`, which falls back to
`admin_url( 'profile.php' )` when WooCommerce is absent. Register from a blog
post today and you land in `wp-admin`. The popup does not cause that; it makes
it unmissable.

Two more findings decide the shape. The REST surface has no `identify` route —
identifier-first, the entire first step of the current UX, exists only on the
HTML path — so a popup that talks to the server has nothing to call. And
`RequestGuard::fields()` writes a nonce and a one-hour stamp into markup, so
printing the form into every page hands a dead nonce to every visitor the moment
a full-page cache is switched on. Both are why the popup **fetches** its markup
instead of carrying it.

### Sub-phases

- [x] **19.0** [Guard rails](sign-in-anywhere/19.0-guard-rails.md) — eleven
      rules, landed red, each naming the finding it comes from. Five describe
      code that does not exist yet, so each asserts its subject was *found*
      before counting anything — a rule that narrows to nothing and passes
      vacuously is what 10.0's PENDING rows were written to avoid. Rule 9 exists
      because the defect it forbids was in the first draft of this plan
- [x] **19.1** [One flow engine](sign-in-anywhere/19.1-one-flow-engine.md) — the
      identifier-first state machine extracted out of `FormController` so both
      controllers drive one implementation, plus the missing `identify` route.
      A refactor: acceptance is *unchanged suite counts*, not green suites
- [x] **19.2** [The fragment](sign-in-anywhere/19.2-the-fragment.md) — one
      endpoint renders any step as HTML from the same templates, with a fresh
      nonce. The renderer must be *given* the host page: `Flow::url()` computes
      links against the current request, which inside REST is `/wp-json/`
- [x] **19.3** [The dialog](sign-in-anywhere/19.3-the-dialog.md) — native
      `<dialog>`, emitted outside every form, carrying no nonce. Two-stage
      assets: a small launcher site-wide, the bundle on first open. Fixes the
      duplicate `id="sl-identity"` and the second `autofocus` before a page can
      ever hold two copies of the form
- [x] **19.4** [The trigger contract](sign-in-anywhere/19.4-the-trigger-contract.md)
      — **rewritten after finding 11.** `?smart_login_step=` already existed,
      already allowlisted, already generated by `Flow::url()`; the first draft
      made `#login` the mechanism and was adding a third vocabulary to a concept
      that had two. The query parameter is canonical because it is the only form
      the server can see; the hash is an alias resolved to it. Plus
      `data-smart-login`, `window.SmartLogin.open()` and `[smart_login_button]`
      for sites built in an editor
- [x] **19.5** [Finishing in place](sign-in-anywhere/19.5-finishing-in-place.md)
      — the sub-phase the request was about. `AuthContext` learns whether the
      flow owns its own surface; a new user in place gets `step: onboard`
      instead of a trip to `wp-admin`. Every existing caller keeps today's
      behaviour byte-identical
- [x] **19.6** [The provider round trip](sign-in-anywhere/19.6-the-provider-round-trip.md)
      — Google returns to the page it left, and a new account returns with the
      dialog already open at onboarding. Sequenced after 19.5 because the return
      lands in the policy 19.5 writes
- [x] **19.8** [Capturing the links that exist](sign-in-anywhere/19.8-capturing-the-links-that-exist.md)
      — the theme's own "Đăng nhập" button opens the dialog on a site where
      nobody edited a template. Every other trigger requires markup to be
      edited, so this is the one that decides whether the feature reaches most
      installs. **Clicks are intercepted; `href` is never rewritten** — that is
      the whole reason it may default on, and the acceptance is that no `a[href]`
      in the document differs before and after the launcher runs
- [x] **19.9** [The cart that already survives](sign-in-anywhere/19.9-the-cart-that-already-survives.md)
      — **scoped as a feature, delivered as a proof.** WooCommerce merges a
      guest cart on `wp_login`, and `SessionIssuer` fires it from the only
      `wp_set_auth_cookie` call site in the plugin, so the cart already
      survives. What did not survive was the visitor's *place*, which is 19.5.
      Ships one rule over the session writers and one integration scenario
      instead of cart code nobody needs
- [x] **19.10** One heading per screen — found by looking at the thing, not by a
      rule. The shell's bar and the fragment's own `<h2>` said the same sentence
      forty pixels apart. The shell's cannot go: it is the dialog's accessible
      name. Rule 14 was written first and immediately showed this was **every**
      step, not one, so `partials/screen-title` became the single owner rather
      than six copies of one condition. Copy as asked: *Đăng nhập* + *Vui lòng
      đăng nhập để hưởng những đặc quyền dành cho thành viên.*
- [x] **19.11** The dialog's proportions — a token scale on `.sl-dialog`,
      declared then **measured**: panel 480, control 52, gaps 16/20/20/16, lead
      two lines, no overflow at 375/480/1400. Two things deliberately not copied
      from the reference, both argued in the commit: the benefit badges are a
      slot (empty by default, `smart_login_dialog_benefits`) because they were
      one pharmacy's claims, and the provider row adapts rather than always
      being circles. Measuring found the lead at three lines and a mobile sheet
      that two rules had already killed between them
- [x] **19.12** [The picker the dialog never
      loaded](sign-in-anywhere/19.12-the-picker-the-dialog-never-loaded.md) —
      reported from the running site: choosing a Tỉnh/Thành phố on the welcome
      screen left Phường/Xã empty and disabled. `address.js` was never loaded on
      the dialog path at all — the template asks through
      `Assets::enqueue_address()`, and inside the REST request that renders a
      fragment there is no `wp_enqueue_scripts` to answer, which is the no-op
      `Shortcodes::render_step()` already documents for `Assets::enqueue()`. It
      now arrives as a **third stage**, fetched by a fragment that actually
      contains a picker, with its config coming from `Assets::address_config()`
      so the contract and the localize call cannot drift. Second defect behind
      the first: `address.js` bound on DOMContentLoaded and exposed nothing, so
      loading it would not have been enough — rule 16 walks every
      `window.SmartLogin*Enhance` the plugin exposes and requires the dialog to
      call each
- [x] **19.7** [The measurements](sign-in-anywhere/19.7-the-measurements.md) —
      375/480/1400, the keyboard walk, the integration gate, suite promoted to
      `required`, baseline restated. Numbered before 19.8 and 19.9, **runs after
      them** — it is the sub-phase that measures the finished thing

---

**Ordering rationale.** Not preference. 19.1 and 19.2 are server work with no
visible output, and they come first because the dialog cannot be built against
an endpoint that does not exist — building the shell first would mean writing
its client against an imagined API and discovering the mismatch in 19.3. 19.5
precedes 19.6 for the same structural reason: the provider return lands in the
redirect policy 19.5 introduces, so shipping it earlier routes a signup at a
destination that has not been written.

19.0 is first for the reason the Postscript gives. **19.7 keeps its number and
runs last** — a measurement taken before the thing is finished is a measurement
that will be taken again, and renumbering it to 19.10 would rename four links
across three files to express something one sentence says. This project has
been bitten by a rename five times; it does not need a sixth for tidiness.

19.8 comes after 19.4 because capture is a consumer of the trigger contract, not
a spelling of it. 19.9 comes after 19.5 because the thing it proves — the
visitor stays where they were — is the thing 19.5 builds.

---

**Outcome.** Every sub-phase landed. Final state:

```text
Sign-in anywhere: 60 passed, 0 failed, 0 pending   (required since 19.7)
Integration gate: 19 checks, 0 failed              SMART_LOGIN_DIALOG_INTEGRATION_OK
Every required suite PASS.
```

19.10, 19.11 and 19.12 were not in the plan. All three came from looking at the
rendered dialog — one from a screenshot showing the same sentence twice, one
from a request to match a reference's proportions, one from a screenshot of a
ward dropdown that would not open. None would have been found by any rule in the
suite, which is the argument Phase 18 makes and this phase paid off three times.

Four things this phase got wrong in the plan and corrected in the work, each
written up in the brief that found it:

- **19.1's guard placement.** The brief put `RequestGuard::verify()` in the
  controller. Porting showed two handlers make flow decisions *from* the guard
  result, so it moved into the engine with a per-transport switch.
- **19.3 and 19.4 could not be split.** The launcher is one file, and a loader
  that recognises nothing cannot be exercised. One commit, both briefs.
- **19.9 was scoped as a feature and delivered as a proof.** The WooCommerce
  cart already survives a sign-in, because `SessionIssuer` fires `wp_login`.
  Writing cart code on top of that would have been inventing work.
- **19.5 left a required rule asserting the shape it replaced.** Two checks in
  the Regression suite read `signup()` and `finish_registration()` for a literal
  `->go( $this->welcome_url() )`. 19.5 made the ending a *decision* — redirect
  when page-hosted, render when in place — and moved it into
  `after_registration()`, which is the right shape and turned both checks red
  against correct code. Repointed to follow the ownership rather than the text:
  neither finisher may decide the ending or render the welcome screen inline,
  and the one method that decides must still redirect on the page-hosted path.
  The nonce hazard the rule exists for is unchanged and still unreachable by any
  suite here, so it stays structural — that deferral is written above the rule.
  Verified by breaking it twice: deleting the `! $this->in_place` guard, and
  restoring the pre-19.5 shape in `signup()`, each fail two checks.
  `335 passed, 2 failed` → `342 passed, 0 failed`

**Three rules passed their structural form and needed a behavioural one** — 3, 7
and 12. The pattern is worth carrying forward: *a rule that reads source proves
a thing was written, not that it works.* Two more rules (1 and 2) were simply
wrong as landed and were caught by the sub-phase they were meant to gate, which
is what landing them red is for.

**The coding-standards baseline was stale.** Documented as `18 ERRORS AND 20
WARNINGS ... IN 16 FILES`; `main` re-measured with a cleared `.phpcs-cache`
gives **23 / 25 / 19**, and this branch gave **21 / 20 / 16** — and **21 / 17 /
15** from 20.4 onward, where `phpcbf` cleared three long-standing alignment
warnings in `class-field-registry.php` while fixing alignment 20.4 had disturbed,
taking that file off the report entirely. The first
attempt at that comparison was wrong because the cache was serving the previous
tree — *two runs are not a comparison*, and this is the phase that had to prove
it on itself.

**The visible half is the last third.** That is the same shape Phase 8 used and
for the same reason: the popup is a presentation of a flow that has to be able
to finish in place first, and a dialog wrapped around today's redirect policy
would look finished while sending every new member to `wp-admin`.

---

## The transport that answered in another's name

Reported on 2026-08-08, from the dialog on the running site: a phone number was
typed into the identify step and came back **"Kênh email chưa được cấu hình.
Liên hệ quản trị viên."** The question asked was whether the response was wrong,
the provider flow was wrong, or the routing was wrong. It was the first.

A Phase 10 defect found during Phase 19, so it is written here rather than given
a 19.N number — the code and the spec entry both belong to delivery routing
(`docs/delivery-routing.md` D8).

Everything up to the sentence was correct, and that is what took the longest to
establish:

```text
destination : 84969789475
channel     : phone                    <- correct, no '@'
transport   : automation               <- correct, delivery.route_phone
result      : smart_login_transport_unavailable
              "Kênh email chưa được cấu hình. Liên hệ quản trị viên."
```

The install had `delivery.route_phone = automation` with `automation.url` empty.
Routing did its job; the refusal was worded by a two-branch ternary that 10.1
never revisited — `'sms' === $transport_id ? … : email` — so every transport that
was not the SMS gateway was refused in the mail transport's name.

- [x] **T1** — **a transport is described by itself, not by a list.** A third
      branch would fix the instance; the bug class is a fixed list of ids
      describing an open registry, since `smart_login_otp_transports` exists so a
      site can add ZNS or in-app push and those were being called email too. The
      transport answers instead, through an **optional** `ReportsUnavailability`.
      Optional and not a fourth method on `TransportInterface`, because that
      interface is published API and the router's own docblock promises adding a
      transport means implementing it "and nothing else" — a required method
      would fatal every transport written against that promise, including the
      three test doubles in this repository, which is how the cost was measured.
      A transport that does not implement it is named by its id. Rule 10 already
      asserted the send fails closed but not that the refusal said *which* door
      closed; two new rules landed red on exactly the reported sentence, and a
      third — that an unavailable transport is refused before its own `send()`
      runs — passed already and stays as a regression guard, because reaching
      `send()` would feed the circuit breaker a failure that says nothing about
      the gateway. `47 passed, 2 failed` → `49 passed, 0 failed`, integration
      gate `SMART_LOGIN_DELIVERY_GATE_OK` on wordpress=7.0.3

**What the readiness screen already had right.** D7 prints the transport it
asked about (`class-readiness.php:176-181`), so the admin side named
`automation` correctly the whole time. Only the path the visitor sees guessed.
Two descriptions of one fact, and the one nobody was testing drifted — the same
shape as the four-way drift the `FieldRegistry` rewrite removed.

**Found by looking, not by a rule** — the fourth time in two phases, after
19.10, 19.11 and 19.12. Every suite was green while a phone number was being
told its email was misconfigured.

---

## Phase 20 — Sending a code

Spec: [`sending-a-code.md`](sending-a-code.md). Revises Phase 10 — D1, D2, D8.

**Not a feature. A vocabulary removal.** Reported 2026-08-08 by the administrator
who had just hit *The transport that answered in another's name*: the message was
fixed and the configuration was still wrong, because the screen that produced it
offers one word for three concepts and three words for one concept. The report is
the spec — *"Việc cấu trúc sai, nên tôi hiểu sai nên cấu hình sai."*

The install had a working gateway URL in `sms.url`, `sms.enabled` on, and
`delivery.route_phone` pointed at `automation` with an empty endpoint. Both
halves were reasonable readings. Together they delivered nothing.

- [x] **20.0** Guard rails, landed red —
      [brief](sending-a-code/20.0-guard-rails.md). Four rules: no setting names a
      transport, one label per concept, the bus cannot reach the OTP path, and an
      enabled channel that serves nothing says so. **Delivery routing 50/3,
      Admin screens 139/5**, no other suite moved. Rule 16 corrected the spec's
      headline finding — "Nhà cung cấp" labels three *settings*, one of them
      `security.captcha_provider`, which the spec had missed and 20.5 now owns.
      Rule 15's intersection is exactly `AutomationEndpoint`'s four settings, so
      20.2 removes it in one move and only two of them travel in 20.3
- [x] **20.1** [The routing table goes
      away](sending-a-code/20.1-the-routing-table-goes-away.md) — both settings
      deleted, `ROUTES` becomes the `CHANNEL_TRANSPORT` constant,
      `AutomationTransport` deleted outright because Rule 15's companion would
      otherwise hold that rule red for ever. `channel_for()` untouched. Three
      **rules** turned out to be consumers too and none was removed quietly:
      10.1's own two assertions and Rule 5 retired with their reasons, 10.5's
      readiness scenario repointed at the modern spelling of the same broken
      site. **Rules 14 and 15 green; Delivery routing 72/0/0**
- [x] **20.2** [The signed envelope becomes a
      provider](sending-a-code/20.2-the-signed-provider.md), **not a preset** —
      D2 was rewritten before any code moved. Reading `EnvelopeSigner` first
      found four controls a body template cannot carry, so the layering changed
      instead: one transport per channel, and the *provider* selects the wire
      format. `WebhookTransport` gains a signed branch; `EnvelopeSigner` is
      reused unchanged, which is the whole point. **Delivery routing 57/3, Admin
      screens 141/5** — two more passing than before, because `show_if` was made
      checkable rather than exempt from the tab-coverage gate
- [x] **20.3** [The migration that refuses to be
      silent](sending-a-code/20.3-the-migration-that-refuses-to-be-silent.md) —
      `automation.url` + `automation.secret` → the signed provider, secrets
      through the secret store on both sides because a raw block copy would copy
      an absence. Two shapes cannot be migrated and are **reported by name**
      instead: `route_email = automation`, which has no equivalent, and
      `route_phone = automation` with an **empty endpoint** — the shape the
      reporting install is in, which the brief missed and which the first
      implementation would have "migrated" straight over a configured gateway.
      Idempotence asserted, not assumed. **Delivery routing 72/3**
- [x] **20.4** [The bus gets its own
      tab](sending-a-code/20.4-the-bus-gets-its-own-tab.md) — top-level **Thông
      báo & Tích hợp**, and the slug goes with it: `delivery-automation` asserted
      the association 20.1 removed and was visible in the address bar. Aliased so
      a bookmark still lands right. No `automation.enabled` flag — a fresh
      install subscribes to nothing and configures nothing, so a toggle would be
      a fourth way for the screen to disagree with itself. A **test fixture**
      turned out to depend on the hierarchy and was repointed.
      **phpcs baseline moves to 21 / 17 / 15** — see below
- [x] **20.5** [One word, one
      meaning](sending-a-code/20.5-one-word-one-meaning.md) — **Rule 16 green.**
      "Nhà cung cấp" split three ways (đăng nhập / SMS / Dịch vụ chống robot);
      "Webhook" removed from all six SMS-side labels and left to the event tab.
      The captcha field was in scope only because 20.0's rule found what the spec
      had missed. Two help strings still described the routing table three
      sub-phases after it was deleted — no rule could see those, only reading
      could. **Admin screens 144/2**, and the last two reds are 20.6's
- [ ] **20.6** Each channel screen states whether it is serving anything. This is
      `delivery-routing.md` D2's unmet requirement, cheap only after 20.1 — the
      answer stops being a routing lookup and becomes "is this channel enabled"

---

**Ordering rationale.** 20.2 before 20.1, and that is the only counter-intuitive
edge. The preset has to be able to carry a site's configuration *before* the
routing table that currently carries it is deleted, or 20.1 lands a window in
which an automation-routed install has nowhere to be. 20.3 then moves the data
across and 20.1 removes the empty shell.

20.4 after 20.3 for the same reason in the other direction: the tab cannot be
re-parented while a migration is still reading `automation.*` as a transport.

20.5 and 20.6 are last because they are the visible half, and the same argument
Phase 19 records applies — a screen relabelled around a structure that is still
moving gets relabelled twice.

**Risk this phase carries and Phase 10 did not.** 10.1 shipped with defaults that
reproduced existing behaviour byte for byte, so no site changed on upgrade. This
one deletes two settings that sites have deliberately set. There is no
no-migration path; 20.3 is load-bearing, and the acceptance for it is written
before 20.2 starts.

---

## Risks

| Risk | Mitigation |
| --- | --- |
| Phase 3 rewrites five handlers — large, hard to review | One commit per handler; decision table green first |
| `dbDelta` + `UNIQUE` on `VARCHAR(191)` utf8mb4 is 764/767 bytes | Already the width in use; the idempotency test catches divergent environments early |
| Opaque `user_login` hinders admin support | Identity column + `user_search_columns` hook, both in Phase 3 |
| Fitness greps produce false positives on legitimate code | Per-file allowlist declared inside the test, forcing any exception to be justified in writing |
| Phase 8.2 silently changes rendered output while claiming not to | Acceptance is a diff of rendered HTML for a fixture user, not a reading of the code |
| Taking over the Woo save path breaks third-party plugins invisibly | `WC_Form_Handler` keeps saving on the Woo page; the renderer emits all four `woocommerce_edit_account_form*` hooks |
| The redesign lands on a page only reachable with WooCommerce active | 8.3 precedes 8.4, so the standalone surface exists before it is redesigned |
| 8.6 repeats the Phase 4 / Phase 7 rename failure at 445× the scale | Sequenced last, gated on a dangling-string scan, and declinable in writing |
| A site-wide ceiling set too low blocks a real launch, gets switched off, and never comes back | Generous defaults; 9.9's screen makes tuning evidence-based rather than a guess |
| 9.6 causes mass lockout behind a CDN | Hard-sequenced after 9.5; loose default; readiness warns on the exact dangerous combination |
| The country allowlist rejects a legitimate foreign customer | Default matches today's effective behaviour for VN sites; `smart_login_phone_is_valid` stays the last word; widening is one text field |
| 9.7's timing check rejects in-flight JS clients on deploy | JS ships as a separate, earlier commit; the check is skipped when no stamp is present |
| The kill switch becomes a DoS — an attacker halts OTP for everyone | The deliberate trade: a halted hour costs less than a drained balance. Bounded by `halt_minutes`, alerted on, clearable from the admin screen |
| New settings scatter across existing tabs and get lost | One new `security` tab; existing keys are **not** moved, so the admin suite's tab-membership assertions do not churn |
| 10.3 sends the plaintext OTP to a third party — a property the plugin currently holds | Accepted deliberately and argued in the spec. HTTPS enforced at save, HMAC signature, timestamp and delivery id for replay rejection, existing redaction applied unchanged. The residual risk — an endpoint the admin should not have trusted — is stated in the help text rather than implied away |
| 10.1 changes routing for every OTP the plugin has ever sent | Defaults reproduce today's behaviour exactly; acceptance is *unchanged suite counts*, not green suites, so an invisible change that is not invisible fails |
| A bus endpoint going down takes sign-in with it | Two breakers, not one, and rule 6 asserts a failing bus leaves `issue()` returning an array. This is the decision most likely to be "simplified" later, so it has a rule rather than a comment |
| 10.6 splits one tab into four and a field lands on none of them | Acceptance walks `FieldRegistry::all()` and renders every tab — the exactly-one-tab property the registry exists to guarantee |
| Deleting a WordPress user strands its identity rows, so the subject can never be registered again — and 14.4 widens this to every provider account's email | **Found in 14.4, fixed in 14.7** before the backfill could multiply it. `deleted_user` now releases them, with two structural rules asking whether anything calls the capability and one behavioural rule in the gate. Rows already stranded on existing installs are out of scope and stated as such |
| The `generic` preset default makes an existing site's SMS stop working | Only new installs; a site that has saved the tab has `custom` stored, and `Settings::sanitize()` writes stored values. Asserted directly |
| 14.1's guard sits on the happy path of every registration and a wrong predicate closes signup for everyone | Acceptance asserts an unused address still registers, not only that an owned one is refused |
| 14.2 is a rename across `META_EMAIL_VERIFIED`, `META_SYNTHETIC` and `billing_email` — the failure mode CLAUDE.md records five times | The grep across `includes/`, `templates/`, `tests/` and `docs/` is a completion condition of the sub-phase, not a follow-up; and the acceptance is unchanged counts, so a behaviour change cannot hide behind a green run |
| 14.4 widens what an address can do, and the flag defaults on for Google | Gated per provider and per `email_verified`; flag off asserted byte-identical to today; what the setting grants is stated in its help text rather than implied |
| 14.5 grants existing accounts a login route their holders did not ask for | Deliberate and argued in the brief: core's own form already reaches them at that address. Skips synthetic addresses and any address held by two users, both asserted; count written to the audit log; opting out is turning the 14.4 flag off before upgrading |
| 14.3 adds a caller to an OTP send, which is the shape of 9.4's original defect | The new route spends `check_identify()` and `check_otp_send()` unchanged, asserted by exhausting the budget rather than by reading the call site |
| 14.6's branch is re-derived from `user_email` by a later change and silently breaks for Google accounts | The acceptance asserts the non-synthetic-plus-no-email-row case specifically — the one a synthetic-email predicate gets wrong — so the predicate is pinned by a test rather than by a comment |
| 16.1 hides a row a member relies on: an account whose only identity is federated renders an empty card | Acceptance asserts the provider-first shape specifically, not only the shape being fixed. The early return moves *after* the filter, so "nothing federated" and "nothing at all" stay different states |
| 16.1 filters in the template, so a second surface renders the unfiltered list | One partial owns the list and both surfaces call it — 8.2's contract, asserted by the existing single-template rule. The filter is presentational by decision, and `linked()` keeps returning everything because `can_unlink()` counts it |
| 16.2 promotes `meta_json` from forensic context to display, and a link-time snapshot goes stale | Accepted and argued in the spec: the row's subject *is* the link-time fact. Three-level fallback asserted separately, so an identity with no meta still renders |
| 16.3's box-model fix is aimed at a cause that was inferred from a screenshot, not measured | Reproducing the overflow and reading the computed `box-sizing` is a completion condition of the sub-phase, not a follow-up. The guard is correct whatever the answer; if the hypothesis is wrong the real cause is found before the CSS is edited |
| The unlink route disappears for phone and email, and somebody needs it | A deliberate narrowing, written down in the spec with its reason. `unlink()` and the REST route are untouched, so nothing is lost but the control on this one screen |
| 17.2 tokenises a stylesheet shared with the sign-in screens Phase 16 has just finished measuring | Scoped to the account-surface region by decision, not by accident. Rule 2 names its region and asserts the marker exists first, so narrowing the rule to nothing fails loudly. The rest of the file is a written deferral |
| 17.4 overwrites a shipping address a customer chose on purpose | The stated cost of one address, argued in the spec and repeated in the brief rather than left to a bug report. `billing_*` stays the only reader, so the mirror cannot become a second source of truth |
| 17.4 is a rename across four files plus the string catalogue — the failure mode CLAUDE.md records five times | The grep across `includes/`, `templates/`, `tests/`, `docs/` and `languages/` is a completion condition of the sub-phase, not a follow-up |
| 17.6 records the date at three call sites and a fourth writer is added later | A companion rule over the writers, not an assertion about a hook. `apply_password_hash()` writes through `$wpdb` and fires nothing, which is why a listener would have been the wrong answer |
| 17.7 ships a denominator that is secretly a constant and passes every test | Acceptance asserts `total` **moves** with `profile.dob` off and on, not merely that it is returned |
| 17.8 renames `headings()`, which four templates call | Same grep condition as 17.4. The rename is the point — one array carries the label and the mark, so the two cannot drift |
| 18.1 ships a renderer that drifts from what the plugin actually serves, so a green picture proves nothing | Real templates, real stubs, real stylesheet, and fixtures taken from the shapes `run-template-tests.php` already declares. Rule 1 fails the moment a partial exists that the renderer cannot build |
| 18.4's readings are manual, so they rot the moment somebody stops taking them | Recorded as numbers in the brief rather than as ticks, and the protocol is a committed file with commands in it. The alternative was a second toolchain, declined in writing |
| A rendered page is not a WordPress page, and 18.1 makes it easy to believe otherwise | Stated in the spec: the renderer does not substitute for `tests/integration/`, and 17.4's meta writes stay unverified against a live database rather than being quietly closed by a picture |
| 18.3's floor changes row height across the account card | Acceptance is a measurement of `.sl-row` before and after, not a reading of the CSS. 17.2 is the precedent — the prediction from the source was wrong in both magnitude and direction |
| Z2 stops asking Zalo for `email`, and a site that was receiving one silently stops | A v4 user access token does not grant it, so the field was already never arriving — the change removes a request, not a result. `smart_login_zalo_profile_url` re-adds it in one filter, and the mapping still reads `email`, so nothing downstream assumes its absence |
| Both Zalo fixtures now encode a reading of Zalo's docs, so a wrong reading is now asserted rather than merely believed | Stated where it is configured: the fixture comments name the behaviour they model and why. A real round trip through **Kiểm tra kết nối** is the check neither fixture replaces, and it is listed as the open item in Z1–Z3 rather than closed by a green run |
| L2 lets a linking failure choose its own redirect target, which is the shape of an open redirect | The value is validated by `wp_validate_redirect()` inside `failure_url()` rather than by its callers, and asserted directly — an off-site return url falls back to the sign-in step. Sign-in failures are excluded entirely, so the widened path is only reachable by a visitor who is already authenticated |
| L3 refuses a save, and an administrator whose provider genuinely issues id and secret alike can no longer configure it | No provider does — an id is public and a secret is not, and a provider that made them equal would have no secret. The refusal names the fix in its message rather than only reporting a rejection, and `Readiness` names which provider holds the bad pair |
| L1's rule reads source text, so it passes the day somebody renames `fail()` | It asserts the method body was found before counting anything, so narrowing the rule to nothing fails loudly instead of passing vacuously — the failure mode 10.0's PENDING rows were written to avoid |
| R2 locks out every account whose only identity was Zalo, and the plugin cannot tell the operator afterwards | Measured before the change rather than discovered after, and the counting query ships in `CHANGELOG.md` under a heading that says what it costs. The rows are left in place so the decision stays reversible by hand — a cleanup that deleted them would make the lockout permanent in the same breath as reporting it |
| R4's cleanup runs on upgrade and deletes stored configuration | It only ever touches provider blocks `ProviderCredentials::PROVIDERS` does not name, so the shipped provider and the shared `auto_link_email` policy are unreachable by it — both asserted in the gate, against a real database, not reasoned about |
| R1's allowlist becomes the place future references hide | One entry, with the reason written beside it, and it names a *different feature* rather than a file that was too hard to clean. Any second entry has to be argued in the same place |
| 19.1 extracts the state machine every sign-in on the site runs through — the largest blast radius in the project | Acceptance is *unchanged suite counts*, not green suites, so a behaviour change cannot hide behind a passing run. The rate-limit ordering that makes the identify lookup non-enumerable is asserted by exhausting the budget, not by reading the call site |
| A popup on every page puts a nonce and a one-hour stamp into markup a full-page cache will serve to every anonymous visitor | The reason decision 1 is a fetch rather than an embed. Rule 4 asserts structurally that nothing hooked to `wp_footer` emits `RequestGuard::fields()` — the property, not one instance of it |
| `MIN_FILL_SECONDS` was written for a form that loads with the page, and a popup filled by a password manager can beat it | The guard is untouched; the client adapts. Submit stays disabled until the fragment's stamp is old enough, so the visitor never meets the error. Loosening a bot control to make a UI feel faster is refused in writing rather than quietly traded away |
| Two copies of the identify form on one page — the shortcode and the shell — collide on `id="sl-identity"` and `autofocus` | Fixed in 19.3 *before* a second copy can exist, and measured with `tests/visual/render.php` rather than reasoned about. This is a defect shipped since the template was written; the popup only makes it reachable |
| The fragment renderer emits step links into `/wp-json/` because `Flow::url()` reads the current request | Rule 7, written in 19.0 against code that does not exist yet, and it asserts the fragment was found before counting links so it cannot pass vacuously |
| 19.5 changes the redirect every registration on the site ends in | The in-place branch is reachable only from a context the fragment endpoint sets. Acceptance asserts the shortcode path stays **byte-identical**, and asserts the WooCommerce-deactivated case specifically — the one that produces a `wp-admin` URL |
| The popup closes without a reload and a signed-in visitor sees a signed-out page | Decided against DOM patching in the spec: price, cart fragment and most nonces on a WooCommerce page are role-dependent. Both onboarding exits reload the host page |
| The shell is emitted inline inside a theme's form and reproduces the nested-form bug `DeferredForms` exists for | Rule 6 asserts the shell is emitted after the last `</form>`. The project has already paid for this once, in a defect where the save button silently did nothing |
| The launcher loads on every page of the site, so a sign-in feature becomes a site-wide performance cost | Two-stage: the launcher owns the hash contract and nothing else, and the bundle arrives on first open. Acceptance is the enqueued handle list on an untouched page, asserted rather than assumed |
| `#login` collides with a fragment the theme already uses | Only the mapped aliases are claimed; an unknown fragment leaves the DOM untouched, asserted directly. The theme keeps every fragment the plugin has not named |
| 19.8 captures a link the site needed left alone, and a visitor cannot reach the real login screen | `href` is never rewritten, so a captured link is one script failure away from being the ordinary link it was. Four refusals asserted separately, including `wp-login.php?action=logout` specifically — capturing that one would trap somebody trying to leave |
| 19.8 defaults on, so an upgrade changes behaviour on every site that has a login link | The behaviour it changes is a navigation becoming a dialog on the same page, and it reverses with one filter returning `array()` — asserted to restore today's behaviour exactly. A capture that is off by default is a feature nobody receives, which was the alternative |
| 19.8 matches by link text and fires on an article about signing in | It does not match text. A link is captured because the plugin can *name* its URL — `Flow::login_url()`, `wp_login_url()`, Woo's my-account — compared as resolved absolute paths. "No guessing" is the sub-phase's Not-in-scope line |
| The query trigger creates a second URL for every page and splits it in search results | 19.4 owns that cost: the dialog-open variant is `noindex` and the page's canonical tag is untouched, asserted rather than assumed |
| 19.9 discovers the cart merge works and the sub-phase is quietly dropped, leaving `wp_login` load-bearing with nothing asserting it | The finding is *why* the sub-phase exists in its current form. The rule sits over the session writers, and the acceptance is that removing `do_action( 'wp_login' )` from `SessionIssuer` **fails** it — verified by removing it, because a rule that has never failed is a comment |
| The whole phase ships without a browser ever opening it, exactly as 8.4, 16.3 and 17.3 did | 19.7 is a sub-phase with readings as acceptance, not a courtesy at the end, and the tool it needs already exists — 18.1 committed it precisely so this could not happen again |
