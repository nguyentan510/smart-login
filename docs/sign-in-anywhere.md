# Sign-in on every page

Normative spec for Phase 19. Status lives in
[`refactor-plan.md`](refactor-plan.md); the execution briefs live in
[`sign-in-anywhere/`](sign-in-anywhere/), one per sub-phase.

The request was "a login popup with a backdrop, callable from anywhere, with
`#login` in the URL". The backdrop is the cheapest part of it. What the request
actually asks for is that **registration and onboarding finish where the visitor
already is** — and that is a server-side property this plugin does not currently
have, on any surface.

---

## Findings

Every claim below is pinned to a line. The ones that contradict the framing of
the request are listed first, deliberately.

### 1. Registration already refuses to stay on the page — everywhere, not just in a popup

```php
if ( $result->is_new_user && ! $profiles->has_seen( $result->user_id ) ) {
    $url = add_query_arg( 'OmniWP_welcome', '1', self::profile_url() );
```
`includes/Auth/class-post-auth-redirector.php:28-34`

This branch **ignores `$requested`** — the `redirect_to` the visitor arrived
with. And `profile_url()` falls back to `admin_url( 'profile.php' )` when
WooCommerce is absent (`:67-73`).

So a visitor who registers from a blog post today is sent to `wp-admin`. The
popup does not cause this defect; it makes it unmissable. **This is the phase.**

### 2. Onboarding has exactly one host, and it is a page with a shortcode

`Shortcodes::is_welcome_request()` requires a signed-in user *and*
`?OmniWP_welcome=1` (`includes/Frontend/class-shortcodes.php:202-205`), and
`STEP_ONBOARD` only renders inside `render_flow()` (`:118-120`). There is no
route by which the welcome screen can appear on a product page.

### 3. The REST surface is a generation behind the form surface

Routes: `register`, `verify`, `resend`, `login`, `forgot`, `reset`
(`includes/Frontend/class-rest-controller.php:59-66`).

There is **no `identify`**. Identifier-first — one field, the server decides
login or signup — is the entire first step of the current UX and exists only on
the HTML path, in `FormController::handle_identify()`
(`includes/Frontend/class-form-controller.php:80`). The JSON API still models
the two-screen login/register world the form flow left behind.

A popup that talks to the server has no endpoint to call for step one.

### 4. The state that matters already survives a page load

| State | Where | Survives navigation |
| --- | --- | --- |
| Pending OTP flow | HttpOnly cookie `OMNIWP_flow` (`class-pending-session.php:19`) | yes |
| Message after redirect | HMAC-signed cookie `OMNIWP_flash` (`class-notices.php:16`) | yes |
| `Flow::$step`, `Flow::$old` | request-scoped statics (`class-flow.php:56-63`) | no |

This is the finding that makes the feature affordable: a popup reopened on a
*different* page still lands on the correct OTP step, because the authority is a
cookie and not a URL.

### 5. Printing the form into every page breaks it behind a page cache

`RequestGuard::fields()` writes a nonce and an HMAC-signed timestamp into the
markup (`includes/Security/class-request-guard.php:29-51`), with
`MAX_FORM_AGE = 3600` (`:24`).

Markup emitted into `wp_footer` on every page is markup a full-page cache will
serve to every anonymous visitor. The nonce is then stale and the stamp is hours
old: **"Biểu mẫu đã hết hạn"** for everyone, on production only, on a site the
developer cannot reproduce. This is why decision 1 is what it is.

### 6. `MIN_FILL_SECONDS` was written for a form that loads with the page

`const MIN_FILL_SECONDS = 2` (`class-request-guard.php:21`). A form that is on
screen when the page loads is several seconds old by the time anyone types. A
form that appears on click is two seconds old when a password manager fills it
and the visitor presses Enter.

The guard is not wrong. It is being asked a question it was not written for.

### 7. Assets never reach the pages the request names

`Assets::maybe_enqueue()` requires `is_singular()` **and** a shortcode in
`post_content` (`class-assets.php:32-49`). Shop archives, category listings and
search results — three of the places a "đăng ký nhanh" button belongs — load
neither the stylesheet nor the script.

