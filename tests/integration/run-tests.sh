#!/usr/bin/env bash
#
# Integration test orchestrator for MCP4j.
#
# Provisions a throwaway Joomla site inside Docker, builds and installs
# pkg_mcp4j, mints a Joomla API token and runs the PHPUnit integration suite
# (phpunit-integration.xml) against it over real HTTP.
#
# Usage:
#   tests/integration/run-tests.sh                 # the whole JOOMLA_MATRIX
#   tests/integration/run-tests.sh 6                # only Joomla 6 (PHP from JOOMLA_MATRIX, else PHP_VERSION)
#   tests/integration/run-tests.sh 4.4 5.4          # Joomla 4.4 and 5.4
#   tests/integration/run-tests.sh 6 --keep         # leave the stack running
#   tests/integration/run-tests.sh 6 -- --filter=InitializeTest
#
# Arguments before a bare `--` that look like a Joomla version (a full
# version, a branch, or a bare major — 4, 4.4, 5.4.1, 6, ...) select which
# Joomla versions to test. With none given, every `joomla:php` pair in
# JOOMLA_MATRIX (committed: buildfiles/docker/e2e/matrix plus docker/matrix.env,
# both sourced after docker/.env) is tested — see tests/integration/README.md, "Why these versions", for why
# it contains exactly the entries it does. A version given explicitly that
# isn't in JOOMLA_MATRIX falls back to the matrix's default PHP_VERSION.
#
# `--keep` leaves the Docker stack running after the run instead of tearing
# it down (only meaningful for a single-version run — see run_matrix()).
# Anything after a bare `--` (or any other unrecognised argument) is passed
# through to PHPUnit.
set -eu

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
DOCKER_DIR="${SCRIPT_DIR}/docker"
INBOX_DIR="${SCRIPT_DIR}/inbox"
WWW_DIR="${DOCKER_DIR}/www"
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
REQUESTED_VERSIONS=()
KEEP=0
PHPUNIT_ARGS=()

while [ $# -gt 0 ]; do
	case "$1" in
		--keep)
			KEEP=1
			shift
			;;
		--)
			shift
			while [ $# -gt 0 ]; do PHPUNIT_ARGS+=("$1"); shift; done
			;;
		[0-9]*)
			REQUESTED_VERSIONS+=("$1")
			shift
			;;
		*)
			PHPUNIT_ARGS+=("$1")
			shift
			;;
	esac
done

# ---------------------------------------------------------------------------
# Environment: create .env from template, load it. See lib.sh's load_env_file()
# for why: values already set in the process environment (e.g. a parallel git
# worktree's COMPOSE_PROJECT_NAME/WEB_PORT) must win over docker/.env.
# ---------------------------------------------------------------------------
[ -f .env ] || { cp env.dist .env; log "Created .env from env.dist"; }
load_env_file

: "${JOOMLA_MATRIX:?JOOMLA_MATRIX is not set (see docker/matrix.env and buildfiles/docker/e2e/matrix)}"
: "${DB_IMAGE:=mariadb:11.4}"
: "${DB_ROOT_PASSWORD:=rootpw}"
: "${DB_NAME:=joomla_test}"
: "${DB_USER:=root}"
: "${DB_PASSWORD:=rootpw}"
: "${DB_PREFIX:=jtest_}"
: "${WEB_PORT:=8180}"

# Compose precedence is `-p` (not used here) > COMPOSE_PROJECT_NAME > the compose file's own `name:`
# (mcp4j-itest, see docker-compose.yml) — export a default so every DC call below is pinned to a
# project name, whether or not docker/.env or the process environment set one.
export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-mcp4j-itest}"

# Looks up the PHP version paired with a Joomla version in JOOMLA_MATRIX. Prints nothing
# (and returns non-zero) if that exact string isn't a matrix entry's Joomla side.
php_for_matrix_entry() {
	local want="$1" pair
	for pair in ${JOOMLA_MATRIX}; do
		if [ "${pair%%:*}" = "${want}" ]; then
			printf '%s' "${pair#*:}"
			return 0
		fi
	done
	return 1
}

# Resolve the list of "joomla:php" pairs to run.
PAIRS=()
if [ "${#REQUESTED_VERSIONS[@]}" -eq 0 ]; then
	log "No version given — running the whole matrix: ${JOOMLA_MATRIX}"
	for pair in ${JOOMLA_MATRIX}; do
		PAIRS+=("${pair}")
	done
