# Abuse boundary — normative spec

Status tracker: [`refactor-plan.md`](refactor-plan.md) Phase 9. Execution
briefs: [`abuse-boundary/`](abuse-boundary/).

This file carries the **decisions**. It says what the abuse boundary is, which
instrument answers which threat, and where the plugin's responsibility ends. It
records no status — status lives in the tracker and nowhere else.

---

## The problem

The identity layer is finished and sound. Ownership lives in
`smartlogin_identities`, `user_login` is opaque, `AuthProof` makes "no proof, no
session" a type error. What is missing is the layer outside it.

Every abuse control the plugin has is scoped to **one** destination or **one**
IP:

| Control | Scope | Defeated by |
| --- | --- | --- |
| `otp.resend_cooldown` | `(destination, intent)` | rotating the destination |
| `otp.max_per_destination_hour` | destination | rotating the destination |
| `otp.max_per_ip_hour` | IP | rotating the IP |
| `login.max_attempts` | `(identity, IP)` | rotating the identity |

Nothing counts across the whole site, so an attacker rotating both axes meets no
ceiling at all. That is the gap, stated in one sentence: **the plugin can measure
an abuser, but not an abuse.**

## What that costs, concretely

`handle_identify()` (`class-form-controller.php:282`) → `start_identity()`
(`class-register-handler.php:115`) → `OtpService::issue()` sends an SMS to an
arbitrary number with no account, no payment and no challenge. The per-IP ceiling
of 10/hour is the only thing in the way, so a thousand addresses buy ten thousand
messages an hour.

`Phone::is_valid()` (`class-phone.php:97`) applies carrier-prefix validation only
when the country code is `84`; every other code falls through to a generic 8–15
digit check. `+254712345678` is accepted. That is the precondition SMS pumping
needs — codes aimed at premium ranges in a country where the attacker shares the
termination revenue.

And the enumeration branch is not covered at all. `RateLimiter` is reached only
from inside `OtpService::issue()`, i.e. the *"no such account"* path. When the
subject exists, `handle_identify()` returns the password screen having passed
through no limiter. The README asserts otherwise, which makes it the more serious
of the two defects: a reader auditing the plugin concludes it is covered.

---

## Two instruments, not one

They are often conflated and they answer different questions.

| Instrument | Caps | Trigger | Recovers by |
| --- | --- | --- | --- |
| **Budget / kill switch** | what the site *spends* | volume across all destinations | time (`halt_minutes`) |
| **Circuit breaker** | what a failing gateway *costs in workers* | consecutive delivery failures | a half-open probe |

A site can be spending nothing and still be falling over, because the gateway is
slow rather than expensive. It can also be healthy and being drained. Both
instruments are required, and neither substitutes for the other.

---

## Decisions

### Queued delivery is rejected for this phase

The textbook fix for worker exhaustion is to send asynchronously. It costs a
property this codebase deliberately paid for: `OtpService::issue()` deletes the
OTP row and returns the failure to the user when sending fails
(`class-otp-service.php:139-142`), precisely so nobody waits on an OTP screen for
a message that was never sent.

A queue reports "sent" before sending and gives that back. So the worker
exhaustion is fixed directly — clamped timeout, a real backoff, a breaker — and
the synchronous failure signal is kept. Queueing stays on the table as its own
brief, with the "queued vs delivered" wording being the decision it has to make.

### Fail-open at the IP, fail-closed at the challenge

These point in opposite directions on purpose.

`Client::ip()` returning empty leaves per-IP limiting off. It **stays** that way:
CLI, cron and unusual SAPI contexts legitimately have no `REMOTE_ADDR`, and
failing closed there breaks real operations to defend a case the site budget
already covers. What changes is the silence, not the behaviour.

Captcha verification that cannot complete **refuses the send**. The only thing
failing open protects there is the attacker.

The rule underneath: fail open where the failure mode is *our own infrastructure
being unusual*, fail closed where it is *the check itself being unavailable*.