### 8. `Flow::url()` computes step links from *the current request*

```php
$base = remove_query_arg( array( 'OMNIWP_step', 'OmniWP_welcome' ) );
```
`class-flow.php:125`

Correct inside a page render, wrong inside a REST request: the "current URL"
there is `/wp-json/omniwp/v1/…`. Any fragment rendered over REST will emit
step links pointing into the API unless the renderer is given an explicit base.

The same applies to `form-auth.php:30`, which reads `redirect_to` out of
`$_GET` — in a REST render that is the API request's query string, not the
page's.

### 9. Two defects are already sitting in the markup, waiting for a second copy of the form

- **Duplicate ids.** `templates/form-auth.php:94-104` hard-codes
  `id="sl-identity"` and `autofocus`. A login page that also carries the popup
  has two elements with one id, a `<label for>` that resolves to whichever came
  first, and two controls claiming focus.
- **Form inside form.** `DeferredForms` exists because a nested `</form>` closes
  the *outer* form and silently disables everything after it
  (`class-deferred-forms.php:1-27`). Popup markup placed inline in content — a
  reasonable-looking thing to do inside a checkout template — reproduces that
  bug exactly.

### 10. There is no modal code in this plugin

`grep -i "modal|dialog|backdrop|popup"` across `includes/`, `assets/` and
`templates/` returns nothing. Everything in Phase 19's client layer is new.

### 11. A server-visible trigger already exists, and the first draft of this plan ignored it

```php
$requested = isset( $_GET['OMNIWP_step'] ) ? sanitize_key( wp_unslash( $_GET['OMNIWP_step'] ) ) : '';
return in_array( $requested, self::PUBLIC_STEPS, true ) ? self::canonical( $requested ) : self::canonical( $fallback );
```
`class-flow.php:78-80`

`?OMNIWP_step=identify` works today, is already allowlisted, is already
generated by `Flow::url()` (`:124-133`), and is already used by
`ProviderAuthController` as its failure fallback (`:385`).
`?OmniWP_welcome=1` is a second one of the same kind, read in three places.

The request named `#login`, and the first draft of this spec took that as the
mechanism. It is not a mechanism, it is a **spelling**. Adding it as a third
vocabulary for a concept that already has two is the drift CLAUDE.md records
five times.

Decision 4 is rewritten accordingly: the query parameter is the canonical form —
it is the only one the server can see, and therefore the only one that can
survive JavaScript being off, be acted on before first paint, or be sent in an
email. `#login` becomes an alias the launcher resolves *to* it.

### 12. The provider hand-off is a full-page navigation and cannot be otherwise

`ProviderAuthController::start_url()` builds an `admin-post.php` URL and
`start()` ends in `wp_redirect()` to the provider
(`class-provider-auth-controller.php:40-52`, `:104-108`). The visitor leaves the
site. `redirect_to` is validated and carried in the transaction store — but
`form-auth.php:30` only ever populates it from `$_GET`, so a popup opened on a
product page sends an **empty** return url.

### 13. The WooCommerce cart already survives a sign-in — because `SessionIssuer` fires `wp_login`

Checked rather than assumed, because the opposite would have been a data-loss
path worth its own sub-phase.

`WC_Cart_Session::get_cart_from_session()` merges a guest's session cart with the
member's saved cart only when `_woocommerce_load_saved_cart_after_login` is set
(`woocommerce/includes/class-wc-cart-session.php:115-124`), and that meta is
written by `wc_user_logged_in()` on the **`wp_login`** action
(`wc-user-functions.php:1096-1100`).

`SessionIssuer::issue()` fires it (`class-session-issuer.php:43`), and
`wp_set_auth_cookie` appears nowhere else in `includes/` — every sign-in the
plugin performs goes through that one method.

So "add to cart as a guest, then sign in" already keeps both carts. What breaks
it today is not the cart, it is finding 1: the member is sent away from the
product page afterwards. **19.5 is the fix; 19.9 is the proof.** No cart code is
needed, and writing some would be inventing work.

---

## Decisions

### 1. One renderer, fetched on open, never printed into the page

