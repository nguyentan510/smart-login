# The account card

Normative spec for Phase 17. Status lives in
[`refactor-plan.md`](refactor-plan.md); the execution briefs live in
[`account-card/`](account-card/), one per sub-phase.

Phase 16 fixed what the "Đăng nhập & liên hệ" card *said*. This phase is about
what the whole surface is *made of*: the four cards share no scale, no control
vocabulary and no glyph set, and two of them make a claim the code does not
back.

---

## Findings

Every claim below is pinned to a line. The ones that contradict the original
premise are kept.

### 1. There is no spacing or type scale — there is a list of numbers

`.omniwp` declares six tokens and all six are colours
(`omniwp.css:7-12`). Every distance and every font size in the stylesheet is
a literal, chosen at the component. Measured across the file:

| | Values in use |
| --- | --- |
| Spacing | 6, 8, 9, 10, 12, 14, 16, 18, 20, 24 |
| Font size | 11, 12, 13, 14, 15, 16, 17, 20, 22 |
| Radius | 6 (token), 12 (literal, twice), 999 |

Nine font sizes is not a scale, it is the absence of one. The account card alone
uses four different sizes for "small text": badge 11 (`:927`), identity note 12
(`:1030`), hint and row label 13 (`:193`, `:989`), label 14 (`:211`).

**Two negative margins are the tell.** `.sl-card__note { margin-top: -8px }`
(`:905-907`) and `.sl-hint--reason { margin: -3px 0 8px }` (`:217-219`) exist to
cancel a distance a component above them declared. A layout that has to subtract
its own spacing does not have a scale; it has arithmetic.

### 2. The input and the button beside it are not the same height

`.sl-input` is `padding: 12px 14px; font-size: 15px; line-height: 1.4`
(`:225-234`). `.sl-btn` is `padding: 13px 16px; font-size: 15px` with **no
`line-height` at all** (`:352-365`). They sit in one grid row in the contact
editor (`:558-563`), where `align-items: center` hides the difference rather
than removing it.

**Measured, after the first reading of it was wrong.** The prediction here was
that the button inherits `1.5` from `.omniwp` and comes out *taller*, near
50px. In a browser it computes `line-height: normal` — the UA stylesheet for
`button` sets it, and that beats inheritance — so the button is 45px against the
input's 47 and is *shorter*. Two pixels, the other way round. The defect is
real; the number and the direction in the first draft of this finding were not,
and 17.2's outcome records the correction.

This is a *consequence* of finding 1, not a separate defect: 12 and 13 are both
off any grid, and nothing forced them to agree.

### 3. One class of action, three kinds of control

| Control | Element | Where |
| --- | --- | --- |
| Đổi / Thêm | `<button class="sl-link sl-link--button">` | `partials/account/contact.php:143-149` |
| Bỏ liên kết | `<summary class="sl-link">` | `partials/linked-identities.php:84-85` |
| Liên kết Google | `<a class="sl-btn sl-btn--outline">` in a grid | `partials/account/providers.php:51-61` |

Three row-level actions in one card, at three visual weights, in three
elements.

**And the base is inverted.** `.sl-btn` declares `display: block; width: 100%`
(`:352-354`), so every inline use has to take it back — three bespoke rules do
exactly that, keyed on where the button sits rather than on what it is
(`:565`, `:1050`, `:1101`).

### 4. The card says "giao hàng"; the code writes `billing_`

`AddressFields::save_for_user()` writes `billing_state`, `billing_city`,
`billing_country` and `billing_address_1`
(`class-address-fields.php:172-186`). **Nothing writes `shipping_*` for a user
profile** — the only `shipping` write in the tree is the per-order ward code at
`class-woo-address.php:410`.

The heading is "Địa chỉ giao hàng" (`class-account-form.php:96`) and the note
says "dùng chung với tab Địa chỉ — sửa ở đây là sửa cả hai"
(`partials/account/address.php:35`). WooCommerce's Addresses tab holds **two**
addresses. For any customer who has ever saved a separate shipping address, both
sentences are false.

The same concept also carries three names: "Địa chỉ giao hàng" (heading),
"Địa chỉ" (`class-profile-completion-service.php:74,77`), "địa chỉ giao hàng
mặc định" (the note).

### 5. Nothing records when a password was last changed

Grepped: the password is written in three places —
`FormController::save_password()` (`:225-232`),
`PasswordResetHandler` (`:179`) and `UserManager::apply_password_hash()`
(`:257-272`). None of them stores a timestamp, and no meta key in the plugin
holds one.

