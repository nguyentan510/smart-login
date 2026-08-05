# The email identity

Normative spec for Phase 14. Status lives in
[`refactor-plan.md`](refactor-plan.md); execution briefs live in
[`email-identity/`](email-identity/).

---

## The problem, stated as a data problem

A visitor signs in with Google. `create_provider_user()` writes the verified
Google address into `wp_users.user_email` (`class-account-provisioner.php:145-147`)
and links **one** identity row — the federated `google:<subject>` claim. The email
gets no row, and `link()` says so in writing
(`class-account-provisioner.php:194-198`): an address is an identity in the
`email` channel, not an attribute of a federated one, and *"Phase 3 decides when a
verified provider email earns its own row"*. Phase 3 did not, and this is that
phase.

So one fact — *this account owns this address* — is stored in two places that
disagree. That is Invariant 1's failure mode, and it is already observable. The
next day the visitor forgets they used the button and types their address instead:

| Door | Mechanism | What it answers |
| --- | --- | --- |
| Identifier-first screen | `resolve()` → UNKNOWN, `LOGIN` → `NO_ACCOUNT` (`class-auth-action.php:59`) | sends a **registration** OTP (`class-form-controller.php:356`), then refuses at the last step with *"Tài khoản đã tồn tại."* (`class-user-manager.php:116`) |
| Forgot password | same UNKNOWN (`class-password-reset-handler.php:87-92`) | *"Thông tin này chưa được đăng ký."* — false |
| `wp-login.php` | core resolves `user_email` at `authenticate` priority 20 | *"mật khẩu không đúng"* — true, and unreachable, because the password is a 64-character random string the account holder has never seen (`class-account-provisioner.php:158`) |

Three doors, three answers, one account. The door that comes closest to the truth
is the one this plugin does not own.

The first door is the expensive one: three steps and a real OTP spent before the
wall appears, and the wall contradicts the invitation that opened the flow. It
fails clean — `email_exists()` is checked before `wp_insert_user()`, so no
half-built account is left behind — but the code and the rate-limit budget are
gone.

## The decision

**A provider-asserted verified email earns an identity row, per provider.**

The argument is not that this adds a capability. It is that the plugin already
trusts that address enough to make it the account's `user_email`, which core then
resolves as a login identifier. Storing it in `wp_users` but not in the directory
does not withhold trust — it splits it, and the split is what the three doors
disagree about. One store, and they agree without anyone writing a special case.

Per provider, not global: Google asserts `email_verified` and it is worth relying
on. Zalo generally returns no email at all, and where it does the assertion is not
equivalent. A single flag would force one answer onto two different guarantees.

### What this does *not* decide

That a provider email may **adopt an existing account** — that is
`providers.auto_link_email`, which already exists, defaults on, and is a different
question (`class-account-provisioner.php:86-110`). This phase decides whether the
address becomes a claimable identity **of the account the provider flow created**.

## The second half, which is not optional

Granting the row makes the first door reach the password screen. The account still
has no password anybody knows, so the door would stop lying and start presenting
an unfillable box instead. An improvement in honesty, not in outcome.

So the password step must be able to offer proof-by-OTP for an account that has no
usable password, and it must land **before** the row is granted. The mechanism
exists: `PasswordResetHandler` sets a password through `wp_set_password()` with no
knowledge of the old one (`class-password-reset-handler.php:179`), gated on an OTP.
Nothing new is invented; a door is put where the user is standing.

## One writer for a verified email

Three sites write the same fact today, in three different subsets:

| Site | Writes | Missing |
| --- | --- | --- |
| `ContactVerificationService::verify()` (`class-contact-verification-service.php:204-229`) | identity row, `user_email`, `META_EMAIL_VERIFIED`, clears `META_SYNTHETIC`, seeds `billing_email` | — |
| `AccountProvisioner::create_provider_user()` (`class-account-provisioner.php:170-175`) | `META_EMAIL_VERIFIED`, seeds `billing_email` | **the identity row** — this is the defect |
| `WooIntegration::save_account()` (`class-woo-integration.php:266-269`) | clears `META_SYNTHETIC`, seeds `billing_email` | housekeeping only; `block_unverified_email_change()` (`:223`) already guarantees the address cannot change here without OTP |

`UserManager::adopt_verified_email( $user_id, $email, $proof )` becomes the only
writer. This is the `FieldRegistry` move applied to a different four-way drift: one
function decides, so a caller cannot write three fifths of the fact and be
plausible.

**`$proof` is required and has no default.** Only `PROOF_OTP` and `PROOF_OAUTH`
reach the directory. A refactor that let an unproven address in would convert a
tidy-up into a way of minting identities, which is the opposite of the phase's
purpose.

## Ownership boundary

| Concern | Owner |
| --- | --- |
| Whether an address may become an identity | `adopt_verified_email()`, gated on `$proof` |
| Whether a *provider's* assertion counts as proof | `providers.<slug>.email_identity`, read only by the provisioner |
| Who owns a subject | `IdentityDirectory`, unchanged — it gains rows, not rules |
| Whether a password may be set without the old one | `PasswordResetHandler`, unchanged |
| What the security section renders | `AccountForm`, from a directory question — never from `user_email` |
| Migrating existing accounts | `Installer::maybe_upgrade()`, calling the same writer |

## The predicate, stated once so it is not re-derived wrongly

The security section branches on **"does the directory hold an `email` or `phone`
row for this user"**, asked through `IdentityDirectory::for_user()`.

Not on `UserManager::is_synthetic_email()`. A Google-first account has a real
address in `user_email`, so the synthetic test answers *false* and would route
exactly the population this phase is about into the branch that cannot serve them.
Recorded here because that is the wrong predicate that suggested itself first.

## Not in this phase

**A `password` credential row in the directory.** Making "password login" a thing
that can be switched on and off is a coherent idea and it is not needed here:
after 14.3 nothing has to know whether a chosen password exists, only whether an
identifier does. Reversible the day the answer has to be shown in an interface.

**A marker meta for "has set a password".** Considered and rejected. It answers a
question the directory should answer, it cannot be reconstructed for existing
accounts, and every consumer of it turned out to be better served by offering the
OTP route unconditionally.

**Telling an anonymous visitor which provider an address uses.** That would make
the identify screen reveal the *method*, not just the existence, of an account —
a stronger oracle than the one Phase 9.4 metered, and not retractable once
shipped. The row makes the door work; it does not make the door talkative.
