#!/usr/bin/env bash
# Prepare a fresh worktree: Composer dependencies and the liblbug shared library.
# Called by bin/worktree.sh after a new worktree is created.
# No test containers are started: the suites run on the host (make docker-test is on demand).
# The native extension is not built here; run `make ext` when you change ext/.
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

composer install --no-interaction --prefer-dist

if [[ ! -f lib/lbug.h ]]; then
  bash tools/fetch-liblbug.sh
fi
