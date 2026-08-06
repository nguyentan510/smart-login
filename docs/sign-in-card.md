# The sign-in card — normative spec

Progress tracker: [`refactor-plan.md`](refactor-plan.md) Phase 16. Execution
briefs: [`sign-in-card/`](sign-in-card/).

This file carries the **decisions**. It says what the "Đăng nhập & liên hệ" card
is, what belongs in it and in what shape. It records no status — status lives in
the tracker and nowhere else.

---

## The problem

One card answers one question — *how do you get in, and how do we reach you* —
and answers it twice, in two grids, two formats and two verbs.

Rendered today for an account with a verified email and a linked Google:

```text
● Đăng nhập & liên hệ                 [lưu riêng, không qua nút Cập nhật]
  Số điện thoại   Chưa có số điện thoại                            Thêm
  Email           hoacai.vi@gmail.com               [đã xác thực]  Đổi

  Cách đăng nhập của bạn
  Email     ho•••••••@gmail.com                        [Chính]  ▸ Bỏ liên kết
  Google    1171••••••                                          ▸ Bỏ liên kết

  Thêm một cách đăng nhập nhanh:
```

Four rows for three facts. The address is printed twice, once whole and once
masked, so the two read as different addresses. Nothing on screen explains why
Email appears above the subtitle *and* below it.

## Findings

### The duplication was decided against in Phase 8, and came back through Phase 14

[`account-surface.md`](account-surface.md) line 131 lists the defect in as many
words — "with email appearing twice" — and
[`account-surface/8.4-layout.md`](account-surface/8.4-layout.md) records it
fixed. It is back, and 8.4 did not regress.

`IdentityLinkService::linked()`
(`includes/Auth/class-identity-link-service.php:56-76`) returns **every**
identity record. It does not filter by channel and never has. Before Phase 14 an
`email` row existed only for accounts that had registered by email, so the
double print hit a minority; 14.4 granted a row to provider accounts and 14.5
backfilled the existing population, which turned a minority into nearly
everyone. The same is true of `phone` for every account that registered by SMS.

The payload has carried the flag that distinguishes the two kinds since Phase 6
— `'federated' => …` at `:67` — and no template has ever read it.

**No suite caught it because no fixture has ever held a non-federated identity.**
`tests/identity/run-template-tests.php:213` renders the contact card with
`sl_identities => array()`, and `:220` renders the provider partial with a single
`google` row. This is 14.6's lesson one phase later: the fixture has to be the
case the code gets wrong, or the assertion is decoration.

### Masking argues against itself here

The service's own docblock (`:49-53`) gives the reason subjects are masked: a
profile screen is a screen-sharing hazard, and the full value is of no use to
somebody who already owns it. Both halves are true. Neither survives this
composition — the whole address is on screen one row above, so masking below
conceals nothing from a shoulder and costs the reader the only thing that would
tell them the two rows are one value.

### A subject is not a name

`Google 1171••••••` is the OIDC `sub` claim, masked. Its owner has never seen
that number anywhere, in this plugin or at Google, so the row identifies nothing
— and an account with two Google links would render two rows a person cannot
tell apart.

The data to do better is already stored. `AccountProvisioner::link()`
(`includes/Auth/class-account-provisioner.php:295-302`) passes
`$identity->claims` into `meta_json`, and `GoogleProvider::safe_claims()`
(`:161-163`) keeps `email` and `name` among them. `IdentityRecord::from_row()`
decodes it. Nothing reads it.

### The list borrowed a verb it should not have

"Bỏ liên kết" is the right verb for a federated identity: the account and the
provider are two things, and one stops pointing at the other. Applied to an
address the account owns, it is the wrong verb for the wrong operation. What a
member wants there is **Đổi**, which exists in the row above and is implemented
correctly by `IdentityDirectory::replace_in_channel()` — retire the old subject,
claim the new one.

The card currently offers both on one value, which is also why the row reads
`[Chính]  ▸ Bỏ liên kết`: a badge saying this is your main way in, beside a link
offering to remove it.

### Geometry

Four defects, all provable from the stylesheet and the partial:

