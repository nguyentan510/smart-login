# Delivery routing and the automation bus

Normative spec for Phase 10. Status lives in
[`refactor-plan.md`](refactor-plan.md); execution briefs live in
[`delivery-routing/`](delivery-routing/).

---

## The problem

An OTP's transport is decided by one line:

```php
return ( false !== strpos( $destination, '@' ) ) ? 'email' : 'sms';
```
`includes/OTP/Transports/class-transport-router.php:41`

The shape of the destination picks the transport. Nothing else gets a say — not
the administrator, not a setting, not a filter. Consequences:

- A site that wants an external automation platform (n8n, Make, a bespoke
  service) to deliver its codes cannot have one. It can add a transport through
  `smart_login_otp_transports`, but nothing will ever route to it, because
  routing does not read the transport list.
- An email destination can never reach a webhook and a phone destination can
  never reach `wp_mail()`. Both are legitimate configurations.
- The one escape hatch, `smart_login_dispatch_otp`
  (`class-transport-router.php:73`), takes over delivery *entirely* — it is
  all-or-nothing, and it is code, not configuration.

Separately, the plugin emits no outbound event stream at all. `AuditLog` holds
nineteen event constants (`includes/Security/class-audit-log.php:19-39`) and
`do_action( 'smart_login_otp_sent', … )` fires
(`includes/OTP/class-otp-service.php:172`) but carries no code, so an external
system can neither send an OTP nor react to one.

## What this phase decides

### D1 — Transport is chosen by a routing table

`transport_for()` stops reading the destination's shape and starts reading a
setting, one per identity channel:

| Setting | Choices | Default |
| --- | --- | --- |
| `delivery.route_phone` | `sms`, `automation` | `sms` |
| `delivery.route_email` | `email`, `automation` | `email` |

The defaults reproduce today's behaviour exactly, so no site changes on upgrade
and the existing suites stay green without amendment. That is the property that
makes 10.1 shippable on its own.

The *identity channel* is still derived from the destination's shape, and that
stays correct — `OtpService:343` and `:375` already do it, and an address
containing `@` genuinely is an email identity. What changes is only the step
after: which transport carries it.

### D2 — One endpoint, two roles

The automation endpoint is configured once and does two distinct jobs.

| | Transport role | Bus role |
| --- | --- | --- |
| Active when | a channel's route points at `automation` | the event is ticked in the checklist |
| Call | blocking, timeout-clamped | non-blocking (`'blocking' => false`) |
| Carries the code | yes, on `otp.send` only | never |
| Failure | rolls back the OTP row, user sees an error | one audit record, nothing user-facing |
| Breaker id | `automation` | `automation_bus` |

**Two breakers, not one.** A bus endpoint that is down must never be able to stop
OTP delivery. Sharing a breaker between the roles would make exactly that
possible, and it would present as an outage whose cause is an analytics webhook.

A site with the bus on and no route pointed at automation is a valid, common
configuration. The screen must say which role is active rather than leaving the
administrator to infer it, because "configured but not delivering" and "broken"
look identical otherwise.

### D3 — The payload is a fixed signed envelope

```json
{
  "event": "otp.send",
  "channel": "phone",
  "destination": "84969789475",
  "intent": "login",
  "code": "482913",
  "ttl_seconds": 300,
  "expires_at": "2026-08-02T10:05:00+00:00",
  "delivery_id": "9f2c…",
  "site": "https://example.com",
  "timestamp": 1754136000
}
```

Fixed, not templated. The existing free-text body template
(`class-field-registry.php:410`) exists because an SMS gateway's API cannot be
changed to suit us; the receiver here is the administrator's own automation,
which can. And a signature computed over an administrator-editable body is a
signature over something the administrator can accidentally make unparseable —
the two features are in direct tension, so the envelope wins.

`code` appears on `otp.send` and on no other event. `channel` is an explicit
field because `Placeholders` blanks `{{email}}` for phone destinations and
`{{phone}}` for email ones (`class-placeholders.php:31-34`), which would leave
the receiver inferring the channel from which field is empty.

Headers:

```
X-Smart-Login-Signature: sha256=<hmac of the raw body, key = automation.secret>
X-Smart-Login-Timestamp: <unix seconds>
X-Smart-Login-Delivery:  <delivery_id>
X-Smart-Login-Event:     <event name>
```

