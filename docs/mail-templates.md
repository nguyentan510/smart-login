# Mail templates

Normative spec for Phase 11. Status lives in
[`refactor-plan.md`](refactor-plan.md); execution briefs live in
[`mail-templates/`](mail-templates/).

---

## The problem

The plugin sends three kinds of mail and can template exactly one of them,
badly.

| What goes out | Where it is written | Can the admin change it? |
| --- | --- | --- |
| Every OTP, all four intents | `email.subject` / `email.body` (`class-field-registry.php`) | One wording for all four |
| Site-budget halt alert | `class-rate-limiter.php:267` | No |
| Transport breaker alert | `class-circuit-breaker.php:163` | No |

`OtpService` has four intents — `register`, `login`, `recover`,
`add_identity` (`class-auth-action.php:29-32`) — and `MailTransport` renders the
same subject and body for all of them. A password reset goes through
`PasswordResetHandler::start()` → `OtpService::issue()` with intent `recover`
and arrives worded *"Mã xác thực của bạn là…"*, identical to a login code.

`{{intent}}` is exposed as a placeholder (`class-placeholders.php:36`) but a
template can only interpolate it, never branch on it. So the admin can print the
word "recover" in the body and nothing else.

There is also no layout. `email.is_html` defaults to `0` and the body *is* the
entire message — no header, no footer, no button. "Thiết kế template email" is
not a matter of editing that textarea; there is nothing to edit it into.

## What this phase decides

### D1 — Templates are keyed by intent, and every one of them is optional

```
email.templates.register.subject      email.templates.register.body
email.templates.login.*               email.templates.recover.*
email.templates.add_identity.*        …
```

An intent with an empty override falls back to `email.subject` / `email.body`.
That is the no-migration property: a site that never opens this screen keeps
exactly today's behaviour, and only the intents actually customised diverge.

The shared pair stays and is renamed in the UI to "Mặc định", not deleted. It is
the fallback, and deleting a fallback in favour of four copies of the same text
is how four-way drift starts.

### D2 — One registry declares every message, and the fields are generated

`MailRegistry` holds one row per message: id, group, label, when it fires, its
default subject and body, and which tokens it may use. `FieldRegistry` merges
the generated rows rather than carrying four hand-written pairs.

The argument is the one `FieldRegistry`'s own docblock makes: a fifth message
must not mean editing four places that nothing checks agree. A message declared
here and drawn by nothing, or drawn and never declared, has to be
unrepresentable rather than guarded against.

### D3 — Tokens are declared per message, not shared globally

`Placeholders::available_tokens()` is one flat list shown under the SMS section.
It is right for the four OTP intents and wrong for everything else: a login
alert needs `{{ip}}`, `{{user_agent}}` and `{{login_time}}`, none of which
exist, and an admin alert needs `{{ceiling}}` and `{{halt_minutes}}`.

Each registry row therefore names its own token set, and the screen shows only
those. Without this an administrator pastes `{{ip}}` into a template and gets a
silent empty string — the exact failure mode this project has hit five times
with renames.

### D4 — The admin alerts join the registry, and gain an off switch

The budget-halt and breaker alerts are mail the plugin sends that nobody can
reword, redirect or turn off. They are operational rather than user-facing, so
they keep diagnostic defaults — but they stop being unreachable.

This also removes an asymmetry Phase 10 created: `AuditLog::OTP_BUDGET_HALTED`
and `TRANSPORT_BREAKER_OPEN` can already be pushed to an automation endpoint by
the event bus, so a site can receive them two ways and silence neither.

### D5 — One HTML layout, shared, and off by default

When `email.is_html` is on, the rendered body is placed inside a single shared
layout: site name or logo, the content block, a footer. Settings are few on
purpose — logo URL, accent colour, footer text — because a mail template editor
is a product and this is a plugin.

Off by default stays. Turning HTML on for an install whose bodies are plain text
would render them as one unwrapped paragraph, and the existing default body has
newlines that only mean anything in text.

### D6 — What this phase does **not** add: a login-alert email

The obvious fourth group from the original request. It is deliberately absent,
because 10.4 already delivers it better: `AuditLog::LOGIN_SUCCESS` is one tick
away from reaching an automation endpoint that can mail, message or ticket it,
with the site owner deciding the wording, the channel and the recipient.

Adding a login-alert mail here would mean the plugin growing recipients,
throttling and a "was this you" flow — each of which is a feature, and none of
which is a template. Recorded as a decision, not an oversight.

## Ownership boundary

| Concern | Owner |
| --- | --- |
| Which messages exist, and their defaults | `MailRegistry` |
| Which tokens a message may use | the registry row, not `Placeholders` |
| Turning a token list into values | `Placeholders`, per message |
| Wrapping HTML | the layout renderer, applied once |
| Whether a message is sent at all | the caller, unchanged |
| Storing the admin's overrides | `FieldRegistry` + `Settings`, unchanged |

`MailTransport` keeps its single job: hand a rendered subject and body to
`wp_mail()`. Choosing *which* subject and body is the registry's, so a new
message costs no transport change at all.

## Not in this phase

**Per-recipient or per-role variants.** One message, one wording.

**A visual editor.** The body stays a textarea with a token list beside it. A
WYSIWYG that produces mail-safe HTML is a project.

**Attachments, and anything scheduled.** Neither has a caller asking for it.
