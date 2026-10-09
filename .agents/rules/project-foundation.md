---
description: Core coding standards — language, architecture, conventions, agent behavior
alwaysApply: true
---

# Project foundation

## Language

- **Documentation** (README, ADRs, planning notes, comments meant as docs): write in **English**.
- Prefer English for **identifiers and commit messages** unless the codebase already standardizes otherwise.

## Philosophy

- Prefer simple over complex
- Prefer readable over compact

## Design priorities

- Favor **simplicity and maintainability** over cleverness.
- Prefer **small modules with a clear, bounded scope** over large grab-bags.
- Apply **single responsibility**: modules, classes, and functions should each have one job; avoid overlapping responsibilities across units.
- Keep **separation of concerns** strict where it helps clarity:
  - **Domain** — entities, invariants, domain rules (minimal framework coupling).
  - **Application / business logic** — use cases, orchestration, policies that sit between domain and delivery.
  - **UI / adapters** — presentation, routing, framework-specific wiring; no domain rules hidden in views.

## Conventions and patterns

- Follow **clear, project-visible conventions** (naming, folder layout, error handling). When none exist, align with the **dominant style already in the repo** and common patterns for the stack.
- Prefer **structural and implementation consistency** within a feature area over one-off shortcuts.

## Coding principles 

- Apply SOLID principles
- Separate business logic from presentation and I/O
- Prefer composition over inheritance
- Use dependency injection and inversion of control
- Declare interfaces for contracts and types for data
- Keep functions short and standalone; single responsibility

## Working with the agent

- When requirements, scope, or trade-offs are **ambiguous**, **ask** rather than guess. Offer sensible defaults only after stating assumptions.
- **Use tools** to inspect the codebase, configuration, and tests before changing behavior; do not rely on memory alone.
- When behavior or public surfaces change, **update the relevant documentation** in the same change when it exists (README, inline docs users rely on).

Before designing or building a feature, pick one interview and name it:

- Terms are fuzzy, or a hard-to-reverse decision is still open: run `grill-with-docs` (`grilling` + `domain-modeling`). Stop when the frontier is empty and the user confirms a shared understanding. Do not write a spec in that session. 

- The decisions are settled enough to design: run `brainstorming`. Classify spike, bounded, or architectural, ask one question at a time, and stop at that path's approval gate.

- Both apply: grill first, then brainstorm from the shared understanding.

Do not start `brainstorming` while a grill frontier is still open. When grilling, ask one question at a time.

## Documentation and Style

- Use JSDoc to document public classes and methods
- Use English for all code and documentation
- Add comments only where intent is non-obvious; avoid narrating what the code already says
- Use standard formatting (Prettier / project formatter)


## Review Checklist

When reviewing or writing code, verify each point:

- [ ] No unnecessary complexity; simplest solution that works
- [ ] Code reads clearly without needing mental compilation
- [ ] Business logic is separated from presentation and I/O
- [ ] No inheritance where composition would suffice
- [ ] Dependencies are injected, not hard-coded
- [ ] Contracts use interfaces; data uses types
- [ ] Each function has a single, clear purpose
- [ ] Functions stay at one level of abstraction
- [ ] Public classes and methods have JSDoc
- [ ] All code and documentation is in English
- [ ] Comments explain *why*, not *what*
- [ ] Formatting follows project standards
