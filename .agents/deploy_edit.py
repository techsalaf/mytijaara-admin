from pathlib import Path
p=Path('.github/workflows/deploy.yml');s=p.read_text(encoding='utf-8')
s=s.replace('find modules -name', 'find Modules -name')
s=s.replace('          .env.example\n','          .env.example\n          config/system-addons.php\n')
start=s.index('          echo "→ Syncing changed files')
end=s.index('          echo "✓ Deploy complete: $DEPLOY_PATH"',start)+len('          echo "✓ Deploy complete: $DEPLOY_PATH"')
s=s[:start]+'''          RELEASE_ID="${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"
          RELEASE_ROOT="$(dirname "$DEPLOY_PATH")/.mytijaara-releases/$RELEASE_ID"
          INCOMING="$RELEASE_ROOT/incoming"
          JOURNAL="$RELEASE_ROOT/journal"
          ssh mytijaara-deploy "mkdir -p '$INCOMING' '$JOURNAL'"
          rsync -az --exclude-from=.rsync-exclude \\
              -e "ssh -p $SSH_PORT -i ~/.ssh/deploy_key -o StrictHostKeyChecking=no" \\
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
''' +s[end:]
p.write_text(s,encoding='utf-8',newline='\n')
