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
