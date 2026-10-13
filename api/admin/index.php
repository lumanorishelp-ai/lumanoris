<?php
require_once __DIR__ . '/../functions/logging.php';
configure_error_log();
require_once __DIR__ . '/../functions/util.php';
require_once __DIR__ . '/functions/tailmind.php';
date_default_timezone_set('Europe/Istanbul');
// The admin panel turned display_errors on unconditionally, regardless of
// APP_DEBUG — so in production a PHP notice or warning would be printed into
// the page (and, for anything that emits JSON, into the response body). Honour
// the same flag the API's exception handler uses; errors are logged either way.
$adminDebug = strtolower((string) ($_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?: '')) === 'true';
ini_set('display_errors', $adminDebug ? '1' : '0');
ini_set('display_startup_errors', $adminDebug ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// ERR-004: DB bağlantısı bu satırların ALTINDA kurulmalı. Üstünde kurulduğunda,
// bağlantı hata verirse display_errors henüz ayarlanmamış oluyordu ve panel boş
// gövdeli bir 500 döndürüyordu — canlıda neyin kırıldığı görünmüyordu.
// N-21: göreli yol çalışma dizinine göre çözülüyordu (`php -S` api/ içinden
// başlatılınca girişten sonra fatal error). __DIR__ ile dosyaya göre.
require_once __DIR__ . '/../functions/db.php';
$database = Database::getInstance();
$conn = $database->getConnection();


$currentPath = $_SERVER['REQUEST_URI'];

// Route table, resolved before any output. The switch that used to do this ran
// after the header partial had already been echoed, so a 404 status could not
// be sent from there at all ("headers already sent") — and with no default
// branch an unknown /admin/* path rendered an empty 200 byte-identical to
// /admin/. Deciding the route up here makes the status code possible.
$adminRoutes = [
    '/admin'                    => null,   // dashboard root, no sub-page
    '/admin/'                   => null,
    '/admin/adminler'           => 'adminler.php',
    '/admin/seo'                => 'seo.php',
    '/admin/sosyalmedya'        => 'sosyal.php',
    '/admin/hit'                => 'hit.php',
    '/admin/smtp'               => 'smtp.php',
    '/admin/genelayar'          => 'genelayar.php',
    '/admin/iletisim'           => 'iletisim.php',
    '/admin/api'                => 'api.php',
    '/admin/chatbotkategoriler' => 'chatbotkategoriler.php',
    '/admin/kullanicilar'       => 'kullanicilar.php',
    '/admin/chatbotlar'         => 'chatbotlar.php',
    '/admin/chatbotistatistik'  => 'chatbotistatistik.php',
    '/admin/chatayar'           => 'chatayar.php',
    '/admin/abonelik'           => 'abonelik.php',
    // COMP-007 / AUDIT D-01: bu satır olmadan `listWithdrawals()` ve
    // `updateWithdrawalStatus()` yayında olmalarına rağmen çağrılamıyordu ve
    // her çekim talebi kalıcı olarak `beklemede` kalıyordu.
    '/admin/parcekme'           => 'parcekme.php',
    // Faz 5 / madde 3 — pazaryeri başvuruları (GK-9, GK-23, GK-26).
    '/admin/basvurular'         => 'basvurular.php',
    // Faz 7a-3 — Hoppa paket ödemeleri ve iade.
    '/admin/odemeler'           => 'odemeler.php',
    '/admin/odemeentegrasyon'   => 'odemeentegrasyon.php',
    '/admin/anasayfa'           => 'anasayfa.php',
    '/admin/hakkinda'           => 'hakkinda.php',
    '/admin/kullanimkosullari'  => 'kullanimkosullari.php',
    '/admin/gizlilikpolitikasi' => 'gizlilikpolitikasi.php',
    '/admin/teslimatiade'       => 'teslimatiade.php',
    '/admin/satiskosullari'     => 'satiskosullari.php',
];

$routePath  = strtok($currentPath, '?');
$routeKnown = array_key_exists($routePath, $adminRoutes);
if (!$routeKnown) {
    http_response_code(404);
}

require_once __DIR__ . '/functions/session.php';
admin_session_start();
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

?>
<?php if (!isset($_SESSION['admin'])): ?>
  <?php include __DIR__ . '/partials/_login.php'; ?>
<?php else: ?>
  <?php
  // G-17 — `getGlobalVars()` bir DİZİ döndürüyor (['theme_index' => '2']),
  // `intval()` ise diziye uygulandığında PHP 8'de her zaman 1 verir (üstelik
  // "Array to string conversion" uyarısı basar). Yani tema seçimi hiç
  // çalışmıyor, her zaman ilk tema kullanılıyordu. Doğru okuma
  // `genelayar.php:2`'deki desen.
  //
  // `$themes` boşsa `$themes[0]` da yok: `$current_theme` null kalıyor ve
  // onu sayfa boyunca dizi gibi okuyan `_sidebar.php` her erişimde uyarı
  // basıp boş sınıf adı üretiyordu (kenar çubuğu görünmez oluyordu).
  // Bu yüzden son çare olarak sabit bir varsayılan tema var.
  $themes       = $database->selectMulti("* FROM themes");
  $theme_index  = (int) ($database->getGlobalVars('theme_index')['theme_index'] ?? 1);
  $current_theme = $themes[$theme_index - 1] ?? ($themes[0] ?? [
      'main_color'   => 'bg-gray-800',
      'sub_color'    => 'bg-gray-700',
      'hover_color'  => 'bg-gray-700',
      'active_color' => 'bg-gray-900',
      'text_color'   => 'text-white',
  ]);
  ?>
  <?php include __DIR__ . '/partials/_header.php'; ?>

  <div class="flex lg:flex-row">
    <?php include __DIR__ . '/partials/_sidebar.php'; ?>

    <main id="admin-panel-content" class="flex-1 bg-gray-100 min-h-screen p-6 transition-all duration-300">
      <?php
      if (!$routeKnown) {
          echo '<div class="rounded-xl bg-white p-8 text-center text-gray-600 shadow">'
             . '<p class="text-lg font-semibold text-gray-800">Sayfa bulunamadı</p>'
             . '<p class="mt-2 text-sm">Aradığınız yönetim sayfası mevcut değil.</p>'
             . '</div>';
      } elseif ($adminRoutes[$routePath] !== null) {
          include __DIR__ . '/' . $adminRoutes[$routePath];
      }
      ?>
    </main>
  </div>
  <!-- <footer class="text-center <?= $current_theme['text_color'] ?> py-3 <?= $current_theme['main_color'] ?>">
        <div>
            <p class="mb-0">Alper KUM © 2025 Tüm hakları saklıdır.</p>
        </div>
    </footer> -->
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const adminNav = document.getElementById('admin-nav');
      const adminPanel = document.getElementById('admin-panel-content');
      const sidebarToggle = document.getElementById('sidebarToggle');
      const toggleButtons = document.querySelectorAll('.toggleButton');

      // Sidebar yüksekliğini admin panel içeriğine göre ayarla
      function updateNavHeight() {
        if (adminPanel && adminNav) {
          adminNav.style.height = `${adminPanel.offsetHeight}px`;
        }
      }

      // Sidebar aç/kapa
      if (sidebarToggle && adminNav) {
        sidebarToggle.addEventListener('click', function() {
          adminNav.classList.toggle('-translate-x-full');
        });
      }

      // Alt menüleri aç/kapa + chevron yönü
      toggleButtons.forEach(button => {
        button.addEventListener('click', function() {
          const contentList = this.nextElementSibling;
          const chevronIcon = this.querySelector('.bi-chevron-down, .bi-chevron-up');
          const isClosed = contentList.style.maxHeight === '0px' || contentList.style.maxHeight === '';

          if (isClosed) {
            // Önce scrollHeight kadar aç
            contentList.style.maxHeight = contentList.scrollHeight + "px";
            
            // Animasyon bittikten sonra limiti kaldır (böylece içerik asla kesilmez)
            contentList.addEventListener('transitionend', function once() {
              if (contentList.style.maxHeight !== '0px') {
                contentList.style.maxHeight = 'none';
              }
              contentList.removeEventListener('transitionend', once);
            });

            if (chevronIcon) {
              chevronIcon.classList.replace('bi-chevron-down', 'bi-chevron-up');
            }
          } else {
            // Kapatırken 'none' ise önce tekrar sayısal değere çekmeliyiz ki animasyon çalışsın
            if (contentList.style.maxHeight === 'none') {
              contentList.style.maxHeight = contentList.scrollHeight + 'px';
              // Tarayıcının render etmesi için minik bir saniye beklet (reflow)
              contentList.offsetHeight; 
            }
            
            contentList.style.maxHeight = '0px';
            if (chevronIcon) {
              chevronIcon.classList.replace('bi-chevron-up', 'bi-chevron-down');
            }
          }
        });
      });

      // Resize olduğunda sidebar yüksekliğini güncelle
      if (adminPanel) {
        const resizeObserver = new ResizeObserver(() => {
          updateNavHeight();
        });
        resizeObserver.observe(adminPanel);
      }

      updateNavHeight();
    });
  </script>
  </body>

  </html>
<?php endif; ?>
