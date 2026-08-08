# Looking at the screen

```bash
php tests/visual/render.php account
```

Writes `build/visual/account.html` and prints the path. Open it. The stylesheet
is inlined, so the file works from anywhere — copy it, attach it to a bug report,
send it to a phone.

```bash
php tests/visual/render.php --all          # every surface and composite
php tests/visual/render.php contact        # one card
php tests/visual/render.php account --stdout > /tmp/card.html
```

`build/` is git-ignored.

---

## What this is not

It renders **templates against stubs**. It is not a WordPress page, and a
correct-looking picture says nothing about what a live database holds.
`tests/integration/` is still the only thing that speaks to a real WordPress,
and as of Phase 18 it still reports `BLOCKED` in this environment.

The fixtures come from `tests/template-fixtures.php`, which the template smoke
suite also reads. That is deliberate: a picture built from a second set of
arguments would be a picture of a screen no suite has ever executed.

---

## The measurements

These need a browser, and this project has decided against carrying a headless
one — the argument is in `docs/rendered-surface.md`. So they are taken by hand,
and **recorded as numbers in the sub-phase's Outcome, never as a tick**. A brief
that says "480px pass ✓" is a claim; the readings below are measurements.

Serve the directory and open the page:

```bash
php -S 127.0.0.1:8899 -t build/visual
```

### 1. Nothing overflows the viewport

At 375, 480 and 1400 CSS pixels wide, in the console:

```js
({
  viewport: innerWidth,
  scrollWidth: document.documentElement.scrollWidth,
  overflowing: [...document.querySelectorAll('.smart-login *')]
    .filter(el => el.getBoundingClientRect().right > innerWidth + 0.5)
    .map(el => el.className)
})
```

Expected: `scrollWidth === viewport`, `overflowing` empty. Record all three
widths, including the ones that pass.

### 2. The input and the button beside it are the same height

The contact editor renders closed, because that is how the card arrives. Open it
first — the rendered page carries **no plugin JavaScript**, so the `hidden`
attribute has to come off by hand rather than by pressing "Đổi":

```js
document.querySelectorAll('[data-sl-contact-edit], [data-sl-contact-confirm]')
  .forEach(el => el.hidden = false);

[...document.querySelectorAll('.sl-contact-row')].map(r => ({
  input: r.querySelector('.sl-input')?.getBoundingClientRect().height,
  button: r.querySelector('.sl-btn')?.getBoundingClientRect().height
}))
```

A closed editor measures `0 × 0`, and a reading of zero that looks like a pass is
exactly the shape of mistake this file exists to prevent.

Expected: equal, by construction — `.sl-input` and `.sl-btn` declare the same
padding and the same `line-height` since 17.2. They were 47 and 45 before that,
and reading the source predicted 47 and 50, so this one is measured rather than
argued.

### 3. Every row action clears the target floor

```js
[...document.querySelectorAll('.sl-action')].map(a => {
  const r = a.getBoundingClientRect();
  return a.textContent.trim() + ': ' + Math.round(r.width) + '×' + Math.round(r.height);
})
```

Expected: every one at least 24 × 24 — WCAG 2.2 AA (2.5.8). "Đổi" measured
20 × 32 before 18.3.

### 4. Keyboard only

Tab from the top of the card to the bottom without touching the mouse. Record:

- the order controls receive focus, and whether it matches reading order
- whether the focus ring is visible on **every** control, including the
  `<summary>` elements and the row actions
- whether both `<details>` open on Enter and close again
- whether the contact editor's fields are reachable once "Đổi" has opened it

### 5. JavaScript off

**The rendered page has none to begin with** — the renderer emits markup and the
stylesheet, and `assets/js/smart-login.js` is never enqueued. So this page is
permanently in the state measurement 5 is about, which makes it the one reading
the tool gives away for free.

Record:

- "Bỏ liên kết" still opens — it is a `<details>`, not a listener
- the password disclosure still opens
- the unlink form still submits (the fields are asserted by the suite; this is
  the browser half)
- what the contact editor does, and whether what it does is honest about needing
  JavaScript

---

## Adding a surface

Add it to `$sl_surfaces` in `render.php`. Every
`templates/partials/account/*.php` must appear there — the rendered-surface
suite fails when one does not, which is the same mechanism that makes "extend
the smoke test" automatic rather than remembered.

---

## The dialog (19.7)

```bash
php tests/visual/render.php dialog
```

