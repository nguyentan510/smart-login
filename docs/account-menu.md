# The account menu

Normative spec for Phase 21. Status lives in
[`refactor-plan.md`](refactor-plan.md); the execution briefs live in
[`account-menu/`](account-menu/), one per sub-phase.

The request was "a header button like the one on nhathuoclongchau.com.vn: an
`Đăng nhập` button when signed out, the member's phone number and a dropdown of
account links when signed in, working on desktop and on mobile".

The dropdown is the visible part. What the request actually asks for is a thing
this plugin does not have: **a single list of account destinations**. The two
screenshots make the point better than the sentence does — the dropdown on the
right and the sidebar on the left of the signed-in screen are the *same seven
items*. Build them from two arrays and they are one refactor away from
disagreeing, on the one screen where disagreeing is most visible.

This phase is the first piece of a larger account system (orders, profiles).
It is scoped so that the piece it lands is the one the rest will read.

---

## Findings

Every claim is pinned to a line. The ones that contradict the framing of the
request are listed first, deliberately.

### 1. `[smart_login_button]` exists, and renders unstyled

The shortcode is real — `render_button()` at
`includes/Frontend/class-shortcodes.php:46-70`, registered at `:29`. But it
never calls `Assets::enqueue()`, and `Assets::maybe_enqueue()` scans six
shortcode tags of which `smart_login_button` is **not one**
(`includes/Frontend/class-assets.php:43`). The stylesheet otherwise arrives only
when the launcher fetches it on first open
(`includes/Frontend/class-login-dialog.php:133-137`).

So the one trigger built for people who cannot edit templates
(`class-shortcodes.php:32-38` says so in as many words) is the only trigger that
renders with no CSS. **The framing of this request assumed a working button that
needed a menu. There is no working button.**

### 2. Even with the stylesheet, `.sl-btn` is the wrong shape for a header

`.sl-btn { display: block; width: 100% }` (`assets/css/smart-login.css:443-457`),
and deliberately so: the comment at `:505-517` records that 20 of the 27 `.sl-btn`
in `templates/` want full width, and that inverting the base would mean editing
18 call sites. The shortcode emits `sl-btn sl-btn--primary` with no
`sl-btn--inline` (`class-shortcodes.php:64`).

A header button that inherits from the sign-in form's button is a header button
that loses an argument the form already won.

### 3. The button has no signed-in state at all

`render_button()` never calls `is_user_logged_in()`. A signed-in member sees
"Đăng nhập / Đăng ký", and because the launcher is not enqueued for a signed-in
visitor (`class-login-dialog.php:44`) the click **navigates away** to the sign-in
page rather than doing nothing.

Brief 19.4 step 9 promised "signed in: the trigger is stripped and nothing
opens" (`sign-in-anywhere/19.4-the-trigger-contract.md:51`). The second half
holds by accident — there is no launcher to open anything. The first half was
never implemented.

### 4. `sections_meta()` names sections, not destinations — it cannot be the menu

`AccountForm::sections_meta()` (`includes/Frontend/class-account-form.php:107-131`)
returns four label+icon pairs. Its only consumer is
`templates/partials/account/card-head.php:27`, and `FORM_SECTIONS` draws all four
**stacked on a single page** (`class-account-form.php:77`, loop at `:170`).

Its own doc comment already refuses the move this phase might have made: an
entry for something no card draws would be "a translatable string naming a
heading nothing draws" (`:99-103`).

"Đơn hàng của tôi" is a page. Feeding the dropdown from `sections_meta()` means
adding a fake section to a form that does not render it — the exact statement
that comment exists to stop the plugin shipping.

### 5. Destination resolution is already spread across four resolvers

| Resolver | Line |
| --- | --- |
| `AccountForm::edit_url()` | `class-account-form.php:363` |
| `Flow::login_url()` | `class-flow.php:230` |
| `LoginHandler::post_login_redirect()` | `class-login-handler.php:321` |
| `RegisterHandler::post_register_redirect()` | `class-register-handler.php:375` |

