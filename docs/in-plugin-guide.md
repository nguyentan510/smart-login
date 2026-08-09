# The guide inside the plugin

Normative spec for Phase 22. Status lives in
[`refactor-plan.md`](refactor-plan.md); the execution briefs live in
[`in-plugin-guide/`](in-plugin-guide/), one per sub-phase.

The request was "the plugin has no instructions — a tab of its own, short and
concrete: which shortcodes exist, where to put them, what to do when something
breaks. A small cheat sheet for the administrator."

---

## Findings

Every claim is pinned to a line. The ones that contradict the framing of the
request are listed first.

### 1. The documentation exists — where the administrator cannot reach it

`README.md` is 33 KB and covers most of what the request asks for: the minimum
configuration (`README.md:17-31`), the shortcodes (`:38`), the four ways to open
the dialog (`:47-59`), the account button and its attributes (`:69-88`). None of
it is reachable from `wp-admin`. An administrator who installed a ZIP has the
file on disk and no reason to know it is there.

So this phase is not "write the documentation". It is **put a short version of
it where the reader is**, and refuse to let the two disagree.

### 2. Nine shortcodes are registered; the README names six

`Shortcodes::register()` registers nine tags
(`includes/Frontend/class-shortcodes.php:21-29`). `README.md:36-38` names
`smart_auth`, `smart_login`, `smart_register`, `smart_verify_otp`,
`smart_forgot_password` and `smart_profile`; `[smart_login_button]` gets its own
section at `:69`. `[smart_account]` and `[smart_address]` are named **nowhere**,
though `render_account()` carries the comment explaining why it exists
(`class-shortcodes.php:116-123`) and `[smart_address]` silently renders nothing
when the address module is off (`:147-149`).

This is the drift CLAUDE.md warns about, in its mildest form: not a documented
control that does not exist, but two shipped controls that no document names.
A hand-written list in a new tab would be the third copy.

### 3. The readiness screen already answers "is it working" — and only that

`Readiness::checks()` covers nine conditions and every red row links to the
control that fixes it (`includes/Admin/class-readiness.php:65-84`, `:652-661`).
What it cannot answer is the question that comes *before* it — "what do I put on
the page" — and the question that comes *after* — "a visitor is seeing this
message, what does it mean". The guide owns those two and must not restate the
first.

### 4. Every error string the guide would quote is already in the code

`Kênh SMS chưa được cấu hình. Liên hệ quản trị viên.`
(`includes/OTP/Transports/class-webhook-transport.php:58`), the email twin
(`class-mail-transport.php:32`), `Không gửi được mã xác thực. Vui lòng thử lại
sau ít phút.` (`class-transport-router.php:176`), `Phiên làm việc đã hết hạn.
Vui lòng tải lại trang.` (`includes/Frontend/class-form-controller.php:354`),
`Vui lòng đợi %d giây trước khi yêu cầu mã mới.`
(`includes/Security/class-rate-limiter.php:58`), the lockout message
(`includes/Auth/class-login-handler.php:98`).

A troubleshooting table is a table of **strings the visitor actually sees**. If
the left-hand column drifts, the table is worse than absent: it sends the reader
hunting for a message the plugin does not print.

---

## Decisions

### D1 — A screen, not a settings tab

The guide holds no fields, so it is not in `FieldRegistry::tabs()`, for the same
reason Overview is not: tab membership in that registry means "this tab draws
these settings and a save writes them". A tab with a Save button that saves
nothing is a lie the admin suite would be right to fail.

It is a `SettingsPage` route beside `OVERVIEW`, and it sits **last** in the
strip. Overview is what you open after installing; the guide is what you open
when you are stuck, which is not the same moment.

### D2 — The shortcode list is declared once, and `register()` reads it

`Shortcodes::CATALOG` becomes the one place a tag is written down: `register()`
walks it, `render_button()` and `render_address()` take their `shortcode_atts()`
defaults from it, and the guide renders from it. A shortcode that is registered
but undocumented cannot be expressed, because there is no second list to omit it
from.

The catalog carries **structure only** — the callback and the attribute
defaults. No prose, so a front-end request does not build a page of Vietnamese
help text on its way to rendering a login form.

### D3 — The prose lives in the admin, and a rule pins it to the catalog

The guide screen holds the wording: what each shortcode is for, where it goes,
what each attribute does. Two rules require the key sets to match exactly — a
tag documented but not registered fails, and a tag registered but not documented
fails. Finding 2 is the reason both directions are asserted rather than one.

### D4 — Quoted strings are verified, not remembered

Every error message the troubleshooting table quotes must appear verbatim in
`includes/`; every filter it names must be applied in `includes/`; every URL
fragment it lists comes from `LoginDialog::aliases()` at render time rather than
being typed. This is finding 4 turned into a gate.

The alias list is *rendered from the method*, not merely checked against it —
that map is filterable (`class-login-dialog.php:244`), so a site that adds one
gets a guide that names it.

### D5 — Every "fix it here" is a link this plugin can build

Rows in the troubleshooting table name a tab slug, and the screen turns it into
a URL through `SettingsPage`. A slug that no longer resolves fails a rule rather
than shipping a dead button. This is `Readiness::check()`'s pattern
(`class-readiness.php:652-661`), applied to a second screen rather than copied
into it.

### D6 — The guide never restates a setting's value

No default OTP length, no cooldown in seconds, no ceiling. Those live in the
registry, are editable, and a guide that repeats one becomes the place it goes
stale. The guide links to the screen that owns the value and says what the value
*means*.

### D7 — Short, and honest about what it is not

It is a cheat sheet, not the README. Gateway presets, the REST surface, the
address dataset build and the token vocabulary stay in `README.md` and on the
screens that own them; the guide points at them. The tab is aimed at the person
who has to put a login form on a page today, and at the same person three months
later reading a message a customer sent them.

Vietnamese in the interface, English in the code and in these documents — the
existing convention, restated because this phase is almost entirely interface
strings.

---

## What this phase does not do

- **No help tab in the WordPress contextual-help pull-down.** It is collapsed by
  default and nobody opens it; a plugin that hid its only instructions there
  would have solved finding 1 on paper.
- **No copy-to-clipboard, no video, no tour.** A `<code>` element is selectable.
- **No second language.** The strings are translatable like every other string
  in the plugin, and the shipped copy is Vietnamese.
- **README.md is not deleted or shortened.** It is the public document and it
  goes further than this screen ever will; the guide gains a pointer to it, and
  README gains a pointer to the guide.
