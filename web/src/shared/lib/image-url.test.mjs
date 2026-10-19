// N-58 — tek görsel URL üreticisi. Çalıştırma: node --test web/src/shared/lib/
// (scripts/verify.sh → image_url_test). Bağımlılık yok.
import { test } from "node:test";
import assert from "node:assert/strict";
import { imageUrl, initialsOf } from "./image-url.mjs";

// Sunucudaki gerçek biçim (canlı, 2026-10-18: bot 39/40).
test("assets/ göreli yol kök-göreli olur (ön yüz /assets'i API'ye proxy'liyor)", () => {
  assert.equal(imageUrl("assets/profil_fotografi/1792312118_ac90fb93cb501187.webp"),
    "/assets/profil_fotografi/1792312118_ac90fb93cb501187.webp");
  assert.equal(imageUrl("  assets/kapak_fotografi/x.png \n"), "/assets/kapak_fotografi/x.png");
});

test("zaten çözülmüş biçimlere dokunulmaz", () => {
  for (const v of ["/assets/a.png", "https://api.lumanoris.com/assets/a.png", "http://x/y.jpg",
    "//cdn.example.com/a.png", "data:image/png;base64,iVBORw0KGgo=", "blob:https://x/123"]) {
    assert.equal(imageUrl(v), v);
  }
});

test("eski kayıt: önekli olmayan ham base64 → data URI (tür imzadan)", () => {
  const jpeg = "/9j/4AAQSkZJRgABAQAAAQABAAD" + "A".repeat(200);
  const png = "iVBORw0KGgoAAAANSUhEUgAA" + "B".repeat(200);
  const webp = "UklGRiQAAABXRUJQVlA4" + "C".repeat(200);
  assert.equal(imageUrl(jpeg), `data:image/jpeg;base64,${jpeg}`);
  assert.equal(imageUrl(png), `data:image/png;base64,${png}`);
  assert.equal(imageUrl(webp), `data:image/webp;base64,${webp}`);
});

test("'/9j/…' ile başlayan kısa kök-göreli yol base64 sanılmaz", () => {
  assert.equal(imageUrl("/9j/a.png"), "/9j/a.png");
});

test("boş/anlamsız değerler → boş dize (çağıran yer tutucu gösterir)", () => {
  for (const v of [null, undefined, "", "   ", "default", "0", "null", "undefined", 0, false, {}]) {
    assert.equal(imageUrl(v), "", `girdi: ${JSON.stringify(v)}`);
  }
});

test("statik import nesnesi ({src}) desteklenir", () => {
  assert.equal(imageUrl({ src: "/_next/static/media/a.jpg", width: 1 }), "/_next/static/media/a.jpg");
});

test("bozuk birleştirme biçimleri (eski yardımcıların ürettiği) düzeltilir", () => {
  // chat/page.jsx formatImage: göreli yolun önüne data öneki ekliyordu.
  assert.equal(imageUrl("data:image/jpeg;base64,assets/profil_fotografi/x.webp"), "/assets/profil_fotografi/x.webp");
  // dashboard/page.jsx & BotCard.bento: /uploads/avatars/ + göreli yol.
  assert.equal(imageUrl("/uploads/avatars/assets/profil_fotografi/x.webp"), "/assets/profil_fotografi/x.webp");
});

test("javascript: ve benzeri şemalar reddedilir", () => {
  assert.equal(imageUrl("javascript:alert(1)"), "");
  assert.equal(imageUrl("vbscript:x"), "");
});

test("baş harfler: Türkçe büyük harf, en fazla 2", () => {
  assert.equal(initialsOf("Aristoteles"), "A");
  assert.equal(initialsOf("Fatih Sultan Mehmet"), "FS");
  assert.equal(initialsOf("ılgaz istanbul"), "Iİ");
  assert.equal(initialsOf("  çağrı  "), "Ç");
  assert.equal(initialsOf("LUMANORIS AI"), "LA");
  assert.equal(initialsOf(""), "?");
  assert.equal(initialsOf(null), "?");
  assert.equal(initialsOf("🤖 Bot"), "B");
});
