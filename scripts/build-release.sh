#!/usr/bin/env bash
#
# Builds a XenForo-ready upload package from the repository's
# src/addons/MEU15/ApiKeyManager/ source tree.
#
# Output:
#   dist/upload/src/addons/MEU15/ApiKeyManager/  (extract to XenForo root)
#   dist/15th-meu-api-key-manager-<version>.zip  (same tree, zipped)
#
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd -P)"
REPO_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd -P)"

SRC_DIR="${REPO_ROOT}/src"
ADDON_SRC="${SRC_DIR}/addons/MEU15/ApiKeyManager"
ADDON_JSON="${ADDON_SRC}/addon.json"
DIST_DIR="${REPO_ROOT}/dist"
UPLOAD_DIR="${DIST_DIR}/upload"
ADDON_DEST="${UPLOAD_DIR}/src/addons/MEU15/ApiKeyManager"

if [[ ! -f "${ADDON_JSON}" ]]; then
    echo "error: ${ADDON_JSON} not found — run from the API-Key-Manager repository" >&2
    exit 1
fi

# Read version_string from addon.json without hard-coding it.
if command -v python3 >/dev/null 2>&1; then
    VERSION="$(python3 -c 'import json, sys; print(json.load(open(sys.argv[1]))["version_string"])' "${ADDON_JSON}")"
else
    VERSION="$(sed -n 's/.*"version_string"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "${ADDON_JSON}" | head -n 1)"
fi

if [[ -z "${VERSION}" ]]; then
    echo "error: could not read version_string from ${ADDON_JSON}" >&2
    exit 1
fi

rm -rf "${DIST_DIR}"
mkdir -p "${UPLOAD_DIR}/src"

# The src/ tree already mirrors the XenForo upload layout — copy it
# verbatim. Repo-level files (.git, docs/, README, LICENSE, scripts/)
# live outside src/ and never ship inside the package.
cp -a "${SRC_DIR}/." "${UPLOAD_DIR}/src/"

ZIP_PATH="${DIST_DIR}/15th-meu-api-key-manager-${VERSION}.zip"

if command -v zip >/dev/null 2>&1; then
    (cd "${DIST_DIR}" && zip -qr "${ZIP_PATH}" upload)
elif command -v python3 >/dev/null 2>&1; then
    python3 - "${DIST_DIR}" "${ZIP_PATH}" <<'PY'
import os
import sys
import zipfile

dist_dir, zip_path = sys.argv[1], sys.argv[2]
with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED) as z:
    for root, dirs, files in os.walk(os.path.join(dist_dir, "upload")):
        dirs.sort()
        for name in sorted(files):
            path = os.path.join(root, name)
            z.write(path, os.path.relpath(path, dist_dir))
PY
else
    echo "error: cannot create ${ZIP_PATH} — neither 'zip' nor 'python3' is available" >&2
    exit 1
fi

echo "Built XenForo upload package:"
echo "  ${ADDON_DEST}"
echo "  ${ZIP_PATH}"
