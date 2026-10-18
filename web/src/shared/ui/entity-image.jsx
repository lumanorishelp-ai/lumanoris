"use client";

/**
 * N-58 — bot/kullanıcı görselleri için tek bileşen.
 *
 * - URL `imageUrl()` ile üretilir (tüm kayıt biçimleri, tek yer).
 * - Görsel yoksa ya da yüklenemezse (404, bozuk dosya) kırık ikon yerine
 *   baş harfli yer tutucu (avatar) / degrade (kapak) gösterilir.
 * - alt="" : ad zaten yanında yazılı; kırık görselde "avatar", "adnan" gibi
 *   metinlerin sayfaya dökülmesi (müşteri görselleri 03–05, 10) bitti.
 * - Düz <img>: next/image optimize edicisi data: URI ve dış adreslerde ayrı
 *   ayar ister; küçük avatarlar için kazancı yok.
 */
import { useEffect, useState } from "react";
import { cn } from "@/lib/utils";
import { imageUrl, initialsOf } from "@/shared/lib/image-url.mjs";

function useLoadable(src) {
  const url = imageUrl(src);
  const [failed, setFailed] = useState(false);
  useEffect(() => setFailed(false), [url]);
  return [url !== "" && !failed ? url : "", () => setFailed(true)];
}

/** Yuvarlak/kare avatar. Boyut ve şekil className ile (ör. "h-10 w-10 rounded-full"). */
export function EntityAvatar({ src, name, className, textClassName, ...rest }) {
  const [url, onError] = useLoadable(src);
  if (!url) {
    return (
      <span
        aria-hidden="true"
        className={cn(
          "inline-flex shrink-0 select-none items-center justify-center overflow-hidden bg-gradient-to-br from-violet-600 to-fuchsia-600 font-semibold text-white",
          className,
        )}
        {...rest}
      >
        <span className={cn("text-[0.8em] leading-none", textClassName)}>{initialsOf(name)}</span>
      </span>
    );
  }
  return (
    // eslint-disable-next-line @next/next/no-img-element
    <img
      src={url}
      alt=""
      loading="lazy"
      decoding="async"
      onError={onError}
      className={cn("shrink-0 object-cover", className)}
      {...rest}
    />
  );
}

/** Kapak görseli. Yoksa/yüklenemezse markanın degradesi. */
export function EntityCover({ src, className, ...rest }) {
  const [url, onError] = useLoadable(src);
  if (!url) {
    return (
      <span
        aria-hidden="true"
        className={cn(
          "block bg-[radial-gradient(120%_120%_at_0%_0%,#7c3aed_0%,#c026d3_45%,#0f0a1f_100%)]",
          className,
        )}
        {...rest}
      />
    );
  }
  return (
    // eslint-disable-next-line @next/next/no-img-element
    <img
      src={url}
      alt=""
      loading="lazy"
      decoding="async"
      onError={onError}
      className={cn("object-cover", className)}
      {...rest}
    />
  );
}
