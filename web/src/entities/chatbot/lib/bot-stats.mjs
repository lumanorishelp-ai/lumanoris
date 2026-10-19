/**
 * N-66 — bot pop-up'ındaki sayılar tek kaynaktan.
 *
 * Müşteri görselleri 05 ve 07: aynı bot için kart "8 diyalog / 1 beğeni",
 * pop-up "6 diyalog / 0 beğeni / 0 takipçi". Sunucuda tüm uçlar aynı sayıyı
 * hesaplıyor; pop-up ise kendi verisini çekmiyor, sayfa yüklendiği andaki
 * liste anlık görüntüsünü gösteriyordu (kartın kalbe basınca artırdığı yerel
 * sayı ona hiç ulaşmıyordu). Pop-up artık açılırken getchatbot.php'den
 * (ChatbotRepository::getDetail — sohbet sayfasıyla aynı) okuyor.
 */
export function statsFromDetail(detail) {
  if (!detail) return null;
  const n = (v) => Number(v) || 0;
  return { dialogues: n(detail.toplam_chats), likes: n(detail.likes), followers: n(detail.follows) };
}

/** Kartla aynı fiyat biçimi (BotCard.bento): "Ücretsiz" ya da "₺X /hafta". */
export function priceLabel(weekly) {
  const p = Number(weekly) || 0;
  return p > 0 ? `₺${p.toLocaleString("tr-TR")} /hafta` : "Ücretsiz";
}