Two of them already delegate to `SitePage::url()`
(`class-account-form.php:390`, `class-flow.php:242`), a class extracted for
precisely this reason — its header reads "One resolver rather than two copies of
a LIKE query and a cached option" (`class-site-page.php:11`).

A fifth resolver typed into a settings field is the wrong direction of travel.

### 6. A repeatable settings row already exists, works, and needs no JavaScript

`type => 'headers'` is declared at `includes/class-field-registry.php:492` and
`:637`, drawn at `includes/Admin/class-field-renderer.php:458-502`, sanitised at
`includes/class-settings.php:706-750`. The renderer shows `max(3, count + 1)`
rows — always one spare — and drops the blank ones on save.

The menu repeater is that table with three columns instead of two. This is not
new machinery, and the phase should not invent any.

### 7. Logout cannot be a settings row

`wp_logout_url()` returns a **nonced** URL, per user and per session — which is
how `templates/logged-in.php:35` already links it. A URL typed into a settings
field carries no nonce, so WordPress answers it with the "Bạn có chắc muốn đăng
xuất?" interstitial instead of signing the member out.

The launcher already knows this boundary from the other side: it refuses to
capture any link carrying an `action` parameter, naming logout as the reason
(`assets/js/smart-login-launcher.js:201-232`).

### 8. There are already two glyph producers, and they mean different things

`sections_meta()` builds 18×18 stroked outline icons from an inline closure
(`class-account-form.php:107-113`). `ProviderMark` returns a provider's **brand**
mark through `icon_svg()` (`includes/Frontend/class-provider-mark.php:63`) — a
Google logo, in Google's colours.

These are not the same kind of object and must not be unified. One is a UI
vocabulary the site owns; the other is somebody else's trademark.

---

## Decisions

### 1. One shortcode, two states

`[smart_login_button]` keeps its name and grows a signed-in state. Not a second
shortcode.

Whoever drops this into a header does not know who is going to look at the page,
so a shortcode that requires them to know is a shortcode they cannot use. Two
shortcodes would also mean two placements to keep aligned, and the header is one
slot.

Signed out it renders a trigger and no menu. Signed in it renders the menu and
no trigger. **Never both** — that is a guard rail, not a hope.

### 2. Destinations are a different concept from sections, and get their own registry

`AccountMenu` is the single registry of **account destinations**: places a member
can navigate *to*. `AccountForm::sections_meta()` stays exactly what it is —
cards within one page — and this phase does not touch its contents.

Every navigational surface reads `AccountMenu` and nothing else:

```
Settings repeater ──┐
AccountForm::edit_url() ──┤                    ┌──▶ header dropdown   (this phase)
wp_logout_url() ──────────┼──▶ AccountMenu ────┼──▶ account sidebar   (later)
filter, last ─────────────┘                    ├──▶ mobile panel      (later)
                                               └──▶ Woo endpoints     (later)
```

**No template reads the setting directly.** That is what makes the sidebar, when
it ships, unable to drift from the dropdown — not a convention anyone has to
remember.

The naming follows commit 20.5, one word one meaning: the class is `AccountMenu`,
the settings section is `account_menu`, the filter is `smart_login_account_menu`,
the docs live in `account-menu/`.

### 3. Settings own the middle; the plugin owns both ends

| Position | Owner | Why it cannot be the other way |
| --- | --- | --- |
| `account` — Thông tin cá nhân | plugin, via `AccountForm::edit_url()` | already resolves filter → WooCommerce → hosting page (`:363`). Asking an administrator to retype it is inviting them to type it wrong |
| *(the middle rows)* | **settings repeater** | "Lịch hẹn tiêm chủng", "Đơn thuốc" are concepts of the site, not of the plugin. No default can guess them |
| `logout` — Đăng xuất | plugin, via `wp_logout_url()` | finding 7: the nonce |

An empty repeater therefore yields a **two-item menu that works**, not an empty
box. A fresh install has a usable account menu before anybody opens Settings.

