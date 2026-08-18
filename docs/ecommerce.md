# The e-commerce surface

Normative spec for `includes/Ecommerce/` plus `Frontend\VoucherService` and
`Frontend\AccountHub`.

Written **after** the code, which is the thing worth saying first. Every other
module in this plugin has a spec, a set of briefs and a tracker row that precede
it. This one arrived across roughly thirty commits following Phase 23 with none
of the three, and this file is the retrofit rather than the plan. What that cost
is recorded in [`refactor-plan.md`](refactor-plan.md) under Phase 24.

---

## What it owns

| Class | Lines | Owns |
| --- | --- | --- |
| `Ecommerce\CheckoutService` | 683 | the Vietnamese checkout: field surgery, address cards, voucher picker, order-confirmation modal |
| `Ecommerce\CartService` | 675 | cart data as an array, coupons, freeship progress, cross-sells, quantity/variation mutation |
| `Frontend\VoucherService` | 521 | which vouchers a customer holds and which of them this cart may use |
| `Ecommerce\SlideCart` | 313 | the drawer, and the eight AJAX actions behind it |
| `Frontend\AccountHub` | 159 | the tabbed account surface |
| `Ecommerce\ThankYouService` | 94 | the post-order screen, including the VietQR payload |

## The boundary, and why it sits here

WooCommerce owns orders, payment and stock. This module never re-implements
them; it replaces presentation and adds Vietnamese-market behaviour on top —
two-level administrative addresses, VietQR, freeship progress, a voucher picker.

The sibling plugin ShopKit is forbidden by its own fitness rules from touching
`WC()->cart`, `WC_Order` or `wc_get_order`, and that prohibition names this
module as the reason. So the cart and checkout live here, and the catalogue
lives there. Neither rule is a preference: ShopKit's HPOS compatibility
declaration is only honest while it holds.

## The write surface is `wp_ajax_*`, and that is the whole of it

Twenty-three registrations across `CheckoutService` and `SlideCart`. There is no
REST route in this module and no form post; every state change a customer can
cause arrives through `admin-ajax.php`.

That makes the nonce policy the security model, so it is a rule rather than a
habit — `tests/ecommerce/run-ecommerce-tests.php` rule 6. **Every callback
verifies a nonce unless it is on an allowlist that carries its reason**, and the
allowlist is checked in both directions so an exemption cannot outlive the
callback it excused.

Three are exempt today, and all three were read before being written down:

| Callback | Why |
| --- | --- |
| `ajax_save_address_nopriv` | answers 401 and writes nothing — it exists so a guest sees a message instead of admin-ajax's bare `0` |
| `ajax_get_wards` | returns the shipped province/ward dataset, already public through the address REST route |
| `ajax_get_checkout_vouchers` | read-only and session-scoped; a cross-origin caller can make it run but cannot read the response |

The rule that matters more than the exemptions is rule 5: a registration names
its callback by string, and a string that names nothing makes `admin-ajax.php`
answer `0` while the browser shows a cart that silently stops working. Nothing
checked that until Phase 24.

## Freeship, and the one place the bar is allowed to lie

`CartService::calculate_freeship_progress()` returns both a rounded
`percentage` and a boolean `is_reached`. At 499,999 of 500,000 the percentage
rounds to 100 while `is_reached` is still false.

They are allowed to disagree **only** in that direction: the bar may look full
before the customer qualifies, never the reverse. `is_reached` is what decides
shipping; `percentage` only decides pixels. A threshold of zero or less disables
the feature and reports `is_reached: true`, so a site that turns it off does not
show every customer an unreachable goal.

## What is still not covered, stated rather than glossed

Phase 24 added a structural floor, not behavioural coverage. These remain
genuinely untested, and the reason is the same for all of them: they need a real
WooCommerce cart with real products, which no suite here has.

- `CheckoutService`'s field filters, template swap and modal rendering
- `VoucherService::evaluate_cart_vouchers()` — the eligibility logic, which is
  where a wrong answer costs money
- `SlideCart`'s eight mutations: quantity, removal, restore, variation switch,
  coupon apply and remove
- `ThankYouService::generate_vietqr_url()` beyond its COD null case

They belong in a `tests/integration/run-ecommerce-gate.php` that does not exist
yet. Until it does, this list is the honest statement of the gap — not a claim
that the module is verified.
