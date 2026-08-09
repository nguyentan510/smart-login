# Working agreement

How work is done in this repository. This file is the rule; the docs are its
output.

---

## The cycle: Research → Plan → Implement → Verify

Every unit of work runs all four stages **in order**, and each stage has a
deliverable. A stage with no artefact did not happen.

### 1. Research

Establish what is *actually* true before proposing anything.

- Read the code. Cite `file.php:line` for every claim about behaviour.
- A claim that cannot be pinned to a line, a test run, or a request/response is a
  hypothesis — label it as one.
- **Documentation is not evidence.** Twice now this project has found the README
  asserting a control that does not exist. Check the code path, not the sentence
  describing it.
- Prefer running the thing to reasoning about it. `php tests/run-all.php` is
  cheap; a confident wrong reading is not.

**Deliverable:** findings with citations, including the ones that contradict the
original premise.

### 2. Plan

Three files, three jobs, no overlap. This is the layout Phase 8 established and
Phase 9 follows:

| File | Holds | Never holds |
| --- | --- | --- |
| `docs/<phase>.md` | the **decisions** — the problem, the trade-offs, ownership, defaults | status, steps |
| `docs/<phase>/N.M-*.md` | one **brief** per sub-phase — Goal, Files, Steps, Acceptance, Not in scope | status, decisions restated |
| `docs/refactor-plan.md` | **status**, ordering rationale, risks | design detail |

**Status lives in the tracker and nowhere else.** A sub-phase marked done in one
file and open in another is a state this repo must not be able to reach — which
is the same argument the tracker itself opens with.

A brief that restates the spec is a second source of truth. Link instead.

**Deliverable:** spec + briefs + tracker rows, with acceptance written **before**
implementation. Acceptance decided afterwards is a description, not a test.

### 3. Implement

- **One commit per sub-phase.** The diff should be reviewable as "one rule turns
  green".
- **Guard rails first, landed red.** A rule written after the fix cannot fail, and
  a rule that has never failed is a comment. Record the red output in the commit
  message — it is the evidence the detector works.
- Order commits so no deploy breaks in-flight clients (ship the JS that sends a
  field before the server that requires it).
- Match the surrounding code: WPCS, LF endings, `sl_`-prefixed test helpers,
  Vietnamese UI strings, English code comments and docs.

**Deliverable:** the commit, plus the red-then-green evidence.

### 4. Verify

Measured, not reasoned about.

- Run `php tests/run-all.php`. Required suites must pass; `spec` suites are
  reported but non-blocking, and are promoted to `required` the moment they go
  green.
- Anything involving a real WordPress — schema, headers, hooks, template
  rendering — goes through `tests/integration/`, not through reading the code.
  Four gates once missed a fatal that only a real WordPress could show.
- **Two runs are not a comparison.** If a result alternates, isolate it before
  concluding anything.
- Write an `## Outcome` section into the brief afterwards: what actually
  happened, what the plan got wrong, and what was found along the way. The
  outcome is part of the deliverable, not a courtesy.

**Deliverable:** test output pasted, not summarised — plus the `## Outcome`
section.

---

## Rules that outrank convenience

- **Never mark something done that is not verified.** If a step was skipped, say
  which and why.
- **A rename crosses every boundary no test covers.** This project has been bitten
  five times: `purpose`/`intent`, `$default`/`$fallback`, the provider gate's
  legacy settings keys, and `Installer::cleanup()`'s flat retention keys. When
  renaming, grep for the old name across `includes/`, `templates/`, `tests/` and
  `docs/` before claiming completion.
- **Prefer making a bug class unrepresentable over fixing one instance.** The
  `FieldRegistry` rewrite is the model: one array decides the default, the type,
  the tab and the control, so the four-way drift cannot recur.
- **Deferrals are written down where they are configured**, with the reason. A
  silent exception is a lie with a longer half-life.

## Commands

```bash
php tests/run-all.php
```

On Windows, use the wrapper instead — there is often no `php` on PATH, and the
binary nearest to hand is Local's, which loads no `php.ini` and turns five suites
red on the environment rather than on the code:

```bash
powershell -File scripts/run-tests.ps1
```

It picks an interpreter by probing for `openssl` and `mbstring` and **blocks**
when none has them, rather than producing a red count with environment noise in
it. `-Strict` forwards `--strict`; `-Suite <file>` runs one suite.

`--strict` refuses to tolerate a `spec` suite.

Coding standards are **at zero** for every enabled sniff, and the gate keeps
them there. The baseline lives in `tests/run-phpcs.php` — not in this file, and
not in the tracker — and it fails in both directions: when a change adds a
violation, and when the real count drops below the number without the number
being lowered. That second direction is what took it to zero, and it is why
"compare against the documented baseline" is no longer something a person has
to remember.

Still deferred, and written down in `phpcs.xml`: the **documentation** sniffs
(`Missing`, `MissingParamTag`, …) are excluded. That is a different statement
from "the standard is red" — everything switched on passes.
