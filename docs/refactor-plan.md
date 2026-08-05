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
