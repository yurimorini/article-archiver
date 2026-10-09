# Feature framework

Feature documents live under `packages/<package>/doc/features/<feature>/`. The package is the one that owns the files being edited. This folder is where `to-spec` and `to-tickets` publish, in place of `.scratch/<feature>/`, and where brainstorming and writing-plans write, in place of `docs/superpowers/specs/` and `docs/superpowers/plans/`.

A feature whose `feature.md` already holds the goal stays as written until that feature is taken through this flow.

## Layout

```text
packages/<package>/doc/features/<feature>/
  feature.md
  <slug>.md
  issues/<NN>-<slug>.md
  specs/<Name>.md
  plans/YYYY-MM-DD-<name>.md
  research/
```

- `feature.md` — index only: status, order, and links to the contract and the current ticket. Update it when the split or the current ticket changes.
- `<slug>.md` — the feature contract from `to-spec`. One file per feature folder. The slug names that contract.
- `issues/<NN>-<slug>.md` — one ticket from `to-tickets`. Number from `01` in dependency order, blockers first.
- `specs/<Name>.md` — the module design for one ticket, from brainstorming.
- `plans/YYYY-MM-DD-<name>.md` — the implementation plan for that ticket, from writing-plans. This is the only layer that names files, signatures, and tests.
- `research/` — notes and ideas. A note there is not a requirement. The contract or a spec adopts it by writing the decision in its own file.

Each layer adds detail the layer above does not contain. `feature.md` links the contract; it does not restate it. A ticket records visible behaviour, acceptance criteria, and what blocks it. A spec adopts that ticket and adds the module boundary, types, data flow, and errors. A plan adopts that spec.

`grill-with-docs` keeps terms in `packages/<package>/doc/GLOSSARY.md` and hard-to-reverse decisions in `packages/<package>/doc/adr/`. The package is the one that owns the terms and the decision. The contract uses those terms and respects those decisions.

Write a change to a finished spec into that spec. Record why it changed in `feature.md` or in `research/`.

## Flow

1. Run `grill-with-docs` until the frontier is empty and the user confirms a shared understanding.
2. Run `to-spec`. Write the contract with the template in `.agents/skills/to-spec/SKILL.md`, saved as `<slug>.md` next to `feature.md`. Done when the user accepts that file.
3. Run `to-tickets`. Write one file per ticket with the local ticket template in `.agents/skills/to-tickets/SKILL.md`. Done when the user accepts the breakdown. Update the index in `feature.md`.
4. For the current ticket, run brainstorming. The decisions in the contract and the ticket are closed. Write the module design to `specs/<Name>.md`. Done when the user accepts that spec.
5. Run writing-plans from that spec. Save the plan under `plans/`. Done when the user accepts the plan.

Then implement that ticket. The next ticket starts at step 4.

## Sub-features

Split a feature into sub-features so implementation stays modular and a later enhancement has its own place. A sub-feature is a directory under the parent feature and uses the same layout. The parent `feature.md` lists each sub-feature and what it owns.

The full split is often unknown at the start. Add a sub-feature or a ticket when the boundary is real. Change the organization in the parent `feature.md` instead of keeping an early guess.