Renders the shell around the identify step, with **both** stylesheets inlined
and one line of JavaScript calling `showModal()`. That line is load-bearing, not
decoration: a `<dialog>` that has not been opened modally is `display:none`, and
one opened with the `open` attribute renders with no backdrop and no top layer —
so a picture taken that way is a picture of a different element.

### Readings taken 2026-08-07

| Viewport | Horizontal overflow | Dialog | Panel | Close control |
| --- | --- | --- | --- | --- |
| 375 × 812 | none | 375 × 351 | 375 × 351 | **32 × 32** |
| 480 × 900 | none | 480 × 351 | 480 × 351 | **32 × 32** |
| 1400 × 900 | none | 448 × 351, centred | 448 × 351 | **32 × 32** |

- **Target size.** 32 × 32 against WCAG 2.2 AA's 24 × 24 floor, at every width.
  18.3 had to widen `.sl-action` from 20 × 32 after the fact; this control was
  drawn above the floor and measured to confirm it, rather than measured after a
  complaint.
- **Input and submit both 47px**, which matches 18.4's reading for the same
  controls on a page. The dialog does not change the form's geometry.
- **Backdrop** resolves to `rgba(15, 23, 42, 0.55)` — read off
  `getComputedStyle(dialog, '::backdrop')`, not off the source.
- **Tall content.** With 2000px of filler appended, the panel scrolls
  (`scrollHeight > clientHeight`, height capped at 868 on a 900px viewport) and
  the document behind it does **not** grow. That is the property
  `overscroll-behavior: contain` plus the panel's own `max-height` exist for.
- **Keyboard.** Four focusable controls inside the dialog, in this order:
  close → identifier input → submit → terms link. Reading order, with the close
  control first as a modal conventionally has it.
- **Accessible name** resolves to `Đăng nhập hoặc đăng ký` through
  `aria-labelledby="sl-dialog-title"`. A modal without one announces itself as
  "dialog" and nothing else.

### What the first run found

The surface rendered **unstyled**: close control 24 × 21, backdrop
`rgba(0,0,0,0.1)` — the browser default. Nothing was wrong with the CSS. The
tool was inlining only `smart-login.css`, and the dialog ships its own
stylesheet because the two-stage asset load requires the shell to be styled
before the fragment arrives.

Worth recording because of how it read: a missing stylesheet in the *tool*
looked exactly like a control below the target-size floor in the *product*.
Phase 18 exists because measurements were claimed and not taken; this is the
adjacent failure — a measurement taken against the wrong thing.

### The proportions (19.11)

Declared as custom properties on `.sl-dialog` and then measured, because 18.3
recorded what reading pixels off a picture costs — the number taken from the
source was wrong in magnitude *and* direction.

| Token | Value | Measured as |
| --- | --- | --- |
| `--sl-dlg-pad` | 28px (20px under 480) | panel 480 wide, content 424 |
| `--sl-dlg-gap` | 20px | lead→benefits 20, benefits→form 20 |
| `--sl-dlg-radius` | 16px | panel corner |
| `--sl-dlg-control` | 52px | input **52**, submit **52** |

Type scale, read off `getComputedStyle`:

| Element | Size | Weight | Align |
| --- | --- | --- | --- |
| dialog title | 22px | 700 | centre |
| lead | 15px / 1.55 | 400 | centre, **2 lines** |
| input & submit | 16px | 400 / 600 | — |
| benefit caption | 13px / 1.35 | 400 | centre |
| divider, terms | 14px / 13px | 400 | centre |

Spacing down the dialog at 720 × 860, with three benefits filled:

```text
title  →  lead          16
lead   →  benefits      20
benefits → form         20
input  →  submit        16
panel                   480 × 469
benefit marks           44 × 44, 44 × 44, 44 × 44   (one baseline)
close                   32 × 32
no horizontal overflow at 375, 480 or 1400
```

**The lead was three lines before it was measured.** At `max-width: 30ch` the
sentence broke to three inside a 480px panel and stopped reading as a subtitle;
26rem wraps it to two and keeps a ragged right edge. That is the whole argument
for taking the reading rather than declaring the value and moving on.

**The mobile sheet was removed, not restyled.** A `@media (max-width: 480px)`
block used to make the panel a bottom-anchored sheet, and the geometry block
below it set `max-width` on the same selector later in the same file — so the
two were fighting and the card was already winning. What shipped was a
bottom-margined panel with square bottom corners that was not full-bleed: a
shape nobody designed. Measured at 375 the card is 343 wide with 16px either
side, and the panel scrolls inside its own `max-height`, which is the answer to
the keyboard argument the sheet was written for.
