---
description: Run every plan implementation in an isolated git worktree
alwaysApply: true
---

# Plan workspace

A plan implementation runs in its own git worktree. That worktree is the workspace. The plan ledger under `.superpowers/sdd/` is the skill's scratch. It is not this workspace.

## When

Use a workspace when any of these is true:

- The session runs `executing-plans` or `subagent-driven-development`.
- The work implements an accepted plan (`plans/` under the feature, or a plan the session is following).
- The user asks to implement a plan.

Unsure whether the work is plan implementation: ask before the first edit, then wait. Example: "A plan exists at `<path>`. Is this implementation of that plan (new workspace), or a change in the current checkout?"

Work with no plan stays in the current checkout.

## Start

Record the branch checked out when the work begins. Create the workspace with `using-git-worktrees`. This rule is the declared preference: when a trigger above is clear, create the workspace without asking.

Already inside a worktree for this same plan: stay there. One plan, one workspace.

The workspace branch starts from that recorded branch. Implementation commits land only on the workspace branch.

## Finish

When the plan's checks are green, follow `finishing-a-development-branch` as written. That skill owns the integration choice, the merge, and when the workspace is removed. Pass it the recorded branch as the known fork point.
