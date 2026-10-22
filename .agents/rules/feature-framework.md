# Feature framework

Feature work for a package lives under `packages/<package>/doc/`. The package is the one that owns the files being edited. `GLOSSARY.md`, `backlog/`, `adr/`, and `flows/` stay beside `features/` when the package already has them. A feature bench does not absorb them.

`grill-with-docs` writes terms in `GLOSSARY.md` and hard-to-reverse decisions in `adr/`. `backlog/` holds work left behind. A backlog note is not a requirement until a feature adopts it. `flows/` describes the code as it stands. `research/` notes are not requirements. A functional spec or a design adopts one by writing the decision in its own file.

## Number

The feature directory name is `NNNN-<slug>`. `NNNN` is four digits. The first feature in a package is `0001`. The next feature is one more than the highest number already present under that package's `doc/features/`. Assign the number when the folder is created. Do not reuse a number. Do not change it after the folder exists.

`<slug>` is a short stable name. `0001-bootstrap` and `0002-server` are the first two features under `packages/web-app/doc/features/`.

A sub-feature is a directory under its parent and keeps a slug only. It does not take a number from this sequence.

A folder that already exists without a number keeps that name.

## Layout

```text
packages/<package>/doc/
  GLOSSARY.md
  backlog/
  adr/
  flows/
  features/NNNN-<slug>/
    feature.md
    research/
    plans/YYYY-MM-DD-<name>.md
    design/<Name>.md
    specs/<name>.md
    tickets/<NN>-<slug>.md
```

- `feature.md` is the index: status, order, and links to the functional spec, the design, and the current ticket. Update it when the split or the current ticket changes.
- `specs/<name>.md` is a functional spec from `to-spec`. It records the behavior a caller can observe. A feature may hold more than one. The name is kebab-case.
- `tickets/<NN>-<slug>.md` is one vertical slice from `to-tickets`. `.agents/rules/issue-tracker.md` is the full rule.
- `design/<Name>.md` is the technical design from `brainstorming` for one ticket. It records the module boundary, types, data flow, and errors. The name is PascalCase. It takes the functional choices in `specs/` as given.
- `plans/YYYY-MM-DD-<name>.md` is the implementation plan from `writing-plans`. It names the files, the signatures, and the tests. A plan may be removed after its ticket is done. The functional spec and the design stay.
- `research/` holds notes and ideas.

`feature.md` links these files. It does not restate them. A ticket records the end-to-end behavior of one slice, its acceptance criteria, and what blocks it. A design adds the module for that ticket. A plan adds the steps that build that design.

This folder is where `to-spec` publishes, in place of `.scratch/<feature>/`. `brainstorming` writes `design/`, in place of `docs/superpowers/specs/`. `writing-plans` writes `plans/`, in place of `docs/superpowers/plans/`.

## Flow

1. Run `grill-with-docs` until the frontier is empty and the user confirms a shared understanding. Done when no decision is still open. That session writes glossary terms and ADRs only.
2. If the feature folder does not exist, create `packages/<package>/doc/features/NNNN-<slug>/` with the next number. Run `to-spec`. Write the functional spec with the template in `.agents/skills/to-spec/SKILL.md`, saved as `specs/<name>.md`. Done when the user accepts that file.
3. Run `to-tickets`. Write one file per ticket under that feature's `tickets/` with the local ticket template in `.agents/skills/to-tickets/SKILL.md`. Done when the user accepts the breakdown. Update the links in `feature.md`.
4. For the current ticket, run `brainstorming`. The functional spec and the ticket are closed. Write the design to `design/<Name>.md`. Done when the user accepts that design.
5. Run `writing-plans` from that design. Save the plan under `plans/`. Done when the user accepts the plan.

Then implement that ticket. The next ticket starts at step 4.

A contrast during `brainstorming or grill-me-with-docs` uses the question in `.agents/rules/project-foundation.md`: name the document, the locked choice, and one alternative, and ask whether to revise it. The document may be the functional spec, a design, or an ADR. Edit it only after the user says yes. Then continue the design.

Write a later change to a finished design into that design. Record why it changed in `feature.md` or in `research/`. A change to behavior goes in the functional spec, after the same question.

## Sub-features

Split a feature into sub-features so implementation stays modular and a later enhancement has its own place. A sub-feature is a directory under the parent feature and uses the same layout, including its own `tickets/`. The parent `feature.md` lists each sub-feature and what it owns.

The full split is often unknown at the start. Add a sub-feature or a ticket when the boundary is real. Change the organization in the parent `feature.md` instead of keeping an early guess.