The popup requests its markup from a REST endpoint when it opens, and receives
HTML rendered by the same templates the shortcode uses.

Rejected alternatives, with reasons:

| Option | Rejected because |
| --- | --- |
| Print the form into `wp_footer` on every page | Finding 5 — a page cache serves a dead nonce to every visitor. Also adds markup and assets to pages nobody signs in from |
| Full JSON API, templates re-implemented in JavaScript | `assets/js/omniwp.js:1-6` states the plugin's contract: *"Every form works without this file."* A second copy of every template in JS is a second source of truth, and this project has been bitten by drift five times (CLAUDE.md) |

The consequence is that the fragment renderer must be given, explicitly, what a
page render gets for free: the host page's URL and the visitor's `redirect_to`
(finding 8).

### 2. Submitting goes through REST and returns the next fragment

"HTML over the wire": the popup POSTs, the server runs the step and answers with
`{ step, html, redirect? }`. The visitor sees one form replaced by the next
without a navigation.

**The step handlers must be shared, not copied.** `FormController` and the popup
path both drive the same state machine; two implementations of
`handle_identify()` would drift within a phase. The handlers move to a flow
engine that both controllers call, and a guard rail asserts neither controller
carries a decision the other does not.

This is the largest piece of work in the phase and the reason 19.1 precedes
everything visible.

### 3. Post-auth redirection becomes context-aware

`AuthContext` gains the fact that the flow is running in place. When it is:

- a new user does **not** get sent to `profile_url()`; the response carries
  `step: onboard` and the popup renders the welcome screen where the visitor is
- "Hoàn tất" and "Để sau" both close the popup and reload the current page
- an existing user signing in reloads the current page

Reloading rather than patching the DOM is deliberate: on a WooCommerce site the
price, the cart fragment and half the nonces on the page are all role-dependent.
A popup that closes without a reload leaves a logged-in visitor looking at a
logged-out page.

`profile_url()` keeps its current behaviour for every existing caller. Nothing
about the shortcode flow changes.

### 4. One vocabulary, four spellings — and the canonical one is the query parameter

Finding 11: the plugin already has a server-visible trigger. It is the canonical
form, and everything else resolves to it.

| Spelling | Role |
| --- | --- |
| `?OMNIWP_step=<step>` | **canonical.** The only form the server can see |
| `#login`, `#dang-ky`, … | client alias; the launcher resolves it to the above |
| `data-omniwp="<step>"` | for elements that are not links |
| `window.OmniWP.open( step )` / `.close()` | for themes and other plugins |
| a captured login link (decision 8) | for sites that edit nothing at all |

Why the query parameter and not the fragment, stated once so it is not
re-argued: a fragment is never sent to the server. It cannot be acted on before
first paint, cannot render anything when the script fails, cannot bypass a page
cache, and cannot be the target of a redirect — which is exactly what the
provider return in 19.6 needs. The hash is kept because it is short, it is what
a person writing a link in the editor will reach for, and it does not alter the
canonical URL.

The trade the query form carries is its own: it creates a second URL for the
same content. The dialog-open variant is emitted `noindex` and the page's
canonical URL is left untouched.

The accepted steps are exactly `Flow::PUBLIC_STEPS` (`class-flow.php:47-54`).
That list already excludes `password`, `signup` and `onboard`, and the comment
above it explains why: each one is meaningless without server-side state, and
reaching it by typing a URL renders a form with nothing behind it. The popup
inherits that rule rather than inventing a second one.

**With JavaScript off, `<a href="#login">` must still go somewhere.** The
launcher rewrites the anchor's `href` to `Flow::login_url()`
(`class-flow.php:154-167`) at render time and intercepts the click; if the
script never runs, the link is an ordinary link to the sign-in page. If no page
hosts the shortcode, `login_url()` returns `''` and the anchor is left as a
fragment — the same "no third answer" case that method already documents.

**Already signed in:** `#login` opens nothing and is stripped from the URL. It
does not show an account screen; `[smart_account]` is that surface and it has an
owner.

### 5. The shell is a native `<dialog>`

