/**
 * N-58 — TEK görsel URL üreticisi. Bot profil/kapak görselleri ve kullanıcı
 * avatarları her sayfada buradan geçer; yer tutucu kararı çağırana kalır
 * (boş dize = "görsel yok").
 *
 * Neden: canlıda (2026-10-18) dosyalar sunucuda duruyordu ve `/assets/…`
 * hem lumanoris.net hem api.lumanoris.com'da 200 dönüyordu, ama sayfaların
 * kendi yerel yardımcıları yolu bozuyordu:
 *   - chat/page.jsx `formatImage`: "data:image/jpeg;base64,assets/…"
 *     (sohbet başlığı, mesaj avatarı, "Takip ettiklerim" seçicisi)
 *   - dashboard/page.jsx, BotCard.bento, history: "/uploads/avatars/assets/…" → 404
 *
 * Desteklenen kayıt biçimleri (sunucu):
 *   assets/<sütun>/<dosya>  — ChatbotController / admin upload_guard
 *   data:image/…;base64,…   — kullanıcı avatarı
 *   ham base64 (öneksiz)     — eski admin akışı (G-14 öncesi)
 *   http(s)://, //, /…      — dokunulmaz
 *
 * Bağımlılığı yok: `node --test` ile doğrudan test ediliyor (image-url.test.mjs).
 */

const EMPTY_TOKENS = new Set(["", "default", "0", "null", "undefined"]);

/** Ham base64 imzası → MIME. */
const BASE64_SIGNATURES = [
  ["/9j/", "image/jpeg"],
  ["iVBOR", "image/png"],
  ["UklGR", "image/webp"],
  ["R0lGOD", "image/gif"],
];

function looksLikeRawBase64(s) {
  return s.length >= 100 && !s.includes(".") && /^[A-Za-z0-9+/=\s]+$/.test(s);
}

/**
 * @param {unknown} value DB değeri ya da statik import nesnesi
 * @returns {string} tarayıcıda kullanılabilir src, ya da "" (görsel yok)
 */
export function imageUrl(value) {
  if (value && typeof value === "object" && typeof value.src === "string") {
    return imageUrl(value.src);
  }
  if (typeof value !== "string") return "";
  let src = value.trim();
  if (EMPTY_TOKENS.has(src.toLowerCase())) return "";

  // Eski yardımcıların bozduğu biçimler (önbellekte/yerel durumda kalmış olabilir).
  const brokenData = /^data:image\/[a-z+]+;base64,(assets\/.+)$/i.exec(src);
  if (brokenData) src = brokenData[1];
  const brokenUploads = /^\/?uploads\/avatars\/(assets\/.+)$/i.exec(src);
  if (brokenUploads) src = brokenUploads[1];

  if (/^(data:image\/|blob:|https?:\/\/|\/\/)/i.test(src)) return src;
  if (/^[a-z][a-z0-9+.-]*:/i.test(src)) return ""; // javascript:, vbscript:, file: …

  if (looksLikeRawBase64(src)) {
    const clean = src.replace(/\s+/g, "");
    const mime = BASE64_SIGNATURES.find(([sig]) => clean.startsWith(sig))?.[1] ?? "image/jpeg";
    return `data:${mime};base64,${clean}`;
  }

  return src.startsWith("/") ? src : `/${src}`;
}

/** Yer tutucu için baş harfler (Türkçe büyük harf, en fazla 2). */
export function initialsOf(name) {
  if (typeof name !== "string") return "?";
  const words = name.trim().split(/\s+/).filter((w) => /^\p{L}/u.test(w));
  if (words.length === 0) return "?";
  return words
    .slice(0, 2)
    .map((w) => w[0].toLocaleUpperCase("tr-TR"))
    .join("");
}
