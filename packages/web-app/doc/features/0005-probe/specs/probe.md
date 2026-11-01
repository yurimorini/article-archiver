# Probe

Functional spec for a private, local probe of the real Eleanor server. The behavior was accepted on 2026-11-01. The module that implements it is [Probe](../design/Probe.md).

The probe is not a product feature and is not part of the distributable image. It lives in the repository so an agent, or a person, can run it.

## Intent

An agent needs one finished folder after a run against the real server: what was sent, what came back, why a URL failed, and what SQLite stored. PHPUnit already proves the contract in-process with a fake fetcher. The probe is the other loop. It hits `php -S`, the real fetcher, the owner file, and the dev log.

The folder is an observation. The agent compares it to the current specs. The probe does not contain expected statuses.

## Goals

- One command starts the dev image, starts the server, runs the steps in a config file, writes a run folder, and stops the server.
- The same command with one catalog name runs only that step.
- The config lists named steps. The runner owns the HTTP. A route change is a change to one step, not to every scenario.
- Steps that do not depend on each other run at the same time. A step that breaks is recorded, and the others still run.
- The run folder records the owner, the sample, each request, each response, the matching dev-log lines, the import `error.log`, and the SQLite row.
- A dedicated owner email keeps the probe file apart from any other owner's file. Dev already stores one SQLite file per email under `var/data`.
- The default config draws at most 10 URLs at random from `var/log-urls.txt`, imports that draw, archives one URL from it, repeats that call, lists the archives, reads that archive as metadata, as metadata plus HTML, and as stored HTML, reads the SQL row, then sends the refusal calls that need no fetch.
- A person can launch the same command. The config is the interface. The only argument is one catalog name, and it is optional.

## Non-goals

- A pass or fail mark, an expected status, or a comparison with a spec.
- Playwright, a browser, or any frontend check.
- A long-running monitor. The command finishes and leaves the folder.
- Parsing a comma-separated URL column. `archive:import` reads one URL per line until that import ticket lands. `var/log-urls.txt` is already that line file.
- Copying `var/log-urls.txt` into the run folder. The run keeps only the draw, and the draw is at most 10 lines.
- Holding one URL out of the import so the first POST is forced to 201. The import may store the URL the later POST sends. Both calls may be 200.
- Forcing a 422 or a 502. Those codes appear only when a sampled page fails, and the folder records them.
- `GET /api/archives/{id}` until that route exists. The `read` step is not in the default config.
- The clean `eleanor` image, Compose, and a second data directory. Dev ignores `ELEANOR_DATA_DIR`. The probe uses `APP_ENV=dev` and `var/data`.
- A new Composer dependency.
- Publishing the probe, or copying it into the distributable image.

## Anti-goals

- Expected codes inside the config. That would make the probe a second test suite that rots when a spec changes.
- Raw method, path, and body in the config. The agent would have to know the routes, and a route change would edit every scenario.
- Copying the bearer secret into the config or the run folder.
- Wiping `var/data` between runs. A later run may see `Already stored`. That is a real outcome.
- Treating an Eleanor 4xx or 5xx, or an import exit 1 caused by failed URLs, as a broken probe.
- Stopping the run at the first broken step when the other steps can still be sent.
- Parallel downloads inside `archive:import`. That command walks the file one URL at a time. A cap on in-flight downloads is a later import ticket. The probe does not add one.
- Adding `ELEANOR_OWNER_EMAIL`. [Archive command](../../0002-server/design/ArchiveCommand.md) and [Bearer](../../0002-server/design/Bearer.md) lock the owner email as the parameter `eleanor.owner_email`.

## Constraints

