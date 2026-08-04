# The mail surface

Normative spec for Phase 13. Status lives in
[`refactor-plan.md`](refactor-plan.md); execution briefs live in
[`mail-surface/`](mail-surface/).

---

## The problem

Phase 11 gave every message its own template and a layout to live in. It put all
of that on one screen:

| Section | Fields |
| --- | --- |
| Mẫu mặc định | 2 |
| Mã xác thực | 8 |
| Cảnh báo quản trị | 6 |
| Giao diện email HTML | 4 |
| **Total** | **20**, six of them 8-row textareas |

That is roughly the wall Phase 10 was created to remove from the delivery tab,
rebuilt one phase later on a different screen. Six messages stacked in one
column, each a subject and a body, with no way to see which are customised and
which are inheriting without reading all twelve boxes.

And the layout, having somewhere to be, still has nothing worth putting there.
`templates/mail/layout.php` has no preheader, no button, and no treatment for the
one thing an OTP email exists to show:

```
Mã xác nhận: {{code}}
```

Six digits, rendered as running text in a paragraph.

## What this phase decides

### D1 — A list, and one message open at a time

The messages become a table: name, when it fires, and whether it is customised
or inheriting. Selecting one opens its editor beside the list.

**Show/hide by JavaScript is correct here**, which reads as a contradiction of
10.6 and is not. There, the panels belonged to *different tabs*, and
`Settings::sanitize()` writes only the fields carried by the tab named in the
POST — hiding them would have dissolved the boundary that stops one screen
saving another's fields. Here all twenty fields belong to one tab and one save,
so hiding a panel changes nothing about what posts.

The distinction is the save boundary, not the technique.

### D2 — The list is generated, and its useful column is inheritance

Built from `MailRegistry`, like the fields themselves. A message added by the
filter appears in the list without anyone editing a second place.

The column that earns its width is not the subject — it is whether this message
has an override or is inheriting. That is the question an administrator actually
has when they open this screen, and today it can only be answered by reading
every box.

### D3 — Copy-to-edit must be paired with revert-to-inherit

Each message ships with a default. 11.4 shows it as a placeholder, so an empty
box reads as "inheriting this". The obvious addition is a button that loads the
default into the box so it can be edited from — and on its own that button
**undoes 11.4**: a filled box is a box that has stopped inheriting, and the first
administrator to press it on all six has six copies to maintain for ever.

So it ships as a pair, and the second half is not optional:

- **Chép mẫu để sửa** — fills the box with the resolved text
- **Xoá, dùng lại mẫu chung** — empties it and returns to inheritance

### D4 — The layout gains structure through tokens, not settings

`{{code_block}}` renders the code as what it is: large, spaced, selectable, in a
bordered block. `{{button:url|label}}` renders a bulletproof table-based CTA.

Tokens rather than settings because they are **content decisions, not appearance
decisions** — whether this message shows a code prominently is a property of the
message, and the message is what the administrator edits. A setting would apply
to all six and be wrong for at least two.

Both are opt-in and backwards compatible: a body that uses neither renders
exactly as it does today, which is what keeps this from being a migration.

### D5 — The preheader is a registry field, not a setting

The preheader is the grey line an inbox shows after the subject. There is none,
so Gmail shows whatever the body starts with — *"Xin chào,"* on every message
this plugin sends.

It goes on the registry row, with a sensible default per message, and gets **no
admin field** in this phase. One shared setting would be wrong for six different
messages, and six new fields would add to the wall this phase exists to remove.
Reversible: it is one row key away from being editable.

### D6 — Dark mode is attempted and its limits are stated

`color-scheme` plus a `prefers-color-scheme` block, and colours chosen so the
light rendering survives a client that inverts it anyway. Gmail's web client
ignores both and applies its own inversion; that is not fixable from here, and
the layout comments say so rather than implying coverage it does not have.

## Not in this phase

**A visual builder.** Declined in Phase 11 and declined again. The body stays a
textarea with a token list beside it.

**Per-message layouts, fonts, widths, or a colour palette.** The chosen scope is
a better default and two tokens — the alternative was eight more appearance
fields, and most of their combinations look worse than the default.

**A send-this-message-to-me test.** Worth having, still a follow-up, and now
cheaper because the list gives it somewhere obvious to live.
