<?php
/**
 * Sistem durumu — yalnızca admin oturumu (index.php rotası). Dosyaya doğrudan
 * istek oturumsuzsa hiçbir şey göstermez.
 *
 * Gösterilenler gizli değer DEĞİL: anahtarın hangi dosyadan okunduğu (değeri
 * değil), model adı, PAYMENT_PROVIDER / HOPPA_MODE ve Hoppa kimlik
 * bilgilerinin dolu olup olmadığı. Gemini testi ajax/sistemdurumu.php'de
 * (POST + CSRF), sohbetle aynı model ve uç noktaya gider.
 */
if (empty($_SESSION['admin'])) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../functions/gemini.php';
require_once __DIR__ . '/../src/autoload.php';

$rawProvider = (string) env_get('PAYMENT_PROVIDER', '');
$provider    = PaymentGatewayFactory::provider();
$hoppaMode   = strtolower(trim((string) env_get('HOPPA_MODE', 'test')));
$prefix      = $hoppaMode === 'live' ? 'HOPPA_LIVE_' : 'HOPPA_TEST_';
$hoppaCreds  = trim((string) env_get($prefix . 'MERCHANT', '')) !== '' && trim((string) env_get($prefix . 'MERCHANT_KEY', '')) !== '';
$keySource   = geminiKeySource();
// N-56 — belge yükleme ve Google girişi api/vendor/'e bağlı; eksikse burada görünsün.
$vendorPdf    = VendorAutoload::load('sistemdurumu', VendorAutoload::PDF_CLASSES);
$vendorGoogle = $vendorPdf && class_exists('Google_Client');
$adminVendor  = is_file(__DIR__ . '/vendor/autoload.php');
$appDebug     = env_bool('APP_DEBUG', false);
$h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$okBad = static fn(bool $ok, string $bad) => $ok ? '<span class="text-emerald-600">kurulu</span>' : '<span class="text-rose-600">' . $bad . '</span>';
?>
<main class="bg-gray-50 p-6 min-h-screen">
    <div class="max-w-screen-xl mx-auto space-y-6">
        <?php pageTitle("Sistem Durumu", "Yapay zeka servisi ve ödeme yapılandırması. Gizli değerler gösterilmez."); ?>

        <section class="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
            <h2 class="font-semibold text-gray-800 mb-3">Yapay zeka (Gemini)</h2>
            <dl class="grid grid-cols-1 sm:grid-cols-[220px_1fr] gap-y-2 text-sm">
                <dt class="text-gray-500">Model</dt><dd class="font-mono"><?= $h(GEMINI_MODEL) ?></dd>
                <dt class="text-gray-500">Anahtar kaynağı</dt><dd class="font-mono"><?= $h($keySource) ?></dd>
            </dl>
            <p class="mt-2 text-xs text-gray-500">Uygulama önce <code>api/.env</code> içindeki <code>API_GOOGLE_GEMINI</code> satırını okur; yoksa <code>api/admin/.env</code>. "API Entegrasyonları" sayfası <code>api/admin/.env</code>'ye yazar.</p>
            <div class="mt-4 flex items-center gap-3">
                <button type="button" id="geminiTestBtn" class="bg-indigo-600 text-white font-semibold px-5 py-2 rounded-lg hover:bg-indigo-700 transition">Gemini'yi test et</button>
                <span id="geminiTestBusy" class="text-sm text-gray-500 hidden">Test ediliyor…</span>
            </div>
            <div id="geminiTestResult" class="hidden">
            <dl class="mt-4 grid grid-cols-1 sm:grid-cols-[220px_1fr] gap-y-2 text-sm">
                <dt class="text-gray-500">Sonuç</dt><dd id="gtOk" class="font-semibold"></dd>
                <dt class="text-gray-500">HTTP kodu</dt><dd id="gtHttp" class="font-mono"></dd>
                <dt class="text-gray-500">Model</dt><dd id="gtModel" class="font-mono"></dd>
                <dt class="text-gray-500">Google durumu</dt><dd id="gtStatus" class="font-mono"></dd>
                <dt class="text-gray-500">Google mesajı</dt><dd id="gtMessage" class="break-words"></dd>
            </dl>
            </div>
        </section>

        <section class="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
            <h2 class="font-semibold text-gray-800 mb-3">Sunucu bileşenleri</h2>
            <dl class="grid grid-cols-1 sm:grid-cols-[220px_1fr] gap-y-2 text-sm">
                <dt class="text-gray-500">PHP sürümü</dt><dd class="font-mono"><?= $h(PHP_VERSION) ?></dd>
                <dt class="text-gray-500">Belge (PDF) okuma — api/vendor</dt><dd><?= $okBad($vendorPdf, 'eksik → belge yükleme çalışmaz') ?></dd>
                <dt class="text-gray-500">Google ile giriş — api/vendor</dt><dd><?= $okBad($vendorGoogle, 'eksik → Google girişi çalışmaz') ?></dd>
                <dt class="text-gray-500">Admin paneli — admin/vendor</dt><dd><?= $okBad($adminVendor, 'eksik → API Entegrasyonları sayfası çalışmaz') ?></dd>
                <dt class="text-gray-500">APP_DEBUG</dt><dd><?= $appDebug ? '<span class="text-rose-600 font-semibold">true — hata ayrıntıları (sunucu yolları) kullanıcıya gösteriliyor; canlıda false olmalı</span>' : '<span class="text-emerald-600">false</span>' ?></dd>
            </dl>
            <p class="mt-2 text-xs text-gray-500">Eksik bileşenin ayrıntısı (yol, eksik sınıf) hata logunda <code>[vendor]</code> satırlarındadır. Çözüm: vendor/ içeren deploy paketini yükleyin (README → Backend deploy).</p>
        </section>

        <section class="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
            <h2 class="font-semibold text-gray-800 mb-3">Ödeme</h2>
            <dl class="grid grid-cols-1 sm:grid-cols-[220px_1fr] gap-y-2 text-sm">
                <dt class="text-gray-500">PAYMENT_PROVIDER</dt>
                <dd class="font-mono"><?= $h($provider) ?><?= $rawProvider !== '' && strtolower(trim($rawProvider)) !== $provider ? ' <span class="text-rose-600">(.env değeri tanınmadı: ' . $h($rawProvider) . ')</span>' : '' ?><?= $rawProvider === '' ? ' <span class="text-gray-400">(satır yok → none)</span>' : '' ?></dd>
                <dt class="text-gray-500">HOPPA_MODE</dt><dd class="font-mono"><?= $h($hoppaMode) ?></dd>
                <dt class="text-gray-500"><?= $h($prefix) ?>MERCHANT / KEY</dt><dd><?= $hoppaCreds ? 'dolu' : '<span class="text-rose-600">eksik</span>' ?></dd>
                <dt class="text-gray-500">Ödeme durumu</dt><dd class="font-semibold"><?= $provider === 'none' ? 'Kapalı' : ($hoppaCreds ? 'Açık (' . $h($hoppaMode) . ')' : '<span class="text-rose-600">Seçili ama kimlik bilgisi eksik → kapalı</span>') ?></dd>
            </dl>
            <div class="mt-4 flex items-center gap-3">
                <button type="button" id="hoppaTestBtn" class="bg-indigo-600 text-white font-semibold px-5 py-2 rounded-lg hover:bg-indigo-700 transition">Hoppa bağlantı testi</button>
                <span id="hoppaTestBusy" class="text-sm text-gray-500 hidden">Test ediliyor…</span>
            </div>
            <p class="mt-2 text-xs text-gray-500">Ödeme başlatmaz, para hareketi yoktur: var olmayan bir sipariş numarasıyla Hoppa'ya "işlem sorgula" isteği atar ve seçili moddaki MERCHANT / MERCHANT_KEY'in kabul edilip edilmediğini gösterir.</p>
            <div id="hoppaTestResult" class="hidden">
            <dl class="mt-4 grid grid-cols-1 sm:grid-cols-[220px_1fr] gap-y-2 text-sm">
                <dt class="text-gray-500">Sonuç</dt><dd id="htOk" class="font-semibold"></dd>
                <dt class="text-gray-500">Açıklama</dt><dd id="htExplain" class="break-words"></dd>
                <dt class="text-gray-500">Mod / adres</dt><dd id="htMode" class="font-mono"></dd>
                <dt class="text-gray-500">Hoppa durumu / kodu</dt><dd id="htStatus" class="font-mono"></dd>
                <dt class="text-gray-500">Hoppa mesajı</dt><dd id="htMessage" class="break-words"></dd>
                <dt class="text-gray-500">Süre</dt><dd id="htMs" class="font-mono"></dd>
            </dl>
            </div>
        </section>
    </div>
