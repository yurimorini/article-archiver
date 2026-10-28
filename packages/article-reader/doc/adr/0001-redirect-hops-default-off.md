# Redirect hops default to five

Status: superseded by the revision below. The title keeps the old file name.

A saved page often answers with one redirect, usually to the same path with a trailing slash. The caller sets the maximum number of redirect hops, from 0 through 5. Five is the default. Zero is still a legal cap and keeps the refusal on a 3xx. A value below 0 or above 5 is rejected. Each hop is guarded and DNS-pinned again. Guzzle does not follow the redirect itself.

Revised 2026-10-25. The earlier decision made zero the default, so a caller that set nothing kept the refusal. That default left a trailing-slash redirect as a failed fetch.

Considered and rejected: turning on Guzzle's redirect follower, and keeping zero as the default.

A cap of zero keeps today's redirect failure. Exhausting a cap of one through five is a different failure. A 300, a 304, and a followable status with no Location stay today's redirect failure. A later URL that the guard rejects is a URL rejection, not a redirect failure. The article result reports the entry URL, the final URL, and the hop history. The hop count is the length of that history. An empty result does not gain those facts. A thrown failure carries none of them. The hop loop is a helper the composition root calls. The foundation Orchestrator spec is revised because that helper follows redirects.
