# Exclude files that must NEVER be pushed to shared hosting
# - .env and storage live on the server and are hand-managed; never overwrite
# - node_modules is rebuilt on the server
# - runtime storage contains logs, sessions, cache, and user uploads
cat > .rsync-exclude <<'EOF'
.git/
.github/
.env*
.agents/
.codex/
docs/
bootstrap/cache/*
config/system-addons.php
.phpunit.result.cache
node_modules/
storage/
public/storage/
public/hot
.DS_Store
*.log
npm-debug.log*
yarn-error.log
.idea/
.vscode/
.ddev/
docker-local/
Homestead.json
Homestead.yaml
docker-compose.override.yml
tests/
Documentation/
EOF
