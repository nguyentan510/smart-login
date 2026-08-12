# The rendered surface

Normative spec for Phase 18. Status lives in
[`refactor-plan.md`](refactor-plan.md); the execution briefs live in
[`rendered-surface/`](rendered-surface/), one per sub-phase.

Three phases in a row have written an acceptance item that says *measured* and
then not measured it. This one is about the missing tool, and about the two
defects that turned up the moment a probe went looking.

---

## Findings

### 1. Three phases have written acceptance they could not run

| Phase | Acceptance item | What happened |
| --- | --- | --- |
| 8.4 | "Desktop and 480px", "keyboard-only pass" | claimed, not recorded |
| 16.3 | 480px pass, keyboard-only pass | **written down as not run** |
| 17.3 | keyboard pass, `<details>` with JavaScript off | not run |
| 17.4 | `tests/integration/` | gate reports `BLOCKED` |

16.3 and 17.4 at least said so. That is the honest half of a dishonest
situation: the project keeps promising a measurement it has no way to take.

**And when somebody does look, it pays.** Phase 17 needed a throwaway renderer
in a scratch directory to see the card at all, and that renderer found two
defects in one afternoon that no suite could have found:

- `.screen-reader-text` was a theme dependency, so on a theme without it the
  profile card read `Họ tên * (bắt buộc)` — shipped since 8.4.
- The input/button height reading taken from the source was wrong in magnitude
  *and direction*: 45 against 47 and shorter, not ~50 and taller.

The tool was deleted with the session. That is the actual finding.

### 2. Two controls in the account card have no accessible name

Rendered and inspected as a DOM rather than as a string, everything resolves —
no duplicate ids, no `aria-controls` pointing at nothing, every `for` finding
its field — except two:

```text
input[type=text] class="sl-input" id=""     (× 2)
```

Both are the OTP code box in `partials/account/contact.php:178-186`, once per
contact panel. It carries `placeholder="Mã OTP"` and nothing else: no `id`, no
`<label>`, no `aria-label`. A placeholder is not a name — it is a hint that
disappears the moment somebody types, and it is the last thing in the accessible
name computation for exactly that reason.

Every other control passes, including the ones that look most likely to fail:
the password eye has an `aria-label`, and the gender radios are named by the
`<label>` wrapped around them.

### 3. The row actions are below the minimum target size

`.sl-action` declares `min-height: 32px` and **no `min-width`**
(`omniwp.css`). Measured in a browser:

| Control | Size |
| --- | --- |
| Đổi / Thêm | **20 × 32** |
| Liên kết | 45 × 32 |
| Bỏ liên kết | 60 × 32 |

WCAG 2.2 AA (2.5.8) puts the floor at 24 × 24. "Đổi" is two short Vietnamese
characters and lands under it — on the row a member touches most often, on the
device most of them are using.

17.3 introduced `.sl-action` and gave it a height floor without a width one.
Nothing in the suite could see the difference, which is finding 1 again.

---

## The decisions

### Decision 1 — The renderer is a committed tool, not a scratch file

`tests/visual/render.php` renders any named surface with the real templates, the
real stubs and the real stylesheet.

**Self-contained output.** The stylesheet is inlined rather than linked, so a
rendered file opens from anywhere — a temp directory, an email, a bug report —
without the CSS resolving beside it. That is what makes it useful for the thing
it exists for: showing somebody what the screen looks like.

**In `tests/`, which does not ship.** The plugin zip is unaffected.

**No new toolchain.** No `package.json`, no headless browser, no change to
`.github/workflows/php-quality.yml`. The renderer produces the page; the
measurements that need a browser are taken by opening it, and their numbers are
recorded in the brief. That is worse than an automated assertion and enormously
better than "not run" — which is the standing alternative, three phases deep.

**What is automatable is automated.** A rendered surface is a DOM, and PHP has
one. Accessible names, IDREF integrity, duplicate ids, the presence of a real
`<form>` behind a destructive control — all of that is checkable with no browser
at all, and it is what found defect 2 above.

### Decision 2 — The surface list is the fixture mechanism, again

Every `templates/partials/account/*.php` must be a named surface the renderer
knows how to build. A new partial fails the rule the moment it lands and passes
once it has arguments — the same mechanism 8.2 built into the template suite,
and the one that caught `card-head` in 17.8 before anything else did.

### Decision 3 — The OTP box gets a label, not an `aria-label`

A visible `<label>`, the same as every other field on the card. The panel it
sits in is already headed "Mã OTP" only by a placeholder, so a sighted user
loses the hint as soon as they start typing too.

`aria-label` would name it for a screen reader and leave the sighted case
unfixed, which is treating an accessibility finding as an accessibility-only
problem.

### Decision 4 — 24px is a floor on the class, not on the instance

`.sl-action` gains `min-width`, so a short label cannot produce a short target.
The alternative — padding the label — makes the number depend on the word, and
"Đổi" is the shortest word the card has today, not the shortest it can have.

The floor is 24px, which is WCAG 2.2 AA. Not 44: these are text controls in a
dense list of rows, and a 44px floor would space the rows out by more than the
control gains.

### Decision 5 — The measurements are recorded as numbers, not as ticks

A brief that says "480px pass ✓" is a claim. A brief that says

```text
375px  document.scrollWidth 375, no element right edge past 375
```

is a measurement. Phase 18's own acceptance is written the second way, and
16.3's and 17.3's outstanding items are closed the same way or not closed.

---

## Ownership boundary

`tests/visual/render.php` owns building a page out of the real templates. It
does **not** own the fixtures: those come from the same shapes
`run-template-tests.php` already declares, so a fixture cannot drift between the
smoke test and the picture.

## Deferrals, written where they are decided

- **No headless browser.** Chosen deliberately over Playwright: it would put a
  second toolchain and a `package.json` into a repo that has stayed PHP-only,
  and CI would have to carry it. Revisit when a measurement has to gate a merge
  rather than inform a decision.
- **`tests/integration/` stays blocked in this environment.** The renderer does
  not substitute for a real WordPress; it renders templates against stubs. 17.4's
  meta writes are still unverified against a live database and that stays
  written down rather than quietly closed.
- **The sign-in screens are not re-measured here.** Phase 16 measured what it
  changed. This phase gives the tool; pointing it at those screens is the next
  reader's call, not a scope this one claims.

## Not in scope

No production behaviour changes beyond the two defects above: one label, one
CSS floor. Everything else in the phase is a tool and the numbers it produced.
