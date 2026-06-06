#!/usr/bin/env bash
# Build a clean WordPress.org-ready zip of the Seonix plugin.
#
# Usage:
#   ./build.sh                # builds dist/seonix-<version>.zip
#   ./build.sh /tmp/out       # builds /tmp/out/seonix-<version>.zip
#
# Version is read from the "Version:" header of seonix.php so the zip name
# always matches the plugin metadata.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT_DIR="${1:-${SCRIPT_DIR}/dist}"
PLUGIN_SLUG="seonix"

# Pull version out of the plugin header.
VERSION="$(grep -E '^[[:space:]]*\*[[:space:]]*Version:' "${SCRIPT_DIR}/seonix.php" \
  | head -n1 | sed -E 's/.*Version:[[:space:]]+//')"

if [[ -z "${VERSION}" ]]; then
  echo "ERROR: could not parse Version from seonix.php" >&2
  exit 1
fi

# Sanity-check Stable tag in readme.txt matches.
README_TAG="$(grep -E '^Stable tag:' "${SCRIPT_DIR}/readme.txt" \
  | head -n1 | sed -E 's/^Stable tag:[[:space:]]+//')"

if [[ "${README_TAG}" != "${VERSION}" ]]; then
  echo "ERROR: Stable tag (${README_TAG}) != Version (${VERSION}). Sync them before building." >&2
  exit 1
fi

mkdir -p "${OUT_DIR}"
STAGE_DIR="$(mktemp -d)"
trap 'rm -rf "${STAGE_DIR}"' EXIT

PKG_DIR="${STAGE_DIR}/${PLUGIN_SLUG}"
mkdir -p "${PKG_DIR}"

# Stage the plugin files, honoring .distignore. rsync's --exclude-from reads
# one pattern per line; comments and blank lines are ignored automatically.
rsync -a \
  --exclude-from="${SCRIPT_DIR}/.distignore" \
  --exclude='build.sh' \
  --exclude='dist/' \
  "${SCRIPT_DIR}/" "${PKG_DIR}/"

# Build the zip from inside the stage so the archive root is "seonix/".
ZIP_PATH="${OUT_DIR}/${PLUGIN_SLUG}-${VERSION}.zip"
rm -f "${ZIP_PATH}"
( cd "${STAGE_DIR}" && zip -rq "${ZIP_PATH}" "${PLUGIN_SLUG}" )

# Report
SIZE_KB="$(du -k "${ZIP_PATH}" | cut -f1)"
FILE_COUNT="$(unzip -l "${ZIP_PATH}" | tail -n1 | awk '{print $2}')"
echo "Built: ${ZIP_PATH}"
echo "  Version:    ${VERSION}"
echo "  Size:       ${SIZE_KB} KB"
echo "  Files:      ${FILE_COUNT}"
