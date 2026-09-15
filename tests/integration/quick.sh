#!/usr/bin/env bash
#
# Quick edit-test cycle for the MCP4j E2E suite, against a stack already left running by
# `run-tests.sh <version> --keep`. Re-runs PHPUnit in seconds instead of a full provision.
#
# Usage:
#   tests/integration/quick.sh                                  # just re-run PHPUnit
#   tests/integration/quick.sh -- --filter=SomeTest              # ...filtered
#   tests/integration/quick.sh --reinstall                       # rebuild+reinstall pkg_mcp4j first
#   tests/integration/quick.sh --reinstall -- --filter=SomeTest
#
# --reinstall runs `phing git` in the repo root, then upgrade-installs the newest
# release/pkg_mcp4j-*.zip over the kept stack's existing install (so code changes under component/ or
# plugins/ actually take effect), then fixes web root ownership again. It does NOT reinstall the
# third-party extra packages (tests/integration/inbox/extensions/*.zip) — those don't change between
# quick.sh runs.
#
# Requires a stack left up by `tests/integration/run-tests.sh <version> --keep` using the SAME
# COMPOSE_PROJECT_NAME (default mcp4j-itest, or whatever the process environment overrides it to — see
# docker/env.dist and ../README.md, "Parallel runs from git worktrees").
set -eu

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
DOCKER_DIR="${SCRIPT_DIR}/docker"
RELEASE_DIR="${REPO_ROOT}/release"

# shellcheck source=./lib.sh
. "${SCRIPT_DIR}/lib.sh"

# ---------------------------------------------------------------------------
# docker compose wrapper (supports both `docker compose` and `docker-compose`)
# ---------------------------------------------------------------------------
if docker compose version >/dev/null 2>&1; then
	DC() { docker compose "$@"; }
elif command -v docker-compose >/dev/null 2>&1; then
	DC() { docker-compose "$@"; }
else
	die "Docker Compose is not available. Install Docker Desktop or the compose plugin."
fi
command -v docker >/dev/null 2>&1 || die "Docker is not installed or not on PATH."

cd "${DOCKER_DIR}"

# ---------------------------------------------------------------------------
# Argument parsing
# ---------------------------------------------------------------------------
REINSTALL=0
PHPUNIT_ARGS=()

while [ $# -gt 0 ]; do
	case "$1" in
		--reinstall)
			REINSTALL=1
			shift
			;;
		--)
			shift
			while [ $# -gt 0 ]; do PHPUNIT_ARGS+=("$1"); shift; done
			;;
		*)
			PHPUNIT_ARGS+=("$1")
			shift
			;;
	esac
done

# ---------------------------------------------------------------------------
# Environment: same precedence rule as run-tests.sh (see lib.sh's load_env_file()) — but quick.sh never
# creates docker/.env itself, since a stack must already be up.
# ---------------------------------------------------------------------------
[ -f .env ] || die "No docker/.env found. Run tests/integration/run-tests.sh <version> --keep first."
load_env_file

export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-mcp4j-itest}"

[ -f .tokens ] || die "No docker/.tokens found. Run tests/integration/run-tests.sh <version> --keep first."
# shellcheck source=/dev/null
. ./.tokens
: "${TOKEN:?docker/.tokens has no TOKEN=... line}"
: "${LOWPRIV_TOKEN:=}"
: "${AUTHOR_TOKEN:=}"
: "${SCOPED_MANAGER_TOKEN:=}"

RUNNING="$(DC ps -q web 2>/dev/null || true)"
[ -n "${RUNNING}" ] || die "The 'web' service is not running (COMPOSE_PROJECT_NAME=${COMPOSE_PROJECT_NAME}). Run tests/integration/run-tests.sh <version> --keep first."

# ---------------------------------------------------------------------------
# Optional: rebuild pkg_mcp4j and upgrade-install it over the kept stack.
# ---------------------------------------------------------------------------
if [ "${REINSTALL}" -eq 1 ]; then
	log "Rebuilding pkg_mcp4j (phing git)"
	command -v phing >/dev/null 2>&1 || die "phing is not on PATH (needed to build pkg_mcp4j). It requires the sibling ../buildfiles checkout."
	( cd "${REPO_ROOT}" && phing git ) || die "phing git failed."

	pkg="$(ls -t "${RELEASE_DIR}"/pkg_mcp4j-*.zip 2>/dev/null | head -1 || true)"
	[ -n "${pkg}" ] || die "No pkg_mcp4j-*.zip found in ${RELEASE_DIR} after build."
	zipName="$(basename "${pkg}")"

	log "Installing ${zipName} (upgrade) via cli/joomla.php extension:install"
	DC exec -T web php cli/joomla.php extension:install --path="/app/release/${zipName}" \
		|| die "pkg_mcp4j installation failed."
	ok "pkg_mcp4j reinstalled"

	log "Fixing web root ownership (www-data)"
	DC exec -T web chown -R www-data:www-data /var/www/html \
		|| die "Could not chown the web root to www-data."
	ok "Web root ownership fixed"
fi

# ---------------------------------------------------------------------------
# PHPUnit
# ---------------------------------------------------------------------------
log "Running the integration test suite against the kept stack (project ${COMPOSE_PROJECT_NAME})"

if [ "${#PHPUNIT_ARGS[@]}" -gt 0 ]; then
	DC exec -T \
		-e MCP4J_BASE_URL="http://web/api/index.php" \
		-e MCP4J_API_TOKEN="${TOKEN}" \
		-e MCP4J_LOWPRIV_TOKEN="${LOWPRIV_TOKEN}" \
		-e MCP4J_AUTHOR_TOKEN="${AUTHOR_TOKEN}" \
		-e MCP4J_SCOPED_MANAGER_TOKEN="${SCOPED_MANAGER_TOKEN}" \
		web phpunit -c /app/phpunit-integration.xml "${PHPUNIT_ARGS[@]}"
else
	DC exec -T \
		-e MCP4J_BASE_URL="http://web/api/index.php" \
		-e MCP4J_API_TOKEN="${TOKEN}" \
		-e MCP4J_LOWPRIV_TOKEN="${LOWPRIV_TOKEN}" \
		-e MCP4J_AUTHOR_TOKEN="${AUTHOR_TOKEN}" \
		-e MCP4J_SCOPED_MANAGER_TOKEN="${SCOPED_MANAGER_TOKEN}" \
		web phpunit -c /app/phpunit-integration.xml
fi
