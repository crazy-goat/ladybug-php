#!/usr/bin/env bash
# Clean up the runtime resources of this worktree.
# Called by bin/worktree-done.sh before the worktree is removed.
# This repository has no Docker Compose stack (make docker-test uses `docker run --rm`),
# so there is nothing to stop. If a compose file is ever added, stop its stack here and
# refuse to run without COMPOSE_PROJECT_NAME from .env.worktree.
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

echo "Nothing to tear down (no Docker Compose stack)."