`delivery_id` is already stable across retries
(`class-webhook-transport.php:65`), which is what makes it usable for
deduplication on the receiving end.

### D4 — HTTPS is enforced at save time

A plaintext endpoint carrying a live OTP is not a warning; it is a rejection. New
sanitise rule `https_url`, applied to `automation.url`. Saving is the only moment
the administrator is present to be told why, so the enforcement belongs there and
not in the sender.

### D5 — Secret storage stops being an if-chain

```php
private static function store_secret( string $path, string $secret ): void {
    if ( 'security.captcha_secret' === $path ) { … }
}
```
`includes/class-settings.php:226-234`

One path today, two once `automation.secret` lands, and a field declared
`type => 'secret'` whose path nobody remembered to add here is silently *not
stored* — the value is pruned from the option array at `:219` regardless. That is
a field that accepts input and discards it without a word, which is the same
defect class `FieldRegistry` was written to remove.

Route generically through `SecretBox` keyed by the field path. Captcha keeps its
own accessor for the one place that reads it.

### D6 — Cost accounting follows the channel, not the transport

```php
$sms  = $repo->count_recent_by_transport( 'sms', DAY_IN_SECONDS );
$cost = Settings::get_int( 'otp.sms_unit_cost', 0 );
```
`includes/Admin/class-readiness.php:220-221`

Route `phone` at `automation` and this reads **zero** while the automation is
still sending real SMS and spending real money. The estimate must count phone
OTPs, whichever transport carried them.

The `identity_channel` column exists but is not reliably populated —
`class-otp-service.php:110` falls back to `''` when neither the payload nor the
context supplies it. `OtpService:336-345` already handles that by deriving the
channel from the destination when the column is empty; counting reuses that
derivation rather than trusting the column.

### D7 — Readiness becomes route-aware

```php
if ( Settings::phone_enabled() && ! ( new WebhookTransport() )->is_available() )
```
`includes/Admin/class-readiness.php:136`

Hard-codes the assumption D1 removes. It must ask the router which transport
serves each enabled channel and check that one — and it gains a new dangerous
configuration to report: a channel routed at `automation` with no endpoint
configured. That failure is otherwise invisible until the first visitor presses
Đăng ký, which is the exact scenario this class was written for
(`class-readiness.php:5-9`).

## Security position, stated plainly

This phase deliberately weakens a property the plugin currently holds: the
plaintext OTP never leaves the site. It is hashed at rest
(`class-otp-service.php:113`), redacted out of admin output and logs
(`class-webhook-transport.php:302-308`), and echoed to the screen only in dev
mode (`:183-185`).

Sending it to an administrator-nominated endpoint is a real expansion of the
blast radius, accepted because the alternative — an automation platform that can
observe an OTP but not deliver it — does not serve the use case. The compensating
controls are HTTPS enforced at save (D4), an HMAC signature the receiver can
verify, a timestamp and delivery id for replay rejection, and the existing
redaction discipline applied unchanged to the new transport's own debug output.

What is **not** compensated: an administrator who points the endpoint at an
untrustworthy service. No control in this phase helps there, and the help text
must say so rather than implying the signature makes the destination safe.

## Ownership boundary

| Concern | Owner |
| --- | --- |
| Which transport serves a channel | `TransportRouter` — the single routing authority |
| Whether a transport can work at all | the transport's own `is_available()` |
| Whether a failing transport is called | `CircuitBreaker`, one instance per transport id |
| Whether the site may send at all | `RateLimiter` — unchanged by this phase |
| Signing and shipping an envelope | the automation sender, used by both roles |

Rate limiting needs no change: limits count OTP **rows**
(`class-rate-limiter.php:47-71`), one row per `issue()`, so routing cannot
double-count.

## Not in this phase

**Email template groups.** The one shared `email.subject` / `email.body` pair
(`class-field-registry.php:488-512`) serves all four intents, so a password-reset
mail reads identically to a login mail. That is a real gap and a separate body of
work — templates, per-template placeholder sets, an HTML layout wrapper. It is
listed as Phase 11 in the tracker and is not started here.

**Queueing.** Rejected for the same reasons 9.3 recorded: a queue turns a
delivery failure into a silent delay, and WP-Cron cannot promise the latency an
OTP needs.