`showModal()` gives the focus trap, the `Esc` handling, the inert background and
the top-layer stacking that would otherwise be four hand-written behaviours that
Phase 18 would then have to measure. Baseline support is universal in every
browser this plugin's stylesheet already targets.

Emitted immediately before `</body>` and outside every form — finding 9's second
half is a bug this project has already paid for once.

### 6. Providers keep the full-page redirect, and the return re-opens the popup

Chosen over `window.open` + `postMessage`: popup blockers on mobile, COOP
headers, and providers that refuse embedded contexts make the smoother option
the less reliable one, and reliability is what a sign-in surface is for.

The popup sends `redirect_to` = the host page URL. On return, a visitor who is
new lands with the flow's own marker in the URL and the launcher re-opens the
popup at `onboard`. An existing user simply lands back on the page, signed in.

### 7. Assets load in two stages

A small launcher script (`assets/js/omniwp-launcher.js`) is enqueued
site-wide and owns the hash contract and nothing else. On the first open it
injects the main stylesheet and script, whose URLs it was localized with.

The alternative — enqueueing 1,512 lines of CSS and 664 of JS on every page of
the site against the possibility of a click — is declined here rather than left
to be discovered as a performance complaint.

Finding 7's `is_singular()` limit is fixed as part of this: the launcher is not
conditional on a shortcode.

### 8. Login links the site already has are captured — but never rewritten

Every trigger in decision 4 requires somebody to edit markup. The site already
has login links that nobody will edit: the theme's header button, `wp-login.php`,
WooCommerce's my-account link. The launcher recognises them and intercepts the
click.

**Intercepted, not rewritten.** The distinction is the whole safety argument: the
`href` stays exactly as the theme wrote it, so with the script blocked, removed
or still loading, every one of those links is the ordinary link it was. There is
no state in which capture can strand a visitor.

Bounded by four conditions, all of them structural rather than heuristic:

- only when the visitor is signed out
- only for URLs the plugin can name: `Flow::login_url()`, `wp_login_url()`, and
  WooCommerce's my-account permalink — the surface it already replaces at
  `class-woo-integration.php:77`
- never for `wp-login.php` carrying an `action` (`logout`, `postpass`,
  `resetpass`)
- never for an element marked `data-no-omniwp`

Default on, because a capture that is off by default is a feature nobody
receives. `OMNIWP_capture_links` turns it off, and it is the same filter a
site uses to add a URL of its own.

---

## Deferrals

Written down here because a silent exception is a lie with a longer half-life.

- **No settings screen, and no new tab.** The off switch is the filter
  `OMNIWP_popup_enabled`, documented in `README.md`. Scope was chosen
  deliberately; a checkbox can be added later by one `FieldRegistry` row, which
  is the point of that registry.
- **Nothing is resumed after sign-in except a WooCommerce cart, which resumes by
  itself.** Finding 13: the cart already survives. Replaying any other
  interrupted action — a comment, a form POST — means replaying a request the
  visitor did not re-authorise, which is a CSRF decision and not a UI one. It
  needs its own spec and it is not this phase.
- **`href` is never rewritten.** Decision 8 captures clicks; it does not edit
  links. A plugin that rewrote the theme's markup would own a failure mode where
  its own script is the only thing keeping the site's login link working.
- **`STEP_RESET` renders in the popup but the emailed reset link does not open
  one.** The link is a URL the mail template controls and it lands on a real
  page. Making mail open a popup is a fourth surface and is not in this phase.
- **`MIN_FILL_SECONDS` is not changed.** The guard is correct; the popup is
  what has to adapt (19.3 keeps submit disabled until the fragment's stamp is
  old enough, so the visitor never meets the error). Loosening a bot control to
  make a UI feel faster is the wrong trade and is refused here in writing.

---

## What this phase does not claim

The fragment renderer is not a browser. Phase 18 built
`tests/visual/render.php` and its README says plainly that a rendered file is
not a WordPress page. The dialog's focus order, the backdrop at 375px and the
scroll lock are measurements, taken in 19.7, and anything involving real
WordPress — the launcher's enqueue, the REST round trip, the provider return —
goes through `tests/integration/`, because four gates once missed a fatal that
only a real WordPress could show.