</main>
<script>
    document.getElementById('hoppaTestBtn').addEventListener('click', async () => {
        const btn = document.getElementById('hoppaTestBtn');
        const busy = document.getElementById('hoppaTestBusy');
        btn.disabled = true; busy.classList.remove('hidden');
        const set = (id, v) => { document.getElementById(id).textContent = v || '—'; };
        try {
            const body = new FormData();
            body.append('test', 'hoppa');
            const res = await fetch('/admin/ajax/sistemdurumu.php', { method: 'POST', body });
            const r = await res.json();
            if (!r.success) throw new Error(r.message || 'İstek başarısız');
            const t = r.hoppa;
            set('htOk', t.ok ? 'Kimlik bilgileri kabul edildi' : 'Hata (' + t.kind + ')');
            document.getElementById('htOk').className = 'font-semibold ' + (t.ok ? 'text-emerald-600' : 'text-rose-600');
            set('htExplain', t.explain);
            set('htMode', t.mode + ' · ' + t.base_url);
            set('htStatus', [t.status, t.code].filter(Boolean).join(' · '));
            set('htMessage', t.message);
            set('htMs', t.ms + ' ms');
            document.getElementById('hoppaTestResult').classList.remove('hidden');
        } catch (e) {
            new Notification({ text: 'Test yapılamadı: ' + e.message, type: 'error', position: 'top-right' });
        } finally {
            btn.disabled = false; busy.classList.add('hidden');
        }
    });

    document.getElementById('geminiTestBtn').addEventListener('click', async () => {
        const btn = document.getElementById('geminiTestBtn');
        const busy = document.getElementById('geminiTestBusy');
        btn.disabled = true; busy.classList.remove('hidden');
        const set = (id, v) => { document.getElementById(id).textContent = v || '—'; };
        try {
            const res = await fetch('/admin/ajax/sistemdurumu.php', { method: 'POST' });
            const r = await res.json();
            if (!r.success) throw new Error(r.message || 'İstek başarısız');
            const g = r.gemini;
            set('gtOk', g.ok ? 'Çalışıyor' : 'Hata');
            document.getElementById('gtOk').className = 'font-semibold ' + (g.ok ? 'text-emerald-600' : 'text-rose-600');
            set('gtHttp', String(g.http) + (g.curl ? ' (ağ: ' + g.curl + ')' : ''));
            set('gtModel', g.model);
            set('gtStatus', [g.status, g.reason].filter(Boolean).join(' · '));
            set('gtMessage', g.message);
            document.getElementById('geminiTestResult').classList.remove('hidden');
        } catch (e) {
            new Notification({ text: 'Test yapılamadı: ' + e.message, type: 'error', position: 'top-right' });
        } finally {
            btn.disabled = false; busy.classList.add('hidden');
        }
    });
</script>