- The server is the one `packages/web-app/bin/serve` already starts: image `log-read-php`, `php -S`, loopback port 8000, repository bind-mounted at `/workspace`.
- `APP_ENV` stays `dev` (`.env`). Domain and application lines go to `var/log/eleanor.dev.log` as JSON, level debug, channels `application` and `domain`.
- The bearer is `ELEANOR_BEARER_TOKEN`. An empty secret makes every authenticated call 500 `unexpected` before the header is read. The server the probe starts must have a non-empty token.
- The HTTP owner is `eleanor.owner_email` in `config/services.yaml`. The default is `owner@example.com`. Import must receive that same email as `--owner`, or the SQL file and the API are two files.
- The file name is the first 16 hexadecimal characters of the SHA-256 of the lowercased email, under `var/data`, plus `.sqlite`.
- `archive:import` writes `error.log` in its working directory. A failed URL does not stop the file. The command exits 1 when any URL failed and exits 0 when none failed.
- Error JSON under `/api` is `application/json` with `code` and `message`. The request id is the `X-Request-Id` header, not a body field.
- Refusal calls the default config sends, and the code the current specs name: missing bearer and wrong bearer are 401 `unauthenticated`; a broken JSON body is 400 `invalid_json`; `{}` is 400 `invalid_body`; a URL the policy refuses is 400 `url_policy`; `text/plain` is 415 `unsupported_media_type`; an unknown path is 404 `not_found`; a method the route does not allow is 405 `method_not_allowed`; `GET /api/archives?sort=created_at` is 400 `invalid_query`.
- The probe does not change those codes. When a spec changes a code, the step implementation changes with the route. The config still names the step.

## Approaches

1. **Probe in the dev container.** Chosen. One command starts the existing image, starts `php -S`, runs the config, writes the folder, and stops the server. The step catalog lives beside the app, outside `src/`.
2. **Client only.** Rejected. The person starts the server, and the probe only sends HTTP. An agent then has two steps, and a server already up may be a personal owner.
3. **Shell client.** Rejected. `curl` and `sqlite3` can send the calls. Slicing the JSON log and writing the run record in the shell is brittle. The image already has PHP and `pdo_sqlite`.
4. **Generic HTTP scenarios, and a PHPUnit run against the live server.** Rejected. Raw HTTP rots as routes change. PHPUnit fails the process when Eleanor returns an error, and the in-process suite already covers the contract.

## What the command does

`packages/web-app/bin/probe` is the only entry. It uses the same image and the same docker flags as `bin/serve`, and it publishes `127.0.0.1:8000:8000`.

With no arguments it runs every name in `steps`. With one argument it runs that catalog name and ignores the rest of `steps`. The argument is the name from the catalog, such as `archive` or `invalid-json`. A second argument, or a name the catalog does not have, is a broken probe. The probe writes nothing and does not start the server.

Inside the container a PHP runner:

1. Reads `probe/probe.json`.
2. Refuses to start when `ELEANOR_BEARER_TOKEN` is missing or empty.
3. Reads `eleanor.owner_email` from the booted container parameter. When the config sets `owner`, the two strings must match after the same lowercasing `Owner` applies. A mismatch is a broken probe.
4. Draws the sample only when a selected step needs it: `import`, `archive`, `archive-again`, `detail`, `detail-content`, `detail-html`, or `sql`. A run of only `list` or only a refusal preset does not read `var/log-urls.txt`. When a draw is required, the runner reads that file, drops blank lines, shuffles with the seed, and keeps `sample` lines. `sample` defaults to 10 and is never above 10. A shorter file keeps every remaining line. Zero URLs is a broken probe. The run directory then receives `sample.txt`: the draw only, at most 10 lines.
5. Starts `php -S 0.0.0.0:8000 -t public router.php` in the package directory. The first HTTP response on `http://127.0.0.1:8000` means the server is up. No response before the boot timeout is a broken probe. The port is the config value, default 8000. A port already taken is a broken probe. The server is not started until the checks above have passed.
6. Runs the selected steps on the schedule below. Each step is recorded. A step that gets an HTTP response is recorded whatever the status. A step that cannot be sent is recorded with `response` null and `error` set, and the others still run.
7. Stops the server when every selected step has been recorded.

Import runs `php bin/console archive:import` on `sample.txt` with `--owner` set to the probe email. The working directory is the run directory, so `error.log` is written there.

HTTP calls use `http://127.0.0.1:<port>`. Authenticated calls send `Authorization: Bearer` and the environment token. Presets that omit or replace the header do not change the token the server was started with.

