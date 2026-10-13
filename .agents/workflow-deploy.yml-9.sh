set -euo pipefail

mkdir -p ~/.ssh
echo "$SSH_PRIVATE_KEY" > ~/.ssh/deploy_key
chmod 600 ~/.ssh/deploy_key

KNOWN_HOSTS_LINE="ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl"
mkdir -p ~/.ssh/known_hosts.d
touch ~/.ssh/known_hosts

cat >> ~/.ssh/config <<EOF
Host mytijaara-deploy
    HostName $SSH_HOST
    Port $SSH_PORT
    User $SSH_USERNAME
    IdentityFile ~/.ssh/deploy_key
    StrictHostKeyChecking no
    UserKnownHostsFile=/dev/null
    LogLevel ERROR
EOF

chmod 600 ~/.ssh/config

RELEASE_ID="${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"
RELEASE_ROOT="$(dirname "$DEPLOY_PATH")/.mytijaara-releases/$RELEASE_ID"
INCOMING="$RELEASE_ROOT/incoming"
JOURNAL="$RELEASE_ROOT/journal"
ssh mytijaara-deploy "mkdir -p '$INCOMING' '$JOURNAL'"
rsync -az --exclude-from=.rsync-exclude \
    -e "ssh -p $SSH_PORT -i ~/.ssh/deploy_key -o StrictHostKeyChecking=no" \
    ./ mytijaara-deploy:"$INCOMING"/

# Preparation verifies every obsolete file and backs up each affected
# code file before maintenance or live writes. Runtime paths are denied.
ssh mytijaara-deploy "php '$INCOMING/scripts/safe-code-release.php' prepare '$DEPLOY_PATH' '$JOURNAL' '$INCOMING'"
ssh mytijaara-deploy "cd '$DEPLOY_PATH' && php artisan down --retry=60"
rollback_code() {
    ssh mytijaara-deploy "php '$INCOMING/scripts/safe-code-release.php' rollback '$DEPLOY_PATH' '$JOURNAL'" || true
    echo "Deployment failed; code recovery attempted. Maintenance remains enabled. Inspect the journal and schema before artisan up."
}
trap rollback_code ERR
ssh mytijaara-deploy "php '$INCOMING/scripts/safe-code-release.php' apply '$DEPLOY_PATH' '$JOURNAL'"

if [ "$MIGRATIONS_CHANGED" = "true" ] || [ "$FORCE_MIGRATE" = "true" ]; then
  ssh mytijaara-deploy "cd '$DEPLOY_PATH' && php artisan migrate --force && php artisan module:migrate --all --force"
fi
if [ "$RUN_SEED" = "true" ]; then
  ssh mytijaara-deploy "cd '$DEPLOY_PATH' && php artisan db:seed --force"
fi
ssh mytijaara-deploy "cd '$DEPLOY_PATH' && php artisan optimize:clear && php artisan queue:restart && php artisan up"
trap - ERR
echo "Deploy complete. Recoverable code journal: $JOURNAL"
