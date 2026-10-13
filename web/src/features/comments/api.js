// N-34 — bot yorumlarının TEK istemci yolu.
//
// Eskiden iki bileşen kendi isteğini kuruyor ve gövdeye `user_id` koyuyordu.
// Sunucu (SocialController::addComment) yalnızca `chatbot_id` ve `comment`
// kabul ediyor, başka her anahtarı 403 ile reddediyor ve yazarı OTURUMDAN
// alıyor — doğru olan bu. Sonuç: her yorum "Bu alanlar gönderilemez" ile
// düşüyordu. Kimlik buradan hiç gönderilmez; access_selftest bu dosyanın tek
// çağıran olduğunu ve kimlik alanı içermediğini kilitler.
//
// Dönüş: { ok: true, id } ya da { ok: false, message } — hiçbir durumda
// fırlatmaz; çağıran yalnızca `ok` ile karar verir.
export async function postBotComment(chatbotId, comment) {
  try {
    const formData = new FormData();
    formData.append("data", JSON.stringify({ chatbot_id: chatbotId, comment }));
    const res = await fetch("/api/social/addcomment.php", {
      method: "POST",
      body: formData,
      credentials: "include",
    });
    let result = null;
    try {
      result = await res.json();
    } catch {
      result = null;
    }
    if (res.ok && result?.success) {
      return { ok: true, id: result.id };
    }
    return {
      ok: false,
      message: result?.message || `Yorum kaydedilemedi (HTTP ${res.status}).`,
    };
  } catch (err) {
    return { ok: false, message: "Sunucuya ulaşılamadı. Bağlantınızı kontrol edin." };
  }
}