So the security row can state the fact only once the fact exists. This is the
finding that turns a one-line UI request into a three-writer change.

### 6. The missing-information notice drops the reason it already has

`partials/account/status.php:41-48` renders `implode( ', ', … labels )` — on a
live page, a box containing the words "Địa chỉ, Ngày sinh". The sentence that
makes each of those worth filling in is already written and already
translated, in `ProfileCompletionService::onboarding_reasons()` (`:41-48`), and
only the onboarding screen reads it.

8.4's outcome already called this block out. It was fixed in
`profile-summary.php` and not here.

### 7. The completion fraction has no denominator

`ProfileCompletionService::status()` returns `complete`, `required_missing`
and `recommended_missing` (`:88-92`) — what is *missing*, never how many were
asked for. And the number asked for moves with four settings:
`profile.email_optional`, `address.required_in_profile`, `address.enabled`,
`profile.dob`, `profile.gender` (`:64-87`).

A fraction whose denominator changes when an admin toggles a setting reads as a
bug. If the surface is going to show one, the service has to own both halves of
it.

### 8. The provider's mark exists and the account card does not use it

`LoginProviderInterface::icon_svg()` has been on the interface since Phase 12
(`class-login-provider-interface.php:48`), Google and Zalo both implement it,
and `templates/form-auth.php:130-140` renders it on the sign-in screen.

The account card renders providers twice — the linked rows
(`partials/linked-identities.php:60-73`) and the invitation
(`partials/account/providers.php:51-61`) — and neither draws a mark.

Meanwhile the four card headings each render the **same** glyph, `&#9679;`, a
red dot (`profile.php:26`, `contact.php:84`, `address.php:31`,
`password.php:37`). Four identical marks distinguish nothing; they are a slot
waiting for content.

---

## The decisions

### Decision 1 — The scale is declared once, and the account surface reads it

Six spacing steps and five type steps, as custom properties on `.omniwp`:

```
--sl-space-1: 4px    --sl-fs-xs: 12px
--sl-space-2: 8px    --sl-fs-sm: 13px
--sl-space-3: 12px   --sl-fs-md: 15px   /* body */
--sl-space-4: 16px   --sl-fs-lg: 16px
--sl-space-5: 20px   --sl-fs-xl: 20px
--sl-space-6: 24px
```

Plus `--sl-radius-card: 12px` (a literal twice today) and `--sl-surface-2`
(`#f7f8fa`, a literal twice today), which is what the sub-panel layering in the
proposal actually needs — a token, not new markup.

**Scoped to the account surface, deliberately.** Converting all 1162 lines would
re-lay-out the sign-in screens Phase 16 has just finished measuring, in the same
commit as an unrelated change, with no way to attribute a regression. The rule
that enforces this names its region and fails loudly if the region marker is
renamed.

Values that have to move to reach the scale: card padding `18px 20px` → `16/20`,
row gap `10` → `12`, badge `11px` → `12px`, `.sl-static` padding `9` → `8`,
button padding `13` → `12`. **Both negative margins go**, replaced by the
adjacent component not emitting the space in the first place.

`.sl-btn` gains `line-height: 1.4`, which is what makes finding 2 go away: 12 +
12 + 15 × 1.4 + 2 = the input's 47px, by construction rather than by luck.

### Decision 2 — One shape for one class of action: `.sl-action`

A row-level action is a small text control that acts on the row it sits in. All
three become `.sl-action`, with `.sl-action--danger` for the destructive one.
`<details>`/`<summary>` stays as the *mechanism* for "Bỏ liên kết" — it holds a
password form and works without JavaScript — but its summary is styled as an
action like the others.

**The provider invitation becomes a row.** 16.3 folded the linked providers into
the contact card's list; leaving "Liên kết Zalo" below it as a full-width outline
button is the last piece of the old two-list geometry. `Zalo · chưa liên kết ·
Liên kết` is the same row shape as everything above it.

### Decision 3 — `.sl-btn` keeps its block default, and width intent moves onto the element

Counted before deciding: **20 of the 27 `.sl-btn` in `templates/` want full
width.** Inverting the base would mean editing 18 call sites across the sign-in
screens Phase 16 has just settled, to make the minority case shorter. The
majority default stays.

What changes is *where the exception is written*. The three bespoke overrides
keyed on an ancestor (`:565`, `:1050`, `:1101`) become one `.sl-btn--inline`
modifier carried by the element. A button's width then depends on the button,
not on what it is inside — which is the property the rule asserts.

### Decision 4 — The address the card names is the address it writes

