#!/usr/bin/env bash
# Downloads the liblbug shared library for this platform into lib/.
#
#   bash tools/fetch-liblbug.sh [version] [--static]
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

echo "Fetching ${URL}"
mkdir -p "$TARGET"
curl -fsSL -o "/tmp/${ARCHIVE}" "$URL"
tar xzf "/tmp/${ARCHIVE}" -C "$TARGET"
rm -f "/tmp/${ARCHIVE}"

echo "Unpacked into ${TARGET}:"
ls -1 "$TARGET"