The filter `smart_login_account_menu` runs **last, over the assembled array**, so
a developer can remove even the pinned ends. It is the escape hatch, and by
running last it is one escape hatch rather than a second way for an
administrator to configure the same list.

### 4. `key` is a stable identifier, decided now because changing it later is expensive

Every entry carries `key`, matching `[a-z0-9_-]+`, independent of its label.
Two things will need it and neither exists yet: the sidebar must know which item
is active, and a filter must be able to name an item that an administrator has
since renamed.

The pinned top item is keyed **`account`**, not `profile` — `profile` is a
*section* key (`class-account-form.php:61`), and reusing it across the two
concepts finding 4 just separated would undo the separation in the vocabulary
while preserving it in the code.

### 5. The entry shape is four keys, and stays four keys

`{ key, label, icon, url }`.

No `capability`, no `badge`, no `children`, no `endpoint`. The orders and profile
systems do not exist yet, so every field added now is a guess, and an unused key
is a key that will be read wrongly later. Fields get added when something real
needs them.

### 6. Icons are a closed set, chosen by name

`IconSet` holds the UI glyph vocabulary: name → inline SVG, 18×18, stroked,
`currentColor`, matching the geometry `sections_meta()` already established
(`class-account-form.php:93-96`). The settings row picks an icon from a
`<select>` of names.

Sanitising an unknown name yields the fallback. **No SVG ever originates in user
input**, so an unknown icon is not guarded against — it is unrepresentable, which
is the argument `FieldRegistry` opens with (`class-field-registry.php:13-16`).

`sections_meta()` is refactored to read from `IconSet` in the same sub-phase that
creates it. One vocabulary, or the phase has added a third glyph producer while
complaining about the second.

**Provider brand marks stay out.** Finding 8: `ProviderMark::icon_svg()` returns
a trademark in its owner's colours, and folding it into a `currentColor` UI set
would be unification past the point where it means anything.

### 7. The header button does not reuse `.sl-btn`

It gets `.sl-account-btn` and its own stylesheet, `assets/css/smart-login-button.css`.

Finding 2 is not a bug in `.sl-btn` — that class is correctly shaped for the
sign-in form, and the comment at `smart-login.css:505-517` already argued the
case. Overriding it in a header context would reopen the argument that comment
closed. A header button and a form button share a colour variable and nothing
else.

The stylesheet is separate, and loads with the button, for the reason the
two-stage split exists at all (`class-login-dialog.php:5-10`): 1,512 lines of
form CSS on every page of the site against the possibility of a click is the
cost that split declined, and a header button must not quietly re-incur it. This
file is small enough to be unconditional.

It reads its colours from decision 13's token file rather than restating them. A
second stylesheet carrying its own copy of `#e30613` is how a site ends up with
two reds that were meant to be one.

### 8. `<details>`/`<summary>` is the mechanism; JavaScript only upgrades it

The dropdown works with **zero JavaScript** — open, close, keyboard-reachable,
screen-reader-announced, all from the element.

Script then adds what the element does not do: close on outside click, close on
`Escape`, and `aria-expanded` bookkeeping. If the script is blocked, removed, or
still loading, the member has a working account menu.

This is the same argument decision 8 of Phase 19 made for links
(`sign-in-anywhere.md:305`): the plugin must not own a failure mode in which its
own script is the only thing keeping a basic navigation working.

### 9. Mobile is CSS over the same DOM

One markup tree. Below the breakpoint the dropdown becomes a full-width sheet and
the button's text label collapses to its icon; the `<details>` mechanics are
unchanged.

A second DOM for small screens is two menus to keep in step, which is the defect
this whole phase is organised against — applied to viewport width instead of to
surface.

### 10. Placement is a shortcode attribute; content is a setting

The rule, so the boundary does not have to be re-argued per attribute:

- `label`, `collapse`, `class` — differ per placement → attributes
- the menu — identical wherever the button is → settings

If the menu were an attribute, a site with the button in two places would have
two menus, and would discover that only after they diverged.

### 11. Placement into a theme's nav menu is a setting — and it is off by default

A `<select>` over `get_registered_nav_menus()`, injecting through
`wp_nav_menu_items`.

