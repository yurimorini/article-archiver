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
- Feature layout and the grill → contract → tickets → spec → plan flow: read `.agents/rules/feature-framework.md` before organizing a feature or writing a contract, ticket, spec, or plan.


## Product

What we develop is not an MVP. Build the real structure. A feature may start incomplete and gain phases later; do not cut the design down to a throwaway slice.

## Tooling

The project uses Docker. `bin/` at the git root is the launcher for the packages under `packages/`. Library code lives in `packages/article-reader`. The Eleanor application lives in `packages/web-app` (Composer name `yumo/eleanor`). Its PHP namespace is unset until the first class.

## Agent skills

### Issue tracker

Feature work lives in the owning package's feature folder. See `.agents/rules/issue-tracker.md`.

### Triage labels

Default role names, stored as a `Status:` line on each ticket. See `.agents/rules/triage-labels.md`.

### Domain docs

Multi-context: one glossary and one ADR directory per package. See `.agents/rules/domain.md`.