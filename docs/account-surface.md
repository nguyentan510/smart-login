# Account surface — normative spec

Progress tracker: [`refactor-plan.md`](refactor-plan.md) Phase 8. Execution
briefs: [`account-surface/`](account-surface/).

This file carries the **decisions**. It says what the account surface is and who
owns which part of it. It records no status — status lives in the tracker and
nowhere else, so a brief marked done in one place and open in another is not a
state this repo can reach.

---

## The problem

`templates/woocommerce/form-edit-account.php` is 330 lines of markup with
services instantiated inline (lines 29–30, 188), and it is the *only* way to edit
a profile. `[smart_profile]` renders a read-only `<dl>` whose "Cập nhật ngay"
link falls back to `admin_url( 'profile.php' )` when WooCommerce is absent
(`profile-summary.php:25`) — the plugin sends its own members into wp-admin.

The editing surface is not merely styled by WooCommerce. It is powered by it.

## The drift, which is the argument for the whole phase

The profile-status notice exists twice:

| File | Renders |
| --- | --- |
| `profile-summary.php:42-56` | heading, sentence, action link |
| `form-edit-account.php:62-65` | `implode()` of labels, nothing else |

On a live site the second produces a blue box containing the single word "Địa
chỉ". `ProfileCompletionService::onboarding_reasons()` holds the sentence that
belongs there ("Để đơn hàng được giao đúng nơi…") and the Woo copy drops it.

The provider block exists twice too. The summary filters
`unlinked_providers()`; the Woo template calls `ProviderRegistry::available()`
raw and so offers "link Google" to accounts whose Google is already linked —
the exact behaviour `partials/linked-identities.php` was written in Phase 6 to
end. It also gives the Woo page no way to unlink at all.

Two copies, one maintained. Extraction is the fix; a fitness rule is what keeps
it fixed.

---

## Ownership boundary

Half of this is already true in the code. The other half is what Phase 8 builds.

| Concern | Owner | Today |
| --- | --- | --- |
| Layout, markup, IA, CSS | Smart Login | `swap_template()` already does this |
| Storage keys (`billing_state`, `billing_city`, `billing_address_1`) | **WooCommerce** | Correct, via `ProfileSeeder` |
| Save path *on the Woo account page* | **WooCommerce** | Correct, via `prepare_account_post()` |
| Save path outside WooCommerce | Smart Login | **Does not exist** |
| Third-party field slots | WooCommerce hooks | Held; must stay held |

"WooCommerce only holds the values" is half right. It also keeps the right to
save on its own page: `WC_Form_Handler::save_account_details` must keep running,
because third-party plugins hook `woocommerce_save_account_details` and
`woocommerce_save_account_details_errors`. Taking that over would stop other
plugins writing — with no error, just missing data.

