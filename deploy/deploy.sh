#!/bin/bash
# Wird auf dem Server per SSH-Forced-Command aus dem Deploy-Workflow aufgerufen
# (authorized_keys: command="/usr/local/sbin/deploy-imapfilter-ui",restrict ...).
# Liegt bewusst als Kopie ausserhalb des Checkouts: sonst wuerde ein Tag das
# Skript austauschen, das ihn ausrollt. Aenderungen hier muessen von Hand nach
# /usr/local/sbin/deploy-imapfilter-ui uebernommen werden.
set -euo pipefail

dir=/var/www/imapfilter-ui
tag="${SSH_ORIGINAL_COMMAND:-}"

[[ $tag =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "ungueltiger Tag: $tag" >&2; exit 1; }

exec 9>/var/lock/deploy-imapfilter-ui
flock -n 9 || { echo "anderer Deploy laeuft noch" >&2; exit 1; }

cd "$dir"
git fetch --tags --force origin
git rev-parse --verify --quiet "refs/tags/$tag" >/dev/null || { echo "Tag $tag nicht gefunden" >&2; exit 1; }
[[ "$(git show "refs/tags/$tag:VERSION" | tr -d '[:space:]')" == "${tag#v}" ]] \
    || { echo "VERSION passt nicht zu $tag" >&2; exit 1; }

prev=$(git rev-parse HEAD)
git checkout --detach "refs/tags/$tag"
if ! find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l >/dev/null; then
    echo "php -l fehlgeschlagen, Rollback auf $prev" >&2
    git checkout --detach "$prev"
    exit 1
fi
chown -R www-data:www-data "$dir"
echo "deployed $tag"
