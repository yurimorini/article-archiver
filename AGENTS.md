# Agent instructions

You are an expert AI coding assistant working in this repository. Your goal is to make small, safe, clean, and reviewable changes that strictly follow existing project patterns.

Write exclusively in English.

## Core Workflow Rules
- **Follow existing patterns:** Inspect adjacent code and match the established style, naming conventions, and architecture before writing new code.
- **Scope of changes:** Keep pull requests and diffs small. Focus on one feature or bugfix at a time.
- **Dependencies:** Do not add new third-party packages or dependencies without explicit justification and approval.
- **Verification:** Always run relevant tests and linters locally to ensure nothing is broken before finishing a task.

## Design

- Apply SOLID principles.
- Prefer simplicity over compactness.
- Prefer readable code and an clean architecture.

## Where to read

- Project rules: `.agents/rules/`
- Skills: `.agents/skills/<name>/SKILL.md`
- Feature layout: read `.agents/rules/feature-framework.md` before organizing a new feature

Spec and plan locations in that file override the Superpowers defaults (`docs/superpowers/specs/`, `docs/superpowers/plans/`). Do not create those directories.

Specs and plans go in the `doc/` of the package whose files the task changes.

- Library work, including the unfinished pipeline: `packages/article-reader/doc/features/<feature>/specs/` and `packages/article-reader/doc/features/<feature>/plans/`. The pipeline feature folder stays `features/foundation/`.
- App work, once `packages/web-app` has a `doc/` tree: the same `doc/features/<feature>/specs/` and `.../plans/` layout under `packages/web-app/doc/`.

The package is the one that owns the files being edited. A pipeline task writes under `article-reader`. An app task writes under `web-app`.

Use `/use-superpower` and `/caveman` skills

## Product

What we develop is not an MVP. Build the real structure. A feature may start incomplete and gain phases later; do not cut the design down to a throwaway slice.

## Tooling

The project uses Docker. `bin/` at the git root is the launcher for the packages under `packages/`. Library code lives in `packages/article-reader`. `packages/web-app` is an empty placeholder until the app starts.