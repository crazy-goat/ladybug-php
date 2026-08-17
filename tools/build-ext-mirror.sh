#!/usr/bin/env bash
# Assembles crazy-goat/ladybug-ext — the PIE package — out of this repository's ext/ directory.
#
#   bash tools/build-ext-mirror.sh              assemble into build/ext-mirror and stop
#   bash tools/build-ext-mirror.sh --push v0.4.0  ... and publish it as that tag
#
# Why a second repository at all: PIE requires an extension's Composer package name to differ
# from any regular package's, "even if they have different type fields", and Packagist reads a
# composer.json only at a repository root. So the extension cannot be a PIE package from inside
# ladybug-php no matter how its files are arranged.
#
# It is generated rather than maintained. ext/ here stays the only place the C sources are
# edited; each release overwrites the mirror with one commit and tags it, so the mirror carries
# no history worth preserving and never diverges. ext/composer.json is written for the mirror's
# root — that is why it names ladybug-ext and not ladybug-php.
set -euo pipefail

REPO="crazy-goat/ladybug-ext"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/build/ext-mirror"

push=0
tag=""
for arg in "$@"; do
    case "$arg" in
        --push) push=1 ;;
        v*) tag="$arg" ;;
        *) echo "usage: $0 [--push] [vX.Y.Z]" >&2; exit 2 ;;
    esac
done

# -- assemble ------------------------------------------------------------------------------

rm -rf "$OUT"
mkdir -p "$OUT"

# Listed rather than copied wholesale: ext/ also accumulates phpize output, .libs, modules and a
# build-mode marker, and shipping any of that in a release archive is how a package ends up
# carrying someone else's object files.
for file in config.m4 composer.json ladybug.c ladybug_value.c php_ladybug.h ladybug_arginfo.h ladybug.map; do
    cp "$ROOT/ext/$file" "$OUT/$file"
done
cp -R "$ROOT/ext/tests" "$OUT/tests"
cp "$ROOT/LICENSE" "$OUT/LICENSE"

version="$(sed -n 's/#define PHP_LADYBUG_VERSION "\([^"]*\)".*/\1/p' "$ROOT/ext/php_ladybug.h")"
liblbug="$(sed -n 's/#define LADYBUG_LIBLBUG_VERIFIED *"\([^"]*\)".*/\1/p' "$ROOT/ext/php_ladybug.h")"
[ -n "$version" ] && [ -n "$liblbug" ] \
    || { echo "could not read the versions out of ext/php_ladybug.h" >&2; exit 1; }

cat > "$OUT/README.md" <<EOF
# ladybug-ext

The native PHP extension for [LadybugDB](https://github.com/LadybugDB/ladybug), installable
with [PIE](https://github.com/php/pie). Verified against liblbug ${liblbug}.

**This repository is generated.** The sources live in
[crazy-goat/ladybug-php](https://github.com/crazy-goat/ladybug-php) under \`ext/\`, and every
release overwrites this mirror with a single commit — issues and pull requests belong there.

## Installing

The extension links against liblbug, so the library has to be on the machine before the build.
For the static link, which is the one to use on Linux:

\`\`\`bash
curl -sL https://raw.githubusercontent.com/crazy-goat/ladybug-php/main/tools/fetch-liblbug.sh \\
  | bash -s ${liblbug} --static          # unpacks into ./lib
pie install ${REPO} --enable-ladybug-static --with-liblbug="\$PWD/lib"
\`\`\`

Or dynamically, if liblbug is already installed somewhere standard:

\`\`\`bash
pie install ${REPO}
\`\`\`

On Linux prefer the static link: a dynamically linked liblbug 0.19.x crashes the process on
\`INSTALL\` whenever another extension carrying libstdc++ (\`intl\` is enough) shares it. The
[main README](https://github.com/crazy-goat/ladybug-php#ladybugdb-extensions) has the mechanism.

Prebuilt binaries for PHP 8.2–8.5 are attached to every
[ladybug-php release](https://github.com/crazy-goat/ladybug-php/releases) if you would rather
not compile at all.

## What it gives you

\`ladybug_*\` functions, which are an internal ABI rather than an API to write against. Install
[crazy-goat/ladybug-php](https://packagist.org/packages/crazy-goat/ladybug-php) for the API; it
detects this extension and uses it automatically.

MIT, same as LadybugDB.
EOF

echo "assembled $OUT (extension version $version, liblbug $liblbug)"
ls -1 "$OUT"

# A version script that never made it into the archive would leave the static build silently
# re-exporting liblbug's libstdc++ symbols — the check is cheap and the failure is quiet.
for required in config.m4 composer.json ladybug.map LICENSE README.md; do
    test -s "$OUT/$required" || { echo "mirror is missing $required" >&2; exit 1; }
done

php -r 'exit(json_decode(file_get_contents($argv[1])) === null ? 1 : 0);' "$OUT/composer.json" \
    || { echo "composer.json is not valid JSON" >&2; exit 1; }

if [ "$push" -eq 0 ]; then
    echo "not pushing (pass --push vX.Y.Z to publish)"
    exit 0
fi

# -- publish -------------------------------------------------------------------------------

[ -n "$tag" ] || { echo "--push needs a tag, e.g. --push v$version" >&2; exit 2; }

if [ "${tag#v}" != "$version" ]; then
    echo "tag $tag does not match PHP_LADYBUG_VERSION $version" >&2
    exit 1
fi

git -C "$ROOT" diff --quiet && git -C "$ROOT" diff --cached --quiet \
    || { echo "the working tree is dirty; the mirror must match a committed state" >&2; exit 1; }

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

gh repo clone "$REPO" "$work/mirror" -- --quiet
cd "$work/mirror"

# Everything except .git: the mirror is a snapshot, so a file dropped from ext/ has to disappear
# here too rather than linger from the previous release.
find . -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +
cp -R "$OUT/." .

git add -A
source_commit="$(git -C "$ROOT" rev-parse --short HEAD)"
if git diff --cached --quiet; then
    echo "mirror is already up to date; tagging only"
else
    git commit -q -m "Mirror ladybug-php $tag ($source_commit)"
fi

git tag -a "$tag" -m "ladybug-ext $tag, generated from ladybug-php $source_commit"
git push -q origin HEAD
git push -q origin "$tag"

echo "pushed $REPO $tag"
