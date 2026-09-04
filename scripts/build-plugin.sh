#!/usr/bin/env bash
#
# Empacota wp-plugins/odontojf-woo-bridge/ em odontojf-woo-bridge.zip na raiz.
#
# O FONTE é wp-plugins/odontojf-woo-bridge/ (diff legível no git); o zip da raiz
# é só o artefato de deploy — sempre gerado por este script, nunca editado à mão.
# O zip precisa ter a pasta do plugin no topo, senão o WordPress instala solto.
#
# Uso: scripts/build-plugin.sh [slug]     (default: odontojf-woo-bridge)
set -euo pipefail

SLUG="${1:-odontojf-woo-bridge}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$ROOT/wp-plugins/$SLUG"
OUT="$ROOT/$SLUG.zip"

[ -d "$SRC" ] || { echo "erro: fonte não encontrado em $SRC" >&2; exit 1; }
[ -f "$SRC/$SLUG.php" ] || { echo "erro: $SRC/$SLUG.php não encontrado" >&2; exit 1; }

# php -l em tudo antes de empacotar: um parse error só apareceria em produção.
if command -v php >/dev/null 2>&1; then
  while IFS= read -r -d '' f; do php -l "$f" >/dev/null; done \
    < <(find "$SRC" -name '*.php' -print0)
else
  echo "aviso: php não encontrado, pulando o lint" >&2
fi

VERSION="$(sed -n 's/^ \* Version: *\([0-9.]*\).*/\1/p' "$SRC/$SLUG.php" | head -1)"
CONST="$(sed -n "s/^define('OJF_BRIDGE_VERSION', *'\([0-9.]*\)').*/\1/p" "$SRC/$SLUG.php" | head -1)"
[ -n "$VERSION" ] || { echo "erro: não achei 'Version:' no header" >&2; exit 1; }
[ "$VERSION" = "$CONST" ] || {
  echo "erro: header ($VERSION) e OJF_BRIDGE_VERSION ($CONST) divergem" >&2; exit 1; }

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/$SLUG"
# rsync não é garantido no runner; tar preserva a árvore e aplica os excludes.
tar -C "$SRC" \
    --exclude='.git' --exclude='.DS_Store' --exclude='*.zip' \
    --exclude='node_modules' --exclude='*.log' \
    -cf - . | tar -C "$STAGE/$SLUG" -xf -

rm -f "$OUT"
# -X: sem metadata de plataforma. Ordem estável para o zip não mudar à toa.
( cd "$STAGE" && find "$SLUG" -print | LC_ALL=C sort | zip -q -X -9 "$OUT" -@ )

echo "ok: $OUT (v$VERSION, $(du -h "$OUT" | cut -f1))"
