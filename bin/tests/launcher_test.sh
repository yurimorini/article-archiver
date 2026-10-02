#!/usr/bin/env bash
# Launcher tests. They use a temporary git-root fixture and do not start Docker.
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FAILURES=0

fail() {
  printf 'FAIL: %s\n' "$1" >&2
  FAILURES=$((FAILURES + 1))
}

assert_eq() {
  local got="$1"
  local want="$2"
  local message="$3"
  if [[ "${got}" != "${want}" ]]; then
    printf 'FAIL: %s\n got: %s\n want: %s\n' "${message}" "${got}" "${want}" >&2
    FAILURES=$((FAILURES + 1))
  fi
}

assert_status() {
  local got="$1"
  local want="$2"
  local message="$3"
  if [[ "${got}" -ne "${want}" ]]; then
    printf 'FAIL: %s\n got status %s, want %s\n' "${message}" "${got}" "${want}" >&2
    FAILURES=$((FAILURES + 1))
  fi
}

require_file() {
  local path="$1"
  if [[ ! -f "${path}" ]]; then
    fail "missing ${path#"${REPO}/"}"
    return 1
  fi
}

copy_launcher() {
  local dest="$1"
  mkdir -p "${dest}/bin/lib"
  cp "${REPO}/bin/lib/projects.sh" "${dest}/bin/lib/projects.sh"
  cp "${REPO}/bin/lib/dispatch.sh" "${dest}/bin/lib/dispatch.sh"
  local script
  for script in quality test phpstan cs-check cs-fix composer-install composer docker-run; do
    cp "${REPO}/bin/${script}" "${dest}/bin/${script}"
    chmod +x "${dest}/bin/${script}"
  done
}

install_docker_run_mock() {
  local dest="$1"
  cat > "${dest}/bin/docker-run" << 'EOF'
#!/usr/bin/env bash
set -euo pipefail
name="$1"
shift
printf '%s\n' "${name} $*" >> "${DOCKER_RUN_LOG:?}"
if [[ "${name}" == "${DOCKER_FAIL_PACKAGE:-}" ]]; then
  printf 'FAILED-%s\n' "${name}"
  exit 4
fi
if [[ "${name}" == "${DOCKER_SLOW_PACKAGE:-}" ]]; then
  sleep 0.2
  printf 'SLOW-%s\n' "${name}"
  exit 5
fi
printf 'OK-%s\n' "${name}"
exit 0
EOF
  chmod +x "${dest}/bin/docker-run"
}

write_projects() {
  local dest="$1"
  shift
  : > "${dest}/bin/projects"
  local line
  for line in "$@"; do
    printf '%s\n' "${line}" >> "${dest}/bin/projects"
  done
}

package_tree() {
  local dest="$1"
  local rel="$2"
  local with_php="${3:-yes}"
  mkdir -p "${dest}/${rel}"
  printf '{}\n' > "${dest}/${rel}/composer.json"
  if [[ "${with_php}" == "yes" ]]; then
    mkdir -p "${dest}/${rel}/src"
    printf '<?php\n' > "${dest}/${rel}/src/A.php"
  fi
}

new_fixture() {
  local dest
  dest="$(mktemp -d)"
  copy_launcher "${dest}"
  install_docker_run_mock "${dest}"
  printf '%s' "${dest}"
}

# --- parser ---

test_projects_read_ignores_blanks_and_comments() {
  require_file "${REPO}/bin/lib/projects.sh" || return 0
  # shellcheck source=/dev/null
  source "${REPO}/bin/lib/projects.sh"
  local file
  file="$(mktemp)"
  printf '%s\n' \
    '# top' \
    '' \
    '   # indented' \
    $'article-reader\tpackages/article-reader' \
    'web-app    packages/web-app' \
    > "${file}"
  local got
  got="$(projects_read "${file}")"
  assert_eq "${got}" $'article-reader\tpackages/article-reader\nweb-app\tpackages/web-app' \
    "projects_read keeps name and path, drops blanks and comments"
  rm -f "${file}"
}

