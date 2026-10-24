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

install_docker_run_mock() {
  local dest="$1"
  mkdir -p "${dest}/bin"
  cat > "${dest}/bin/docker-run" << 'EOF'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "${DOCKER_RUN_LOG:?}"
exit 0
EOF
  chmod +x "${dest}/bin/docker-run"
}

copy_docker_run() {
  local dest="$1"
  mkdir -p "${dest}/bin/lib" "${dest}/packages/article-reader"
  cp "${REPO}/bin/lib/container.sh" "${dest}/bin/lib/container.sh"
  cp "${REPO}/bin/docker-run" "${dest}/bin/docker-run"
  chmod +x "${dest}/bin/docker-run"
}

copy_package_run() {
  local dest="$1"
  local rel="$2"
  mkdir -p "${dest}/${rel}/bin"
  cp "${REPO}/${rel}/bin/run" "${dest}/${rel}/bin/run"
  chmod +x "${dest}/${rel}/bin/run"
}

# --- registry gone ---

test_root_dispatch_is_gone() {
  local path
  for path in \
    bin/projects \
    bin/lib/projects.sh \
    bin/lib/dispatch.sh \
    bin/test \
    bin/quality \
    bin/cs-check \
    bin/cs-fix \
    bin/phpstan \
    bin/composer \
    bin/composer-install \
    bin/docker-serve
  do
    if [[ -e "${REPO}/${path}" ]]; then
      fail "still present: ${path}"
    fi
  done
  if [[ ! -x "${REPO}/packages/article-reader/bin/run" ]]; then
    fail "missing packages/article-reader/bin/run"
  fi
  if [[ ! -x "${REPO}/packages/web-app/bin/run" ]]; then
    fail "missing packages/web-app/bin/run"
  fi
  if [[ ! -x "${REPO}/packages/web-app/bin/serve" ]]; then
    fail "missing packages/web-app/bin/serve"
  fi
}

# --- docker-run ---