else
	for v in "${REQUESTED_VERSIONS[@]}"; do
		if phpv="$(php_for_matrix_entry "${v}")"; then
			PAIRS+=("${v}:${phpv}")
		else
			warn "Joomla ${v} is not a JOOMLA_MATRIX entry; using PHP_VERSION=${PHP_VERSION} for it."
			PAIRS+=("${v}:${PHP_VERSION}")
		fi
	done
fi

# ---------------------------------------------------------------------------
# Admin credentials, used consistently across install + token provisioning.
# ---------------------------------------------------------------------------
ADMIN_NAME="MCP4j Integration Test"
ADMIN_USER="admin"
ADMIN_PASSWORD="Integr8tion-Test-Passw0rd!"
ADMIN_EMAIL="admin@example.com"
SITE_NAME="MCP4j Integration Tests"

mkdir -p "${WWW_DIR}" "${INBOX_DIR}"

# ---------------------------------------------------------------------------
# Stack bring-up / tear-down
# ---------------------------------------------------------------------------
# The database is started once and reused (reset_database wipes it) across every matrix
# entry; only `web` is rebuilt per entry, since that's the piece whose PHP version varies.
start_database() {
	log "Starting the database (${DB_IMAGE})"
	DC up -d db

	log "Waiting for the database to become healthy"
	local tries=0
	until [ "$(DC ps -q db | xargs -I{} docker inspect -f '{{.State.Health.Status}}' {} 2>/dev/null)" = "healthy" ]; do
		tries=$((tries + 1))
		[ "${tries}" -gt 60 ] && die "Database did not become healthy in time."
		sleep 2
	done
	ok "Database is healthy"
}

# Rebuilds and (re)starts `web` for the given PHP version.
build_and_start_web() {
	local phpVersion="$1"
	log "Building and starting the web container (PHP ${phpVersion})"
	PHP_VERSION="${phpVersion}" DC build web >/dev/null
	PHP_VERSION="${phpVersion}" DC up -d --force-recreate web

	log "Waiting for the web server to answer"
	local wtries=0
	until curl -s -o /dev/null "http://localhost:${WEB_PORT}/" 2>/dev/null; do
		wtries=$((wtries + 1))
		[ "${wtries}" -gt 30 ] && die "The web server did not become reachable on port ${WEB_PORT}."
		sleep 2
	done
	ok "Web server is up (PHP ${phpVersion})"
}

teardown_stack() {
	log "Bringing the Docker stack down"
	DC down -v >/dev/null 2>&1 || true
	ok "Stack down"
}

