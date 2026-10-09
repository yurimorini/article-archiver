# Issue tracker: package feature folder

Issues, contracts, and specs live in the package that owns the work.

`packages/<package>/doc/features/<feature>/`

The package is the one whose files the work changes. `.agents/rules/feature-framework.md` is the layout. This file is how the engineering skills find it. There is no GitHub or GitLab issue tracker, and no `.scratch/` tree.

## Layout

- `feature.md` — index: status, order, links to the contract and the current ticket.
- `<slug>.md` — the feature contract from `to-spec`. One file per feature folder.
- `issues/<NN>-<slug>.md` — one ticket from `to-tickets`, numbered from `01` in dependency order.
- `specs/<Name>.md` — the module design for one ticket.
- `plans/YYYY-MM-DD-<name>.md` — the implementation plan for that ticket.
- `research/` — notes. A note is not a requirement until a contract or a spec adopts it.

## When a skill says "publish to the issue tracker"

Create the file in that feature folder. `to-spec` writes `<slug>.md`. `to-tickets` writes one file per ticket under `issues/`.

## When a skill says "fetch the relevant ticket"

Read the file at the path the user gives. A bare number means the `issues/<NN>-*.md` file in the feature under discussion.

## Status, labels, and comments

Triage state is a `Status:` line near the top of a ticket. The strings are in `.agents/rules/triage-labels.md`. A ticket also carries one category, `bug` or `enhancement`, on a `Type:` line.

Comments append at the bottom under a `## Comments` heading. A triage comment starts with the triage disclaimer.

Pull requests are not a request surface.
