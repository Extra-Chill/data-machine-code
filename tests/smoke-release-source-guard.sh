#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
GUARD="${ROOT_DIR}/.github/scripts/verify-release-source.sh"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

pass_count=0
fail() {
	echo "not ok - $1" >&2
	exit 1
}

pass() {
	pass_count=$((pass_count + 1))
	echo "ok ${pass_count} - $1"
}

git_init_repo() {
	local repo="$1"
	git -C "$repo" config user.email smoke@example.com
	git -C "$repo" config user.name "Smoke Test"
}

REMOTE="${TMP_DIR}/remote.git"
WORK="${TMP_DIR}/work"
STALE="${TMP_DIR}/stale"

git init -q --bare -b main "$REMOTE"
git clone -q "$REMOTE" "$WORK" 2>/dev/null
git_init_repo "$WORK"

printf 'first\n' > "${WORK}/source.txt"
git -C "$WORK" add source.txt
git -C "$WORK" commit -q -m 'fix: first releasable change'
git -C "$WORK" push -q origin main
FIRST_SHA="$(git -C "$WORK" rev-parse HEAD)"

git clone -q "$REMOTE" "$STALE"

printf 'second\n' > "${WORK}/source.txt"
git -C "$WORK" commit -q -am 'fix: intended merged change'
git -C "$WORK" push -q origin main
SECOND_SHA="$(git -C "$WORK" rev-parse HEAD)"

if ( cd "$STALE" && bash "$GUARD" --remote-ref=origin/main ) >"${TMP_DIR}/stale-checkout.out" 2>"${TMP_DIR}/stale-checkout.err"; then
	fail 'stale checkout guard should fail'
fi

if ! grep -q "HEAD=${FIRST_SHA}" "${TMP_DIR}/stale-checkout.err"; then
	fail 'stale checkout error includes HEAD SHA'
fi

if ! grep -q "origin/main=${SECOND_SHA}" "${TMP_DIR}/stale-checkout.err"; then
	fail 'stale checkout error includes origin/main SHA'
fi

if ! grep -q 'behind=1' "${TMP_DIR}/stale-checkout.err"; then
	fail 'stale checkout error includes behind count'
fi

pass 'stale checkout fails with actionable SHA and behind evidence'

git -C "$STALE" fetch -q origin main --tags
git -C "$STALE" reset -q --hard origin/main
git -C "$STALE" tag v1.0.0

( cd "$STALE" && bash "$GUARD" --remote-ref=origin/main --tag=v1.0.0 --expected-source="$SECOND_SHA" ) >"${TMP_DIR}/fresh-tag.out" 2>"${TMP_DIR}/fresh-tag.err"

if ! grep -q "tag v1.0.0 ${SECOND_SHA}" "${TMP_DIR}/fresh-tag.out"; then
	fail 'fresh tag output includes tag SHA'
fi

pass 'fresh tag containing origin/main passes with tag SHA evidence'

git -C "$STALE" tag -f v0.9.0 "$FIRST_SHA" >/dev/null

if ( cd "$STALE" && bash "$GUARD" --remote-ref=origin/main --tag=v0.9.0 --expected-source="$SECOND_SHA" ) >"${TMP_DIR}/stale-tag.out" 2>"${TMP_DIR}/stale-tag.err"; then
	fail 'stale tag guard should fail'
fi

if ! grep -q "v0.9.0 (${FIRST_SHA})" "${TMP_DIR}/stale-tag.err"; then
	fail 'stale tag error includes tag SHA'
fi

if ! grep -q "expected source ${SECOND_SHA}" "${TMP_DIR}/stale-tag.err"; then
	fail 'stale tag error includes expected source SHA'
fi

if ! grep -q 'behind=1' "${TMP_DIR}/stale-tag.err"; then
	fail 'stale tag error includes behind count'
fi

pass 'stale tag fails with tag SHA, origin/main SHA, and behind evidence'

echo "${pass_count}/${pass_count} passed"
