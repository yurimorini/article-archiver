#!/usr/bin/env bash
# Run one Composer command across the packages selected from bin/projects.

package_has_php() {
  local package_dir="$1"
  local found

  found="$(find "${package_dir}/src" "${package_dir}/tests" -type f -name '*.php' -print -quit 2>/dev/null || true)"
  [[ -n "${found}" ]]
}

run_one_package_job() {
  local root="$1"
  local kind="$2"
  local name="$3"
  local rel="$4"
  shift 4

  local package_dir="${root}/${rel}"
  if [[ ! -f "${package_dir}/composer.json" ]]; then
    printf 'Package %s has no composer.json at %s/composer.json\n' "${name}" "${rel}" >&2
    return 1
  fi

  case "${kind}" in
    quality|cs-check|cs-fix|phpstan)
      if ! package_has_php "${package_dir}"; then
        printf 'No PHP files under src/ or tests/; skipping.\n'
        return 0
      fi
      "${root}/bin/docker-run" "${name}" composer --working-dir="/workspace/${rel}" "${kind}" "$@"
      ;;
    test|install)
      "${root}/bin/docker-run" "${name}" composer --working-dir="/workspace/${rel}" "${kind}" "$@"
      ;;
    *)
      printf 'Unknown launcher command: %s\n' "${kind}" >&2
      return 1
      ;;
  esac
}

dispatch_composer_jobs() {
  local root="$1"
  local kind="$2"
  shift 2

  local projects_file="${root}/bin/projects"
  local -a names=()
  local -a rels=()
  local -a extra=()

  if [[ $# -eq 0 ]]; then
    local name rel
    while IFS=$'\t' read -r name rel; do
      [[ -z "${name}" ]] && continue
      names+=("${name}")
      rels+=("${rel}")
    done < <(projects_read "${projects_file}")
  else
    local name="$1"
    shift
    if ((${#@})); then
      extra=("$@")
    fi
    local rel
    if ! rel="$(projects_path_for "${projects_file}" "${name}")"; then
      printf 'Unknown package: %s\n' "${name}" >&2
      projects_names "${projects_file}" >&2
      return 1
    fi
    names+=("${name}")
    rels+=("${rel}")
  fi

  local count="${#names[@]}"
  local tmp
  tmp="$(mktemp -d)"
  local -a pids=()
  local i

  for ((i = 0; i < count; i++)); do
    (
      run_one_package_job "${root}" "${kind}" "${names[i]}" "${rels[i]}" ${extra[@]+"${extra[@]}"}
    ) >"${tmp}/${i}.log" 2>&1 &
    pids+=("$!")
  done

  local failed=0
  for ((i = 0; i < count; i++)); do
    if ! wait "${pids[i]}"; then
      failed=1
    fi
  done

  for ((i = 0; i < count; i++)); do
    printf '%s\n' "${names[i]}"
    cat "${tmp}/${i}.log"
  done

  rm -rf "${tmp}"
  return "${failed}"
}