This passes the criterion that decides whether anything here becomes a setting:
**the site owner holds information the plugin cannot derive.** The plugin can
enumerate a theme's menu locations — so this is a closed list, not a free-text
hook name — but only the owner knows which of them is the header.

It is also the largest usability gain in the phase, larger than any appearance
option. Finding 1 records that the shortcode exists *for people who cannot edit
templates*; a classic theme with no page builder leaves those people with no
placement at all.

**The injected item and the shortcode are the same renderer.** Two entry points
producing two markups is the drift this phase is organised against, applied to
placement instead of to menu contents.

Off by default, which is the opposite of Phase 19's link capture
(`sign-in-anywhere.md:305`) — and the difference is not preference. Capture
intercepts a click and rewrites nothing; with its script gone the page is exactly
what the theme wrote. Injection **adds a node to somebody else's markup**. A
plugin may default to being invisible; it may not default to editing the theme.

### 12. The signed-in label is a setting, with three choices and no email

`Số điện thoại` / `Tên hiển thị` / `Tự động`, defaulting to `Tự động` — the
phone from `UserManager::META_PHONE` when there is one, the display name
otherwise.

The reference screenshot shows a phone number; a site whose members identify by
something else wants something else, and the plugin cannot tell which. That is
the criterion met.

**Email is deliberately not offered.** `UserManager::user_has_synthetic_email()`
exists (`class-shortcodes.php:286`) because an OTP registration mints an address
nobody chose. Offering a label that renders as a machine-generated string is
offering a setting whose worst case is silent and ugly.

### 13. The design tokens move to `:root`, in their own file, before anything else lands

`.smart-login` currently welds twenty design tokens to a **page layout block** in
one rule — `max-width: 460px`, `margin: 0 auto`, `padding: 8px 0 32px`
(`smart-login.css:6-66`).

This is the finding that changes the plan. The obvious way to give the header
button the plugin's tokens is to wrap it in `.smart-login`, and that way is
broken: the button would inherit a 460px cap and 32px of bottom padding, and
overriding them re-opens exactly the argument decision 7 declined to have.

So: `assets/css/smart-login-tokens.css` holds the token block on `:root`; the
layout properties stay on `.smart-login`; both stylesheets declare it as a
dependency. One token source instead of one buried inside a layout rule.

Two consequences worth stating outright:

- **Customisation becomes "override one CSS variable"**, which is why decision 6
  of the refused list below can refuse a colour picker without refusing colour
  customisation.
- **Overrides that exist today keep winning.** A theme setting
  `.smart-login { --sl-accent: … }` still outranks `:root` on specificity, so
  this is backwards compatible by construction rather than by testing.

This lands in **its own sub-phase, first, with no value changed** — same hexes,
same numbers, only the selector moves. Any visual difference is then a bug and
not a redesign, which is the only way a change with this much reach can be
reviewed. The rendered-surface suite pins a baseline of 40 off-scale declarations
(`smart-login.css:36-40`), and it is the thing that has to stay still.

---

## Defaults

| Thing | Default | Why |
| --- | --- | --- |
| Menu when repeater is empty | `account` + `logout` | decision 3: a fresh install has a working menu |
| Signed-out label | `Đăng nhập` | matches the reference; the current `Đăng nhập / Đăng ký` is a two-screen label the identifier-first flow retired |
| Signed-in label | `Tự động` — phone, else display name | decision 12; the reference shows the identifier, and it is what a member recognises as "my account" |
| Nav-menu placement | **none** | decision 11: a plugin may default to being invisible, not to editing the theme |
| `collapse` | `mobile` | icon-only under the breakpoint |
| Stylesheet | enqueued whenever the shortcode renders | finding 1 is a defect, not a trade-off |
| Token stylesheet | dependency of both others, always present | decision 13: a surface without tokens renders unthemed |
| Repeater rows shown | `max(5, count + 1)` | finding 6's proven pattern, one spare row |

---

## Settings that were refused, and what replaces each

