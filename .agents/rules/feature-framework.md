# Feature framework

Feature documents live under `doc/features/`. This layout overrides the Superpowers defaults. Do not create `docs/superpowers/specs/` or `docs/superpowers/plans/`.

## New feature

Create `doc/features/<name>/`. The name is short and identifying.

Each feature folder contains:

- `feature.md` — context for the feature as a whole, and the record of how it is organized. Update it when the split or the phases change.
- `specs/` — Superpowers design specs and any other spec for this feature.
- `plans/` — Superpowers implementation plans and any other plan for this feature.
- `research/` — notes and ideas. A note there is not a requirement. A spec or `feature.md` adopts a decision by writing it in its own file.

Write a change to a finished spec into that spec. Record why it changed in `feature.md` or in `research/`.

## Sub-features

Split a feature into sub-features so implementation stays modular and a later enhancement has its own place. A sub-feature is a directory under the parent feature and uses the same layout: `feature.md`, `specs/`, `plans/`, `research/`. The parent `feature.md` lists each sub-feature and what it owns.

The full split is often unknown at the start. Add a sub-feature or a phase when the boundary is real. Change the organization in the parent `feature.md` instead of keeping an early guess.

## Plans

Every plan file is named `YYYY-MM-DD-<name>.md`. The directory is the feature's `plans/` folder, not `docs/superpowers/plans/`.
