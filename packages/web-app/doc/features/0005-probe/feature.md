# Feature: Probe

**Status:** Design

## Goal

A private probe runs the real Eleanor server, imports a random draw of at most 10 URLs, calls the live HTTP API, and reads that owner's SQLite file. It writes one run folder an agent or a person can read. One catalog name runs that step alone. Steps that do not depend on each other run together. A broken step does not stop the others. It does not judge the responses.

## Current

The behavior is [probe.md](specs/probe.md). The module is [Probe](design/Probe.md). The plan is [2026-11-01-probe.md](plans/2026-11-01-probe.md). The ticket is [YUM-11](https://linear.app/yurimorini/issue/YUM-11/probe-the-live-eleanor-server). The behavior and the module were accepted on 2026-11-01. The plan is the current review.
