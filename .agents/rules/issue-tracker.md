# Issue tracker: Linear

Tickets are Linear issues in the Yumo workspace, team **Yumo** (key `YUM`, https://linear.app/yurimorini). There is no GitHub or GitLab issue tracker, and no `.scratch/` tree. Do not create a new ticket file under a feature's `tickets/` folder.

Functional specs, designs, and plans stay in the feature folder. `.agents/rules/feature-framework.md` is that layout. `feature.md` links the current ticket by its Linear identifier and URL. It does not copy the ticket body.

A spec is not an issue. `to-spec` writes `specs/<name>.md` in the feature. It does not create a Linear issue and it does not apply a triage label to the file.

## Conventions

Use the Linear tools. Team is `Yumo` on every create.

- **Create an issue**: `save_issue` with `team` `Yumo`, `title`, and `description`. Publish blockers first. Set `blockedBy` to the blocking identifiers. Set `parentId` only when the source was already a Linear issue. Put the feature path (`packages/<package>/doc/features/<feature>/feature.md`) and, when one exists, the spec path at the top of the description.
- **Read an issue**: `get_issue` with the identifier (for example `YUM-123`) and `includeRelations` true. Then `list_comments` with `issueId` set to that identifier.
- **List issues**: `list_issues` with `team` `Yumo`. Filter with `label` or `state` when the caller names one.
- **Comment**: `save_comment` with `issueId` and `body`. A triage comment starts with the triage disclaimer.
- **Apply / remove a label**: `save_issue` with `id` and `addLabels` or `removeLabels`. Do not send `labels`. That field replaces the whole set.
- **Close**: `save_issue` with `state` `Done`. A `wontfix` close uses `state` `Canceled` after the `wontfix` label is the only triage role on the issue.

An identifier looks like `YUM-123`. A Linear URL is the same issue. A bare number is not a file path. Ask which issue it is.

Blocking edges are Linear relations (`blockedBy` / `blocks`), not a line in the body. Omit a "Blocked by" section when those relations are set.

## Category and triage role

A ticket carries one category label and one triage role label.

| Role in skills | Linear label |
| -------------- | ------------ |
| `bug`          | `Bug`        |
| `enhancement`  | `Feature`    |

`Improvement` is not a skill category. The five triage roles are Linear labels. The strings are in `.agents/rules/triage-labels.md`. They are not workflow states. The workflow states of team Yumo are `Backlog`, `Todo`, `In Progress`, `In Review`, `Done`, `Canceled`, and `Duplicate`.

When the triage role changes, remove the other four role labels in the same update. Leave the category label in place.

`to-tickets` applies `ready-for-agent` unless told otherwise.

## Pull requests

Pull requests are not a request surface.

## When a skill says "publish to the issue tracker"

Create a Linear issue, as in **Create an issue**. Then add the identifier and URL to that feature's `feature.md`.

`to-spec` is the exception above: it writes a spec file and does not create an issue.

## When a skill says "fetch the relevant ticket"

Read it as in **Read an issue**. A path under `specs/`, `design/`, or `plans/` is that file, not a Linear issue. A file still present under `tickets/` is legacy and is not the tracker.

## Wayfinding operations

Used by `/wayfinder`. The map and its child tickets are Linear issues. Create a missing `wayfinder:*` label with `save_issue_label` before the first use.

- **Map**: one issue labelled `wayfinder:map`. The description holds Notes, Decisions-so-far, and Fog.
- **Child ticket**: an issue with `parentId` set to the map. Label `wayfinder:research`, `wayfinder:prototype`, `wayfinder:grilling`, or `wayfinder:task`. The question is the description.
- **Blocking**: `blockedBy` on the child. A ticket is unblocked when every blocker is `Done` or `Canceled`.
- **Frontier**: `list_issues` with `parentId` of the map. Drop any issue that is `Done` or `Canceled`, has an assignee, or has an open blocker. First in map order wins.
- **Claim**: `save_issue` with `assignee` `me` before any other write on that ticket.
- **Resolve**: `save_comment` with the answer, set `state` to `Done`, then append a context pointer to the map's Decisions-so-far.