clean_www() {
	log "Cleaning web root"
	rm -rf "${WWW_DIR:?}"/* 2>/dev/null || \
		docker run --rm -v "${WWW_DIR}:/work" alpine:3 sh -c 'rm -rf /work/*' || true
}

reset_database() {
	log "Resetting database ${DB_NAME}"
	# MariaDB 11.x ships the `mariadb` client; the legacy `mysql` symlink is gone.
	DC exec -T db mariadb -uroot -p"${DB_ROOT_PASSWORD}" \
		-e "DROP DATABASE IF EXISTS \`${DB_NAME}\`; CREATE DATABASE \`${DB_NAME}\`;" \
		|| die "Could not reset the database."
}

# ---------------------------------------------------------------------------
# pkg_mcp4j build / install / token provisioning
# ---------------------------------------------------------------------------
PACKAGE_ZIP=""
SKIP_BUILD=0

build_package() {
	if [ "${SKIP_BUILD}" -eq 1 ] && [ -n "${PACKAGE_ZIP}" ]; then
		ok "Reusing $(basename "${PACKAGE_ZIP}") (built once for this matrix run)"
		return 0
	fi

	log "Building pkg_mcp4j (phing git)"
	command -v phing >/dev/null 2>&1 || die "phing is not on PATH (needed to build pkg_mcp4j). It requires the sibling ../buildfiles checkout."
	( cd "${REPO_ROOT}" && phing git ) || die "phing git failed."

	local pkg
	pkg="$(ls -t "${RELEASE_DIR}"/pkg_mcp4j-*.zip 2>/dev/null | head -1 || true)"
	[ -n "${pkg}" ] || die "No pkg_mcp4j-*.zip found in ${RELEASE_DIR} after build."
	PACKAGE_ZIP="${pkg}"
	ok "Built $(basename "${PACKAGE_ZIP}")"
}

install_package() {
	local zipName; zipName="$(basename "${PACKAGE_ZIP}")"
	log "Installing ${zipName} via cli/joomla.php extension:install"
	DC exec -T web php cli/joomla.php extension:install --path="/app/release/${zipName}" \
		|| die "pkg_mcp4j installation failed."
	ok "pkg_mcp4j installed"
}

# Installs any optional third-party extension packages (Panopticon Connector, ATS, Admin Tools, Akeeba
# Backup, ...) dropped into ${MCP4J_EXTRA_PACKAGES_DIR:-inbox/extensions}, so their tool classes'
# tests (which self-skip via requireToolsOrSkip() when absent) actually get to run. A failed install of
# one package is a warning, not a die — the tests that depend on it just skip instead of the whole
# matrix entry failing.
install_extra_packages() {
	local dir="${MCP4J_EXTRA_PACKAGES_DIR:-${INBOX_DIR}/extensions}"

	if [ ! -d "${dir}" ]; then
		log "No extra packages directory (${dir}); skipping third-party extensions."
		return 0
	fi

	shopt -s nullglob
	local -a zips=("${dir}"/*.zip)
	shopt -u nullglob

	if [ "${#zips[@]}" -eq 0 ]; then
		log "No extra packages (*.zip) in ${dir}; skipping third-party extensions."
		return 0
	fi

	log "Installing extra packages from ${dir}"
	mkdir -p "${WWW_DIR}/tmp"

	local zip name
	while IFS= read -r zip; do
		[ -n "${zip}" ] || continue
		name="$(basename "${zip}")"

		log "Installing extra package: ${name}"
		# The container can't necessarily follow a host symlink inside /app (inbox/ is one), so copy
		# the zip into the bind-mounted web root first, then install it from there.
		cp "${zip}" "${WWW_DIR}/tmp/${name}"

		# `< /dev/null` is not optional: without it, `DC exec` inherits this loop's own stdin (the
		# process-substitution fd feeding `read -r zip` below) and the docker/compose client's own
		# input handling races the loop for it — silently swallowing later lines and ending the loop
		# after one iteration. This is the classic "ssh/docker exec inside a while-read loop" pitfall.
		if DC exec -T web php cli/joomla.php extension:install --path="/var/www/html/tmp/${name}" < /dev/null; then
			ok "Installed ${name}"
		else
			warn "Failed to install ${name} (continuing — tests that depend on it will skip)."
		fi

		rm -f "${WWW_DIR}/tmp/${name}"
	done < <(printf '%s\n' "${zips[@]}" | sort)
}

# Joomla itself and every extension above are installed via `DC exec` as root, but Apache serves as
# www-data. Any tool that writes a file under the web root (configuration.php, media uploads, language
# override INI files, ...) would fail on a Linux Docker host without this.
fix_permissions() {
	log "Fixing web root ownership (www-data)"
	DC exec -T web chown -R www-data:www-data /var/www/html \
		|| die "Could not chown the web root to www-data."
	ok "Web root ownership fixed"
}

TOKEN=""

provision_token() {
	log "Provisioning a Joomla API token for ${ADMIN_USER}"
	local out
	out="$(DC exec -T web php /app/tests/integration/docker/provision-token.php "${ADMIN_USER}")" \
		|| die "Token generation failed."

	TOKEN="$(printf '%s\n' "${out}" | tr -d '\r' | awk 'NF { line = $0 } END { print line }')"

	[ -n "${TOKEN}" ] || die "Could not extract a non-empty API token from provision-token.php output."
	ok "Token acquired"
}

LOWPRIV_USER="lowpriv"
LOWPRIV_TOKEN=""

# Provisions a low-privilege ("Registered" group only) API user and mints its token, so every tool's
# ACL enforcement can be proven end to end — see provision-lowpriv-user.php's docblock for exactly what
# it grants and why a stock Joomla install refuses this user at the Web Services API layer otherwise.
provision_lowpriv() {
	log "Provisioning the low-privilege API user (${LOWPRIV_USER})"
	DC exec -T web php /app/tests/integration/docker/provision-lowpriv-user.php "${LOWPRIV_USER}" \
		|| die "Low-privilege user provisioning failed."
	ok "Low-privilege user ready"

	log "Provisioning a Joomla API token for ${LOWPRIV_USER}"
	local out
	out="$(DC exec -T web php /app/tests/integration/docker/provision-token.php "${LOWPRIV_USER}")" \
		|| die "Low-privilege token generation failed."

	LOWPRIV_TOKEN="$(printf '%s\n' "${out}" | tr -d '\r' | awk 'NF { line = $0 } END { print line }')"

	[ -n "${LOWPRIV_TOKEN}" ] || die "Could not extract a non-empty API token for ${LOWPRIV_USER}."
	ok "Low-privilege token acquired"
}

AUTHOR_USER="author"
AUTHOR_TOKEN=""

# Provisions an "Author" group (group 3) API user and mints its token — for security.md finding M3's
# live test: a stock Joomla install's Author group already has core.create but NOT core.edit.state on
# com_content (verified against a live install's #__assets rules, not assumed — see
# provision-author-user.php's docblock), the exact shape needed to test whether
# content_articles_create's default-published ($state omitted/1) path can be used to publish an article
# without core.edit.state.
provision_author() {
	log "Provisioning the Author-group API user (${AUTHOR_USER})"
	DC exec -T web php /app/tests/integration/docker/provision-author-user.php "${AUTHOR_USER}" \
		|| die "Author-group user provisioning failed."
	ok "Author-group user ready"

	log "Provisioning a Joomla API token for ${AUTHOR_USER}"
	local out
	out="$(DC exec -T web php /app/tests/integration/docker/provision-token.php "${AUTHOR_USER}")" \
		|| die "Author-group token generation failed."

	AUTHOR_TOKEN="$(printf '%s\n' "${out}" | tr -d '\r' | awk 'NF { line = $0 } END { print line }')"

	[ -n "${AUTHOR_TOKEN}" ] || die "Could not extract a non-empty API token for ${AUTHOR_USER}."
	ok "Author-group token acquired"
}

SCOPED_MANAGER_USER="ats-scoped-manager"
SCOPED_MANAGER_TOKEN=""

# Provisions an ATS "manager" API user who is denied a specific department at category level — for
# security.md findings M4/M5's live regression tests (tests/integration/src/Tests/Tickets/*ScopedAcl*): a
# user with core.manage at the com_ats component root (so requireAtsManager() passes and every *_list
# tool's list-level gate is satisfied) but explicitly Denied core.manage/core.admin at one specific ATS
# category ("MCP4j Scoped Denied") — see provision-ats-scoped-manager.php's own docblock for the full ACL
# shape and why. A no-op (exit 0) if com_ats isn't installed on this run, matching how the Tickets test
# classes themselves self-skip.
provision_scoped_manager() {
	log "Provisioning the ATS scoped-manager API user (${SCOPED_MANAGER_USER})"
	DC exec -T web php /app/tests/integration/docker/provision-ats-scoped-manager.php "${SCOPED_MANAGER_USER}" \
		|| die "ATS scoped-manager user provisioning failed."
	ok "ATS scoped-manager user ready"

	log "Provisioning a Joomla API token for ${SCOPED_MANAGER_USER}"
	local out
	if [ -d "${WWW_DIR}/administrator/components/com_ats" ]; then
		out="$(DC exec -T web php /app/tests/integration/docker/provision-token.php "${SCOPED_MANAGER_USER}")" \
			|| die "ATS scoped-manager token generation failed."
	else
		# ATS 5.x does not install on Joomla 4 (its floor is 5.4), so on a 4.4 run there is no such user.
		out=""
	fi

	SCOPED_MANAGER_TOKEN="$(printf '%s\n' "${out}" | tr -d '\r' | awk 'NF { line = $0 } END { print line }')"

	# com_ats not installed on this run: provision-ats-scoped-manager.php no-ops and there's no user for
	# provision-token.php to mint a token for either — leave SCOPED_MANAGER_TOKEN empty rather than dying,
	# the same way the Tickets test classes self-skip without ATS installed.
	if [ -z "${SCOPED_MANAGER_TOKEN}" ]; then
		warn "No ATS scoped-manager token acquired (com_ats not installed on this run?) — M4/M5 regression tests will self-skip."
	else
		ok "ATS scoped-manager token acquired"
	fi
}

# Writes every token to docker/.tokens (shell-sourceable), so quick.sh can reuse them without
# re-provisioning on every edit-test cycle against a kept-up stack.
write_tokens_file() {
	local tokensFile="${DOCKER_DIR}/.tokens"

	{
		printf 'TOKEN=%s\n' "${TOKEN}"
		printf 'LOWPRIV_TOKEN=%s\n' "${LOWPRIV_TOKEN}"
		printf 'AUTHOR_TOKEN=%s\n' "${AUTHOR_TOKEN}"
		printf 'SCOPED_MANAGER_TOKEN=%s\n' "${SCOPED_MANAGER_TOKEN}"
	} > "${tokensFile}"
	chmod 600 "${tokensFile}"

	ok "Wrote $(basename "${tokensFile}")"
}

# ---------------------------------------------------------------------------
# PHPUnit
# ---------------------------------------------------------------------------
run_phpunit() {
	log "Running the integration test suite (Joomla ${JOOMLA_RESOLVED}, PHP ${1})"

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
}

# ---------------------------------------------------------------------------
# One version:php pair, start to finish. Run in a subshell by the caller so that
# die() (which calls exit) only ends this one entry, not the whole matrix.
# ---------------------------------------------------------------------------
run_one() {
	local wantVersion="$1" phpVersion="$2"

	if ! acquire_joomla "${wantVersion}"; then
		warn ">>> SKIP Joomla ${wantVersion}: no stable release could be resolved"
		return 2
	fi

	# Report the resolved version back to the parent shell now, while we're sure to run:
	# RESOLVED_FILE is inherited from the parent (subshells see parent variables), but
	# variable *assignments* made here never propagate back, and a later exit/die further
	# down would skip any "report it after the fact" step placed after this function call.
	printf '%s' "${JOOMLA_RESOLVED}" > "${RESOLVED_FILE}"

	build_and_start_web "${phpVersion}"
	clean_www
	extract_joomla
	reset_database
	install_joomla
	build_package
	install_package
	install_extra_packages
	fix_permissions
	provision_token
	provision_lowpriv
	provision_author
	provision_scoped_manager
	write_tokens_file
	run_phpunit "${phpVersion}"
}

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
log "MCP4j integration harness — pairs: ${PAIRS[*]} (project ${COMPOSE_PROJECT_NAME}, port ${WEB_PORT})"

start_database

# pkg_mcp4j itself doesn't vary by Joomla/PHP version, so build it once up front and
# force every entry below to reuse that one package instead of rebuilding it each time.
build_package
SKIP_BUILD=1

OVERALL_STATUS=0
SUMMARY_PAIR=()
SUMMARY_VERSION=()
SUMMARY_RESULT=()
RESOLVED_FILE="$(mktemp)"
trap 'rm -f "${RESOLVED_FILE}"' EXIT

for pair in "${PAIRS[@]}"; do
	wantVersion="${pair%%:*}"
	phpVersion="${pair#*:}"

	echo
	log "=== Joomla ${wantVersion} / PHP ${phpVersion} ==="

	: > "${RESOLVED_FILE}"
	entry_status=0
	# run_one() itself runs in a subshell so die() (which calls exit) only ends this one
	# entry, not the whole matrix; RESOLVED_FILE is how it reports the resolved version
	# back out, since subshell variable changes aren't visible here.
	( run_one "${wantVersion}" "${phpVersion}" ) || entry_status=$?

	if [ "${entry_status}" -eq 2 ]; then
		SUMMARY_PAIR+=("${pair}")
		SUMMARY_VERSION+=("-")
		SUMMARY_RESULT+=("SKIP")
		continue
	fi

	if [ "${entry_status}" -ne 0 ]; then
		OVERALL_STATUS=1
		SUMMARY_RESULT+=("FAIL")
	else
		SUMMARY_RESULT+=("PASS")
	fi

	SUMMARY_PAIR+=("${pair}")
	SUMMARY_VERSION+=("$(cat "${RESOLVED_FILE}" 2>/dev/null || echo "${wantVersion}")")
done

if [ "${KEEP}" -eq 1 ]; then
	warn "Leaving the Docker stack up (--keep). Tear it down with: (cd ${DOCKER_DIR} && docker compose down -v)"
else
	teardown_stack
fi

echo
log "Summary (joomla:php requested -> resolved -> result)"
i=0
while [ "${i}" -lt "${#SUMMARY_PAIR[@]}" ]; do
	printf "  %-10s  resolved %-10s  %s\n" "${SUMMARY_PAIR[$i]}" "${SUMMARY_VERSION[$i]}" "${SUMMARY_RESULT[$i]}"
	i=$((i + 1))
done

echo
log "Run the (host-side) unit suite with: phpunit -c phpunit.xml"

exit "${OVERALL_STATUS}"
