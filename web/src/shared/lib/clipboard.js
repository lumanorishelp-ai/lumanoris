// N-41 — panoya kopyalama, sonucu bildirerek.
//
// Eskiden `navigator.clipboard.writeText()` beklenmeden çağrılıp hemen
// "Kopyalandı" gösteriliyordu; izin reddi, güvensiz bağlam (http) ya da desteği
// olmayan tarayıcıda hiçbir şey kopyalanmadığı hâlde kullanıcı başarı görüyordu.
// Dönüş: true yalnızca yazma gerçekten tamamlandıysa. Fırlatmaz.
export async function copyText(text) {
  try {
    if (typeof navigator === "undefined" || !navigator.clipboard?.writeText) return false;
    await navigator.clipboard.writeText(text);
    return true;
  } catch {
    return false;
  }
}