Taking **option (a)**: one address, mirrored to both Woo address books.

`save_for_user()` writes the `shipping_*` counterparts alongside the `billing_*`
ones, through the same `ProfileSeeder::set_many_from_user_input()` path and with
the same ward-code bookkeeping `WooAddress` already uses for shipping
(`OmniWP_shipping_ward_code`, `class-woo-address.php:158`). The heading
becomes **"Địa chỉ nhận hàng"**, and the note stops claiming "cả hai" and states
what is true: this is the address both checkout and delivery use.

**This is a behaviour change, and it is the one thing in the phase that touches
data.** A customer who has deliberately kept a different shipping address will
have it overwritten the next time they save this form. That is the cost of one
address, it is what "nhận hàng" promises, and it is written here rather than
discovered later. `set_from_user_input()` is already the correct semantic: the
customer just typed it.

`is_complete()` and `get_for_user()` keep reading `billing_*`. Two readers of one
truth is the drift this project keeps finding; billing stays the source and
shipping is its mirror.

### Decision 5 — The notice states the reason, from the one place the reason lives

`status()` items already carry a `key`. The status partial looks each key up in
`onboarding_reasons()` and renders `label` + reason + the existing action link.
The strings are not copied into the template — that would be the second source of
truth 8.4 removed from this exact block.

### Decision 6 — A password write records when it happened

One class, `SecurityMeta`, owns the key and the phrasing:

- `record_password_change( int $user_id )` — called by all three writers
- `password_changed_at( int $user_id ): string`
- `describe_password_age( int $user_id ): string` — "hôm nay", "5 ngày trước",
  "3 tháng trước", or `''`

**`''` is a designed answer, not a fallback.** Every account that exists today
has no stored timestamp, and "chưa rõ" is the truth for them. The row renders the
action without the age rather than guessing one.

Own strings rather than `human_time_diff()`: that function is translated by
WordPress core, so on a site whose core is English the card would read
"Mật khẩu · đổi lần cuối 3 months trước".

The rule that keeps this honest is a *companion* rule — any file that writes a
password must also record the change — because the failure mode is not a wrong
date, it is a fourth writer added later and a row that quietly goes stale.

### Decision 7 — The fraction is computed where the rule lives

`status()` gains `total` and `done`. Every branch that decides a field is
required or recommended already runs there; counting them anywhere else means
re-deriving five settings lookups in a template.

### Decision 8 — One glyph set, one owner

`AccountForm::headings()` becomes `AccountForm::sections_meta()`: `label` and
`icon` per section, one array. The four partials stop each writing their own
`<span class="sl-card__icon">&#9679;</span>` and render a shared
`partials/account/card-head`.

The marks are inline SVG at 18×18, matching what `icon_svg()` already returns for
providers, so the card has one glyph size and not two.

Provider marks come from a single presentational helper, `ProviderMark::svg()`,
which resolves a channel id through `ProviderRegistry::get()`. **Not through
`available()`** — an account can hold a Google identity on a site where Google
has since been switched off, and that row still has to draw. And **not** by
adding the SVG to `IdentityLinkService::linked()`: that payload also serves the
REST route, and markup does not belong in it.

---

## Ownership boundary

Unchanged from 8.2 and 16: one partial owns the provider list, one owns the
contact panel, one owns the status notice. This phase adds two owners and no
duplicates — `partials/account/card-head` for the heading row, `ProviderMark`
for the glyph.

## Deferrals, written where they are decided

- **The stylesheet outside the account surface keeps its literals.** Decision 1
  states why. Promoting the rest is a phase, not a step, and it belongs with
  whichever screen is next opened.
- **The ward-first quick search is not reinstated.** It was removed from the
  codebase in Phase 8 (recorded in `account-surface/8.4-layout.md`), and bringing
  it back needs a ward→province index over the whole dataset. That is a feature
  decision, not a layout one.
- **The contact row keeps showing the address whole.** The proposal masks it to
  `t•••@example.test`. This is the account holder's own profile, already
  authenticated, and 16.2 spent a sub-phase removing a masked value from exactly
  these rows for exactly this reason (`partials/linked-identities.php:63-73`).
  Declined, with the reason recorded rather than the change made quietly.
- **No dark theme.** The stylesheet hardcodes `#fff` in ten places. The proposal
  is drawn dark; that is a rendering of the mock, not a requirement, and a theme
  is its own phase.

## Not in scope

No schema change, no `OMNIWP_DB_VERSION` bump. One new user meta key
(`_OmniWP_password_changed_at`) and the `shipping_*` mirror, both written
through existing paths.
