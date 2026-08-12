# Identity model

Normative specification. Where this document and the code disagree, the code is
wrong. Every claim here is backed by an executable test in `tests/identity/`.

Status: **specification agreed, implementation not started.** The tests that
encode this document are intentionally red. See `docs/refactor-plan.md`.

---

## 1. Why this document exists

The pre-refactor codebase held the answer to "who is this person?" in eight
places at once:

`wp_users.user_login`, `wp_users.user_email`, `usermeta.OmniWP_phone`,
`usermeta.OmniWP_*_verified_at`, `usermeta.OmniWP_synthetic_email`,
`usermeta.billing_phone` / `billing_email`,
`wp_OMNIWP_external_identities`, `usermeta.OmniWP_known_devices`.

No single one was declared authoritative. Three separate defects traced back to
that, not to three independent mistakes:

| Defect | Mechanism |
| --- | --- |
| Account takeover after a phone change | `user_login` keeps the retired number forever and was queried as an identity key |
| WooCommerce orders storing `00076` instead of `Phường Cầu Giấy` | checkout wrote through a value-passed `$data` array that WooCommerce discards |
| Billing phone silently overwritten by the login phone | profile data and identity data shared one write path |

The codebase already contained the correct pattern — a dedicated table with
`UNIQUE (provider, provider_subject)` and a `linked_by` provenance column — but
applied it to *only* federated providers. Phone and email, the two identifiers
that actually gate account recovery, got scattered user meta plus a shadow copy
in `user_login`.

This refactor does not invent a model. It generalises the one that already
works to the identifiers that lack it.

---

## 2. Two invariants

### Invariant 1 — one source of truth for "who"

> Only the `OmniWP_identities` table answers the question *"which user owns
> this subject?"*. Not `user_login`, not `user_email`, not user meta, not
> `OmniWP_identity_history`.

Enforced by three independent mechanisms, because one is not enough:

1. **Structural** — `user_login` becomes an opaque value (see §3), so WordPress
   core physically cannot resolve a phone number to a user.
2. **Architectural fitness test** — `tests/identity/run-fitness-tests.php`
   scans `includes/` and fails on identity lookups through any other store.
3. **Encapsulation** — `IdentityDirectory` is the only class permitted to
   construct `IdentityRepository`.

### Invariant 2 — identity and profile are different data domains

> **Identity** is proven, carries `verified_at`, and is never accepted from a
> form. **Profile** is user-asserted, may be wrong, and is freely editable.
>
> Identity → Profile: **seed only when the profile field is empty.**
> Profile → Identity: **never.**

`billing_phone`, `billing_email`, every other `billing_*` field, `display_name`,
date of birth and gender are profile. `identities.subject` is identity.

The practical consequence, which the pre-refactor code got wrong: a customer
whose delivery contact is a family member's phone must be able to keep it. The
login phone must not overwrite it on save.

---

## 3. Why `user_login` must be opaque

This is the one design decision that cannot be enforced by our own code, because
the violation lives in WordPress core.

The `authenticate` filter chain. Verified against the WordPress 7.0.2 source
installed at `C:\Users\PC\Local Sites\wp\app\public`, not from memory —
`wp-includes/default-filters.php:503-506`:

| Priority | Handler | What it does |
| --- | --- | --- |
| 5 | `LoginHandler::gate_lockout` | plugin |
| **20** | **`wp_authenticate_username_password`** (core) | **`get_user_by( 'login', $username )`** — `pluggable.php:833` |
| 20 | `wp_authenticate_email_password` (core) | `get_user_by( 'email', $username )` |
| 20 | `wp_authenticate_application_password` (core) | resolves the Basic-auth username, which may be a login |
| 30 | `LoginHandler::authenticate_by_phone` | plugin |
| 99 | `wp_authenticate_spam_check` (core) | multisite spam flag |

**Three** core handlers resolve an identifier at priority 20, all of them before
any plugin identity code at 30. If `user_login` holds a phone number, core
authenticates that number directly and `IdentityDirectory` is never consulted.
A fitness test cannot stop this; it is not our code path.

