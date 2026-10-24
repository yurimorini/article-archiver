# Feature: Redirect hops

**Status:** Open

## Goal

A caller can follow redirect hops, up to a cap, and still tell the entry URL from the final URL. The default cap is zero.

Terms live in the package [GLOSSARY.md](../../GLOSSARY.md). **Entry URL** is the string the caller passed. **Final URL** is the canonical URL of the page whose body is extracted. **Hop count** is how many redirect hops were followed. **Redirect hop** is one followable HTTP redirect.

## Slices

| Order | Slice | Category | Done when | Status |
| --- | --- | --- | --- | --- |
| 1 | [Redirect hops](specs/redirect-hops.md) | Fetch | A fetch follows redirect hops up to the caller cap, re-checks and re-pins each hop, and an article result reports the entry URL, the final URL, and the hop history | Tickets |

## Tickets

| | Ticket | Blocked by | Status |
| --- | --- | --- | --- |
| 01 | [Report the entry URL, the final URL, and the hop count](tickets/01-report-entry-and-final-url.md) | None | Done |
| 02 | [Follow redirect hops up to the cap](tickets/02-follow-redirect-hops.md) | 01 | ready-for-agent |

## Current

Slice 1, ticket [02 — Follow redirect hops up to the cap](tickets/02-follow-redirect-hops.md). Ticket 01 is done at `e8ec443` on `import-command`. The ticket 02 design is [FollowRedirectHops.md](design/FollowRedirectHops.md). The ticket 02 plan is [2026-10-24-follow-redirect-hops.md](plans/2026-10-24-follow-redirect-hops.md). Ticket 01 stays [RedirectHops.md](design/RedirectHops.md) and [2026-10-24-redirect-hops.md](plans/2026-10-24-redirect-hops.md).