test_projects_path_for_unknown_name() {
  require_file "${REPO}/bin/lib/projects.sh" || return 0
  # shellcheck source=/dev/null
  source "${REPO}/bin/lib/projects.sh"
  local file
  file="$(mktemp)"
  printf '%s\n' $'article-reader\tpackages/article-reader' > "${file}"
  local status=0
  projects_path_for "${file}" "web-app" >/dev/null 2>&1 || status=$?
  assert_status "${status}" 1 "unknown name returns 1"
  local names
  names="$(projects_names "${file}")"
  assert_eq "${names}" "article-reader" "projects_names prints the listed name"
  rm -f "${file}"
}

# --- fan-out ---

test_quality_unknown_package_prints_known_names() {
  local dest
  dest="$(new_fixture)"
  write_projects "${dest}" $'article-reader\tpackages/article-reader'
  local stdout stderr status
  stdout="$(mktemp)"
  stderr="$(mktemp)"
  export DOCKER_RUN_LOG="${dest}/invocations"
  : > "${DOCKER_RUN_LOG}"
  status=0
  (cd "${dest}" && ./bin/quality web-app) >"${stdout}" 2>"${stderr}" || status=$?
  assert_status "${status}" 1 "unknown package exits 1"
  assert_eq "$(cat "${stdout}")" "" "unknown package prints no job block"
  assert_eq "$(cat "${stderr}")" $'Unknown package: web-app\narticle-reader' \
    "unknown package prints the known names"
  assert_eq "$(cat "${DOCKER_RUN_LOG}")" "" "unknown package must not call docker-run"
  unset DOCKER_RUN_LOG
  rm -rf "${dest}" "${stdout}" "${stderr}"
}

test_quality_one_package_calls_composer_script() {
  local dest log
  dest="$(new_fixture)"
  log="${dest}/invocations"
  export DOCKER_RUN_LOG="${log}"
  write_projects "${dest}" $'article-reader\tpackages/article-reader'
  package_tree "${dest}" "packages/article-reader" yes
  local stdout status
  stdout="$(mktemp)"
  status=0
  (cd "${dest}" && ./bin/quality article-reader) >"${stdout}" 2>"${stdout}.err" || status=$?
  assert_status "${status}" 0 "quality article-reader exits 0"
  assert_eq "$(cat "${stdout}")" $'article-reader\nOK-article-reader' \
    "quality prints one block whose first line is the package name"
  assert_eq "$(cat "${log}")" \
    "article-reader composer --working-dir=/workspace/packages/article-reader quality" \
    "quality runs the composer quality script in the package"
  rm -rf "${dest}" "${stdout}" "${stdout}.err"
  unset DOCKER_RUN_LOG
}

test_quality_all_packages_and_parallel_failure() {
  local dest log
  dest="$(new_fixture)"
  log="${dest}/invocations"
  export DOCKER_RUN_LOG="${log}"
  export DOCKER_SLOW_PACKAGE="slow-fail"
  write_projects "${dest}" \
    $'slow-fail\tpackages/slow-fail' \
    $'fast-ok\tpackages/fast-ok'
  package_tree "${dest}" "packages/slow-fail" yes
  package_tree "${dest}" "packages/fast-ok" yes
  local stdout status
  stdout="$(mktemp)"
  status=0
  (cd "${dest}" && ./bin/quality) >"${stdout}" 2>"${stdout}.err" || status=$?
  assert_status "${status}" 1 "a failing package makes the launcher exit non-zero"
  assert_eq "$(cat "${stdout}")" $'slow-fail\nSLOW-slow-fail\nfast-ok\nOK-fast-ok' \
    "both packages finish and each block stays intact"
  if ! grep -Fxq "slow-fail composer --working-dir=/workspace/packages/slow-fail quality" "${log}"; then
    fail "slow-fail was not invoked"
  fi
  if ! grep -Fxq "fast-ok composer --working-dir=/workspace/packages/fast-ok quality" "${log}"; then
    fail "fast-ok was not invoked"
  fi
  rm -rf "${dest}" "${stdout}" "${stdout}.err"
  unset DOCKER_RUN_LOG DOCKER_SLOW_PACKAGE
}

