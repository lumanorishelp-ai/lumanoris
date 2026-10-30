/**
 * N-59 — sohbet cevabı üretilemediğinde kullanıcıya gösterilecek metin.
 * Sunucu hata karesinde `kind` gönderir (functions/gemini.php →
 * geminiFailureKind); eski sunucu yalnızca `code` gönderir.
 *
 * Müşteri görseli 04: Google'ın geçici yoğunluğu (503 "high demand") bile
 * "Yapay zeka servisine şu anda ulaşılamıyor… yöneticinize bildirin" diye
 * görünüyordu — kullanıcı sistemi bozuk sanıyordu.
 */

const TEXTS = {
  busy: "Şu an yoğunluk var, lütfen birkaç saniye sonra tekrar deneyin.",
  quota: "Yapay zeka servisinin kullanım sınırına ulaşıldı. Lütfen daha sonra tekrar deneyin.",
  timeout: "Yanıt zamanında gelmedi. Tekrar Dene'ye basarak yeniden sorabilirsiniz.",
  unavailable: "Yapay zeka servisine şu anda ulaşılamıyor. Biraz sonra tekrar deneyin.",
  network: "Yapay zeka servisine şu anda ulaşılamıyor. Biraz sonra tekrar deneyin.",
  config: "Yapay zeka servisi şu anda yanıt veremiyor. Sorun sürerse yöneticinize bildirin.",
  empty: "Bu soruya yanıt üretilemedi. Sorunuzu farklı biçimde yazmayı deneyin.",
};

/**
 * @param {{kind?: string, code?: number, refunded?: boolean} | null} error sunucunun hata karesi
 * @returns {string}
 */
export function chatFailureText(error) {
  if (!error) return "Şu anda cevap veremiyorum.";
  let kind = typeof error.kind === "string" && TEXTS[error.kind] ? error.kind : null;
  if (!kind) kind = error.code === 429 || error.code === 503 ? "busy" : "unavailable";
  const refund = error.refunded ? " Bu mesaj için LumaCoin düşülmedi." : "";
  return TEXTS[kind] + refund;
}
