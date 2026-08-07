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
