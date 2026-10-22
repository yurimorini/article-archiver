# Issue tracker: feature tickets folder

Tickets live in the feature that owns the work. Functional specs, designs, and plans stay in that same feature folder.

`packages/<package>/doc/features/<feature>/tickets/<NN>-<slug>.md`

The package is the one whose files the work changes. `<feature>` is the feature directory name. `.agents/rules/feature-framework.md` is that layout. There is no GitHub or GitLab issue tracker, and no `.scratch/` tree.

## Layout

- `tickets/<NN>-<slug>.md` — one ticket from `to-tickets`. `NN` is two digits. The first ticket in a feature is `01`. The next ticket is one more than the highest number already present in that feature's `tickets/` folder. A sub-feature has its own `tickets/` folder and its own sequence. Within one breakdown, assign numbers in dependency order, blockers first. Do not reuse a number.
- `feature.md`, `specs/`, `design/`, and `plans/` stay beside `tickets/`. `feature.md` links the current ticket.

## When a skill says "publish to the issue tracker"

Create the ticket under that feature's `tickets/` folder. `to-spec` writes `specs/<name>.md` in the same feature. `to-tickets` writes one file per ticket.

A ticket names the feature it belongs to, as a link to that feature's `feature.md`.

## When a skill says "fetch the relevant ticket"

Read the file at the path the user gives. A bare number means the `tickets/<NN>-*.md` file of the feature under discussion.

## Status, labels, and comments

Triage state is a `Status:` line near the top of a ticket. The strings are in `.agents/rules/triage-labels.md`. A ticket also carries one category, `bug` or `enhancement`, on a `Type:` line.

Comments append at the bottom under a `## Comments` heading. A triage comment starts with the triage disclaimer.

Pull requests are not a request surface.