test_quality_skips_package_without_php() {
  local dest log
  dest="$(new_fixture)"
  log="${dest}/invocations"
  export DOCKER_RUN_LOG="${log}"
  : > "${log}"
  write_projects "${dest}" \
    $'article-reader\tpackages/article-reader' \
    $'other\tpackages/other'
  package_tree "${dest}" "packages/article-reader" no
  mkdir -p "${dest}/packages/other/src" "${dest}/packages/article-reader/bin"
  printf '<?php\n' > "${dest}/packages/other/src/B.php"
  printf '{}\n' > "${dest}/packages/other/composer.json"
  printf '<?php\n' > "${dest}/packages/article-reader/bin/spike.php"
  local stdout status
  stdout="$(mktemp)"
  status=0
  (cd "${dest}" && ./bin/quality article-reader) >"${stdout}" 2>"${stdout}.err" || status=$?
  assert_status "${status}" 0 "skip exits 0"
  assert_eq "$(cat "${stdout}")" $'article-reader\nNo PHP files under src/ or tests/; skipping.' \
    "quality skips when the package has no PHP under src or tests"
  assert_eq "$(cat "${log}")" "" "skip does not call docker-run"
  rm -rf "${dest}" "${stdout}" "${stdout}.err"
  unset DOCKER_RUN_LOG
}

test_cs_check_cs_fix_and_phpstan_skip_too() {
  local dest log command stdout status
  for command in cs-check cs-fix phpstan; do
    dest="$(new_fixture)"
    log="${dest}/invocations"
    export DOCKER_RUN_LOG="${log}"
    : > "${log}"
    write_projects "${dest}" $'article-reader\tpackages/article-reader'
    package_tree "${dest}" "packages/article-reader" no
    stdout="$(mktemp)"
    status=0
    (cd "${dest}" && "./bin/${command}") >"${stdout}" 2>"${stdout}.err" || status=$?
    assert_status "${status}" 0 "${command} skip exits 0"
    assert_eq "$(cat "${log}")" "" "${command} skip does not call docker-run"
    rm -rf "${dest}" "${stdout}" "${stdout}.err"
  done
  unset DOCKER_RUN_LOG
}

test_test_and_composer_install_do_not_skip() {
  local dest log
  dest="$(new_fixture)"
  log="${dest}/invocations"
  export DOCKER_RUN_LOG="${log}"
  write_projects "${dest}" $'article-reader\tpackages/article-reader'
  package_tree "${dest}" "packages/article-reader" no
  local status=0
  (cd "${dest}" && ./bin/test article-reader) >/dev/null || status=$?
  assert_status "${status}" 0 "test runs without PHP files"
  status=0
  (cd "${dest}" && ./bin/composer-install) >/dev/null || status=$?
  assert_status "${status}" 0 "composer-install runs without PHP files"
  assert_eq "$(cat "${log}")" \
    $'article-reader composer --working-dir=/workspace/packages/article-reader test\narticle-reader composer --working-dir=/workspace/packages/article-reader install' \
    "test and composer-install call composer"
  rm -rf "${dest}"
  unset DOCKER_RUN_LOG
}

test_missing_composer_json_fails_that_job_only() {
  local dest log
  dest="$(new_fixture)"
  log="${dest}/invocations"
  export DOCKER_RUN_LOG="${log}"
  : > "${log}"
  write_projects "${dest}" \
    $'broken\tpackages/broken' \
    $'article-reader\tpackages/article-reader'
  package_tree "${dest}" "packages/article-reader" yes
  mkdir -p "${dest}/packages/broken"
  local stdout status
  stdout="$(mktemp)"
  status=0
  (cd "${dest}" && ./bin/quality) >"${stdout}" 2>"${stdout}.err" || status=$?
  assert_status "${status}" 1 "missing composer.json fails the launcher"
  assert_eq "$(cat "${stdout}")" \
    $'broken\nPackage broken has no composer.json at packages/broken/composer.json\narticle-reader\nOK-article-reader' \
    "the package with a manifest still runs"
  rm -rf "${dest}" "${stdout}" "${stdout}.err"
  unset DOCKER_RUN_LOG
}

