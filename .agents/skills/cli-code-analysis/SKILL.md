---
name: cli-code-analysis
description: >-
  Documents preferred CLI tools for exploring and analyzing this codebase from
  the shell: ripgrep, fd, difftastic, fzf, bat, git, plus POSIX utilities (wc,
  head, tail, sort, cut, sed, awk, xargs). Use when searching the repo,
  counting or slicing output, composing pipelines, reviewing diffs, navigating
  files, or answering which terminal tools to use for code analysis.
disable-model-invocation: false
---

# CLI tools for code analysis

Assume these commands are available in the environment. Prefer them over plain `grep`, `find`, or `cat` when they fit the task.

## For the agent

- **POSIX tools** (`wc`, `head`, `tail`, `sort`, `uniq`, `cut`, `tr`, `sed`, `awk`, `xargs`, and the like) are universal building blocks. Use them freely in pipelines; do not spell out what `wc -l` or `head` does unless the user asks for teaching material.
- Load this skill when terminal-based **exploration, measurement, or diff review** is the right approach—not for every shell command.

## Principles

- Search text: use **ripgrep** (`rg`); respect `.gitignore` by default.
- Find files by name: use **fd**; faster and simpler than `find` for typical cases.
- Read files in the terminal: use **bat** for syntax highlighting and paging.
- Pick from lists (commits, files, ripgrep hits): combine with **fzf** when interactive selection helps.
- Structural or semantic diffs: use **difftastic** (`difft`) when comparing files or revisions.
- History, blame, bisect, and patch workflows: use **git** built-ins.
- Counting, shaping, and summarizing command output: use **POSIX utilities** together with the tools above.

## POSIX shell utilities

Standard Unix tools; compose them with `rg`, `fd`, and `git` output.

```bash
# Line / word / byte counts
wc -l frontend/src/main.js
rg -c 'import ' frontend/src          # per-file match counts (often better than wc on rg output)

# Preview or trim long output
git log -n 20 --oneline | head -n 5
tail -n 50 frontend/npm-debug.log

# Sorting and histograms
rg -ho "from '([^']+)'" frontend/src | sort | uniq -c | sort -nr

# Fields and light text surgery
git log --oneline | cut -d' ' -f1
sed -n '1,20p' some-output.txt

# Many paths from a list (use -0 / xargs -0 when filenames may contain spaces)
fd -0 -e ts . frontend/src | xargs -0 wc -l
```

Common roles: **`wc`** for sizes and counts; **`head`** / **`tail`** for windows; **`sort`** / **`uniq`** for ordering and frequency; **`cut`** / **`awk`** / **`sed`** for parsing and transforms; **`xargs`** to run a command over many files. Use **`find`** only when `fd` cannot express the query (e.g. unusual time predicates).

## ripgrep (`rg`)

```bash
# Line-numbered matches under the repo root
rg -n 'fetchUser' frontend/src

# Only filenames (good to pipe into fzf or xargs)
rg -l 'TODO|FIXME' --glob '!**/dist/**'

# Type filter (see rg --type-list)
rg 'useEffect' --type ts --type tsx frontend/src
```

## fd (`fd`)

```bash
# By extension
fd -e ts -e tsx . frontend/src

# Regex on full path
fd 'InsertData' frontend/src

# Include ignored paths when needed (e.g. build output)
fd -I 'report\\.json$' frontend
```

## difftastic (`difft`)

```bash
# Two files or directories
difft path/to/a.ts path/to/b.ts

# Git diff with difftastic as external diff (one-off)
GIT_EXTERNAL_DIFF=difft git diff
GIT_EXTERNAL_DIFF=difft git diff HEAD~1..HEAD -- frontend/src/main.js
```

Use for reviewing changes when a syntax-aware diff is clearer than unified diff alone.

## fzf (`fzf`)

```bash
# Interactive pick among ripgrep matches (respects .gitignore via rg)
rg --line-number --no-heading 'TODO|FIXME' | fzf

# Pick a file path, then show with bat
fd . frontend/src | fzf --preview 'bat --color=always --style=numbers {}'

# Pick a recent commit subject/hash
git log --oneline | fzf
```

## bat (`bat`)

```bash
bat frontend/src/main.js

# Plain style (closer to cat; good for piping)
bat -p frontend/vite.config.ts
```

## git (`git`)

```bash
# Who changed a line
git blame -L 10,40 frontend/src/main.js

# Find commits that introduced or removed a string
git log -S 'someSymbol' --oneline -- frontend/src

# Word-level diff in the terminal
git diff --word-diff=color

# Optional: use difftastic for the next diff only (if configured, or use GIT_EXTERNAL_DIFF above)
```

## Optional tools (use if installed)

Not required by this skill; reach for them when they clearly reduce work.

| Tool | Use case |
|------|----------|
| [ast-grep](https://ast-grep.github.io/) | Structural search/replace across ASTs (e.g. rename a pattern in one language consistently). |
| [jq](https://jqlang.github.io/jq/) / [yq](https://github.com/mikefarah/yq) | Inspect or filter JSON/YAML from scripts and CI logs. |
| [tokei](https://github.com/XAMPPRocky/tokei) | Fast line counts by language (lighter than full cloc for a quick overview). |
| [hyperfine](https://github.com/sharkdp/hyperfine) | Compare command timings when optimizing scripts or build steps. |
| [shellcheck](https://www.shellcheck.net/) | Lint shell scripts before committing bash changes. |

When in doubt, start with **rg**, **fd**, **git**, and **difft**; add **fzf**, **bat**, and POSIX filters (**head**, **sort**, **wc**, etc.) when shaping output or counts matters.
