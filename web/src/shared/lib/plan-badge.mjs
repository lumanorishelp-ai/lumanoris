/**
 * N-64 — sol üstteki "PRO" etiketinin TEK karar noktası (Sidebar, DashboardHeader).
 *
 * Eskiden karar plan ADINA göre veriliyordu (`isPaidPlan(planName)`: ad
 * "Ücretsiz" değilse PRO). Varsayılan planın adı farklı yazılmışsa ücretsiz
 * kullanıcıda da görünüyordu (E-05 geçmişi) ve süresi dolan paket, sayfa
 * yenilenene kadar PRO kalıyordu. Artık:
 *   - sunucunun fiyat temelli bayrağı (getuserheader → planIsPaid) şart;
 *     bilinmiyorsa gösterilmez (fail-closed),
 *   - bitiş anı geçtiyse gösterilmez (planSecondsLeft'ten; saat dilimi bağımsız).
 */

/** @returns {number|null} bitiş anı (ms) — süresizse null */
export function planExpiryMs(secondsLeft, now = Date.now()) {
  return typeof secondsLeft === "number" && Number.isFinite(secondsLeft) ? now + secondsLeft * 1000 : null;
}

export function showProBadge(account, now = Date.now()) {
  if (!account || account.planIsPaid !== true) return false;
  const exp = account.planExpiresAtMs;
  return exp === null || exp === undefined || now < exp;
}