### A boolean is not enough for proxy trust

`Client::ip()` defaults to `REMOTE_ADDR` and the comment explaining why is
correct. The problem is that `smart_login_trust_proxy_headers` is the only
interface — no control, no readiness check, one line in a hook list.

But a plain "trust the headers" flag is worse than nothing. An attacker who finds
the origin address and connects directly can then set `CF-Connecting-IP` per
request and dissolve every per-IP limit in the plugin. **The header may only be
trusted when the peer is trusted**, so the setting is a CIDR allowlist and trust
with an empty list is a readiness failure, not a warning.

No Cloudflare range list is shipped. A hardcoded list goes stale silently, which
is how a security control becomes a liability.

### An empty country allowlist means restricted, not unlimited

`identity.allowed_country_codes` empty reads as *"only the configured default
country code"*. That makes the safe reading the default one and needs no
migration, because the resulting behaviour on an existing VN site is what it
already does. The help text has to say so — an empty field that silently means
"restricted" is only safe if the screen admits it.

`smart_login_phone_is_valid` remains the last word, as it already is.

### The `wp_rest` nonce is not a bot control

For anonymous visitors it is constant for 12–24 hours across *all* of them
(uid 0, empty session token). It is a CSRF control and nothing more. This is the
argument for the budget, the identify ceiling and the captcha existing at all,
and it belongs in the README next to the `X-WP-Nonce` requirement so nobody
mistakes one for the other.

### Sampling never drops the forensic events

An audit log that amplifies the attack it records is a defect. But a cap that
discards `lockout`, `user_registered`, `password_reset`, `otp_budget_halted` or
any `provider_*` event defeats its own purpose — those are low-volume and
load-bearing. High-volume events degrade to one aggregated row per hour, so the
signal survives even when the detail does not.

---

## Ownership boundary

| Concern | Owner |
| --- | --- |
| Ceilings, breaker, challenge, audit | Smart Login |
| Which country codes are legitimate | **Operator**, via settings |
| Whether the peer is a trusted proxy | **Operator**, via CIDR allowlist |
| L3/L4 flood, TLS termination, bot scoring | **CDN / WAF**, not this plugin |
| Gateway balance alerts | **Gateway**, but the plugin must not be the last to know |

The plugin's job is to stop being the cheapest way to spend the operator's money.
It is not a WAF and must not pretend to be one; what it owes is a ceiling, a
signal, and a screen that shows both.

---

## Defaults

Chosen so a real launch is not blocked. A ceiling low enough to break the first
day gets switched off and never switched back on, which is worse than a high one.

| Setting | Default | Reasoning |
| --- | --- | --- |
| `security.max_per_site_hour` | 100 | generous; readiness warns at 0 |
| `security.max_per_site_day` | 500 | |
| `security.halt_minutes` | 60 | bounded, clearable from the admin screen |
| `security.max_identify_per_ip_hour` | 30 | enumeration is the target, not browsing |
| `security.max_login_failures_per_ip_hour` | 30 | loose: office NAT, school, CGNAT |
| `security.breaker_threshold` / `cooldown` | 5 / 300s | |
| `security.audit_max_per_event_hour` | 500 | |
| `sms.timeout` | 5, clamped 2–15 | the clamp matters more than the default |
| `identity.allowed_country_codes` | `''` | = default country code only |
| `security.captcha_mode` | `adaptive` | invisible under normal load |

---

## Out of scope, recorded so it is not lost

- **Queued OTP delivery** — see the decision above. Needs its own brief for the
  UX question.
- **Budget split by trust level** — signed-in `add_identity` sends currently
  share the anonymous budget. A refinement, not a launch requirement.
- **Per-country spend caps** — the finer-grained form of the allowlist, worth
  having if the allowlist is ever widened beyond a handful of codes.
- **Gateway failover** — `TransportRouter` is the right seam and the breaker
  supplies the trigger, but it is a feature, not a control.
