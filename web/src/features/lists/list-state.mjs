/**
 * N-65 — liste durumu yardımcıları (Liste sayfası, "Listeye Ekle" penceresi).
 *
 * Müşteri görseli 08: liste içindeki çöp kutusu hiçbir şey yapmıyordu —
 * ListDetailModal `onRemoveBot` verilmeden çiziliyordu.
 */

/** Bir yerde liste değişince diğer açık görünümler yenilensin (window olayı). */
export const LISTS_CHANGED_EVENT = "lumanoris:lists-changed";

/** Botu listeden çıkarılmış YENİ liste nesnesi; sayaçlar yeniden hesaplanır. */
export function withoutBot(list, botId) {
  const bots = (list.bots || []).filter((b) => String(b.id) !== String(botId));
  const chats = bots.reduce((sum, b) => sum + (Number(b.toplam_chats ?? b.dialogues) || 0), 0);
  return { ...list, bots, summary: `${bots.length} Bot İçeriyor`, dialog: `${chats} Diyalog` };
}

export function announceListsChanged() {
  if (typeof window !== "undefined") window.dispatchEvent(new Event(LISTS_CHANGED_EVENT));
}
