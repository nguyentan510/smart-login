# The provider surface

Normative spec for Phase 12. Status lives in
[`refactor-plan.md`](refactor-plan.md); execution briefs live in
[`provider-surface/`](provider-surface/).

---

## What is left

Phase 12 came out of a wireframe review during Phase 10 and had four items. The
first was a defect and was fixed early, ahead of the rest, because it was
shipping wrong on live sites: the card's badge read `is_configured()` while the
runtime reads `is_available()`, so a provider with credentials saved and
`Kích hoạt` unticked showed a green **Sẵn sàng** with no button rendering
anywhere.

Three remain, and one of them should not be built.

## D1 — The master switch belongs in the card header

`providers.<slug>.enabled` renders as a row inside the card's `form-table`, level
with Client ID. It is the control an administrator touches repeatedly — turning a
provider on and off is routine, filling in credentials happens once — and it is
also the input the badge now reports on, so the two belong beside each other
rather than one above a table and one inside it.

## D2 — A policy that governs both cards goes above both cards

`providers.auto_link_email` is rendered by its own section *below* the grid,
which reads as a footnote to the second card. It decides, for every provider,
whether a verified provider email may silently adopt an existing account — the
single most consequential setting on the screen.

Above the grid, framed as applying to everything under it.

## D3 — A connection test, because a redirect URI cannot be checked any other way

The provider screen has the least feedback and the most ways to be wrong.
`sms` and `email` both have **Gửi thử**; a provider has nothing, and its common
failure — a redirect URI that does not match to the character — is invisible
until a real visitor meets it.

There is no remote check for this. Google will not tell us whether a URI is
registered; the only honest test is to perform the exchange and report what the
provider says. So the test **is** a real OAuth round trip.

Which makes the safety property the whole of the design:

> A test round trip must never issue a session, create a user, or link an
> identity.

`ProviderAuthController::callback()` consumes the transaction and reaches
`SessionIssuer::issue()` (`class-provider-auth-controller.php:139`). A test that
reused that path would log the administrator in as whatever account they picked
and, on a fresh one, provision it — a diagnostic with side effects, and the side
effects are account creation.

The transaction store already carries a `linking` flag
(`class-o-auth-transaction-store.php:19`), so it already knows how to mean
something other than "sign this person in". A test transaction is a third mode
that stops after the token exchange and the identity read, reports what came
back, and discards it.

## D4 — The shared channel card is **not** built, and here is why

The wireframe proposed promoting `ProviderCards` into a reusable component,
because the delivery tab redesign was going to build four screens on it and
building them first would mean writing the card twice.

**10.6 shipped without it.** The five delivery screens are `form-table`s, they
are qualified against a real WordPress, and they are merged. The premise expired.

Building it now inverts the argument: it would mean retrofitting five working
screens onto a component, with no defect driving it and no third consumer asking
for it. `sl-provider-card` has exactly one user
(`grep sl-provider-card includes/ templates/ assets/` → `class-provider-cards.php`),
and a shared abstraction with one caller is a rename with extra steps.

The rule this project keeps is to make a bug class unrepresentable rather than to
fix one instance. There is no bug class here — the delivery screens do not have a
status to lie about, which was the defect the card's badge had. Recorded as a
decision rather than dropped silently, and reversible the day a second surface
needs a card with a badge.

## Ownership boundary

| Concern | Owner |
| --- | --- |
| Whether a provider can run | `LoginProviderInterface::is_available()`, unchanged |
| What the badge says | `ProviderCards::state()`, which asks the registry |
| Where a control sits | `ProviderCards`, presentation only |
| Whether a round trip signs anyone in | the transaction's mode, checked in `callback()` |
| Exchanging a code for an identity | the provider class, unchanged |

Nothing in this phase changes what a provider does. It changes where two controls
sit and adds a path that deliberately stops short of the one that matters.

## Not in this phase

**Per-provider redirect URI overrides.** The callback URL is derived and copied;
making it editable invites the mismatch the test exists to detect.

**A third provider.** Adding one is a `FederatedChannel` and a provider class,
and it is not blocked on anything here.
