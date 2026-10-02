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

Use `/use-superpower` and `/caveman` skills

## Product

What we develop is not an MVP. Build the real structure. A feature may start incomplete and gain phases later; do not cut the design down to a throwaway slice.

## Tooling

The project uses Docker, `bin/` contains utilities for interacting with the container commands.