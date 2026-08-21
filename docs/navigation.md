> **Historical.** This specification described a navigation module that lived
> in OmniWP through Phase 25. Phase 26 moved it into **NaviKit**, a plugin
> that runs on sites with neither OmniWP nor ShopKit installed; its API is
> documented in that plugin's `docs/contract.md`.
>
> The file is kept because §1 is a set of measurements taken on a real tree,
> and four of them were defects this plugin had shipped. What OmniWP still
> does here is `Frontend\NaviKitBridge` — see
> [`refactor-plan.md`](refactor-plan.md) Phase 26.

# Navigation and mobile chrome

Normative spec for Phase 25. Status lives in
[`refactor-plan.md`](refactor-plan.md); the per-sub-phase briefs are in
[`navigation/`](navigation/).

Requested 2026-08-20, as three things:

> - khi từ Web chuyển về Mobile, hệ thống tự lược giản Header/Footer
> - Floating Mobile Bottom Bar, điều hướng, custom được
> - Mega Menu: bấm vào menu F1 sổ ra bảng lựa chọn, responsive cả Desktop lẫn Mobile

---

## 0. The short version

**These are not three features. They are one tree with three projections.**

The mega panel on desktop, the two-pane browser on mobile, and the `Danh mục` tab
of the bottom bar all render the same node list. Built as three features they
become three lists that have to agree by hand, which is the defect class the
`FieldRegistry` rewrite exists to make unrepresentable, and which
[ShopKit's R1](../../shopkit/docs/p24-roadmap-thuc-thi.md) has just finished
removing from the sibling plugin — one component had three names there because
three lists described it.

So the first question is not *how do we draw a mega menu*. It is **where does the
tree live, and who owns each projection of it**.

---

## 1. What was measured before anything was designed

Five readings, taken on the tree at the start of the phase. Each is a file and a
line, not a recollection.

### 1.1 A mobile bottom dock is already half-built, and none of it runs

`ecommerce.mobile_dock_enabled` (`class-field-registry.php:1197`) is declared, the
Bán hàng tab draws it, the store owner toggles it — and **nothing reads it**.
`.sl-mobile-bottom-dock` (`omniwp-ecommerce.css:7494`) styles it — and **no PHP,
template or script emits that class**.

So the feature this phase was asked for exists twice already, as a switch that
changes nothing and a stylesheet with no markup, and the two halves have never
met. That is the name 25.4 would have reached for, and a dock landing beside a
dead switch of the same name is how a store owner ends up with two toggles, one
of which lies.

### 1.2 Five settings in total are declared, drawn, and read by nothing

The dock switch is not alone. Walking all 131 registry rows against every file
that is not the settings form itself:

| Path | Tab | What the store owner thinks they are setting |
| --- | --- | --- |
| `ecommerce.mobile_dock_enabled` | Bán hàng | the dock in 1.1 |
| `ecommerce.address_book_checkout` | Thanh toán | address book on the checkout |
| `branding.show_floating_cart` | Giao diện | the floating cart bubble — which is real, and reads `ecommerce.floating_cart_enabled` instead (`class-slide-cart.php:131`) |
| `automation.success_path` | Tích hợp | how the automation endpoint judges success — `AutomationEndpoint` reads `url`, `secret`, `timeout` and `headers`, and never these two |
| `automation.success_value` | Tích hợp | as above |

The `sms.*` twins of those last two **are** read
(`class-webhook-transport.php:306`), which is what makes the pair readable as an
oversight rather than a design.

This is the ShopKit "setting chết" class, which bit that plugin six times before a
rule closed it. This plugin had the same exposure and no rule; rule 1 of the
Phase 25 suite is that rule.

### 1.3 Cart state is rendered into every page's HTML

`SlideCart::render_drawer()` runs on `wp_footer` (`class-slide-cart.php:23`) and
writes `CartService::get_cart_data()` — line items, quantities, totals — into the
document. `Shortcodes::render_cart_button()` writes `get_cart_contents_count()`
inline (`class-shortcodes.php:637`). The JS has a `refresh()`
(`omniwp-slide-cart.js:544`) but calls it **after a mutation**, not on load.

Under full-page caching that is not a stale badge. It is one visitor's cart served
to the next visitor. Cart and checkout pages are exempt in practice — every
serious cache plugin sets `DONOTCACHEPAGE` there — but the drawer and the bubble
render on **every** page.

Three call sites do this on a page-render path, and the AJAX callbacks that read
the same data are *not* among them — a callback returning cart JSON is the
correct shape, and is what the fragment marker will call:

| Call site | Runs on |
| --- | --- |
| `SlideCart::render_drawer()` — `class-slide-cart.php:122` | `wp_footer`, every page |
| `Shortcodes::render_cart_button()` — `class-shortcodes.php:637` | wherever the shortcode is placed |
| `templates/ecommerce/voucher-module.php:22` | file scope; pulled into the drawer at `slide-cart-drawer.php:266` |

A bottom bar with a cart badge walks straight into this, so it is settled here
rather than discovered later.

### 1.4 Six elements compete for the bottom edge, and two of them overlap today

| | Selector | Anchor |
| --- | --- | --- |
| ShopKit | `.sk-sticky-cart` | `bottom: 0`, `height: var(--sk-sticky-height, 64px)`, inside `@media (max-width: 768px)` — `shopkit-single.css:172-190` |
| OmniWP | `.sl-floating-cart` | `bottom: 28px`, `bottom: 16px` on mobile — `omniwp-ecommerce.css:1853, 6230` |
| OmniWP | `.sl-sticky-checkout-bar` | `bottom: 0` — `omniwp-ecommerce.css:3126` |
| OmniWP | `.sl-co-sticky-bar` | `bottom: 0` — `omniwp-ecommerce.css:4833` |
| OmniWP | `.sl-toast-notification` | `bottom: 24px` — `omniwp-ecommerce.css:7085` |
| OmniWP | `.sl-mobile-bottom-dock` | `bottom: 0` — `omniwp-ecommerce.css:7494`, and nothing emits it (1.1) |

Not all six can appear at once, but two already do: on a product page on a phone
the bubble sits **on top of** ShopKit's sticky add-to-cart bar, over the
right-hand end of it — which is where the buy button is. Neither element knows the
other exists, and neither adds `padding-bottom` to the document, so the sticky bar
also covers the last 64px of the page permanently.

This is shipped behaviour, not a hypothetical, and a seventh fixed element is
exactly what this phase proposes to add.

### 1.5 The 768px breakpoint is written in JS four times

`omniwp-account-hub.js:82,208` and `smart-account-hub.js:82,208` compare
`window.innerWidth < 768`. The CSS that decides what 768 *means* lives somewhere
else, so the two can disagree and nothing notices. (Those two files are also
near-identical copies of one another, a leftover of the Smart Login → OmniWP
rename; that is out of scope here, and written down rather than fixed.)

ShopKit closed this class in P17.1: the breakpoint lives in CSS, JS asks
`getComputedStyle()` what the layout currently is, and a rule forbids the literal
in the script. This phase adopts that rule rather than reinventing it.

---

## 2. Ownership

The existing boundaries decide most of this, and where they do the decision is
recorded rather than argued.

| Concern | Owner | Why |
| --- | --- | --- |
| Bottom bar (dock) | **OmniWP** | It needs cart count, auth state, a drawer and a checkout exemption. ShopKit's fitness rules forbid `WC()->cart` and `WC()->session` outright, so the code cannot live there |
| Attaching a panel to a WP nav menu item | **OmniWP** | `SmartMenuRenderer` already holds `wp_setup_nav_menu_item`, `wp_nav_menu_objects` and `walker_nav_menu_start_el` (`class-smart-menu-renderer.php:17-20`), plus the item meta and the metabox |
| Header/footer trimming on mobile | **OmniWP** | Site chrome. It is not a catalog surface, so it is not ShopKit's under that plugin's own ownership rule |
| Category/brand tree, term images, term colours | **ShopKit** | `TaxonomyCatalog`, `TermVisual`, `[shopkit_category_nav]` and `[shopkit_brands]` already exist and are tested there |

**The two plugins are joined by a hook, not by a class name.** ShopKit registers a
provider through `omniwp_navigation_providers`; OmniWP never calls a ShopKit class
and never checks `class_exists()` for one. With ShopKit deactivated the menu still
renders — as plain links from the WP menu provider, with no panel — and nothing
fatals. That is the shape the WooCommerce gate already uses in
`Plugin::boot():79`.

---

## 3. The model

### 3.1 One tree, N providers

```
Navigation\Catalog      provider registry: id => label + callback + capabilities
Navigation\Provider     one source of nodes (wp_menu, product_cat, product_brand, …)
Navigation\Tree         a list of root nodes, depth-capped, walkable
Navigation\Node         one entry
```

A projection asks `Catalog::tree( $provider_id, $args )` and renders what comes
back. It never asks a taxonomy, a menu or an option directly — which is what makes
"the same tree in three places" true by construction rather than by discipline.

### 3.2 A node is not always a term

The reference stores settle this. Concung's panel carries a brand-logo grid and a
promo banner beside the category columns; Co.opOnline's carries four labelled
column groups. A model with only `term` nodes cannot express either.

| `type` | Is | Renders as |
| --- | --- | --- |
| `term` | a taxonomy term | link, optionally with a `TermVisual` image/colour and a count |
| `link` | an arbitrary URL | link |
| `group` | a labelled column heading | heading plus its children |
| `block` | a named render callback (brand grid, banner, promo) | whatever the callback returns |

Deciding this now is cheap. Deciding it after nodes are stored is a migration.

### 3.3 Depth is capped at 3, and the cap is in the model

F1 → F2 → F3. Every reference store stops there and puts a **`Xem tất cả`** link
at the foot of each F2 column instead of a fourth level, because a panel that
scrolls is a panel nobody reaches the bottom of.

The cap belongs to `Tree`, not to each renderer: three renderers each enforcing
their own depth is three lists again.

### 3.4 The device axis is data, and it resolves in CSS

A node carries `devices` = `all` | `desktop` | `mobile`. It is rendered as a class
on the element and hidden by a media query. It is **never** resolved by dropping
the node at render time.

`wp_is_mobile()` reads the user agent. The page it produces is then cached and
served to the other kind of device. This is the most common way a mobile
optimisation becomes a desktop bug, and the plugin calls that function nowhere
today — a property worth keeping, and therefore worth a rule.

**This is why the two visibility axes have two mechanisms, and the asymmetry is
deliberate.** `guest`/`logged_in` resolves on the server
(`SmartMenuRenderer::filter_objects():105`) because a cache varies on the auth
cookie, so the two halves are never mixed. `desktop`/`mobile` resolves in the
browser because no cache varies on viewport width. Anyone who later "unifies"
them re-opens 1.5 and 3.4 together.

### 3.5 Cart state never enters cacheable HTML

The dock renders its badge as an **empty** element carrying a fragment marker,
filled from an uncached read on load. The same rule retires the drawer's
server-rendered copy of the cart (1.3).

Cart and checkout pages keep rendering server-side and are allowlisted with that
reason at the call site, in the shape 24.1 established for the nonce exemptions —
checked in both directions, so an exemption cannot outlive what it excused.

### 3.6 The bottom edge has one owner and one token

`--ow-dock-height` is published on `:root` by the dock, and is `0px` when the dock
is off. Everything anchored to the bottom of the viewport stacks on it — the
floating bubble included — and the document gets a matching `padding-bottom`,
plus `env(safe-area-inset-bottom)` for iOS.

ShopKit's `.sk-sticky-cart` reads the same token when it is present and keeps its
current behaviour when it is not, so neither plugin requires the other.

### 3.7 Trimming the theme's header and footer

The plugin does not own theme markup, so there are three honest options and this
phase takes the middle one.

| | Approach | Verdict |
| --- | --- | --- |
| L1 | Hide theme elements by a CSS selector the store types in | Cheap; breaks silently on a theme change |
| **L2** | **Render our own mobile chrome, and hide the theme's with one declared selector that has a preview and an off switch** | **Taken.** Matches "display is ours", and fails visibly rather than silently |
| L3 | Take over `get_header()` / `get_footer()` | The same risk class as ShopKit's archive takeover, which cost that plugin a whole phase to make honest. No |

The nav-menu half of trimming is not a selector problem at all: menu items that
should not appear on a phone are dropped by 3.4, at the source, without touching
theme CSS.

---

## 4. Performance

A store like the reference ones has hundreds of terms. Rendering the whole tree
into every page is tens of kilobytes on every request.

- F1 and the first F2 branch render server-side, so the links are crawlable.
- The remaining F2/F3 panels load on first open from a public, idempotent GET
  endpoint — cache-friendly and nonce-free for the reason ShopKit's four read
  routes are: a `wp_rest` nonce baked into cached HTML outlives the cache entry.
- Term reads go through one `get_terms()` and one prime, never per-node lookups.
  ShopKit already exposes `TermVisual::prime()` for exactly this.

Thresholds are measured in 25.2, not guessed here.

---

## 5. Not in scope

- No canvas editor. The tree is a list, and it is edited as a list.
- No takeover of `get_header()` / `get_footer()`.
- No new write endpoint. If one appears it takes `wp_rest` + `check_ajax_referer`
  and fetches its nonce from an uncached route.
- Not fixing the `omniwp-account-hub.js` / `smart-account-hub.js` duplication
  (1.5). Written down, allowlisted with its reason, left alone.
