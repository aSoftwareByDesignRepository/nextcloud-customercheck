#!/usr/bin/env bash
# Build a Nextcloud App Store–ready customercheck-X.Y.Z.tar.gz.
#
# Works in both layouts:
#   • Standalone clone of nextcloud-customercheck (app files at repo root; this script in release/)
#   • Private monorepo where the app lives at apps/customercheck/ (script path unchanged)
#
# Usage (from anywhere inside the git tree):
#   ./release/build-appstore-archive.sh X.Y.Z
# Monorepo example:
#   ./apps/customercheck/release/build-appstore-archive.sh X.Y.Z
#
# Produces: <app>/release/customercheck-VERSION.tar.gz
#
# Notes: no npm build (js/css ship as sources) and no runtime composer deps —
# vendor/ is dev-only (phpunit/infection/ocp stubs) and is excluded entirely.
set -euo pipefail

VERSION="${1:-}"
if [[ -z "$VERSION" ]]; then
	echo "Usage: $0 <version>   (must match appinfo/info.xml)" >&2
	exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

if [[ ! -f "${APP_DIR}/appinfo/info.xml" ]] || ! grep -q '<id>customercheck</id>' "${APP_DIR}/appinfo/info.xml" 2>/dev/null; then
	echo "Expected CustomerCheck app at ${APP_DIR} (parent of release/)." >&2
	exit 1
fi

git -C "${APP_DIR}" rev-parse --show-toplevel >/dev/null 2>&1 || {
	echo "Run from inside the git repository." >&2
	exit 1
}

APP="customercheck"
OUT="${APP_DIR}/release/${APP}-${VERSION}.tar.gz"
INFO_VERSION="$(sed -n 's:[[:space:]]*<version>\([^<]*\)</version>:\1:p' "${APP_DIR}/appinfo/info.xml" | head -1)"

if [[ -z "${INFO_VERSION}" ]]; then
	echo "Could not read <version> from ${APP_DIR}/appinfo/info.xml" >&2
	exit 1
fi
if [[ "${INFO_VERSION}" != "${VERSION}" ]]; then
	echo "Version mismatch: script argument is '${VERSION}' but info.xml is '${INFO_VERSION}'." >&2
	exit 1
fi

if [[ -x "${APP_DIR}/scripts/check-db-naming.sh" ]]; then
	echo "==> DB naming convention check (${APP_DIR})"
	(cd "${APP_DIR}" && bash scripts/check-db-naming.sh)
fi

echo "==> packing ${OUT}"

PACK_PARENT="$(dirname "${APP_DIR}")"
PACK_BASE="$(basename "${APP_DIR}")"

EXCLUDES_MONOREPO=(
	--exclude="${APP}/node_modules"
	--exclude="${APP}/node_modules.broken-*"
	--exclude="${APP}/.git"
	--exclude="${APP}/.github"
	--exclude="${APP}/test-results"
	--exclude="${APP}/playwright-report"
	--exclude="${APP}/blob-report"
	--exclude="${APP}/.phpunit.result.cache"
	--exclude="${APP}/.auth"
	--exclude="${APP}/tests"
	--exclude="${APP}/scripts"
	--exclude="${APP}/build"
	--exclude="${APP}/vendor"
	--exclude="${APP}/docs"
	--exclude="${APP}/release"
	
	--exclude="${APP}/appinfo/signature.json"
	--exclude="${APP}/phpunit*.xml"
	--exclude="${APP}/playwright.config.js"
	--exclude="${APP}/vitest.config.js"
	--exclude="${APP}/infection*.json"
	--exclude="${APP}/infection*.html"
	--exclude="${APP}/infection*.log"
	--exclude="${APP}/package.json"
	--exclude="${APP}/package-lock.json"
	--exclude="${APP}/composer.lock"
	--exclude="${APP}/*.log"
	--exclude="${APP}/.vscode"
	--exclude="${APP}/.idea"
	--exclude="${APP}/.DS_Store"
)

EXCLUDES_STANDALONE=(
	--exclude='node_modules'
	--exclude='node_modules.broken-*'
	--exclude='.git'
	--exclude='.github'
	--exclude='test-results'
	--exclude='playwright-report'
	--exclude='blob-report'
	--exclude='.phpunit.result.cache'
	--exclude='.auth'
	--exclude='tests'
	--exclude='scripts'
	--exclude='build'
	--exclude='vendor'
	--exclude='docs'
	--exclude='release'
	
	--exclude='appinfo/signature.json'
	--exclude='phpunit*.xml'
	--exclude='playwright.config.js'
	--exclude='vitest.config.js'
	--exclude='infection*.json'
	--exclude='infection*.html'
	--exclude='infection*.log'
	--exclude='package.json'
	--exclude='package-lock.json'
	--exclude='composer.lock'
	--exclude='*.log'
	--exclude='.vscode'
	--exclude='.idea'
	--exclude='.DS_Store'
)

if [[ "${PACK_BASE}" == "${APP}" ]]; then
	# Monorepo (…/apps/customercheck) or standalone clone checked out as …/customercheck
	(cd "${PACK_PARENT}" && tar "${EXCLUDES_MONOREPO[@]}" -czf "${OUT}" "${APP}")
else
	# Standalone clone with another directory name (e.g. nextcloud-customercheck): archive top folder must be customercheck/
	(cd "${APP_DIR}" && tar --transform "s,^,${APP}/," "${EXCLUDES_STANDALONE[@]}" -czf "${OUT}" .)
fi

echo "Done: ${OUT}"
echo "Next: sha256sum / sha512sum, openssl signature (see nextcloud-development ready2publish/APPSTORE-RELEASE.md)"
