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
- [ ] **Phase 10 — Delivery routing and the automation bus**

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
- [ ] **10.5** [Readiness and cost](delivery-routing/10.5-readiness-and-cost.md)
      — two admin-facing numbers that 10.1–10.3 quietly break: readiness
      constructs its transports directly, and the spend estimate counts rows
      where `transport = 'sms'`, which reads zero the moment phone is routed
      elsewhere
- [ ] **10.6** [Delivery tab](delivery-routing/10.6-delivery-tab.md) — the
      original request. Four sub-tabs, each a real slug because saving is
      tab-scoped; second-level nav; the SMS preset default moves off `custom`
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

**Phase 11, not started:** email template groups. One `email.subject` /
`email.body` pair serves all four intents (`class-field-registry.php:488-512`),
so a password-reset mail is worded identically to a login mail, and `{{intent}}`
can be interpolated but not branched on. Separate phase, deliberately not folded
into 10.6.

**Phase 12, not started:** the provider surface and the shared channel card.
Split out of Phase 10 on purpose — it touches the same admin screens but has
nothing to do with delivery routing, and folding it in would dilute an otherwise
single-subject phase. Four items, from a wireframe review:

1. **A defect, not a redesign.** The provider card's status badge reads
   `ProviderCredentials::is_configured()` — credentials only
   (`class-provider-cards.php:55,88`). What decides whether a provider actually
   runs is `is_available()` = `enabled && is_configured`
   (`class-google-provider.php:32-35`). A provider with credentials saved and
   `Kích hoạt` left off therefore shows a green **Sẵn sàng** while no button
   appears on the front end. The badge must read the same function the runtime
   reads, with a rule asserting the two cannot disagree — otherwise 10.6 copies
   the defect into the channel cards.
2. Master toggle into the card header; `providers.auto_link_email` above the grid
   where its scope is visible, not below it as a trailing form row.
3. Promote `ProviderCards` to a reusable channel card. **If Phase 12 is
   reordered before 10.6, this item must lead it** — 10.6 builds four screens on
   that component, and building them first means writing the card twice.
**Found in 10.2, deliberately not fixed there:** `uninstall.php:12` gates the
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
| The `generic` preset default makes an existing site's SMS stop working | Only new installs; a site that has saved the tab has `custom` stored, and `Settings::sanitize()` writes stored values. Asserted directly |
