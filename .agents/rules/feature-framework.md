# Feature framework

Feature documents live under `packages/<package>/doc/features/NNNN-<slug>/`. The package is the one that owns the files being edited. This folder is where `to-spec` publishes, in place of `.scratch/<feature>/`, and where brainstorming and writing-plans write, in place of `docs/superpowers/specs/` and `docs/superpowers/plans/`. Tickets from `to-tickets` live in `packages/<package>/doc/issues/`. `.agents/rules/issue-tracker.md` is that layout.

A feature whose `feature.md` already holds the goal stays as written until that feature is taken through this flow.

## Number

The directory name is `NNNN-<slug>`. `NNNN` is four digits. The first feature in a package is `0001`. The next feature is one more than the highest number already present under that package's `doc/features/`. Assign the number when the folder is created. Do not reuse a number. Do not change it after the folder exists.

`<slug>` is a short stable name. `0001-bootstrap` and `0002-server` are the first two features under `packages/web-app/doc/features/`.

A sub-feature is a directory under its parent and keeps a slug only. It does not take a number from this sequence.

A folder that already exists without a number keeps that name.

## Layout

```text
packages/<package>/doc/features/NNNN-<slug>/
  feature.md
  <slug>.md
  specs/<Name>.md
  plans/YYYY-MM-DD-<name>.md
  research/

packages/<package>/doc/issues/<NN>-<slug>.md
```

- `feature.md` — index only: status, order, and links to the contract and the current ticket. Update it when the split or the current ticket changes.
- `<slug>.md` — the feature contract from `to-spec`. One file per feature folder. The slug names that contract.
- `packages/<package>/doc/issues/<NN>-<slug>.md` — one ticket from `to-tickets`. Number from `01` across that package. The next ticket is one more than the highest number already present. Within one breakdown, assign numbers in dependency order, blockers first. Do not reuse a number. Do not restart the sequence for a new feature. `.agents/rules/issue-tracker.md` is the full rule.
- `specs/<Name>.md` — the module design for one ticket, from brainstorming.
- `plans/YYYY-MM-DD-<name>.md` — the implementation plan for that ticket, from writing-plans. This is the only layer that names files, signatures, and tests.
- `research/` — notes and ideas. A note there is not a requirement. The contract or a spec adopts it by writing the decision in its own file.

Each layer adds detail the layer above does not contain. `feature.md` links the contract; it does not restate it. A ticket records visible behaviour, acceptance criteria, and what blocks it. A spec adopts that ticket and adds the module boundary, types, data flow, and errors. A plan adopts that spec.

`grill-with-docs` keeps terms in `packages/<package>/doc/GLOSSARY.md` and hard-to-reverse decisions in `packages/<package>/doc/adr/`. The package is the one that owns the terms and the decision. The contract uses those terms and respects those decisions.

Write a change to a finished spec into that spec. Record why it changed in `feature.md` or in `research/`.

## Flow

1. Run `grill-with-docs` until the frontier is empty and the user confirms a shared understanding.
2. If the feature folder does not exist, create `packages/<package>/doc/features/NNNN-<slug>/` with the next number. Run `to-spec`. Write the contract with the template in `.agents/skills/to-spec/SKILL.md`, saved as `<slug>.md` next to `feature.md`. Done when the user accepts that file.
3. Run `to-tickets`. Write one file per ticket under `packages/<package>/doc/issues/` with the local ticket template in `.agents/skills/to-tickets/SKILL.md`. Done when the user accepts the breakdown. Update the index in `feature.md`.
4. For the current ticket, run brainstorming. The decisions in the contract and the ticket are closed. A decision closed in another spec or ADR is not settled for this ticket when following it would make the module incoherent. Ask before adopting it. Name that document, what it locks, and the coherent alternative. Write the module design to `specs/<Name>.md`. Done when the user accepts that spec.
5. Run writing-plans from that spec. Save the plan under `plans/`. Done when the user accepts the plan.

Then implement that ticket. The next ticket starts at step 4.

## Sub-features

Split a feature into sub-features so implementation stays modular and a later enhancement has its own place. A sub-feature is a directory under the parent feature and uses the same layout. Tickets stay in the package `doc/issues/` folder. The parent `feature.md` lists each sub-feature and what it owns.

The full split is often unknown at the start. Add a sub-feature or a ticket when the boundary is real. Change the organization in the parent `feature.md` instead of keeping an early guess.
