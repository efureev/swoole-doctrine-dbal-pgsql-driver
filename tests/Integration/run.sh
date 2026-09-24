#!/usr/bin/env bash
#
# Прогоняет набор `integration` на каждом образе матрицы поверх общего Postgres из compose.yaml.
#
#   tests/Integration/run.sh                         # вся матрица, весь набор
#   tests/Integration/run.sh --filter Concurrency    # любые аргументы phpunit
#   SWOOLE_IMAGES="phpswoole/swoole:6.2.3-php8.5" tests/Integration/run.sh
#
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

# ZTS — рабочая сборка Swoole в проде (SWOOLE_THREAD); NTS доступен через SWOOLE_IMAGES.
DEFAULT_IMAGES=(
  "phpswoole/swoole:6.2.3-php8.5-zts"
)

if [[ -n "${SWOOLE_IMAGES:-}" ]]; then
  read -ra IMAGES <<< "${SWOOLE_IMAGES}"
else
  IMAGES=("${DEFAULT_IMAGES[@]}")
fi

if ! docker compose -f "${ROOT}/compose.yaml" up -d --wait postgres; then
  echo 'postgres failed to start' >&2
  exit 1
fi

declare -a RESULTS=()
STATUS=0

for base in "${IMAGES[@]}"; do
  tag="$(printf '%s' "${base}" | tr '/:.' '___')"

  printf '\n\033[1m=== %s ===\033[0m\n' "${base}"

  if ! BASE_IMAGE="${base}" BASE_TAG="${tag}" docker compose -f "${ROOT}/compose.yaml" --profile integration build app; then
    RESULTS+=("BUILD FAILED  ${base}")
    STATUS=1
    continue
  fi

  # vendor/ смонтирован с хоста и обычно уже установлен; запасной install держит чистый checkout рабочим.
  BASE_IMAGE="${base}" BASE_TAG="${tag}" docker compose -f "${ROOT}/compose.yaml" --profile integration run --rm -T app \
    sh -c '
      set -e
      [ -d vendor ] || composer install --no-progress --prefer-dist
      exec vendor/bin/phpunit --testsuite integration "$@"
    ' -- "$@"

  if [[ $? -eq 0 ]]; then
    RESULTS+=("PASS          ${base}")
  else
    RESULTS+=("FAIL          ${base}")
    STATUS=1
  fi
done

printf '\n\033[1m=== summary ===\033[0m\n'
for line in "${RESULTS[@]}"; do
  printf '  %s\n' "${line}"
done

exit "${STATUS}"