test_docker_run_sets_package_workdir_and_composer_home() {
  local dest fake log stdout stderr status
  dest="$(mktemp -d)"
  copy_docker_run "${dest}"
  fake="${dest}/fake-bin"
  log="${dest}/docker.log"
  install_fake_docker "${fake}"
  export DOCKER_BIN_LOG="${log}"
  stdout="$(mktemp)"
  stderr="$(mktemp)"
  status=0
  PATH="${fake}:${PATH}" "${dest}/bin/docker-run" packages/article-reader php -v >"${stdout}" 2>"${stderr}" || status=$?
  assert_status "${status}" 0 "docker-run exits 0 when the image exists"
  if [[ ! -f "${log}" ]]; then
    fail "docker-run did not record a docker invocation: $(cat "${stderr}")"
    rm -rf "${dest}" "${stdout}" "${stderr}"
    unset DOCKER_BIN_LOG
    return 0
  fi
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

test_docker_run_usage_missing_path_and_missing_image() {
  local dest fake stderr status
  dest="$(mktemp -d)"
  copy_docker_run "${dest}"
  fake="${dest}/fake-bin"
  install_fake_docker "${fake}"
  stderr="$(mktemp)"
  export DOCKER_BIN_LOG="${dest}/docker.log"
  : > "${DOCKER_BIN_LOG}"
  status=0
  PATH="${fake}:${PATH}" "${dest}/bin/docker-run" >"${stderr}.out" 2>"${stderr}" || status=$?
  assert_status "${status}" 1 "docker-run without arguments exits 1"
  assert_eq "$(cat "${stderr}")" "Usage: ./bin/docker-run <path> <command> [args...]" \
    "docker-run usage"
  assert_eq "$(cat "${DOCKER_BIN_LOG}")" "" "usage does not docker run"
  status=0
  PATH="${fake}:${PATH}" "${dest}/bin/docker-run" packages/missing php -v >"${stderr}.out" 2>"${stderr}" || status=$?
  assert_status "${status}" 1 "docker-run missing path exits 1"
  assert_eq "$(cat "${stderr}")" "Package path not found: packages/missing" \
    "docker-run missing path message"
  assert_eq "$(cat "${DOCKER_BIN_LOG}")" "" "missing path does not docker run"
  status=0
  PATH="${fake}:${PATH}" "${dest}/bin/docker-run" ../outside php -v >"${stderr}.out" 2>"${stderr}" || status=$?
  assert_status "${status}" 1 "docker-run path outside the repository exits 1"
  assert_eq "$(cat "${stderr}")" "Package path must stay inside the repository: ../outside" \
    "docker-run rejects a path that leaves the repository"
  assert_eq "$(cat "${DOCKER_BIN_LOG}")" "" "outside path does not docker run"
  export DOCKER_IMAGE_MISSING=1
  : > "${DOCKER_BIN_LOG}"
  status=0
  PATH="${fake}:${PATH}" "${dest}/bin/docker-run" packages/article-reader php -v >"${stderr}.out" 2>"${stderr}" || status=$?
  assert_status "${status}" 1 "missing image exits 1"
  assert_eq "$(cat "${stderr}")" "Docker image 'log-read-php' not found. Build it with: ./bin/docker-build" \
    "missing image message"
  assert_eq "$(cat "${DOCKER_BIN_LOG}")" "" "missing image does not docker run"
  rm -rf "${dest}" "${stderr}" "${stderr}.out"
  unset DOCKER_IMAGE_MISSING DOCKER_BIN_LOG
}

# --- package bin/run ---

test_package_run_forwards_composer_args() {
  local dest log stdout status rel
  dest="$(mktemp -d)"
  log="${dest}/invocations"
  export DOCKER_RUN_LOG="${log}"
  : > "${log}"
  install_docker_run_mock "${dest}"
  for rel in packages/article-reader packages/web-app; do
    require_file "${REPO}/${rel}/bin/run" || continue
    copy_package_run "${dest}" "${rel}"
    stdout="$(mktemp)"
    status=0
    "${dest}/${rel}/bin/run" test -- --filter Foo >"${stdout}" 2>"${stdout}.err" || status=$?
    assert_status "${status}" 0 "${rel} bin/run exits 0"
    rm -rf "${stdout}" "${stdout}.err"
  done
  assert_eq "$(cat "${log}")" \
    $'packages/article-reader composer test -- --filter Foo\npackages/web-app composer test -- --filter Foo' \
    "bin/run calls docker-run with the package path and composer"
  rm -rf "${dest}"
  unset DOCKER_RUN_LOG
}

test_package_run_requires_an_argument() {
  local dest log stderr status
  dest="$(mktemp -d)"
  log="${dest}/invocations"
  export DOCKER_RUN_LOG="${log}"
  : > "${log}"
  install_docker_run_mock "${dest}"
  require_file "${REPO}/packages/article-reader/bin/run" || return 0
  copy_package_run "${dest}" "packages/article-reader"
  stderr="$(mktemp)"
  status=0
  "${dest}/packages/article-reader/bin/run" >"${stderr}.out" 2>"${stderr}" || status=$?
  assert_status "${status}" 1 "bin/run without arguments exits 1"
  assert_eq "$(cat "${stderr}")" "Usage: ./bin/run <composer-args...>" \
    "bin/run usage"
  assert_eq "$(cat "${log}")" "" "bin/run without arguments does not call docker-run"
  rm -rf "${dest}" "${stderr}" "${stderr}.out"
  unset DOCKER_RUN_LOG
}

# --- web-app bin/serve ---

test_serve_publishes_loopback_and_starts_php() {
  local dest fake log stdout stderr status
  dest="$(mktemp -d)"
  mkdir -p "${dest}/bin/lib" "${dest}/packages/web-app/bin"
  cp "${REPO}/bin/lib/container.sh" "${dest}/bin/lib/container.sh"
  require_file "${REPO}/packages/web-app/bin/serve" || return 0
  cp "${REPO}/packages/web-app/bin/serve" "${dest}/packages/web-app/bin/serve"
  chmod +x "${dest}/packages/web-app/bin/serve"
  fake="${dest}/fake-bin"
  log="${dest}/docker.log"
  install_fake_docker "${fake}"
  export DOCKER_BIN_LOG="${log}"
  stdout="$(mktemp)"
  stderr="$(mktemp)"
  status=0
  PATH="${fake}:${PATH}" "${dest}/packages/web-app/bin/serve" >"${stdout}" 2>"${stderr}" || status=$?
  assert_status "${status}" 0 "serve exits 0 when the image exists"
  local recorded
  recorded="$(cat "${log}")"
  case "${recorded}" in
    *"-w /workspace/packages/web-app"*) ;;
    *)
      fail "serve workdir was wrong: ${recorded}"
      ;;
  esac
  case "${recorded}" in
    *"log-read-php"*) ;;
    *)
      fail "serve image was wrong: ${recorded}"
      ;;
  esac
  case "${recorded}" in
    *" -p 127.0.0.1:8000:8000 "*) ;;
    *)
      fail "serve publish was wrong: ${recorded}"
      ;;
  esac
  case "${recorded}" in
    *"php -S 0.0.0.0:8000 -t public router.php"*) ;;
    *)
      fail "serve php command was wrong: ${recorded}"
      ;;
  esac
  case "${recorded}" in
    *"0.0.0.0:8000:8000"*)
      fail "serve must not publish 0.0.0.0:8000:8000: ${recorded}"
      ;;
  esac
  case "${recorded}" in
    *"-d"*)
      fail "serve must not detach: ${recorded}"
      ;;
  esac
  rm -rf "${dest}" "${stdout}" "${stderr}"
  unset DOCKER_BIN_LOG
}