`WooIntegration::prepare_account_post()` (`template_redirect` @10, ahead of
Woo's @20) is already the correct adapter: it translates `smartlogin_full_name`
into the `account_first_name` / `account_last_name` / `account_display_name`
triplet Woo expects. Phase 8 generalises it. It does not replace it.

Owning the design carries an obligation in the other direction. The renderer must
keep emitting `woocommerce_before_edit_account_form`,
`woocommerce_edit_account_form_start`, `woocommerce_edit_account_form` and
`woocommerce_edit_account_form_end`. Those are where other plugins inject their
fields. Own the layout, but reserve the slots by name.

---

## Section contract

Five sections, one partial each, under `templates/partials/account/`:

| Section | `saves_own` | In the form loop | Persists via |
| --- | --- | --- | --- |
| `profile` | no | yes | the form |
| `contact` | **yes** | yes | REST `contact/start`, `contact/verify` |
| `providers` | **yes** | **no** | OAuth redirect, REST `identities/unlink` |
| `address` | no | yes | the form |
| `password` | no | yes | the form |

8.4 moved the boundaries. `identity` and `profile-extra` are gone: the cards
group by the question each answers, not by which table a field lives in, so the
name joined DOB and gender in `profile` while phone and email joined the
providers in `contact`.

`providers` stays a section because `profile-summary.php` asks for it by name,
but `AccountForm::FORM_SECTIONS` leaves it out of the loop — on the editing
surface it renders *inside* the contact card rather than as a sixth box.

`saves_own` is a data property, not a presentational note. Three things read it:
the renderer excludes those sections from the form's dirty-state accounting, the
save bar ignores them, and the layout renders a badge from it so a member can
tell which controls take effect immediately. A section that persists over REST
while sitting inside a form the user must still submit is the single most
confusing thing about the current page.

`providers` is a call into the existing `partials/linked-identities.php`, never a
third copy of that markup.

## Render contexts

| Context | Nonce / action | Saved by |
| --- | --- | --- |
| `woocommerce` | `save-account-details-nonce`, `save_account_details` | `WC_Form_Handler` |
| `standalone` | `FormController::ACTION_FIELD` = `save_profile` | `FormController` |

One renderer, two contexts. The section partials do not know which one they are
in; the renderer emits the form tag, the nonce and the hook points.

---

## Layout

Section order, and the reason it differs from today's:

1. **Profile status** — only when incomplete, carrying the `reason` string the
   current notice drops
2. **Thông tin cá nhân** — name, DOB, gender. First because it is what people
   actually come here to change
3. **Đăng nhập & liên hệ** — phone, email, linked providers, badged as saving
   independently. Today these three answer one question in three places and
   three visual styles, with email appearing twice
4. **Địa chỉ**
5. **Bảo mật** — password, collapsed
6. **Save bar** pinned to the viewport bottom, showing dirty state

Contact changes collapse from two always-visible OTP panels into one "Đổi" per
row that expands in place, so the current value and the new one sit together.
Providers render from `linked()` with a masked subject and an unlink control;
only `unlinked_providers()` get an invitation.

## CSS inventory — what is missing, not what should change

`.smart-login--account` has **no rules at all**. The page inherits
`max-width: 460px; margin: 0 auto` from `.smart-login` (`smart-login.css:15`) — a
login-card width applied to a profile page inside a full-width WooCommerce
column. This one rule is the largest single visual improvement available.

Ten of the eleven classes in `linked-identities.php` have no CSS: `sl-identities`,
`sl-identity-list`, `sl-identity-item`, `sl-identity-label`, `sl-identity-value`,
`sl-identity-badge`, `sl-identity-note`, `sl-identity-unlink`, `sl-subtitle`,
`sl-btn--danger`. Phase 6 shipped the markup without them, which is why the
partial renders as a bulleted list with a bare `<details>`.

## Field-level corrections

- One read-only treatment: `readonly` + `aria-readonly`. Never `disabled`, which
  is unfocusable and skipped by screen readers. Today email is `readonly` and
  looks editable, while phone and country are `disabled` and look greyed
- Drop the hardcoded "Quốc gia: Việt Nam" row — a locked field carrying no
  information
- The ward select says why it is inert instead of being silently grey
- The hint under Email stops rendering in `--sl-accent` red, which currently
  reads as an error message
- `<p class="sl-lead">` section titles become real headings, so the page has an
  outline
- `password-field.php:22` derives `autocomplete` from `'password' === $name`, but
  the account form passes `password_current`, so the current-password box is
  advertised to password managers as `new-password`

## Removed by request, after 8.4

Two things the earlier drafts of this file describe are gone from the codebase
entirely, on the owner's decision that neither earns its place:

- **Mã giới thiệu.** The meta key, the `profile.referral` setting, the write in
  `UserManager::create_verified_user()`, the `referral_code` slot in the
  registration payload and the field itself. `uninstall.php` still deletes the
  meta key, because a feature going away does not delete the rows an earlier
  version already wrote.
- **Tìm nhanh địa chỉ.** The field, `AddressRepository::search()`, the
  `/address/search` REST route, `AddressNormalizer::index_key()`, the
  `address.quick_search` setting, `initQuickSearch()` in `address.js`, the build
  step that generated it and `data/search-index.php` — 312 KB of shipped
  dataset.

Three fitness rules keep both removed. Integrators who want a referral field can
add one through `smart_login_registration_payload`; the README shows how.

## Address boundary

One picker component, two hosts, one set of keys. The profile section edits the
default delivery address — the `billing_*` fields that prefill checkout — and
Woo's Addresses tab keeps existing for the ship-elsewhere case, with its billing
form rendering the same `partials/address-fields.php`.

Taking the tab over outright would cost the separate shipping address, the
recipient name, `address_2` and the postcode. That is a regression for a real
shop, and no amount of design ownership justifies it.
