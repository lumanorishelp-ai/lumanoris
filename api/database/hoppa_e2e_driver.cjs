/**
 * Hoppa TEST ortamı ödeme sayfası sürücüsü — yalnızca hoppa_selftest.php --e2e.
 *
 *   node hoppa_e2e_driver.cjs <URL_3DS> <test_kart_no>
 *
 * Ortak Ödeme Sayfası'nda kart bilgisi kullanıcının tarayıcısında girildiği
 * için uçtan uca test gerçek bir tarayıcı gerektiriyor. Bu sürücü sayfayı
 * açar, dokümandaki herkese açık TEST kartını girer, 3D test sayfasını geçer
 * ve BACK_URL'e (…/hoppa_return.php) giden form POST'unu yakalar. POST
 * gerçek uca gitmez; yakalanan gövde stdout'a JSON basılır ve selftest
 * kesinleştirmeyi kendi transaction'ı içinde (ROLLBACK ile) yapar.
 *
 * Gereksinim: `playwright-core` (NODE_PATH ile; selftest HOPPA_E2E_NODE_PATH'i
 * aktarır) ve bir Chromium/Chrome. Tarayıcı yolu HOPPA_E2E_CHROME ile
 * verilebilir; verilmezse playwright'ın indirdiği Chromium denenir.
 *
 * Güvenlik: yalnızca esnekpos.com test alan adlarındaki bir adresi açar.
 */
const { chromium } = require("playwright-core");

(async () => {
  const [url, card] = process.argv.slice(2);
  const host = new URL(url).hostname;
  if (!/(^|\.)esnekpos\.com$/.test(host) || !host.includes("test")) {
    throw new Error("Yalnızca Hoppa TEST ödeme sayfası açılabilir: " + host);
  }

  const launch = { headless: true };
  if (process.env.HOPPA_E2E_CHROME) launch.executablePath = process.env.HOPPA_E2E_CHROME;
  const browser = await chromium.launch(launch);
  const page = await browser.newPage();

  let captured = null;
  await page.route("**/hoppa_return.php*", async (route) => {
    captured = route.request().postData() || "";
    await route.fulfill({ status: 200, contentType: "text/html", body: "<html><body>ok</body></html>" });
  });

  await page.goto(url, { waitUntil: "networkidle", timeout: 60000 });
  await page.fill("#Card_Number", card);
  await page.fill("#Card_Name", "TEST KULLANICI");
  await page.selectOption("#expirymonth", "12");
  await page.selectOption("#expiryyear", String(new Date().getFullYear() + 3));
  await page.fill("#Card_CvC", "000");
  await page.waitForTimeout(1500); // BIN sorgusu taksit tablosunu dolduruyor
  await page.click("text=ÖDEME YAP");

  // Ardından Hoppa'nın 3D test sayfası (ThreeDTestPage.aspx) gelir; onay
  // düğmesine basılınca sonuç BACK_URL'e form POST edilir.
  for (let i = 0; i < 40 && captured === null; i++) {
    await page.waitForTimeout(1000);
    if (captured !== null || page.url().includes("CommonPaymentNew")) continue;
    try {
      const submit = await page.$("input[type=submit]:visible, button[type=submit]:visible");
      if (submit) await submit.click();
    } catch {
      // Sayfa tam bu sırada yönleniyorsa (3D sayfası kendiliğinden POST
      // edebiliyor) bağlam yok olur; bir sonraki turda yeniden bakılır.
    }
  }
  await browser.close();

  if (captured === null) throw new Error("BACK_URL'e dönüş yakalanamadı (40 sn).");
  process.stdout.write(JSON.stringify(Object.fromEntries(new URLSearchParams(captured))));
})().catch((e) => {
  process.stderr.write(String(e && e.message ? e.message : e));
  process.exit(1);
});