Note that `wp_authenticate_application_password` widens the exposure beyond the
interactive login form to the REST API, which makes the structural fix more
valuable rather than less.

WordPress also offers no supported way to change `user_login`, so it is
permanently stale the moment a user changes their phone.

Therefore:

```
user_login  =  'ow_' + 24 hex characters      generated once, never changes,
                                             never displayed as an identifier,
                                             never typed by a human
```

`user_email` is deliberately **not** made opaque. It behaves differently in a
way that matters: `wp_update_user()` keeps it in sync on change, so it is
self-correcting rather than permanently stale, and it must stay real for mail
delivery. Core resolving an email to a user is therefore acceptable — the
directory holds the same fact.

**Accepted cost.** The WordPress Users list shows `ow_9f2c…` instead of a phone
number. Mitigated by `display_name` (already the full name) plus an "Định danh
chính" column and a `user_search_columns` hook so support staff can still search
by phone. Roughly 20 lines, specified in Phase 3.

---

## 4. Schema

`OMNIWP_DB_VERSION` moves from `2` to `3`. `wp_OMNIWP_external_identities`
is dropped; federated providers are no longer a special case.

```sql
wp_OmniWP_identities              -- authorization index: who owns what NOW
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT
  user_id      BIGINT UNSIGNED NOT NULL
  channel      VARCHAR(32)     NOT NULL   -- phone | email | google | zalo | …
  subject      VARCHAR(191)    NOT NULL   -- 84969789475 | a@b.com | google sub
  is_primary   TINYINT UNSIGNED NOT NULL DEFAULT 0
  verified_at  DATETIME        NOT NULL   -- unverified rows never enter this table
  linked_by    VARCHAR(32)     NOT NULL   -- registration|otp|oauth|auto_email|admin
  meta_json    LONGTEXT        NULL       -- provider claims
  created_at   DATETIME        NOT NULL
  PRIMARY KEY (id)
  UNIQUE KEY  subject_owner (channel, subject)
  KEY         user_channel  (user_id, channel)

wp_OmniWP_identity_history        -- the old frame: append-only, never authenticates
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT
  user_id      BIGINT UNSIGNED NOT NULL
  channel      VARCHAR(32)     NOT NULL
  subject      VARCHAR(191)    NOT NULL
  event        VARCHAR(20)     NOT NULL   -- claimed|verified|retired|relinked|rejected
  reason       VARCHAR(64)     NULL
  actor        VARCHAR(32)     NOT NULL   -- self|admin|system
  occurred_at  DATETIME        NOT NULL
  PRIMARY KEY (id)
  KEY subject_lookup (channel, subject)
  KEY user_id (user_id)
```

### Why two tables instead of one with `retired_at`

A single table needs "at most one *active* owner per `(channel, subject)`",
which MySQL cannot express without a generated column. `dbDelta()` mis-diffs
generated columns and re-issues `ALTER TABLE` on every request. Two tables need
only a plain `UNIQUE KEY`, which `dbDelta()` handles correctly.

