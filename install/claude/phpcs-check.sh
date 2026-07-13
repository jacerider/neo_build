#!/usr/bin/env bash
# Claude Code PostToolUse (Edit|Write) hook: lint an edited Drupal PHP file with
# phpcs (Drupal coding standard) and block (exit 2) with the violations so they
# get fixed. Installed by `drush neo:build:install --claude`.
#
# It is personal and gitignored — it only affects your local Claude Code, not
# teammates. Safe to delete. No-ops (exit 0) whenever it cannot lint: non-PHP
# files, files outside modules/themes, no phpcs, no Drupal standard installed,
# or ddev not running.
set -u

# Project root = two levels up from this script (.claude/hooks/). No hard-coded
# paths, so it works from any checkout.
ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"

f=$(jq -r '.tool_input.file_path // empty' 2>/dev/null)
[ -z "$f" ] && exit 0

# Only Drupal PHP-ish files under the project's own modules/themes.
rel=${f#"$ROOT"/}
[ "$rel" = "$f" ] && exit 0
case "$rel" in
  web/modules/*|web/themes/*|modules/*|themes/*) ;;
  *) exit 0 ;;
esac
case "$rel" in
  *.php|*.module|*.inc|*.install|*.theme|*.profile|*.engine) ;;
  *) exit 0 ;;
esac

cd "$ROOT" || exit 0
[ -f vendor/bin/phpcs ] || exit 0

# Prefer the jacerider/grumphp-drupal standard; fall back to drupal/coder's
# "Drupal" standard; if neither is installed there is nothing to lint against.
if [ -d vendor/jacerider/grumphp-drupal/src/CodingStandards/Drupal ]; then
  std="./vendor/jacerider/grumphp-drupal/src/CodingStandards/Drupal"
elif [ -d vendor/drupal/coder ]; then
  std="Drupal"
else
  exit 0
fi

# Host PHP may be too old (composer platform check), so run phpcs inside ddev
# when this is a ddev project; otherwise run it natively.
run=""
if [ -f .ddev/config.yaml ] && command -v ddev >/dev/null 2>&1; then
  run="ddev exec"
fi

out=$($run vendor/bin/phpcs --standard="$std" "$rel" 2>&1)
code=$?

# Block only on a genuine phpcs report. A non-zero exit alone is unreliable:
# infra failures (ddev down, a stale-container conflict, a bad standard) also
# exit non-zero, and must NOT be mistaken for lint violations. phpcs prints a
# "FOUND N ERRORS/WARNINGS" summary only when it actually linted the file.
if [ "$code" != 0 ] \
  && printf '%s\n' "$out" | grep -qiE 'FOUND [0-9]+ (ERROR|WARNING)'; then
  printf 'phpcs found issues in %s (fix before continuing):\n%s\n' \
    "$rel" "$out" >&2
  exit 2
fi
exit 0
