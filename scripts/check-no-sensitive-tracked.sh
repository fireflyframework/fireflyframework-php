#!/usr/bin/env bash
# Pre-push / CI safety guard: FAIL if any TRACKED or STAGED path (or file content) matches a
# sensitive pattern that must NEVER reach the public GitHub mirror. User-mandated Phase 3 guardrail.
set -uo pipefail

fail=0
flag() { echo "GUARD FAIL: $1" >&2; fail=1; }

tracked="$(git ls-files 2>/dev/null || true)"
staged="$(git diff --cached --name-only 2>/dev/null || true)"
candidates="$(printf '%s\n%s\n' "$tracked" "$staged" | sort -u | sed '/^$/d')"

# Case-insensitive path matching (bash-3.2-safe) so .SuperPowers/, .Superpowers/, claude.md, etc.
# are also caught by the case arms below. Restored to the shell default right after the loop.
shopt -s nocasematch
while IFS= read -r path; do
  [ -z "$path" ] && continue
  case "$path" in
    .superpowers/*|*/.superpowers/*)         flag "superpowers path tracked: $path" ;;
    docs/superpowers/*|*/docs/superpowers/*) flag "docs/superpowers path tracked: $path" ;;
    .claude|.claude/*|*/.claude|*/.claude/*) flag ".claude path tracked: $path" ;;
    CLAUDE.md|*/CLAUDE.md)                   flag "CLAUDE.md tracked: $path" ;;
    */id_rsa|id_rsa|id_ed25519|*/id_ed25519|*.pem|*/*.pem|*.p12|*/*.p12|*.pfx|*/*.pfx) flag "key file tracked: $path" ;;
    .env.example|*/.env.example)             : ;;  # explicitly allowed (must precede the .env arms)
    .env|*/.env|.env.*|*/.env.*)             flag "env file tracked: $path" ;;
  esac
done <<< "$candidates"
shopt -u nocasematch

# Content scan (tracked files only; git grep searches tracked by default).
# NOTE: the leading "-e" is REQUIRED — $secret_re starts with "-----BEGIN...", which git grep would
# otherwise parse as an (unknown) option and exit 129, silently skipping this whole block under the
# >/dev/null 2>&1 guard below. "-e" pins it as the pattern argument, not an option.
secret_re='-----BEGIN (RSA |OPENSSH |EC |DSA |PGP )?PRIVATE KEY-----|AKIA[0-9A-Z]{16}'
if git grep -I -l -E -e "$secret_re" >/dev/null 2>&1; then
  while IFS= read -r f; do
    [ -n "$f" ] && flag "secret marker in tracked file: $f"
  done < <(git grep -I -l -E -e "$secret_re")
fi

if [ "$fail" -ne 0 ]; then
  echo "Pre-push guard: sensitive path/content detected — refusing." >&2
  exit 1
fi
echo "Pre-push guard: clean (no sensitive tracked/staged paths)."
exit 0
