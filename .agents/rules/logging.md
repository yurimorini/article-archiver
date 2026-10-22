---
description: A use case logs the outcome of its decision, not the step it is about to take
globs: packages/**/src/**/*.php
alwaysApply: false
---

# Logging

A class logs work it has finished, or a failure it is about to report. It does not log that it is about to call something else.

## Level

- `info` is an outcome a person running the app needs: the branch the command took, or the stage a component has finished.
- `warning` is a failure that command reports.
- `debug` is a step. It belongs in the component that performs the step, and only when that step is hard to see from the outcome. A use case does not write it.

`LogApiRequest` is the exception that is allowed to write before the work. It writes `http.request.start` and `http.request.finish` on `application` for the HTTP round. The controller does not. A use case does not copy that pair for its own steps.

## Shape

The message is the event name. Values sit in the PSR-3 context array. A line does not carry article HTML, a title, an email, a bearer token, or a credential.

The use case calls one method per outcome. That method owns the level, the event name, and the context. The same outcome is assembled in one method, even when two catches report it.

Ambient facts, such as the request id, are added by a Monolog processor. The use case does not pass them.

A failed log write is caught and not rethrown. It does not change the command's outcome.

## What not to add

Do not add a logger type, a decorator, or a domain event for outcomes that one class writes. That class already knows which branch it took.
