#!/usr/bin/env bash
# Lumanoris — TEK doğrulama kapısı (CLAUDE.md → "Doğrulama komutları").
#
#   bash scripts/verify.sh
#
# Çalıştırır: npm run lint · verify build (NEXT_DIST_DIR=.next-verify) ·
# php -l (vendor hariç tüm .php) · tüm selftest'ler (plan_limits --strict).
# Her adımın çıkış kodu ayrı kontrol edilir; borular kullanılmaz. Sonuç
# satırı "DOĞRULAMA: GEÇTİ" ya da "DOĞRULAMA: KALDI (...)" ve çıkış kodu
# 0 / 1. Commit YALNIZCA bu betik 0 döndükten sonra, AYRI bir komutla atılır.
#
# Kayıtlar: storage/logs/verify/ (git dışında).

set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
LOG="$ROOT/storage/logs/verify"
mkdir -p "$LOG"
failed=()

step() { # ad, komut...
  local name="$1"; shift
  if "$@" > "$LOG/$name.log" 2>&1; then
    echo "  [OK]   $name"
  else
    echo "  [FAIL] $name  (kayıt: storage/logs/verify/$name.log)"
    failed+=("$name")
  fi
}

lint() {
  (cd "$ROOT/web" && npm run lint) || return 1
  grep -q "No ESLint warnings or errors" "$LOG/lint.log"
}
build() { (cd "$ROOT/web" && NEXT_DIST_DIR=.next-verify npm run build); }
phplint() {
  local bad=0 f
  while IFS= read -r -d '' f; do
    php -l "$f" > /dev/null 2>&1 || { echo "sözdizimi hatası: $f"; bad=1; }
  done < <(find "$ROOT/api" -name '*.php' -not -path '*/vendor/*' -print0)
  return $bad
}
selftest() { # dosya [arg]
  php "$ROOT/api/database/$1" ${2:-} || return 1
  ! grep -q '\[FAIL\]' "$LOG/$(basename "$1" .php).log"
}

echo "Lumanoris doğrulama ($(git -C "$ROOT" rev-parse --short HEAD 2>/dev/null))"
step lint lint
step build build
step php-l phplint
step iyzico_selftest selftest iyzico_selftest.php
step plan_limits_selftest selftest plan_limits_selftest.php --strict
step access_selftest selftest access_selftest.php
step application_selftest selftest application_selftest.php
step hoppa_selftest selftest hoppa_selftest.php
step migrate_selftest selftest migrate_selftest.php

if [ ${#failed[@]} -eq 0 ]; then
  echo "DOĞRULAMA: GEÇTİ"
  exit 0
fi
echo "DOĞRULAMA: KALDI (${failed[*]})"
exit 1