| Symptom | Cause |
| --- | --- |
| The confirm button overflows its panel | `.sl-btn` is `display:block; width:100%` with `13px 16px` padding (`assets/css/smart-login.css:343-347`) inside a panel with `12px` padding (`:1042-1049`). It fits only while `box-sizing` is `border-box`, and `.smart-login *` sets that by `inherit` at one class of specificity — the exact exposure `:251-267` already documents for `.sl-input` |
| The password box is unstyled and inline with its label | The input at `templates/partials/linked-identities.php:58-64` carries no `sl-input` class and its label at `:55-57` carries no `sl-label`, so neither `width:100%` (`:225-226`) nor `display:block` (`:207-208`) applies |
| The two groups do not share a column | `.sl-row__label` is `flex: 0 0 108px` (`:963-967`); `.sl-identity-label` has no basis at all (`:1014-1016`), so its values start wherever the word ends |
| The rarest, most destructive control is the largest | A full-width outlined button, heavier than the page's own "Cập nhật" |

The overflow is the one finding here that a stylesheet cannot fully prove: it
depends on what the active theme declares. The measurement belongs in a browser;
the fix does not depend on the answer.

---

## Decisions

### 1. One list, one grid

The card renders **one row per way in**, in one column grid. `.sl-row` is that
shape and it already exists. The parallel `.sl-identity-item` grid goes.

Rows differ only in their action:

| Row | Value shown | Action |
| --- | --- | --- |
| Số điện thoại | the number, or "chưa có" | Đổi / Thêm |
| Email | the address, or "chưa có" | Đổi / Thêm |
| Google, Zalo | the linked account | Bỏ liên kết |

The subtitle "Cách đăng nhập của bạn" is removed with the second grid. A card
titled *Đăng nhập & liên hệ* does not need a subtitle repeating half its own
title over half its own rows.

### 2. One value, one place

A channel the contact rows own is never repeated below them. The template
filters on `federated`, the flag `linked()` has emitted since Phase 6.

This is presentation, not policy: `linked()` keeps returning everything, because
`can_unlink()`'s orphan guard counts identities and the REST routes serve
callers other than this card.

### 3. The contact row carries the identity state

Saying "your email is one of your ways in" belongs beside the address, not in a
second list. Where an identity row exists for a self-asserted channel, its
contact row says so, and carries `Chính` when that record is primary.

### 4. Unmasked where the value is already on screen, masked where it is not

Self-asserted rows show the value they already show — one row, one format.
Federated rows are the shoulder-surfing case the masking rule was written for
and keep it.

### 5. A provider row names the account, not the number

`linked()` gains one computed key, `display`, resolved in the service:

1. the display name from `meta['name']`, if stored
2. otherwise the masked `meta['email']`
3. otherwise the linked date

Never the raw `sub`, and never an unmasked provider address.

**Written-down trade:** `meta_json` is described in
`includes/class-installer.php:151` as forensic context, and it is a link-time
snapshot — a member who renames their Google account will see the old name here.
Accepted, because the row's subject *is* the link-time fact "which account you
attached", and the alternative on screen today is a number that identifies
nothing. Staleness is stated in the code, not implied away.

### 6. Đổi for what you own, Bỏ liên kết for what you borrowed

No unlink control is rendered for a self-asserted channel.

**Deferral, recorded here because this is where it is decided:** removing a
phone or an email outright — as opposed to replacing it — then has no route on
this screen. That is a narrowing, and it is deliberate. The operation is rare,
it is destructive, its only protection is the orphan guard, and this card has no
copy that distinguishes "I no longer use this address" from "this address is
wrong". A screen for it is a design problem, not a control to leave lying next
to a badge that says `Chính`. `IdentityLinkService::unlink()` is unchanged and
still serves the REST route.

### 7. A control is sized by the card, not by whatever the theme resets

The border-box guard `:251-267` established for `.sl-input` extends to every
full-width component the plugin styles, and every input the plugin renders
carries the classes its own stylesheet targets. Both are stated as rules over
the stylesheet and the templates, so the next component cannot arrive without
them — the instance is one overflowing button; the class is "a plugin component
whose width is decided by somebody else's reset".

The destructive confirmation stops being the heaviest control in the card.

### 8. The badge states the effect, not the mechanism

`lưu riêng, không qua nút Cập nhật` describes the implementation and asks the
reader to hold a rule about which button applies to which card. It becomes a
statement of effect. `saves_own` and everything reading it are untouched — this
is the badge's copy, not its source.

---

## Ownership boundary

Phase 8.2's section contract holds unchanged, and this phase must not weaken it:

- `providers` stays a section, because `profile-summary.php` asks for it by name
- `templates/partials/linked-identities.php` stays the **only** owner of the
  unlink markup. Its rows change shape; they do not move into the contact
  partial, and no second copy is created
- `saves_own` stays a data property with three readers

## Not in this phase

The address card, the password card and the save bar. The REST routes, the
orphan guard and `unlink()`'s re-authentication. The msgid language, declined in
8.6.