test_serve_rejects_arguments_and_missing_image() {
  local dest fake stderr status
  dest="$(mktemp -d)"
  mkdir -p "${dest}/bin/lib" "${dest}/packages/web-app/bin"
  cp "${REPO}/bin/lib/container.sh" "${dest}/bin/lib/container.sh"
  require_file "${REPO}/packages/web-app/bin/serve" || return 0
  cp "${REPO}/packages/web-app/bin/serve" "${dest}/packages/web-app/bin/serve"
  chmod +x "${dest}/packages/web-app/bin/serve"
  fake="${dest}/fake-bin"
  install_fake_docker "${fake}"
  stderr="$(mktemp)"
  export DOCKER_BIN_LOG="${dest}/docker.log"
  : > "${DOCKER_BIN_LOG}"
  status=0
  PATH="${fake}:${PATH}" "${dest}/packages/web-app/bin/serve" web-app >"${stderr}.out" 2>"${stderr}" || status=$?
  assert_status "${status}" 1 "serve with an argument exits 1"
  assert_eq "$(cat "${stderr}")" "Usage: ./bin/serve" \
    "serve usage"
  assert_eq "$(cat "${DOCKER_BIN_LOG}")" "" "usage does not docker run"
  export DOCKER_IMAGE_MISSING=1
  : > "${DOCKER_BIN_LOG}"
  status=0
  PATH="${fake}:${PATH}" "${dest}/packages/web-app/bin/serve" >"${stderr}.out" 2>"${stderr}" || status=$?
  assert_status "${status}" 1 "missing image exits 1"
  assert_eq "$(cat "${stderr}")" "Docker image 'log-read-php' not found. Build it with: ./bin/docker-build" \
    "missing image message"
  assert_eq "$(cat "${DOCKER_BIN_LOG}")" "" "missing image does not docker run"
  rm -rf "${dest}" "${stderr}" "${stderr}.out"
  unset DOCKER_IMAGE_MISSING DOCKER_BIN_LOG
}

test_root_dispatch_is_gone
test_docker_run_sets_package_workdir_and_composer_home
test_docker_run_usage_missing_path_and_missing_image
test_package_run_forwards_composer_args
test_package_run_requires_an_argument
test_serve_publishes_loopback_and_starts_php
test_serve_rejects_arguments_and_missing_image

if [[ "${FAILURES}" -ne 0 ]]; then
  printf '%s failure(s)\n' "${FAILURES}" >&2
  exit 1
fi

printf 'launcher tests passed\n'
