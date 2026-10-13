/** @type {import('next').NextConfig} */
// NEXT_EXPORT=1 next build → paylaşımlı hosting için statik dosya üretir.
// nodemon server.js (dev) → NEXT_EXPORT tanımsız, custom server çalışır.
const isStaticExport = process.env.NEXT_EXPORT === '1';

// Vercel'de (VERCEL=1) derleme proje ayarına bağımlı kalmasın: Preview
// ortamında değişken tanımlanmamışsa derleme aşağıdaki korumalarda
// düşüyordu (PR derlemesi "PHP_TARGET tanımlı değil"). Vercel'de tanımsız
// değişken canlı değere düşer ve derleme log'una uyarı yazılır. Yerel
// production derlemesinde (VERCEL yok) korumalar aynen duruyor.
// NEXT_PUBLIC_SITE_URL burada process.env'e yazılınca Next onu derleme
// anında pakete gömüyor (site.js); PHP_TARGET aşağıdaki `env` ile sunucu
// paketine gömülüyor (legal-content.server.js çalışma anında okuyor).
const VERCEL_DEFAULTS = {
  PHP_TARGET: 'https://api.lumanoris.com',
  NEXT_PUBLIC_SITE_URL: 'https://www.lumanoris.net',
};
if (process.env.VERCEL === '1') {
  for (const [key, value] of Object.entries(VERCEL_DEFAULTS)) {
    if (!process.env[key]?.trim()) {
      console.warn(`[next.config] UYARI: ${key} Vercel ortam değişkenlerinde tanımlı değil — varsayılan ${value} kullanılıyor. Vercel → Settings → Environment Variables'a ekleyin.`);
      process.env[key] = value;
    }
  }
}
const resolvedPhpTarget = process.env.PHP_TARGET?.trim();

