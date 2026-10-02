#!/usr/bin/env bash
# Read bin/projects. Each line is a package name, then a path relative to the git root.
# Blank lines and lines whose first non-whitespace character is # are ignored.

projects_read() {
  local file="$1"
  local line name rest

  while IFS= read -r line || [[ -n "${line}" ]]; do
    line="${line#"${line%%[![:space:]]*}"}"
    [[ -z "${line}" ]] && continue
    [[ "${line}" == \#* ]] && continue

    name="${line%%[[:space:]]*}"
    rest="${line#"${name}"}"
    rest="${rest#"${rest%%[![:space:]]*}"}"
    rest="${rest%"${rest##*[![:space:]]}"}"
    if [[ -z "${rest}" ]]; then
      printf 'Invalid projects line (missing path): %s\n' "${line}" >&2
      return 1
    fi
    printf '%s\t%s\n' "${name}" "${rest}"
  done < "${file}"
}

projects_names() {
  local file="$1"
  local name rel

  while IFS=$'\t' read -r name rel; do
    printf '%s\n' "${name}"
  done < <(projects_read "${file}")
}

projects_path_for() {
  local file="$1"
  local want="$2"
  local name rel

  while IFS=$'\t' read -r name rel; do
    if [[ "${name}" == "${want}" ]]; then
      printf '%s\n' "${rel}"
      return 0
    fi
  done < <(projects_read "${file}")

  return 1
}