The semantic split is also cleaner, and it is load-bearing for Invariant 1:
`identities` is an authorization index, `identity_history` is an audit trail.
Reading history for **policy** ("this number had a previous owner, add friction")
is permitted. Reading it for **authentication** ("this number belongs to user
42") is the defect this refactor exists to remove.

`subject VARCHAR(191)` with `utf8mb4` is 764 bytes, just inside the 767-byte
index limit on older MySQL. This is the width the plugin already used.

---

## 5. The flow

```
IDENTIFY ────────► RESOLVE ────────► PROVE ────────► ACT
Claim              Resolution        AuthProof       intent × state
{channel,subject}  {state,user_id}   unforgeable     single choke point
no writes          no writes
```

### RESOLVE returns exactly four states

| State | Meaning |
| --- | --- |
| `UNKNOWN` | no active owner |
| `KNOWN` | owned by user U |
| `RETIRED` | no active owner, but history records a previous one |
| `CONFLICT` | more than one candidate owner (only reachable via email auto-link) |

### ACT is a decision table, not nested conditionals

| intent \ state | `UNKNOWN` | `KNOWN` | `RETIRED` | `CONFLICT` |
| --- | --- | --- | --- | --- |
| `register` | create user + link | "already registered, please sign in" | create **new** user + history entry | reject |
| `login` | "no such account" | issue session | same as `UNKNOWN` | reject |
| `recover` | "no such account" | issue reset grant | **same as `UNKNOWN`** | reject |
| `add_identity` | link to signed-in user | ≠ self → conflict; = self → no-op | link + history entry | reject |

The cell `recover × RETIRED` is the point. The previous owner is unreachable
because RESOLVE has no active owner to return. The takeover defect is not fixed
here — it becomes **unrepresentable**. The old code was defective precisely
because its resolve step consulted history (`user_login`) and reported `KNOWN`.

### Proof cannot be forged

```php
final class AuthProof {
    private function __construct( ... ) {}              // uninstantiable from outside
    public static function fromOtp( VerifiedClaim $c ): self
    public static function fromOAuth( VerifiedClaim $c ): self
    public static function fromPassword( WP_User $u ): self
}

SessionIssuer::issue( AuthProof $proof, AuthContext $ctx )   // proof is mandatory
```

Only the PROVE layer can construct an `AuthProof`. Issuing a session without
proof becomes a type error rather than a review finding. `SessionIssuer` remains
the single owner of `wp_set_auth_cookie()` — that part of the existing
architecture is correct and is the foundation everything above rests on.

---

## 6. Channel contract — the scaling mechanism

```php
interface IdentityChannel {
    public function id(): string;                     // 'phone'
    public function normalize( string $raw ): string; // canonical subject, or ''
    public function is_valid( string $subject ): bool;
    public function proof_method(): string;           // 'otp' | 'oauth'
    public function is_self_asserted(): bool;         // phone/email = true
    public function can_receive_otp(): bool;
    public function label(): string;
    public function mask( string $subject ): string;
}
```

Adding Telegram, Apple or Zalo ZNS is **one class plus one registry line**. No
new tables, no new OTP purposes, no changes to register / login / recover. That
is the operational definition of "scales long-term" for this project, and
`tests/identity/run-contract-tests.php` asserts it by registering a fictional
channel and requiring zero edits elsewhere.

### Proof and intent are separate concerns

The pre-refactor code had six `PURPOSE_*` constants and was growing one per
feature, because it conflated two things:

```
proof  = demonstrated control over (channel, subject)     otp | oauth | password
intent = what the user is trying to do                    register | login | recover | add_identity | step_up
```

Splitting them is what makes the channel count and the intent count independent.

`id_mode` (`phone_only` / `email_only` / `both`) is replaced by per-channel
enable flags in `ChannelRegistry`; three hard-coded values do not generalise.

---

## 7. Deliberately unchanged

An honest specification states what is already right:

- `SessionIssuer` as the single point of cookie issuance.
- OTP cryptography: `random_int` generation, HMAC-only storage, `hash_equals`
  comparison, atomic `consume_if_open`, atomic attempt increment.
- The address module's rule that names are always looked up from codes and never
  accepted from the client. That is Invariant 2 applied to addresses; it was
  already correct and serves as the reference implementation.
- The `external_identities` table *shape*, which becomes the template for
  `identities`.
- Template loader, theme override mechanism, webhook placeholder engine.

---

## 8. Known blended boundary

`provider_auto_link_email` (currently defaulting to on) attaches a federated
identity to an existing user matched by verified email. It is the only place two
channel namespaces intentionally merge, and the only way `CONFLICT` can arise.

Existing guards: verified email required, multi-owner emails rejected,
synthetic emails rejected. This is a deliberate UX trade-off, not an oversight.
Sites wanting strict channel separation should turn it off and accept that a
Google sign-in creates a second account.
