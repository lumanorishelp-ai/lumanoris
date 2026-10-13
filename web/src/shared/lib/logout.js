// N-39 — çıkışın tek istemci yolu (başlık ve kenar çubuğu).
//
// Eskiden iki yer de logout.php'nin yanıtına bakmadan /login'e yönlendiriyordu.
// Çıkış başarısızsa oturum açık kalıyor, /login oturumu görüp kullanıcıyı geri
// gönderiyordu: kullanıcı (paylaşılan bir bilgisayarda bile) çıktığını sanıyordu.
// Şimdi yalnızca sunucu `success` dönünce yönlendirilir; aksi hâlde çağıran
// hatayı gösterir ve kullanıcı sayfada kalır.
//
// Dönüş: { ok: true } ya da { ok: false, message } — fırlatmaz.
export async function logoutAndRedirect() {
  try {
    const res = await fetch("/api/auth/logout.php", {
      method: "POST",
      credentials: "include",
    });
    const result = await res.json().catch(() => null);
    if (!res.ok || !result?.success) {
      return {
        ok: false,
        message: result?.message || `Çıkış yapılamadı (HTTP ${res.status}). Lütfen tekrar deneyin.`,
      };
    }
  } catch {
    return { ok: false, message: "Sunucuya ulaşılamadı. Çıkış yapılamadı." };
  }
  if (typeof window !== "undefined") {
    window.location.href = "/login";
  }
  return { ok: true };
}
