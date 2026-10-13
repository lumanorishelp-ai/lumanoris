#!/usr/bin/env bash
# Lumanoris — canlı arka uç deploy'u (Markahost cPanel, Terminal yok).
#
# cPanel "Git Version Control" main'i çektikten sonra .cpanel.yml bu betiği
# çağırır. Sıra:
#   1. kilit           — aynı anda iki deploy çalışmaz
#   2. yedek           — mysqldump (.env bilgileriyle), web dışı klasöre,
#                        tarihli, son 10 tutulur. Yedek alınamazsa DURUR.
#   3. başlangıç kaydı — schema_migrations yoksa: 004/010 uygulanmış,
#                        001/002/002b/003/005/006 ertelendi. Varsa hiçbir şey.
#   4. migration       — migrate.php --apply --auto: yalnızca migrations/
#                        içindeki, uygulanmamış, ertelenmemiş, yıkıcı olmayan
#                        dosyalar. 008 yinelenen satır varsa atlanır. İlk
#                        hatada durur; kod KOPYALANMAZ, çıkış ≠ 0.
#   5. kod             — api/ (yalnızca git'te izlenen dosyalar) canlı dizine.
#                        .env'e dokunulmaz.
# Tüm çıktı $STATE_DIR/logs/ altına (web'den erişilemez).
#
# Kullanım:
#   DEPLOYPATH=$HOME/api.lumanoris.com bash scripts/deploy-backend.sh
#   … --approve 005_fix_table_collations.sql             (onaylı tek dosya)
#   … --approve 002_clean_orphan_rows.sql --allow-destructive
#     --approve: kilit + yedek + yalnızca o dosya; kod kopyalanmaz.
#
# Ortam: DEPLOYPATH (zorunlu) · ENV_FILE (varsayılan $DEPLOYPATH/.env) ·
#        STATE_DIR (varsayılan $HOME/lumanoris-deploy) · PHP_BIN · MYSQLDUMP

set -u
set -o pipefail
umask 077

REPO="$(cd "$(dirname "$0")/.." && pwd -P)"
DEPLOYPATH="${DEPLOYPATH:-}"
STATE_DIR="${STATE_DIR:-${HOME:?HOME tanımlı değil}/lumanoris-deploy}"
PHP_BIN="${PHP_BIN:-php}"
MYSQLDUMP="${MYSQLDUMP:-mysqldump}"
KEEP_BACKUPS=10
KEEP_LOGS=30

APPROVE=""
ALLOW_DESTRUCTIVE=""
while [ $# -gt 0 ]; do
  case "$1" in
    --approve) APPROVE="${2:-}"; shift 2 ;;
    --allow-destructive) ALLOW_DESTRUCTIVE="--allow-destructive"; shift ;;
    *) echo "bilinmeyen argüman: $1" >&2; exit 2 ;;
  esac
done
if [ -n "$ALLOW_DESTRUCTIVE" ] && [ -z "$APPROVE" ]; then
  echo "--allow-destructive yalnızca --approve DOSYA ile" >&2; exit 2
fi

mkdir -p "$STATE_DIR/logs" "$STATE_DIR/backups" || exit 1
chmod 700 "$STATE_DIR" "$STATE_DIR/logs" "$STATE_DIR/backups" 2>/dev/null
STATE_DIR="$(cd "$STATE_DIR" && pwd -P)"
TS="$(date +%Y%m%d-%H%M%S)"
LOG="$STATE_DIR/logs/deploy-$TS.log"
exec > >(tee -a "$LOG") 2>&1

COMMIT="$(git -C "$REPO" rev-parse --short HEAD 2>/dev/null || echo '?')"
MODE="deploy"; [ -n "$APPROVE" ] && MODE="approve $APPROVE"
CNF=""
LOCKDIR=""