The SQL step opens `var/data/<16-hex>.sqlite` read-only with PDO. It reads the `archive` row for the URL chosen for this run, and `length(html)` from `archive_body`. A missing file or a missing row is `rows: []`. It is not a broken probe. A run that selects `sql` without `archive` still uses the first line of the draw.

## Schedule

Selected steps run in two bands.

The write chain stays in this order, and only the selected names run: `import`, then `archive`, then `archive-again`. `archive-again` is the repeat of `archive`, so it waits until `archive` has been recorded. A broken `import` does not skip `archive`. A broken `archive` does not skip `archive-again`. Both still use the first line of the draw.

Every selected refusal preset runs at the same time as that chain and at the same time as the other presets. A preset does not wait for the chain. The chain does not wait for a preset.

When the write chain has been recorded, `list`, `detail`, `detail-content`, `detail-html`, and `sql` run at the same time, if they were selected. They do not wait for a refusal preset that is still in flight. They do wait for the write chain, so the page and the row are the result of those writes. A broken write does not skip them.

`detail` is `GET /api/archives/{id}`. `detail-content` is that path with `includeContent=true`. `detail-html` is `GET /api/archives/{id}.html`. `{id}` is the id stored for the first drawn URL. A missing file or a missing row records `error` and sends no request. It does not create the database.

A step that breaks does not cancel a sibling that is already running, and it does not remove a step that has not started.

One selected step has no sibling. It runs alone. `archive-again` alone is one POST of the first drawn URL. It does not look up an earlier run.

`archive:import` still walks `sample.txt` one URL at a time. The probe does not run those URLs side by side.

## Exit status

The process exits 0 when setup held and every selected step was recorded without a toolbelt break. Setup is the token, the owner, the server, and the draw when a selected step needs it.

It exits non-zero, and does not start the steps, when setup is broken: the image is missing, the token is empty, the argument is not one catalog name, the owner does not match the parameter, the draw is required and the URL file is missing or empty, the server never answers, or the port is taken.

A selected step that cannot be sent is a toolbelt break for that step only. The runner records it, runs the remaining steps, keeps the folder, and exits non-zero at the end. One broken step does not decide the exit before the others finish.

An Eleanor status, including 401, 400, 404, 405, 415, 422, 500, and 502, does not change the exit. An `archive:import` exit of 0 or 1, with the summary printed, does not change the exit. The import status is stored on that step. Any other import outcome is a broken step. The later steps still run.

## Config

`packages/web-app/probe/probe.json` is committed. JSON, so the runner needs no new parser. The file contains no secret.

```json
{
  "urls": "var/log-urls.txt",
  "sample": 10,
  "owner": "owner@example.com",
  "port": 8000,
  "steps": [
    "import",
    "archive",
    "archive-again",
    "list",
    "sql",
    "no-bearer",
    "wrong-bearer",
    "invalid-json",
    "invalid-body",
    "url-policy",
    "unsupported-media-type",
    "not-found",
    "method-not-allowed",
    "invalid-query"
  ]
}
```

`urls` is a path relative to the package root. The default, and the file this probe is built for, is `var/log-urls.txt`: one URL per line, already extracted, and gitignored with the rest of `var/`. The runner does not write that file. `sample` is how many lines to draw. The default is 10. A value above 10, or below 1, is a broken probe. `owner` is optional. When it is absent, the runner uses the container parameter. `port` defaults to 8000. `steps` is the closed catalog below, in run order. Unknown names are a broken probe.

The seed is not a config field. The runner generates one and writes it into `run.json`. Repeating a run means copying that seed into the config later. The builder adds an optional `seed` integer. When it is present, the shuffle uses it instead of a new one.

## Step catalog

