#!/usr/bin/env bash
# Downloads the liblbug shared library for this platform into lib/.
#
#   bash tools/fetch-liblbug.sh [version] [--static]
#
# The archive is checked against a pinned SHA-256 before anything is unpacked, and it is
# unpacked into a scratch directory that is validated before the files reach lib/. A version
# without a pinned checksum is refused; to use one, pass the digest of the published archive
# in LIBLBUG_SHA256 (add it to the table below when the version becomes the default).
set -euo pipefail

VERSION="${1:-0.19.1}"
VARIANT="liblbug"
if [[ "${2:-}" == "--static" ]]; then
    VARIANT="liblbug-static"
fi

case "$(uname -s)-$(uname -m)" in
    Darwin-arm64)  PLATFORM="osx-arm64" ;;
    Darwin-x86_64) PLATFORM="osx-x86_64" ;;
    Linux-aarch64) PLATFORM="linux-aarch64" ;;
    Linux-x86_64)  PLATFORM="linux-x86_64" ;;
    *) echo "Unsupported platform: $(uname -s)-$(uname -m)" >&2; exit 1 ;;
esac

# The static Linux builds carry a -compat/-perf suffix the shared ones do not.
if [[ "$VARIANT" == "liblbug-static" && "$PLATFORM" == linux-* ]]; then
    PLATFORM="${PLATFORM}-compat"
fi

ARCHIVE="${VARIANT}-${PLATFORM}.tar.gz"
URL="https://github.com/LadybugDB/ladybug/releases/download/v${VERSION}/${ARCHIVE}"
TARGET="$(cd "$(dirname "$0")/.." && pwd)/lib"

# SHA-256 of every archive this script is known to fetch, as published with the release
# (`gh release view v<version> -R LadybugDB/ladybug --json assets`). Keep it in this file:
# the script is also run on its own, with no other file of the repository next to it.
pinned_sha256() {
    case "$1" in
        "0.19.1/liblbug-linux-aarch64.tar.gz") echo "b07df2cd533c3976a2a3025866d6420a5f35514d0a822ecc4b2902d55b4725b7" ;;
        "0.19.1/liblbug-linux-x86_64.tar.gz") echo "ed263ae913f68cb0ddba0b98548b58edaac49929766d03bdaaa83be46c68847d" ;;
        "0.19.1/liblbug-osx-arm64.tar.gz") echo "276ce32705fb01f3bf27dcffa053dd181f5bc96628760e961bece46cf85b770e" ;;
        "0.19.1/liblbug-osx-x86_64.tar.gz") echo "0f941f9f983f0184a177e938f0816d3aaa71266fe6d87fef2c5023cebe03c20a" ;;
        "0.19.1/liblbug-static-linux-aarch64-compat.tar.gz") echo "a357add94b764336da41aa0d3f2406c13bf7648849e91e8f172608041a6ceaac" ;;
        "0.19.1/liblbug-static-linux-aarch64-perf.tar.gz") echo "f4b1af814caca6a9cfa84fd8de018034ccb3344f8838d1265457177b3757ebe2" ;;
        "0.19.1/liblbug-static-linux-x86_64-compat.tar.gz") echo "6d706ddb7317f3d816685bd616a252df002ddeb6f270a3a7eb875a301f3aa266" ;;
        "0.19.1/liblbug-static-linux-x86_64-perf.tar.gz") echo "098106eea694d43b7b32299b1f59a165d8a882790de73ed738b176a42b6c174a" ;;
        "0.19.1/liblbug-static-osx-arm64.tar.gz") echo "9d8bf7fd2a2b715e419db1f087f57777fd9413e214abdf32fa60ca3a9e51d883" ;;
        "0.19.1/liblbug-static-osx-x86_64.tar.gz") echo "8ae8597da0295b14a06ee89cb632ab44c5f0e834be9576689d706eea16159f79" ;;
        *) return 1 ;;
    esac
}

if [[ -n "${LIBLBUG_SHA256:-}" ]]; then
    EXPECTED="$LIBLBUG_SHA256"
elif ! EXPECTED="$(pinned_sha256 "${VERSION}/${ARCHIVE}")"; then
    echo "No pinned SHA-256 for ${VERSION}/${ARCHIVE}." >&2
    echo "Refusing to unpack an unverified archive; set LIBLBUG_SHA256 to the published digest." >&2
    exit 1
fi

sha256_of() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | cut -d' ' -f1
    else
        shasum -a 256 "$1" | cut -d' ' -f1
    fi
}

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT


echo "Fetching ${URL}"
curl -fsSL -o "${WORK}/${ARCHIVE}" "$URL"

ACTUAL="$(sha256_of "${WORK}/${ARCHIVE}")"
if [[ "$ACTUAL" != "$EXPECTED" ]]; then
    echo "SHA-256 mismatch for ${ARCHIVE}: expected ${EXPECTED}, got ${ACTUAL}." >&2
    exit 1
fi

# Reject absolute paths and `..` components before extracting anything.
while IFS= read -r entry; do
    case "$entry" in
        /* | .. | ../* | */.. | */../*)
            echo "Unsafe path in ${ARCHIVE}: ${entry}" >&2
            exit 1
            ;;
    esac
done < <(tar tzf "${WORK}/${ARCHIVE}")

mkdir "${WORK}/extract"
tar xzf "${WORK}/${ARCHIVE}" -C "${WORK}/extract"

# Symlinks may only point at a sibling file (liblbug.so -> liblbug.so.0); hard links and
# special files do not occur in these archives.
while IFS= read -r -d '' path; do
    link="$(readlink "$path")"
    case "$link" in
        /* | */* | ..)
            echo "Unsafe symlink in ${ARCHIVE}: ${path#"${WORK}/extract/"} -> ${link}" >&2
            exit 1
            ;;
    esac
done < <(find "${WORK}/extract" -type l -print0)

if [[ -n "$(find "${WORK}/extract" ! -type f ! -type l ! -type d -print -quit)" ]]; then
    echo "Unexpected special file in ${ARCHIVE}." >&2
    exit 1
fi

mkdir -p "$TARGET"
find "${WORK}/extract" -mindepth 1 -maxdepth 1 -exec mv -f {} "$TARGET"/ \;

echo "Unpacked into ${TARGET}:"
ls -1 "$TARGET"
