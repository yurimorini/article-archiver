---
description: Locate code with the CodeGraph MCP tool before text search
alwaysApply: true
---

# Code search

Locate symbols, call flow, and blast radius with the CodeGraph MCP tool `codegraph_explore` before Grep, Glob, or ripgrep.

When `codegraph` is not on the initial tool list, load that MCP namespace and read the `codegraph_explore` schema, then call it.

Name the symbol, file, or question in the query. One call returns the source, the call paths, and the blast radius.

When that tool is missing from the session or the project has no index, search with ripgrep and read the files it names.
