# Sending a code

The delivery settings are correct and nearly unusable. Every control does what
it says; the words it says mean different things in different tabs, and an
administrator who reads them carefully still arrives at a configuration that
sends nothing.

This phase does not add a feature. It removes a vocabulary.

Reported 2026-08-08, and the report is the specification: *"Việc cấu trúc sai,
nên tôi hiểu sai nên cấu hình sai."* The install that produced it had a working
SMS gateway URL saved in `sms.url`, `sms.enabled` on, and `delivery.route_phone`
pointed at `automation` with an empty endpoint. Both halves were reasonable
readings of the screen. Together they delivered nothing.

Related: [`delivery-routing.md`](delivery-routing.md) is the phase this one
revises — D1, D2 and D8 in particular.

---

## Findings

### 1. "Nhà cung cấp" is the label of three different settings

Measured by Rule 16 rather than counted by hand, and the count was wrong when
counted by hand — the first draft of this spec said two:

| Owner | What it actually means |
| --- | --- |
| section `provider` (`class-field-registry.php:100`) | an **identity** provider — Google |
| `sms.preset` (`class-field-registry.php:445`) | an **SMS vendor** — eSMS.vn |
| `security.captcha_provider` (`class-field-registry.php:880`) | a **captcha vendor** — Turnstile, hCaptcha |

Three settings, one word, on three different tabs. A fourth sense exists in the
code but not on screen: `TransportInterface` (`class-transport-interface.php:20`)
is a route out, not a vendor, and its own docblock has to spend a paragraph
saying so.

### 2. Two webhook mechanisms exist, and nothing in the naming separates them

Both POST JSON to a URL the administrator supplies. The difference is which side
bends:

| | `sms` — `WebhookTransport` | `automation` — `AutomationTransport` |
| --- | --- | --- |
| Shape | the plugin bends to the vendor: free-text `url`, `method`, `body`, `headers`, `success_path` | the vendor bends to the plugin: one fixed envelope |
| Signature | whatever the admin adds | HMAC, mandatory |
| HTTPS | not enforced | enforced at save (D4) |
| Presets | three | none |

### 3. The SMS preset list already advertises the Automation tab's use case

`class-gateway-presets.php:68`:

```php
'generic' => __( 'Webhook JSON (n8n / Make / Zapier)', 'smart-login' ),
```

The tab named **Automation** documents itself as being for n8n, Make and Zapier.
So does a preset inside **Kênh SMS**. An administrator holding an n8n URL has two
correct-looking homes for it and no way to choose between them. This is the
mechanism of the reported defect, stated as one line of code.

### 4. `automation` carries two roles that share settings but not consequences

`delivery-routing.md` D2 already tabulates them — blocking versus non-blocking,
carries the code versus never, rolls back a registration versus writes one audit
row. Six of the seven `automation.*` fields serve **both** roles; only
`automation.events` is bus-only. Turning on an event stream therefore configures
an OTP transport as a side effect.

D2 closes with the requirement this phase exists to satisfy, written two phases
before anybody hit it:

> *The screen must say which role is active rather than leaving the administrator
> to infer it, because "configured but not delivering" and "broken" look
> identical otherwise.*

No screen says it.

### 5. Routing `automation` on both axes silently collapses them onto one endpoint

`TransportRouter::ROUTES` lets `route_phone` and `route_email` each choose
`automation`, and both resolve to the same `AutomationEndpoint`. A site that
picks it twice has one URL receiving two channels, which is legitimate and
completely invisible on the screen that offered the choice.

### 6. The decision and the thing it decides live on different tabs

`tab_parents()` (`class-field-registry.php:67-74`) puts the routing selects on
the parent **Gửi mã** while `sms.*`, `email.*` and `automation.*` sit on four
children. Reading whether a channel is in use requires holding the parent's state
in your head while looking at a child. Nothing on **Kênh SMS** says "routing does
not point here, so nothing you configure on this screen will send."

### 7. The only surviving difference is an envelope shape

Strip the naming away and `automation`-as-transport differs from the `generic`
SMS preset in exactly one respect: a fixed, HMAC-signed body instead of a
free-text one. That is the definition of a **preset** — a body template plus a
credential list. It has been occupying a slot in the routing table, which is the
one place in this plugin where a wrong choice produces silence.

### 8. The admin side already had the right model

`Readiness` asks the router which transport serves each channel and prints its
id (`class-readiness.php:171-181`). It has named `automation` correctly the whole
time. Two descriptions of one fact existed; the one facing the visitor drifted,
which is what D8 has just finished repairing at the sentence level. This phase
removes the second description instead.

---

## Decisions

### D1 — The routing table goes away

`delivery.route_phone` and `delivery.route_email` are deleted. The identity
channel decides the transport directly: a phone goes to the SMS channel, an email
goes to the email channel.

This reverses 10.1's D1, and the reversal is not a retraction of it. 10.1 existed
so a site could reach an automation platform for phone delivery, and the routing
table was how that was expressed. D2 below reaches the same platform through a
mechanism that was already there and is more flexible, so the table now buys
nothing and costs a 2×2 matrix in which one cell delivers nothing and says
nothing.

`TransportRouter::channel_for()` stays exactly as it is — it answers a property
of the identifier, which was always correct and is not what 10.1 changed.

### D2 — One transport per channel; the provider selects the *wire format*

