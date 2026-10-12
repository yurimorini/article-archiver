#!/usr/bin/env bash
# Shared docker run flags for one package. Writes the global array run_args.
# Does not add the image name, the command, or a publish port.

container_fill_run_args() {
  local root_dir="$1"
  local package_rel="$2"
  local security_options

  run_args=(
    --rm
    -v "${root_dir}:/workspace"
    -w "/workspace/${package_rel}"
    -e "COMPOSER_HOME=/workspace/${package_rel}/.composer"
    -e HOME=/workspace
  )

  security_options="$(docker info --format '{{json .SecurityOptions}}')"
  if [[ "${security_options}" == *'"name=rootless"'* ]]; then
    # Container uid 0 is the host user. The host uid is a different user inside
    # the namespace, so Git rejects /workspace and Composer cannot write vendor/.
    # Slirp also blocks host loopback, so Xdebug cannot reach an IDE on
    # 127.0.0.1:9003. Stay off unless the caller sets XDEBUG_MODE.
    run_args+=(-e COMPOSER_ALLOW_SUPERUSER=1)
    if [[ -n "${XDEBUG_MODE+x}" ]]; then
      run_args+=(-e "XDEBUG_MODE=${XDEBUG_MODE}")
      if [[ "${XDEBUG_MODE}" != "off" ]]; then
        run_args+=(-e "XDEBUG_CONFIG=${XDEBUG_CONFIG:-client_host=10.0.2.2}")
      fi
    else
      run_args+=(-e XDEBUG_MODE=off)
    fi
  else
    run_args+=(
      --user "$(id -u):$(id -g)"
      --add-host=host.docker.internal:host-gateway
    )
  fi
}