test_named_package_forwards_composer_args() {
  local dest log
  dest="$(new_fixture)"
  log="${dest}/invocations"
  export DOCKER_RUN_LOG="${log}"
  write_projects "${dest}" $'article-reader\tpackages/article-reader'
  package_tree "${dest}" "packages/article-reader" yes
  (cd "${dest}" && ./bin/cs-fix article-reader --diff) >/dev/null
  assert_eq "$(cat "${log}")" \
    "article-reader composer --working-dir=/workspace/packages/article-reader cs-fix --diff" \
    "arguments after the package name reach composer"
  rm -rf "${dest}"
  unset DOCKER_RUN_LOG
}

test_composer_requires_a_package_name() {
  local dest log
  dest="$(new_fixture)"
  log="${dest}/invocations"
  export DOCKER_RUN_LOG="${log}"
  : > "${log}"
  write_projects "${dest}" $'article-reader\tpackages/article-reader'
  local stderr status
  stderr="$(mktemp)"
  status=0
  (cd "${dest}" && ./bin/composer) >"${stderr}.out" 2>"${stderr}" || status=$?
  assert_status "${status}" 1 "composer without a package exits 1"
  assert_eq "$(cat "${stderr}")" $'Usage: ./bin/composer <package> [composer-args...]\narticle-reader' \
    "composer without a package prints usage and the known names"
  assert_eq "$(cat "${log}")" "" "composer without a package does not call docker-run"
  (cd "${dest}" && ./bin/composer article-reader update --lock) >/dev/null
  assert_eq "$(cat "${log}")" \
    "article-reader composer --working-dir=/workspace/packages/article-reader update --lock" \
    "composer forwards arguments after the package name"
  rm -rf "${dest}" "${stderr}" "${stderr}.out"
  unset DOCKER_RUN_LOG
}

test_projects_file_lists_only_article_reader() {
  require_file "${REPO}/bin/projects" || return 0
  local got
  got="$(cat "${REPO}/bin/projects")"
  assert_eq "${got}" $'article-reader\tpackages/article-reader' \
    "bin/projects lists only article-reader"
}

# --- docker-run ---

install_fake_docker() {
  local bin_dir="$1"
  mkdir -p "${bin_dir}"
  cat > "${bin_dir}/docker" << 'EOF'
#!/usr/bin/env bash
set -euo pipefail
if [[ "$1" == "image" && "$2" == "inspect" ]]; then
  if [[ "${DOCKER_IMAGE_MISSING:-}" == "1" ]]; then
    exit 1
  fi
  exit 0
fi
if [[ "$1" == "info" ]]; then
  printf '%s\n' '["name=rootless"]'
  exit 0
fi
if [[ "$1" == "run" ]]; then
  printf '%s\n' "$*" >> "${DOCKER_BIN_LOG:?}"
  exit 0
fi
printf 'unexpected docker %s\n' "$*" >&2
exit 99
EOF
  chmod +x "${bin_dir}/docker"
}