**Revised during 20.2, before any code moved. The first draft of this decision
was wrong and the code said so.**

That draft made the signed envelope a fourth `GatewayPresets` entry and claimed
"nothing is lost". Reading `EnvelopeSigner` before implementing showed four
controls that a preset cannot carry:

| Control | Where it lives | Why a preset cannot hold it |
| --- | --- | --- |
| Signature over **code-built bytes**, encoded once | `class-envelope-signer.php:3-14` | `GatewayPresets::resolve()` writes `sms.body` as a *template*; `WebhookTransport` renders it at send time. Signing a rendered template is the exact hazard that file was written to prevent |
| Administrator headers **may add, never replace** | `class-automation-endpoint.php:64-73` | `WebhookTransport`'s header loop sets whatever is configured, including over a signature header |
| HTTPS enforced at save | `'sanitize' => 'https_url'` on `automation.url` | a sanitizer is a property of a *field*, not of a value a preset happens to hold. `sms.url` is plain `url` |
| Secret encrypted at rest | `'type' => 'secret'` | gateway credentials marked `'secret' => true` travel a different path |

The mistake was a category error. **A preset is a body template plus a credential
list. The signed envelope is a wire format implemented in code.** They are not
the same kind of thing, and the first draft only looked right because both end up
as "POST JSON somewhere".

So the layering changes instead, and it lands closer to the mental model the
report described than the first draft did:

```
identity channel  →  one transport      (phone → the SMS transport)
                        └─ provider     →  wire format
                                           esms     template
                                           generic  template
                                           signed   code-built envelope, HMAC
                                           custom   template, admin-authored
```

`AutomationTransport`'s **transport role** retires; `WebhookTransport` remains the
single transport for the SMS channel and gains a branch on the provider's declared
envelope. `EnvelopeSigner` is reused unchanged — the whole point is that the
envelope keeps being built where it is signed.

The `signed` provider's endpoint and key become `sms.signed_url` (`https_url`) and
`sms.signed_secret` (`secret`), so all four controls above survive as field
properties rather than as prose. They are not credentials in the `sms.credentials`
array, and the reason is that array has neither guarantee.

The **bus role does not retire.** It keeps `AutomationEndpoint`, `automation.*`,
its own breaker id, and its own screen.

**What this costs.** `WebhookTransport` gains a conditional it did not have, which
is a real complexity increase in the one class that already carries the most. The
alternative was losing a security control to make a settings screen tidier, which
is not a trade this project makes.

### D3 — The bus becomes a top-level tab, named for what it does

`delivery-automation` stops being a child of **Gửi mã** and becomes a top-level
tab **Thông báo & Tích hợp**, off by default.

It is not under delivery because it delivers nothing to a visitor. It is not
called "Automation" because that named a platform rather than a behaviour, and
because the behaviour is one-directional: the site tells an outside system what
happened. `EventBus` says so in code — it never gates anything and never carries
the code (`class-event-bus.php:14-17`). The name says the same thing to somebody
who will not read the code.

### D4 — One word, one meaning

| Concept | Today | From here |
| --- | --- | --- |
| Google | Nhà cung cấp | **Nhà cung cấp đăng nhập** |
| eSMS, n8n endpoint | preset / webhook / automation | **Nhà cung cấp SMS** |
| Outbound event stream | Automation | **Thông báo & Tích hợp** |
| `TransportInterface` | kênh / transport | **Kênh** — internal only, never a label |

"Webhook" is reserved for the event tab. "API" appears only inside the custom
gateway's advanced fields, where it is the accurate word and "webhook" is not.

### D5 — A channel screen states whether it is serving anything

Each channel tab opens with one line of status: which identity channel it serves,
or that it is enabled but nothing routes to it. This is D2's unmet requirement
from `delivery-routing.md`, and after D1 it is cheap — the answer is no longer a
lookup through a routing table, it is whether the channel is enabled.

### D6 — Migration is explicit, and refuses to be silent

An install with `delivery.route_phone = automation` is upgraded, not reset:
`automation.url` and `automation.secret` become the `signed` preset's credentials
and `sms.preset` becomes `signed`. Where the old configuration cannot be
expressed — `route_email = automation`, which had no SMS-side equivalent — the
upgrade records an admin notice naming the setting rather than choosing for the
site.

A silent exception is a lie with a longer half-life, and this rename crosses
`includes/`, `templates/`, `tests/` and `docs/` — the boundary this project has
been bitten at five times.

### D7 — Two breakers stay two breakers

Unchanged from `delivery-routing.md` D2. A dead event endpoint must never be able
to stop a sign-in, and merging the breakers is exactly how that becomes possible.
The bus keeps `automation_bus`; the `signed` preset runs on the SMS breaker with
every other gateway.

---

## Deferrals

**No new gateway presets.** The eSMS parameters are the ones this project has
verified against a live account; a preset with the wrong parameter names is worse
than no preset because it looks authoritative while failing. Others arrive
through `smart_login_gateway_presets` when somebody has an account to test with.

**Email keeps one transport.** `wp_mail()` and nothing else. Routing email at an
external sender is the same class of change this phase is undoing, and no report
has asked for it.

**The `delivery` tab's own layout is not redrawn** beyond removing the routing
section and adding D5's status line. Re-ordering the OTP fields is a separate
argument with separate evidence.