The criterion is decision 11's: a setting earns its place when the site owner
holds information the plugin cannot derive. These four fail it, and each is
written down with its replacement so the question does not get re-asked as a
feature request with no answer on file.

| Refused | Why | Replaced by |
| --- | --- | --- |
| Colour picker | `--sl-accent` already exists (`smart-login.css:7`). A picker makes two sources of colour, and the CSS one still wins | decision 13's `:root` tokens, documented in `README.md` |
| Breakpoint as a number field | the theme owns its breakpoints. A number stored here will disagree with the theme's and neither side will be visibly wrong | the theme re-declares the collapse rules at its own width — **not** a CSS variable, see below |
| Per-role item visibility | WordPress already refuses the destination to a member who may not have it. A second gate here is a second authorisation model, and the wrong one to trust | nothing; the deferral below |
| Open the menu on hover | `<details>` has no hover state, and hover menus are unusable on touch. Two behaviours means one of them is untested on the device most visitors use | one behaviour: click |

**Correction, made in 21.6.** The breakpoint row above said the replacement was
"a CSS variable the theme sets". That is not possible: a custom property cannot
appear in a media query condition — `@media (max-width: var(--x))` is invalid
CSS, and the rule is simply dropped. The refusal stands and its reason is
unchanged; only the replacement was wrong. The collapse breakpoint is `782px`,
matching WordPress's own admin-bar breakpoint, it is written once in
`smart-login-button.css`, and a theme that wants a different one re-declares
those rules at its own width in a stylesheet that loads after ours. Recorded
here rather than quietly fixed, because it was an approved decision.

---

## Deferrals

Written down here because a silent exception is a lie with a longer half-life.

- **No live preview beside the settings repeater.** An administrator typing
  icon + text + link cannot see what they are building, and `WebhookTester`
  is the precedent for that kind of affordance on this plugin's admin screens.
  It is worth doing and it is cheap; it is left out because it was not in the
  three decisions approved for this phase, not because it was judged against.

- **The account sidebar is not built.** `AccountMenu` is shaped to serve it and
  guard-railed so nothing else can become a second source, but the sidebar is
  the account-system phase, not this one. Landing a registry with no consumer
  would leave it unverified; landing it with one consumer proves it and no more.
- **No reordering by drag.** The row order in the settings table is the menu
  order. A drag handle needs JavaScript and a sortable, and finding 6's pattern
  deliberately has neither. Reordering means retyping rows; that is a real cost
  and it is accepted rather than hidden.
- **No page picker in the repeater.** The link column is a `url` field.
  `FieldRegistry`'s `page` type exists (`class-field-registry.php` — three uses)
  but it is a single select and does not nest inside a repeater row.
- **No active-state highlighting.** It needs `key` (decision 4 provides it) and a
  surface that knows which page it is on. The header does not.
- **No capability filtering.** A menu row is a link; WordPress already refuses
  the destination if the member may not see it. Duplicating that judgement here
  would be a second authorisation model, and a wrong one.
- **No badge counts.** The reference screenshot's `1` is on the cart, which is
  WooCommerce's and not this plugin's.
- **`sections_meta()` keeps its contents.** Only its icon *source* changes
  (decision 6). Its four entries, their labels and their order are Phase 17's and
  are not reopened.

---

## What this phase does not claim

`tests/visual/render.php` is not a browser, and its README says so. Focus order
in the open dropdown, the sheet at 375px, the collapse breakpoint, and whether
the stylesheet actually reaches a page hosting only `[smart_login_button]` are
**measurements**, and they go through `tests/integration/` — because the enqueue
in finding 1 is exactly the class of defect that only a real WordPress can show,
and four gates once missed a fatal by reasoning about one instead.

Decision 13 claims the token move changes nothing visually. That claim is also a
measurement, not an argument: it is the rendered-surface baseline holding still
across the sub-phase, and if it moves the claim was wrong. Nav-menu injection
(decision 11) is likewise a real-WordPress question — `wp_nav_menu_items` fires
inside a theme's own markup, and whether the result is a valid `<li>` is not
something a fixture can answer.
