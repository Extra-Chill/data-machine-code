#!/usr/bin/env bash
set -euo pipefail

usage() {
	cat <<'USAGE'
Usage: verify-release-source.sh [--remote-ref=<ref>] [--tag=<tag>] [--expected-source=<sha>]

Verifies release source freshness for the Data Machine Code release workflow.
Without --tag, the current checkout must match the remote main ref exactly.
With --tag, the tag commit must contain --expected-source or the remote main ref.
USAGE
}

REMOTE_REF="origin/main"
TAG_NAME=""
EXPECTED_SOURCE=""

for arg in "$@"; do
	case "$arg" in
		--remote-ref=*)
			REMOTE_REF="${arg#--remote-ref=}"
			;;
		--tag=*)
			TAG_NAME="${arg#--tag=}"
			;;
		--expected-source=*)
			EXPECTED_SOURCE="${arg#--expected-source=}"
			;;
		-h|--help)
			usage
			exit 0
			;;
		*)
			echo "Unknown argument: $arg" >&2
			usage >&2
			exit 2
			;;
	esac
done

REMOTE_NAME="${REMOTE_REF%%/*}"
REMOTE_BRANCH="${REMOTE_REF#*/}"

if [ -z "$REMOTE_NAME" ] || [ "$REMOTE_NAME" = "$REMOTE_REF" ] || [ -z "$REMOTE_BRANCH" ]; then
	echo "::error::Remote ref must look like origin/main; got ${REMOTE_REF}" >&2
	exit 2
fi

git fetch --tags "$REMOTE_NAME" "$REMOTE_BRANCH"

REMOTE_SHA="$(git rev-parse "$REMOTE_REF")"
HEAD_SHA="$(git rev-parse HEAD)"

echo "Release source guard: checkout HEAD ${HEAD_SHA}"
echo "Release source guard: ${REMOTE_REF} ${REMOTE_SHA}"

if [ -z "$TAG_NAME" ]; then
	read -r HEAD_ONLY REMOTE_ONLY < <(git rev-list --left-right --count "HEAD...${REMOTE_REF}")
	echo "Release source guard: HEAD...${REMOTE_REF} ahead=${HEAD_ONLY} behind=${REMOTE_ONLY}"

	if [ "$HEAD_SHA" != "$REMOTE_SHA" ]; then
		echo "::error::Release checkout is not at ${REMOTE_REF}. HEAD=${HEAD_SHA} ${REMOTE_REF}=${REMOTE_SHA} ahead=${HEAD_ONLY} behind=${REMOTE_ONLY}. Fetch latest main and release from the current source before tagging." >&2
		exit 1
	fi

	echo "Release source guard: checkout is current with ${REMOTE_REF}."
	if [ -n "${GITHUB_OUTPUT:-}" ]; then
		echo "source-sha=${REMOTE_SHA}" >> "$GITHUB_OUTPUT"
	fi
	exit 0
fi

if ! git rev-parse --verify --quiet "${TAG_NAME}^{commit}" >/dev/null; then
	echo "::error::Release tag ${TAG_NAME} was not found after release. Expected tag to verify source ancestry." >&2
	exit 1
fi

TAG_SHA="$(git rev-parse "${TAG_NAME}^{commit}")"
SOURCE_SHA="${EXPECTED_SOURCE:-$REMOTE_SHA}"
SOURCE_SUBJECT="$(git log -1 --format=%s "$SOURCE_SHA")"
TAG_SUBJECT="$(git log -1 --format=%s "$TAG_SHA")"
REMOTE_SUBJECT="$(git log -1 --format=%s "$REMOTE_SHA")"
read -r TAG_ONLY REMOTE_ONLY < <(git rev-list --left-right --count "${TAG_NAME}...${REMOTE_REF}")
read -r TAG_SOURCE_ONLY SOURCE_ONLY < <(git rev-list --left-right --count "${TAG_NAME}...${SOURCE_SHA}")

echo "Release source guard: tag ${TAG_NAME} ${TAG_SHA}"
echo "Release source guard: tag subject ${TAG_SUBJECT}"
echo "Release source guard: expected source ${SOURCE_SHA}"
echo "Release source guard: expected source subject ${SOURCE_SUBJECT}"
echo "Release source guard: ${REMOTE_REF} subject ${REMOTE_SUBJECT}"
echo "Release source guard: ${TAG_NAME}...${REMOTE_REF} ahead=${TAG_ONLY} behind=${REMOTE_ONLY}"
echo "Release source guard: ${TAG_NAME}...${SOURCE_SHA} ahead=${TAG_SOURCE_ONLY} behind=${SOURCE_ONLY}"

if ! git merge-base --is-ancestor "$SOURCE_SHA" "$TAG_SHA"; then
	echo "::error::Release tag ${TAG_NAME} (${TAG_SHA}) does not contain expected source ${SOURCE_SHA}. Tag/source divergence: ahead=${TAG_SOURCE_ONLY} behind=${SOURCE_ONLY}. Latest ${REMOTE_REF}=${REMOTE_SHA}; tag/latest-main divergence: ahead=${TAG_ONLY} behind=${REMOTE_ONLY}. The tag may report a new version while missing merged fixes; fetch latest main and retag from current source." >&2
	exit 1
fi

echo "Release source guard: tag ${TAG_NAME} contains expected source ${SOURCE_SHA}."
