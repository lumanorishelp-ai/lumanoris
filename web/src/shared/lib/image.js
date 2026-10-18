import avatarFallback from "@/images/avatar-bot.jpg";
import coverFallback from "@/images/bot-image.png";
import { imageUrl } from "./image-url.mjs";

/**
 * next/image's <Image> throws a hard render error when `src` is
 * null/undefined/"" (not just a broken-image icon like a plain <img> would
 * show) — so any chatbot row missing a profil_fotografi/kapak_fotografi
 * crashes the whole page wherever this wasn't guarded. Normal bots can't
 * end up without both images (ChatbotForm requires them before submit), but
 * a bot inserted outside that form — e.g. a system/platform bot seeded
 * directly into the database — can. Route every chatbot avatar/cover
 * through one of these instead of a one-off `||`/`&&` at each call site, so
 * the guard can't be forgotten the next time a card/list/modal needs one.
 */
/**
 * Her iki yardimci da DAIMA string dondurur.
 *
 * Eskiden yedek deger olarak static import nesnesinin kendisi donuyordu
 * ({src, width, height, blurDataURL}). Bu yalnizca <Image> ile calisiyor;
 * duz bir <img src={...}> ile React nesneyi stringe cevirip
 * src="[object Object]" yaziyor ve gorsel kirik cikiyor — checkout/sepet
 * sayfasinda tam olarak bu oluyordu. Cagri yerlerinin bir kismi bunu
 * ".src" ekleyerek telafi etmisti, yani tuzaga birden fazla kez dusulmus.
 *
 * <Image> string src'yi zaten kabul ediyor (fill ya da acik width/height
 * ile kullaniliyorlar), bu yuzden tek tip string donmek iki tarafi da
 * memnun ediyor.
 */
/**
 * F-01 — Sunucu yuklenen gorseli GORELI yolla sakliyor:
 * `assets/kapak_fotografi/<ad>.jpg` (ChatbotController::handleImageUploads).
 * Boyle bir src tarayicida bulundugu sayfaya gore cozulur, yani
 * `/dashboard/chatbots` icinde `/dashboard/chatbots/assets/...` olur ve 404
 * verir. Dort sayfa bunu kendi yerel yardimcisiyla duzeltiyordu, uc render
 * noktasi duzeltmiyordu — normalizasyon artik burada, tek yerde.
 *
 * http(s):, protokolsuz //, data: ve zaten kok-goreli /... degerlere
 * dokunulmuyor.
 */
export function normalizeImagePath(value) {
    // N-58 — tek üretici: image-url.mjs (ham base64, bozuk eski biçimler,
    // "default"/"0" gibi boş değerler de burada çözülüyor).
    return imageUrl(value);
}

export function resolveAvatarSrc(value) {
    return normalizeImagePath(value) || avatarFallback.src;
}

export function resolveCoverSrc(value) {
    return normalizeImagePath(value) || coverFallback.src;
}
