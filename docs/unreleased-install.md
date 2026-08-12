# The unreleased install

Normative spec for Phase 15. Status lives in
[`refactor-plan.md`](refactor-plan.md); execution briefs live in
[`unreleased-install/`](unreleased-install/).

---

## The decision

**This plugin has never run in production, so it upgrades from nothing.**

`refactor-plan.md` has said that since Phase 0 — *"The project has never run in
production, so there is no data migration burden"* — and then eleven phases wrote
migration code anyway, each one for the handful of development installs that existed
at the time. The result is a body of code whose only job is to carry a past that no
site outside this machine has ever had.

Phase 15 makes the sentence true. Every path that exists to upgrade an older install
is deleted, the database is wiped, and the version resets to `1`. From here a
1.0.x install is **reinstallable, not upgradable**, and that is a stated rule rather
than an accident.

## What goes, and what it serves

| Surface | Serves |
| --- | --- |
| `Installer::migrate_settings_shape()`, `legacy_key_map()` | flat settings arrays from before 1.0.1 — its own docblock says it is not for anybody's production site |
| `Installer::recreate_renamed_tables()` | installs at `db_version < 4` |
| `Installer::drop_legacy_tables()` and the `external_identities` line in `uninstall.php` | a table deleted in Phase 2 |
| `Settings::LEGACY_SECRETS`, `legacy_secret()`, `forget_legacy_secret()` | secrets stored before 10.2 moved them |
| `Installer::backfill_provider_emails()` and its cursor | accounts created before 14.4 — written yesterday, obsolete today |
| `templates/form-register.php`, `templates/form-login.php` | nothing; the README documents them as unused and one has zero references |
| `WebhookTester`'s acceptance of a `channel` field | an admin JS build that no longer ships |

Roughly 400 lines of shipped code, every line of which can be pointed at.

## What does **not** go, and why the distinction matters

The architecture stays. So do all ten test suites.

The four defects found while finishing Phase 14 — a missing `deleted_user` hook, two
vacuous gate assertions, a migration cursor that stranded itself, a version literal
pinned in an unrelated gate — were **missing wiring, not wrong structure**. Every one
of them was caught by the identity model and the suites that surround it. Deleting
those to start again would throw away the thing that found them, in the name of
avoiding defects.

`Installer::maybe_upgrade()` **stays**, emptied of its migrations. A version-gated
upgrade hook is how the next schema change will be delivered; what goes is the
contents, not the mechanism.

## The rule this phase establishes

> Migration code is written when there is something to migrate, and not before.

The eleven-phase habit was to write the upgrade path at the same time as the change,
which felt careful and was not: every one of those paths ran on this machine and
nowhere else, was never exercised by a fresh install, and 14.5's cursor defect shows
what untested migration code is actually worth.

## The thing this phase is really for

**Nothing has ever tested a fresh install.** Every gate runs against a site that was
already installed by hand, months ago. `activate()` → tables → defaults → first use →
`uninstall.php` has never executed end to end in one run.

That gap is worth more than the deletions. A wiped database is the only way to close
it, and the assertion it enables is the strongest kind available here: after
uninstall, **no option, no table and no user meta carrying this plugin's prefixes may
survive** — not a list of names to keep in step with the code, but a query that fails
whenever anything is forgotten.

It already would have caught something: 14.5 added
`OMNIWP_email_backfill_cursor` and did not add it to `uninstall.php`.

## Ownership boundary

| Concern | Owner |
| --- | --- |
| Creating schema | `Installer::install_tables()`, unchanged |
| Deciding an upgrade is needed | `Installer::maybe_upgrade()`, kept and emptied |
| Removing every trace | `uninstall.php`, and a gate that proves it |
| Which settings exist | `FieldRegistry`, unchanged |
| Whether an old install can upgrade | nobody — it cannot, by decision |

## Not in this phase

**A module-by-module simplification sweep.** Tempting, and the wrong shape: each
change would be a fresh chance to introduce the defects this phase exists to avoid,
and none of them can be pointed at with a number the way the table above can. If
duplication is found later it gets its own phase and its own evidence.

**Rewriting anything.** See above.

**phpcs to zero.** 19 errors and 21 warnings remain against a documented baseline;
18 are auto-fixable. Worth doing, and it is a formatting pass, not this phase's
subject.