const nextConfig = {
  ...(isStaticExport && { output: 'export' }),

  // Doğrulama amaçlı bir `next build`, çalışan dev sunucusunun `.next`
  // klasörünü production çıktısıyla ezerse dev sunucu ayakta kalır ama
  // aradığı dev chunk'ları (main-app.js, app-pages-internals.js…) artık
  // yoktur: tarayıcıda 404 + "MIME type ('text/plain') is not executable".
  // NEXT_DIST_DIR ile böyle bir build ayrı bir klasöre yazılabilir.
  // Değişken tanımsızken davranış birebir eskisi gibi (.next).
  distDir: process.env.NEXT_DIST_DIR || '.next',

  // Vercel'de varsayılana düşülen PHP_TARGET çalışma anındaki sunucu
  // fonksiyonlarında yok (orada yalnızca proje ayarındaki değişkenler var);
  // tanımlıysa derleme anındaki değer pakete gömülüyor.
  ...(resolvedPhpTarget && { env: { PHP_TARGET: resolvedPhpTarget } }),

  // Statik export'ta route uyumu için (custom server'da gereksiz ama zararsız)
  trailingSlash: true,

  // Statik export'ta Next'in image optimizasyon API route'u çalışmaz (Node.js
  // süreci yok), o yüzden orada unoptimized zorunlu. Custom server (server.js)
  // ile çalışırken optimizasyon süreci mevcut, kapatmaya gerek yok.
  images: {
    unoptimized: isStaticExport,
  },

  reactStrictMode: false,
  // NEXT-001 🟠 — bu dosyada `headers()` yoktu: CSP, X-Frame-Options,
  // Referrer-Policy, HSTS hiçbiri tanımlı değildi. server.js ve .htaccess'te
  // de yoktu. Kendi başına bir açık değil ama SEC-017 (altı bileşende
  // dangerouslySetInnerHTML), SEC-005/009 + BE-001 (oturum sabitleme) ve
  // FE-005 (ham PAN) bulgularının **azaltıcı katmanı hiç yoktu**.
  //
  // Statik export'ta (output: 'export') headers() desteklenmiyor — o modda
  // başlıkları sunan web sunucusu (Apache/nginx) vermek zorunda; api/.htaccess
  // aynı üçlüyü PHP tarafı için zaten set ediyor.
  ...(!isStaticExport && {
    async headers() {
      // Next dev modu inline eval kullanıyor; script-src'de 'unsafe-eval'
      // yalnızca geliştirmede açık.
      const isDev = process.env.NODE_ENV !== 'production';

      const csp = [
        "default-src 'self'",
        // Next.js runtime'ı inline bootstrap script'i enjekte ediyor;
        // nonce'a geçmek ayrı bir iş (her sayfanın SSR'a alınması gerekir),
        // bu yüzden şimdilik 'unsafe-inline'.
        `script-src 'self' 'unsafe-inline' https://accounts.google.com${isDev ? " 'unsafe-eval'" : ''}`,
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://accounts.google.com",
        "font-src 'self' https://fonts.gstatic.com data:",
        "img-src 'self' data: blob: https:",
        "connect-src 'self' https://accounts.google.com",
        // FE-005 / SEC-017: sayfa hiçbir yere gömülemez (frame-ancestors). Gömebildiği
        // tek kaynak Google Identity Services: login/page.jsx gsi/client script'ini
        // yüklüyor ve renderButton butonu accounts.google.com kaynaklı bir iframe
        // içinde çiziyor — script/style/connect/frame izinlerinin dördü de gerekli.
        // Ödeme 3D akışı eklendiğinde frame-src ayrıca genişletilmeli.
        "frame-ancestors 'none'",
        "frame-src https://accounts.google.com",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        // HSTS ile ayni gerekce: bu direktif tarayiciya TUM http:// alt
        // istekleri https://'e yukselttiriyor. Yerelde uygulama duz http
        // uzerinden calisiyor; localhost disinda bir adresle (LAN IP,
        // makine adi, tunel) acildiginda istekler TLS servis etmeyen bir
        // porta https ile gidiyor ve ERR_SSL_PROTOCOL_ERROR aliniyor.
        // Production'da HTTPS zorunlu oldugu icin orada aciik kaliyor.
        ...(isDev ? [] : ["upgrade-insecure-requests"]),
      ].join('; ');

      const securityHeaders = [
        { key: 'Content-Security-Policy', value: csp },
        { key: 'X-Frame-Options', value: 'DENY' },
        { key: 'X-Content-Type-Options', value: 'nosniff' },
        { key: 'Referrer-Policy', value: 'strict-origin-when-cross-origin' },
        // MIC-001 — 'microphone=()' mikrofonu HER kaynak icin kapatiyordu,
        // kendi sayfamiz dahil. Sohbetteki sesli mesaj dugmesi bu yuzden izin
        // istemeden dogrudan NotAllowedError aliyordu. 'self' ile yalnizca
        // kendi originimiz kullanabilir; ucuncu taraf iframe'lere kapali kalir.
        { key: 'Permissions-Policy', value: 'camera=(), microphone=(self), geolocation=(), payment=()' },
        // 'same-origin' acilan pop-up ile window.opener bagini koparir.
        // Google ile giris pop-up'i kimlik bilgisini opener'a geri gonderiyor;
        // bag kopunca pencere acilip bos kaliyordu. 'same-origin-allow-popups'
        // dokumani belgede onerilen deger: sayfa yine cross-origin sayfalarca
        // acilmaya karsi korunur, yalnizca KENDI actigi pop-up'lar opener
        // baglantisini korur.
        { key: 'Cross-Origin-Opener-Policy', value: 'same-origin-allow-popups' },
      ];

      // HSTS yalnızca production'da: yerel HTTP geliştirmede tarayıcıyı
      // localhost'u kalıcı olarak HTTPS'e zorlamaya ikna eder ve geri almak
      // zordur.
      if (!isDev) {
        securityHeaders.push({
          key: 'Strict-Transport-Security',
          value: 'max-age=31536000; includeSubDomains',
        });
      }

      // /admin, /api ve /assets PHP tarafına proxy'leniyor ve kendi
      // başlıklarını api/.htaccess'ten alıyor. Admin paneli ayrıca iki
      // üçüncü taraf CDN'den script/CSS yüklüyor; buradaki CSP onları
      // engellerdi. Bu yüzden yalnızca Next'in kendi sayfaları kapsanıyor.
      return [
        {
          source: '/((?!api/|admin/|assets/).*)',
          headers: securityHeaders,
        },
      ];
    },
  }),


  // server.js (Express) proxy'si sadece kendi çalıştırdığımız Node
  // sürecinde (local dev, klasik VPS) devrede — Vercel custom server
  // çalıştırmaz, server.js orada hiç yürütülmez. Vercel (ve `next start`
  // ile çalışan her ortam) için aynı /api, /admin, /assets yönlendirmesini
  // Next'in kendi rewrites() mekanizmasıyla tekrarlıyoruz, PHP_TARGET env
  // değişkeni gerçek backend URL'ini gösterecek şekilde ayarlanmalı.
  // Statik export'ta (output: 'export') rewrites desteklenmediği için o
  // modda atlanıyor.
  ...(!isStaticExport && {
    async rewrites() {
      const phpTarget = process.env.PHP_TARGET?.trim();

      // Eksik PHP_TARGET eskiden sessizce boş liste döndürüyordu ve sonucu
      // teşhis edilmesi çok zor bir production arızasıydı:
      //   /api/auth/login.php isteği yönlendirilmeyip Vercel'in kendisinde
      //   kalıyor → Vercel WAF `.php` uzantısını saldırı taraması sanıp
      //   `403 Forbidden` (X-Vercel-Mitigated: deny) dönüyor → istemci JSON
      //   bekliyor, "Forbidden" metnini alıyor ve
      //   `SyntaxError: Unexpected token 'F'` ile düşüyor.
      // Yani giriş dahil TÜM API çağrıları kırılıyor ama build yeşil geçiyor.
      //
      // shared/config/site.js NEXT_PUBLIC_SITE_URL için aynı deseni zaten
      // uyguluyor: production build'de sessiz yanlış davranış yerine açık hata.
      if (!phpTarget) {
        if (process.env.NODE_ENV === 'production') {
          throw new Error(
            'PHP_TARGET tanımlı değil. Bu değişken olmadan /api, /admin ve /assets ' +
            'istekleri PHP backend\'e yönlendirilmez ve tarayıcı 403 alır. ' +
            'Vercel proje ayarlarına PHP_TARGET=https://api.lumanoris.com ekleyin ' +
            '(yerel geliştirme için web/.env dosyasına).'
          );
        }
        console.warn('[next.config] PHP_TARGET tanımsız — /api proxy\'si devre dışı.');
        return [];
      }

      // Sondaki eğik çizgi çift slash üretirdi (".../api//auth/login.php").
      const base = phpTarget.replace(/\/+$/, '');
      return [
        { source: '/api/:path*', destination: `${base}/api/:path*` },
        { source: '/admin/:path*', destination: `${base}/admin/:path*` },
        { source: '/assets/:path*', destination: `${base}/assets/:path*` },
      ];
    },
  }),
};

export default nextConfig;
// /** @type {import('next').NextConfig} */
// const nextConfig = {};

// export default nextConfig;
