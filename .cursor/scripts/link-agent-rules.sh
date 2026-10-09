#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
src_dir="${root}/.agents/rules"
dest_dir="${root}/.cursor/rules"

mkdir -p "${dest_dir}"

shopt -s nullglob
for src in "${src_dir}"/*; do
  [[ -f "${src}" ]] || continue

  name="$(basename "${src}")"
  stem="${name%.*}"
  dest="${dest_dir}/${stem}.mdc"

  if [[ -e "${dest}" || -L "${dest}" ]]; then
    printf 'skip %s\n' "${stem}.mdc"
    continue
  fi

  ln -s "../../.agents/rules/${name}" "${dest}"
  printf 'link %s\n' "${stem}.mdc"
done
