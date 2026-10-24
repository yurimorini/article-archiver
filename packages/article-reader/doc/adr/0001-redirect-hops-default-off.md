# Redirect hops stay off unless the caller sets a cap

A saved page often answers with a redirect, and following that redirect without a new guard is an SSRF hole. The caller sets the maximum number of redirect hops, from 0 through 5. Zero is the default and keeps today's failure on a 3xx. A value below 0 or above 5 is rejected. Each hop is guarded and DNS-pinned again. Guzzle does not follow the redirect itself.

Considered and rejected: turning on Guzzle's redirect follower, a fixed cap of five with no way to keep the old behavior, and a caller-chosen cap with no maximum.

A cap of zero keeps today's redirect failure. Exhausting a cap of one through five is a different failure. A 300, a 304, and a followable status with no Location stay today's redirect failure. A later URL that the guard rejects is a URL rejection, not a redirect failure. The article result reports the entry URL, the final URL, and the hop history. The hop count is the length of that history. An empty result does not gain those facts. A thrown failure carries none of them. The hop loop is a helper the composition root calls. The foundation Orchestrator spec is revised because that helper follows redirects.
