#!/usr/bin/env bash
# Lumanoris — canlı arka uç için elle yüklenecek zip paketi (N-56).
#
#   bash scripts/build-backend-zip.sh 2026-10-18-A     → deploy/backend-2026-10-18-A.zip
#
# Zip'in kökü = api/ dizininin içi (cPanel'de api.lumanoris.com Document
# Root'una "üzerine yaz" ile açılır). İçerik:
#   • api/ altında git'te İZLENEN dosyalar, HEAD commit'inden (git archive) —
#     commit edilmemiş değişiklik pakete girmez.
#   • vendor/ ve admin/vendor/ — composer.lock'tan, `composer install --no-dev
#     --optimize-autoloader` ile temiz kurulum. vendor/ git'te değil; canlıda
#     eksik/eski kaldığı için belge yükleme düştü (N-56).
# Pakete ASLA girmeyenler: .env ve .env.* (örnekler dahil), storage/, kullanıcı
# yüklemeleri (assets/ ve uploads/ altında yalnızca koruyucu .htaccess
# olabilir), *.zip. Zip yalnızca dosya ekler/değiştirir; açmak sunucudaki
# yüklemeleri ve .env'i silmez. Klasörü silip yeniden yüklemeyin.
#
# Durdurma koşulları (paket üretilmez, çıkış ≠ 0):
#   - pakette .env / .env.* · assets/uploads altında .htaccess dışında dosya
#   - bilinen anahtar biçimi (functions/secret_scan.php) — vendor dahil
#   - composer.lock'taki bir paket PHP 8.2 ile uyumsuz (canlı: ea-php82)
#   - vendor/autoload.php ya da admin/vendor/autoload.php üretilmedi

set -u
set -o pipefail

NAME="${1:-}"
[ -n "$NAME" ] || { echo "kullanım: bash scripts/build-backend-zip.sh <ad>   (ör. 2026-10-18-A)" >&2; exit 2; }
case "$NAME" in *[!A-Za-z0-9._-]*) echo "ad yalnızca harf, rakam, . _ - içerebilir" >&2; exit 2 ;; esac

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/deploy/backend-$NAME.zip"
PHP_TARGET="8.2.0"
die() { echo "DURDU: $*" >&2; exit 1; }

[ -e "$OUT" ] && die "$OUT zaten var (üzerine yazılmaz)"
command -v composer >/dev/null 2>&1 || die "composer bulunamadı"
php -r 'exit(class_exists("ZipArchive") ? 0 : 1);' || die "PHP zip eklentisi yok"

COMMIT="$(git -C "$ROOT" rev-parse --short HEAD)" || die "git yok"
if [ -n "$(git -C "$ROOT" status --porcelain -- api)" ]; then
  echo "UYARI: api/ altında commit edilmemiş değişiklik var — pakete GİRMEZ (HEAD $COMMIT kullanılıyor):"
  git -C "$ROOT" status --short -- api | sed 's/^/  /'
fi

STAGE="$(mktemp -d)" || die "geçici dizin oluşturulamadı"
trap 'rm -rf "$STAGE"' EXIT

echo "== HEAD $COMMIT → api/ (git archive)"
git -C "$ROOT" archive --format=tar HEAD:api | tar -x -C "$STAGE" || die "git archive başarısız"

# Örnek env dosyaları git'te izleniyor ama canlıya gitmez (kural: pakette .env* yok).
find "$STAGE" \( -name '.env' -o -name '.env.*' \) -type f -print -delete | sed "s#^$STAGE/#  çıkarıldı: #"

