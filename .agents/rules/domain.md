# Domain Docs

How the engineering skills should consume this repo's domain documentation when exploring the codebase.

## Before exploring, read these

- **`GLOSSARY-MAP.md`** at the repo root. It points at one glossary per package. Read each glossary relevant to the topic.
- **`packages/<package>/doc/adr/`**: read ADRs that touch the area you are about to work in. The package is the one that owns the decision.
- **`docs/adr/`**: system-wide decisions only. Read it when the decision is not owned by one package.

If any of these files don't exist, **proceed silently**. Don't flag their absence; don't suggest creating them upfront. The `/domain-modeling` skill creates them lazily when terms or decisions actually get resolved.

## File structure

Multi-context. Each package owns its language and its decisions.

```
/
├── GLOSSARY-MAP.md
├── docs/adr/                                  ← system-wide decisions, only when one exists
└── packages/
    ├── article-reader/
    │   └── doc/
    │       ├── GLOSSARY.md
    │       └── adr/
    └── web-app/
        └── doc/
            ├── GLOSSARY.md
            └── adr/
```

`packages/web-app/doc/GLOSSARY.md` exists. `packages/article-reader/doc/GLOSSARY.md` does not, until that package resolves a term. Feature folders may still contain older ADR copies; the package `doc/adr/` directory is the one to read and update.

## Use the glossary's vocabulary

When your output names a domain concept (in an issue title, a refactor proposal, a hypothesis, a test name), use the term as defined in that package's `GLOSSARY.md`. Don't drift to synonyms the glossary explicitly avoids.

If the concept you need isn't in the glossary yet, that's a signal: either you're inventing language the project doesn't use (reconsider) or there's a real gap (note it for `/domain-modeling`).

## Flag ADR conflicts

If your output contradicts an existing ADR, surface it explicitly rather than silently overriding:

> _Contradicts ADR-0007 (event-sourced orders), but worth reopening because…_