test_docker_run_sets_package_workdir_and_composer_home() {
  local dest fake log stdout stderr status
  dest="$(mktemp -d)"
  mkdir -p "${dest}/bin/lib" "${dest}/packages/article-reader"
  cp "${REPO}/bin/lib/projects.sh" "${dest}/bin/lib/projects.sh"
  cp "${REPO}/bin/docker-run" "${dest}/bin/docker-run"
  chmod +x "${dest}/bin/docker-run"
  printf '%s\n' $'article-reader\tpackages/article-reader' > "${dest}/bin/projects"
  fake="${dest}/fake-bin"
  log="${dest}/docker.log"
  install_fake_docker "${fake}"
  export DOCKER_BIN_LOG="${log}"
  stdout="$(mktemp)"
  stderr="$(mktemp)"
  status=0
  PATH="${fake}:${PATH}" "${dest}/bin/docker-run" article-reader php -v >"${stdout}" 2>"${stderr}" || status=$?
  assert_status "${status}" 0 "docker-run exits 0 when the image exists"
  local recorded
  recorded="$(cat "${log}")"
  local root_real
  root_real="$(cd "${dest}" && pwd)"
  case "${recorded}" in
    *" -v ${root_real}:/workspace "*"-w /workspace/packages/article-reader "*"COMPOSER_HOME=/workspace/packages/article-reader/.composer "*" log-read-php php -v")
      ;;
    *)
      fail "docker-run mount, workdir, COMPOSER_HOME, or command was wrong: ${recorded}"
      ;;
  esac
  rm -rf "${dest}" "${stdout}" "${stderr}"
  unset DOCKER_BIN_LOG
}

test_docker_run_usage_and_unknown_package_and_missing_image() {
  local dest fake stderr status
  dest="$(mktemp -d)"
  mkdir -p "${dest}/bin/lib"
  cp "${REPO}/bin/lib/projects.sh" "${dest}/bin/lib/projects.sh"
  cp "${REPO}/bin/docker-run" "${dest}/bin/docker-run"
  chmod +x "${dest}/bin/docker-run"
  printf '%s\n' $'article-reader\tpackages/article-reader' > "${dest}/bin/projects"
  fake="${dest}/fake-bin"
  install_fake_docker "${fake}"
  stderr="$(mktemp)"
  status=0
  PATH="${fake}:${PATH}" "${dest}/bin/docker-run" >"${stderr}.out" 2>"${stderr}" || status=$?
  assert_status "${status}" 1 "docker-run without arguments exits 1"
  assert_eq "$(cat "${stderr}")" "Usage: ./bin/docker-run <package> <command> [args...]" \
    "docker-run usage"
  status=0
  PATH="${fake}:${PATH}" "${dest}/bin/docker-run" web-app php -v >"${stderr}.out" 2>"${stderr}" || status=$?
  assert_status "${status}" 1 "docker-run unknown package exits 1"
  assert_eq "$(cat "${stderr}")" $'Unknown package: web-app\narticle-reader' \
    "docker-run unknown package prints known names"
  export DOCKER_IMAGE_MISSING=1
  export DOCKER_BIN_LOG="${dest}/docker.log"
  : > "${DOCKER_BIN_LOG}"
  status=0
  PATH="${fake}:${PATH}" "${dest}/bin/docker-run" article-reader php -v >"${stderr}.out" 2>"${stderr}" || status=$?
  assert_status "${status}" 1 "missing image exits 1"
  assert_eq "$(cat "${stderr}")" "Docker image 'log-read-php' not found. Build it with: ./bin/docker-build" \
    "missing image message"
  assert_eq "$(cat "${DOCKER_BIN_LOG}")" "" "missing image does not docker run"
  rm -rf "${dest}" "${stderr}" "${stderr}.out"
  unset DOCKER_IMAGE_MISSING DOCKER_BIN_LOG
}

test_projects_read_ignores_blanks_and_comments
test_projects_path_for_unknown_name
test_quality_unknown_package_prints_known_names
test_quality_one_package_calls_composer_script
test_quality_all_packages_and_parallel_failure
test_quality_skips_package_without_php
test_cs_check_cs_fix_and_phpstan_skip_too
test_test_and_composer_install_do_not_skip
test_missing_composer_json_fails_that_job_only
test_named_package_forwards_composer_args
test_composer_requires_a_package_name
test_projects_file_lists_only_article_reader
test_docker_run_sets_package_workdir_and_composer_home
test_docker_run_usage_and_unknown_package_and_missing_image

if [[ "${FAILURES}" -ne 0 ]]; then
  printf '%s failure(s)\n' "${FAILURES}" >&2
  exit 1
fi

printf 'launcher tests passed\n'
