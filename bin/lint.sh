#!/usr/bin/env bash
# Run all static analysis, linters and formatter checks. --fix applies fixes first.
# Needs composer dependencies, clang-format, shellcheck and hadolint (CI installs them).
set -uo pipefail
cd "$(dirname "$0")/.." || exit 1

FIX=0
[ "${1:-}" = "--fix" ] && FIX=1
failed=()

step() {
    local name="$1"; shift
    echo "==> $name"
    "$@" || failed+=("$name")
}

if [ "$FIX" = 1 ]; then
    vendor/bin/rector process --no-progress-bar || true
    vendor/bin/php-cs-fixer fix || true  # exits non-zero when it changed files
    git ls-files -z 'ext/*.c' 'ext/*.h' | xargs -0 -r clang-format -i || true
fi

step "php-cs-fixer" vendor/bin/php-cs-fixer fix --dry-run --diff
step "phpstan" vendor/bin/phpstan analyse --no-progress
step "rector" vendor/bin/rector process --dry-run --no-progress-bar
step "clang-format" bash -c "git ls-files -z 'ext/*.c' 'ext/*.h' | xargs -0 -r clang-format --dry-run --Werror"
# Every tracked shell script: by extension, plus extensionless files with a shell shebang
# (git hooks and the like).
shell_scripts() {
    local file
    while IFS= read -r -d '' file; do
        case "$file" in
            *.sh) printf '%s\0' "$file"; continue ;;
            *.*) continue ;;
        esac
        if [ -f "$file" ] && head -n 1 "$file" 2>/dev/null | grep -aqE '^#!.*[/ ](ba|da|k)?sh( |$)'; then
            printf '%s\0' "$file"
        fi
    done < <(git ls-files -z)
}
step "shellcheck" bash -c "$(declare -f shell_scripts); shell_scripts | xargs -0 -r shellcheck"
step "hadolint" bash -c "git ls-files -z '*Dockerfile*' | xargs -0 -r hadolint"

if [ "${#failed[@]}" -gt 0 ]; then
    echo "Failed: ${failed[*]}" >&2
    exit 1
fi
echo "All checks passed."
