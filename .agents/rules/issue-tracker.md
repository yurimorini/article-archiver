# Issue tracker: package issues folder

Tickets live in the package that owns the work. Contracts, specs, and plans stay in the feature folder.

`packages/<package>/doc/issues/<NN>-<slug>.md`

The package is the one whose files the work changes. `.agents/rules/feature-framework.md` is the feature-folder layout, including how `NNNN` is chosen. This file is how the engineering skills find a ticket. There is no GitHub or GitLab issue tracker, and no `.scratch/` tree.

## Layout

- `packages/<package>/doc/issues/<NN>-<slug>.md` — one ticket from `to-tickets`. `NN` is two digits. The first ticket in a package is `01`. The next ticket is one more than the highest number already present under that package's `doc/issues/`. Within one breakdown, assign numbers in dependency order, blockers first. Do not reuse a number. Do not restart the sequence for a new feature or sub-feature.
- `feature.md`, the contract, `specs/`, and `plans/` stay under `packages/<package>/doc/features/NNNN-<slug>/`. `feature.md` links the current ticket.

## When a skill says "publish to the issue tracker"

Create the ticket under `packages/<package>/doc/issues/`. `to-spec` still writes `<slug>.md` in the feature folder. `to-tickets` writes one file per ticket in the package issues folder.

A ticket names the feature it belongs to, as a link to that feature's `feature.md`, so the contract and the spec stay findable.

## When a skill says "fetch the relevant ticket"

Read the file at the path the user gives. A bare number means the `packages/<package>/doc/issues/<NN>-*.md` file for the package under discussion.

## Status, labels, and comments

Triage state is a `Status:` line near the top of a ticket. The strings are in `.agents/rules/triage-labels.md`. A ticket also carries one category, `bug` or `enhancement`, on a `Type:` line.

Comments append at the bottom under a `## Comments` heading. A triage comment starts with the triage disclaimer.

Pull requests are not a request surface.