finish() {
  local code=$?
  [ -n "$CNF" ] && rm -f "$CNF"
  [ -n "$LOCKDIR" ] && rm -rf "$LOCKDIR"
  local word="BAŞARILI"; [ "$code" -ne 0 ] && word="BAŞARISIZ (çıkış $code)"
  echo "== $(date '+%F %T') $word"
  echo "$(date '+%F %T')  $word  commit=$COMMIT  mod=$MODE  log=$(basename "$LOG")" >> "$STATE_DIR/logs/ozet.log"
}
trap finish EXIT
die() { echo "DURDU: $*"; exit 1; }

echo "== $(date '+%F %T') Lumanoris arka uç — $MODE (commit $COMMIT)"

# ── Yol güvenliği ─────────────────────────────────────────────────────────
[ -n "$DEPLOYPATH" ] || die "DEPLOYPATH tanımlı değil (.cpanel.yml)"
[ -d "$DEPLOYPATH" ] || die "DEPLOYPATH yok: $DEPLOYPATH"
DEPLOYPATH="$(cd "$DEPLOYPATH" && pwd -P)"
ENV_FILE="${ENV_FILE:-$DEPLOYPATH/.env}"
[ -f "$ENV_FILE" ] || die ".env bulunamadı: $ENV_FILE — DEPLOYPATH yanlış olabilir"
[ -f "$DEPLOYPATH/functions/db.php" ] || die "$DEPLOYPATH bir Lumanoris api dizini gibi görünmüyor (functions/db.php yok)"
case "$STATE_DIR/" in
  "$DEPLOYPATH"/*|*/public_html/*) die "STATE_DIR web'den erişilebilir bir yerde: $STATE_DIR" ;;
esac
case "$REPO/" in
  "$DEPLOYPATH"/*) die "repo kopyası canlı dizinin içinde: $REPO" ;;
esac
echo "repo: $REPO"
echo "hedef: $DEPLOYPATH"
echo "php: $("$PHP_BIN" -r 'echo PHP_VERSION;' 2>&1)"

# ── 1. Kilit ──────────────────────────────────────────────────────────────
if command -v flock >/dev/null 2>&1; then
  exec 9>"$STATE_DIR/deploy.lock"
  flock -n 9 || die "başka bir deploy çalışıyor (kilit: $STATE_DIR/deploy.lock)"
else
  if ! mkdir "$STATE_DIR/deploy.lock.d" 2>/dev/null; then
    pid="$(cat "$STATE_DIR/deploy.lock.d/pid" 2>/dev/null || true)"
    if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
      die "başka bir deploy çalışıyor (pid $pid)"
    fi
    echo "eski kilit bulundu (pid ${pid:-?} yaşamıyor) — kaldırılıp yeniden alınıyor"
    rm -rf "$STATE_DIR/deploy.lock.d"
    mkdir "$STATE_DIR/deploy.lock.d" 2>/dev/null || die "kilit alınamadı"
  fi
  echo $$ > "$STATE_DIR/deploy.lock.d/pid"
fi
LOCKDIR="$( [ -d "$STATE_DIR/deploy.lock.d" ] && echo "$STATE_DIR/deploy.lock.d" )"
echo "kilit alındı"

# ── 2. Yedek ──────────────────────────────────────────────────────────────
CNF="$STATE_DIR/.my-$TS-$$.cnf"
DB_NAME="$("$PHP_BIN" "$REPO/api/database/deploy_mysql_defaults.php" --env="$ENV_FILE" --out="$CNF")" \
  || die "veritabanı bilgileri .env'den okunamadı"
DB_NAME="$(printf '%s' "$DB_NAME" | tr -d '\r\n')"
command -v "$MYSQLDUMP" >/dev/null 2>&1 || [ -x "$MYSQLDUMP" ] || die "mysqldump bulunamadı ($MYSQLDUMP)"

DUMP_OPTS=(--single-transaction --quick --default-character-set=utf8mb4)
# Paylaşımlı hostingte PROCESS yetkisi yok; MySQL 8 tablespace dökümü onu ister.
DUMP_HELP="$("$MYSQLDUMP" --help 2>/dev/null || true)"
case "$DUMP_HELP" in *--no-tablespaces*) DUMP_OPTS+=(--no-tablespaces) ;; esac

BACKUP="$STATE_DIR/backups/db-$TS.sql.gz"
echo "yedek alınıyor: $BACKUP"
"$MYSQLDUMP" --defaults-extra-file="$CNF" "${DUMP_OPTS[@]}" "$DB_NAME" | gzip -c > "$BACKUP.part" \
  || { rm -f "$BACKUP.part"; die "mysqldump başarısız — hiçbir migration çalıştırılmadı"; }
gzip -t "$BACKUP.part" 2>/dev/null \
  || { rm -f "$BACKUP.part"; die "yedek arşivi bozuk"; }
DUMP_TAIL="$(gzip -dc "$BACKUP.part" | tail -c 512)"
case "$DUMP_TAIL" in
  *"-- Dump completed"*) ;;
  *) rm -f "$BACKUP.part"; die "yedek yarıda kesilmiş (kapanış satırı yok)" ;;
esac
mv "$BACKUP.part" "$BACKUP" || die "yedek yerine taşınamadı"
rm -f "$CNF"; CNF=""
echo "yedek tamam: $(du -h "$BACKUP" | cut -f1)"

ls -1 "$STATE_DIR/backups"/db-*.sql.gz 2>/dev/null | sort -r | tail -n +$((KEEP_BACKUPS + 1)) | while IFS= read -r old; do
  rm -f "$old" && echo "eski yedek silindi: $(basename "$old")"
done
ls -1 "$STATE_DIR/logs"/deploy-*.log 2>/dev/null | sort -r | tail -n +$((KEEP_LOGS + 1)) | while IFS= read -r old; do
  rm -f "$old"
done

MIGRATE=("$PHP_BIN" "$REPO/api/database/migrate.php" --env="$ENV_FILE" --backup="$BACKUP")

# ── 3. Başlangıç kaydı (yalnızca bir kez) ─────────────────────────────────
echo "== başlangıç kaydı"
"${MIGRATE[@]}" --bootstrap || die "başlangıç kaydı başarısız"

# ── 4. Migration ──────────────────────────────────────────────────────────
if [ -n "$APPROVE" ]; then
  echo "== onaylı migration: $APPROVE"
  "${MIGRATE[@]}" --apply --approve="$APPROVE" $ALLOW_DESTRUCTIVE || die "onaylı migration başarısız"
  exit 0
fi

echo "== migration (--auto)"
"${MIGRATE[@]}" --apply --auto || die "migration başarısız — kod KOPYALANMADI, sunucudaki eski kod duruyor"

# ── 5. Kod ────────────────────────────────────────────────────────────────
echo "== kod kopyalanıyor: api/ → $DEPLOYPATH"
# Canlıya hiç gitmeyecekler: gizli değerler ve yerel geliştirme betikleri.
EXCLUDES=(--exclude='.env' --exclude='.env.*' --exclude='vendor' --exclude='_restoretest.php')
if git -C "$REPO" rev-parse --verify -q HEAD >/dev/null 2>&1; then
  # Yalnızca izlenen dosyalar: .env, vendor/, yerel çöp asla gitmez.
  if git -C "$REPO" ls-tree -r --name-only HEAD api | grep -E '(^|/)\.env$' >/dev/null; then
    die "repoda izlenen bir .env var — kopyalanmadı"
  fi
  git -C "$REPO" archive --format=tar HEAD:api | tar -C "$DEPLOYPATH" "${EXCLUDES[@]}" -xf - \
    || die "kod kopyalanamadı"
else
  # Git'siz kopya (ör. File Manager'dan açılmış zip).
  tar -C "$REPO/api" "${EXCLUDES[@]}" -cf - . | tar -C "$DEPLOYPATH" -xf - \
    || die "kod kopyalanamadı"
fi
echo "kod tamam"
