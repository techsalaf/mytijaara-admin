set -euo pipefail
BEFORE="fixture"
if [ -z "$BEFORE" ] || [ "$BEFORE" = "0000000000000000000000000000000000000000" ]; then
  BEFORE="$(git rev-parse HEAD^ 2>/dev/null || true)"
fi

MIGRATION_PATTERN='(^|/)(database/migrations|modules/.*/database/migrations)/'

# Check changes in current push diff (supports both PascalCase and lowercase paths)
MIGRATIONS_CHANGED="false"
if [ -n "$BEFORE" ] && git diff --name-only "$BEFORE" "$GITHUB_SHA" | grep -Eiq "$MIGRATION_PATTERN"; then
  MIGRATIONS_CHANGED="true"
# Also check recent commits (up to 5 commits) in case previous deployment skipped them
elif git diff --name-only HEAD~5 HEAD 2>/dev/null | grep -Eiq "$MIGRATION_PATTERN"; then
  MIGRATIONS_CHANGED="true"
fi

echo "migrations_changed=$MIGRATIONS_CHANGED" >> "$GITHUB_OUTPUT"
