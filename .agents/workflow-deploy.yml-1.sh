find app -name "*.php" -print0 | xargs -0 -n1 -P4 php -l
find Modules -name "*.php" -print0 2>/dev/null | xargs -0 -n1 -P4 php -l
find config -name "*.php" -print0 | xargs -0 -n1 -P4 php -l