| Name | What the runner sends |
| --- | --- |
| `import` | `archive:import` on the sample file for the probe owner |
| `archive` | `POST /api/archives` with the first URL of the shuffled sample |
| `archive-again` | The same POST as `archive` |
| `list` | `GET /api/archives` with no query string |
| `sql` | The read-only row lookup for the `archive` URL |
| `read` | Reserved. Not in the default config. It becomes `GET /api/archives/{id}` when that route exists, using the id the `archive` response returned |
| `no-bearer` | `POST /api/archives` with a public URL and no `Authorization` header |
| `wrong-bearer` | The same POST with `Authorization: Bearer wrong` |
| `invalid-json` | `POST /api/archives` as `application/json` with a broken body |
| `invalid-body` | `POST /api/archives` with `{}` |
| `url-policy` | `POST /api/archives` with a URL the policy refuses, such as `http://127.0.0.1/` |
| `unsupported-media-type` | `POST /api/archives` as `text/plain` |
| `not-found` | `GET /api/no-such-route` |
| `method-not-allowed` | `PUT /api/archives` |
| `invalid-query` | `GET /api/archives?sort=created_at` |

`archive` and `archive-again` share the URL chosen for that run. Later steps that need it use that same value. The choice is the first line of `sample.txt`, which the seed already shuffled.

Presets that POST a URL of their own (`no-bearer`, `wrong-bearer`, `url-policy`) use a fixed URL in the step implementation, not a sample URL. They must not depend on the sample.

## Run folder

Each run is `packages/web-app/probe/runs/<utc>-<seed>/`. `probe/runs/` is gitignored. `var/` is already gitignored, so the SQLite file and `eleanor.dev.log` stay out of git. The run folder is the copy an agent reads, and it must stay out of git too because it holds page titles and URLs from the person's list.

`run.json`:

```json
{
  "started": "2026-11-01T15:41:00Z",
  "owner": "owner@example.com",
  "baseUrl": "http://127.0.0.1:8000",
  "urls": "var/log-urls.txt",
  "seed": 1847291,
  "sample": ["https://example.com/a", "https://example.com/b"]
}
```

`sample.txt` is that draw, one URL per line, at most 10 lines. It is the file import reads. It is not a copy of `var/log-urls.txt`.

One file per step, `steps/01-import.json`, `steps/02-archive.json`, and so on, in run order. A step file has this shape. Fields that do not apply are `null` or `[]`, not omitted, so an agent can read every file the same way.

```json
{
  "name": "archive",
  "request": {
    "method": "POST",
    "url": "http://127.0.0.1:8000/api/archives",
    "authorization": "bearer",
    "contentType": "application/json",
    "body": "{\"url\":\"https://example.com/a\"}"
  },
  "response": {
    "status": 201,
    "headers": {"x-request-id": "…", "content-type": "application/json"},
    "body": "{\"id\":1,\"url\":\"https://example.com/a\",\"title\":\"A\"}"
  },
  "log": [],
  "import": null,
  "sql": null,
  "error": null
}
```

`authorization` is `bearer`, `absent`, or `wrong`. The secret is never written.

`error` is null when the step was sent. When the step could not be sent, `error` is one sentence, `response` is null, and the other steps still have their own files.

`response.headers` keeps `x-request-id`, `content-type`, and `allow`. Other headers are dropped.

`log` is the `eleanor.dev.log` JSON objects whose `extra.request_id` equals that response's request id. Lines written before the step, and lines for other requests, stay out.

For `import`, `request`, `response`, and `log` are null. `import` is:

```json
{
  "exit": 1,
  "stdout": "…",
  "stderr": "…",
  "errorLog": "https://example.com/a\ttransport\n"
}
```

`errorLog` is null when `error.log` was not written. `stdout` includes the summary and the result lines.

For `sql`, `request` and `response` are null. `sql` is:

```json
{
  "file": "var/data/0123456789abcdef.sqlite",
  "rows": [
    {
      "id": 1,
      "url": "https://example.com/a",
      "title": "A",
      "excerpt": null,
      "site_name": "Example",
      "created_at": "2026-11-01T15:41:02Z",
      "body_bytes": 1204
    }
  ]
}
```

Column names are the stored names in `SqliteSchema`. `body_bytes` is `length(html)`, not the HTML. The probe does not invent a second schema.
