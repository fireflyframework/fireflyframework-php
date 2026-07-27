#!/usr/bin/env bash
# Pre-push / CI safety guard: FAIL if any TRACKED or STAGED path (or file content) matches a
# sensitive pattern that must NEVER reach the public GitHub mirror. User-mandated Phase 3 guardrail.
set -uo pipefail

fail=0
flag() { echo "GUARD FAIL: $1" >&2; fail=1; }

tracked="$(git ls-files 2>/dev/null || true)"
staged="$(git diff --cached --name-only 2>/dev/null || true)"
candidates="$(printf '%s\n%s\n' "$tracked" "$staged" | sort -u | sed '/^$/d')"

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

# Content scan (tracked files only; git grep searches tracked by default).
secret_re='-----BEGIN (RSA |OPENSSH |EC |DSA |PGP )?PRIVATE KEY-----|AKIA[0-9A-Z]{16}'
if git grep -I -l -E "$secret_re" >/dev/null 2>&1; then
  while IFS= read -r f; do
    [ -n "$f" ] && flag "secret marker in tracked file: $f"
  done < <(git grep -I -l -E "$secret_re")
fi

if [ "$fail" -ne 0 ]; then
  echo "Pre-push guard: sensitive path/content detected — refusing." >&2
  exit 1
fi
echo "Pre-push guard: clean (no sensitive tracked/staged paths)."
exit 0