echo "== composer install (--no-dev --optimize-autoloader)"
for dir in "$STAGE" "$STAGE/admin"; do
  [ -f "$dir/composer.lock" ] || die "${dir#$STAGE/}/composer.lock yok"
  (cd "$dir" && composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet) \
    || die "composer install başarısız: ${dir#$STAGE}"
  [ -f "$dir/vendor/autoload.php" ] || die "${dir#$STAGE}/vendor/autoload.php üretilmedi"
  wn="$(cd "$dir" && composer why-not php "$PHP_TARGET" 2>&1)"
  case "$wn" in
    *"There is no installed package"*) echo "  PHP $PHP_TARGET uyumlu: ${dir#$STAGE}/ ($(ls "$dir/vendor" | wc -l | tr -d ' ') üst dizin)" ;;
    *) echo "$wn"; die "PHP $PHP_TARGET ile uyumsuz paket var: ${dir#$STAGE}/" ;;
  esac
  # Paket belgeleri canlıda gereksiz; firebase/php-jwt README'sindeki örnek
  # "BEGIN … PRIVATE KEY" blokları anahtar taramasını haklı olarak durduruyordu.
  find "$dir/vendor" -type f -iname '*.md' -delete
done

echo "== denetimler"
envs="$(find "$STAGE" \( -name '.env' -o -name '.env.*' \) -print)"
[ -z "$envs" ] || die "pakette .env dosyası var: ${envs//$STAGE\//}"
user_files="$(find "$STAGE" -type f \( -path '*/uploads/*' -o -path "$STAGE/assets/*" -o -path "$STAGE/storage/*" \) ! -name '.htaccess' -print)"
[ -z "$user_files" ] || die "pakette kullanıcı yüklemesi/storage dosyası var: ${user_files//$STAGE\//}"
zips="$(find "$STAGE" -name '*.zip' -print)"
[ -z "$zips" ] || die "pakette zip var: ${zips//$STAGE\//}"

php -r '
  require $argv[1];
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($argv[2], FilesystemIterator::SKIP_DOTS));
  $bad = 0; $n = 0;
  foreach ($it as $f) {
    if (!$f->isFile() || $f->getSize() > 5000000) continue;
    $n++;
    foreach (secretScanText((string) file_get_contents($f->getPathname())) as $hit) {
      echo "  ", substr($f->getPathname(), strlen($argv[2]) + 1), " — $hit\n"; $bad++;
    }
  }
  echo "  anahtar taraması: $n dosya, $bad bulgu\n";
  exit($bad > 0 ? 1 : 0);
' "$ROOT/api/functions/secret_scan.php" "$STAGE" || die "pakette anahtar biçiminde değer var (yukarıda; değer basılmadı)"

echo "== zip"
mkdir -p "$ROOT/deploy"
php -r '
  [$_, $src, $out] = $argv;
  $z = new ZipArchive();
  if ($z->open($out, ZipArchive::CREATE | ZipArchive::EXCL) !== true) { fwrite(STDERR, "zip açılamadı\n"); exit(1); }
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
  foreach ($it as $f) {
    $rel = str_replace("\\", "/", substr($f->getPathname(), strlen($src) + 1));
    $f->isDir() ? $z->addEmptyDir($rel) : $z->addFile($f->getPathname(), $rel);
  }
  exit($z->close() ? 0 : 1);
' "$STAGE" "$OUT" || { rm -f "$OUT"; die "zip yazılamadı"; }

# Son doğrulama: üretilen ZİP'in kendisinde .env / zip yok, vendor var.
listing="$(unzip -Z1 "$OUT")" || { rm -f "$OUT"; die "zip okunamadı"; }
if printf '%s\n' "$listing" | grep -Eq '(^|/)\.env(\.|$)|\.zip$'; then rm -f "$OUT"; die "zip'te .env/.zip bulundu"; fi
for need in vendor/autoload.php admin/vendor/autoload.php vendor/smalot/pdfparser/src/Smalot/PdfParser/Parser.php router.php .htaccess; do
  printf '%s\n' "$listing" | grep -qx "$need" || { rm -f "$OUT"; die "zip'te eksik: $need"; }
done

files="$(printf '%s\n' "$listing" | grep -vc '/$')"
echo "== HAZIR: deploy/backend-$NAME.zip"
echo "  commit:  $COMMIT"
echo "  dosya:   $files ($(printf '%s\n' "$listing" | grep -c '^vendor/\|^admin/vendor/') vendor girdisi)"
echo "  boyut:   $(du -h "$OUT" | cut -f1)"
echo "  sha256:  $(sha256sum "$OUT" | cut -d' ' -f1)"
