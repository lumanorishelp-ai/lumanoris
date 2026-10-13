"use client";
import { useCallback, useEffect, useState } from "react";

/**
 * "Pazaryeri kaydı var mı?" — madde 4 (Bakiyem kapısı) ve başvuru sayfası
 * için TEK kaynak.
 *
 * GK-22 (Faz 5): karar artık SUNUCUDA, `application_status.php` yanıtındaki
 * `registered` alanında (`hasMarketplaceRegistration()`): yeni başvuru
 * tablosunda submitted/reviewed VEYA eski param_marketplace_sellers'ta
 * active/suspended.
 *
 * DAVRANIŞ DEĞİŞİKLİĞİ: Faz 2 sürümü eski tablodaki `pending` ve `rejected`
 * durumlarını da "kayıtlı" sayıyordu. `rejected` B1 yüzünden otomatik
 * yazılan bir ret olduğu için artık sayılmıyor; o kullanıcılar Bakiyem'i
 * görmek için yeni başvuru yapmalı.
 *
 * Bu bir ARAYÜZ kapısıdır; wallet uç noktalarının sunucu yetkisi değişmedi.
 *
 * Kenar çubuğu, başlık ve sayfa aynı anda soruyor; istek kullanıcı başına bir
 * kez atılıyor (modül düzeyinde önbellek). Başvuru gönderilince `refetch()`.
 */
const cache = new Map(); // userId -> Promise<{registered, application}>

function loadStatus(userId) {
  if (!cache.has(userId)) {
    const p = fetch("/api/seller/application_status.php", { credentials: "include" })
      .then((res) => res.json())
      .then((data) => {
        if (!data || data.success === false) {
          throw new Error(data?.message || "Pazaryeri kaydı durumu alınamadı.");
        }
        return { registered: data.registered === true, application: data.application ?? null };
      })
      .catch((err) => {
        cache.delete(userId); // hata önbelleğe alınmasın, tekrar denenebilsin
        throw err;
      });
    cache.set(userId, p);
  }
  return cache.get(userId);
}

export function useMarketplaceRegistration(userId) {
  const [state, setState] = useState({
    loading: true,
    registered: false,
    application: null,
    error: null,
  });
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    if (!userId) {
      setState({ loading: false, registered: false, application: null, error: null });
      return;
    }
    let cancelled = false;
    setState((prev) => ({ ...prev, loading: true, error: null }));
    loadStatus(userId)
      .then(({ registered, application }) => {
        if (cancelled) return;
        setState({ loading: false, registered, application, error: null });
      })
      .catch((err) => {
        if (cancelled) return;
        setState({ loading: false, registered: false, application: null, error: err.message || String(err) });
      });
    return () => {
      cancelled = true;
    };
  }, [userId, attempt]);

  const refetch = useCallback(() => {
    if (userId) cache.delete(userId);
    setAttempt((n) => n + 1);
  }, [userId]);

  return { ...state, refetch };
}

export default useMarketplaceRegistration;
