"use client";
import React, { useState, useEffect, useContext } from "react";
import { toast } from "@/shared/hooks/use-toast";
import { UserContext } from "@/shared/contexts/UserContext";
import PricingPageHeader from "./components/PricingPageHeader";
import PricingLoadingState from "./components/PricingLoadingState";
import PricingCard from "./components/PricingCard";
import EnterpriseContactFooter from "./components/EnterpriseContactFooter";
import StatusBanner from "./components/StatusBanner";
import PlanPaymentModal from "@/features/payment/PlanPaymentModal";

/**
 * E-04 — burada kodlanmış dört planlık bir katalog vardı (₺149/₺299/₺599)
 * ve API başarısız olduğunda "güvenli modda varsayılan planlar" diye
 * GÖSTERİLİYORDU. Bu, plan kataloğunun ÜÇÜNCÜ kopyasıydı (diğer ikisi:
 * `plans` tablosu ve WalletController::getPricing()'in sunucu tarafı geri
 * düşüşü) ve hiçbiri senkron tutulmuyordu.
 *
 * Daha kötüsü: sunucu katalog hazır değilken satın almayı 503 ile
 * reddediyor. Yani kullanıcıya fiyat gösterip "Bu Paketi Seç"e basınca
 * "kullanılamıyor" demek oluyordu. Fiyat artık tek kaynaktan geliyor;
 * gelmiyorsa hiç fiyat gösterilmiyor.
 */
const initialPlanData = [];

export default function PricingPlans() {
  const { userId } = useContext(UserContext);
  const [selectedPlan, setSelectedPlan] = useState(null);
  const [plansData, setPlansData] = useState(initialPlanData);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [upgrading, setUpgrading] = useState(null);
  const [upgradedPlan, setUpgradedPlan] = useState(null);
  const [salesContactSending, setSalesContactSending] = useState(false);
  const [salesContactSent, setSalesContactSent] = useState(false);
  // Ücretli bir paket seçildiğinde ödeme penceresi açılır; ücretsiz paket
  // doğrudan uygulanır (bkz. handleChoosePlan).
  const [pendingPlan, setPendingPlan] = useState(null);
  const [startingPayment, setStartingPayment] = useState(false);
  const [paymentUnavailable, setPaymentUnavailable] = useState(false);
  // Hoppa ödeme sayfasından dönüşte sunucu buraya ?odeme=paid|failed|pending
  // ile yönlendiriyor (WalletController::hoppaReturn). Sonuç sunucuda
  // ProcessQuery ile kesinleşmiş durumda; burada yalnızca gösteriliyor.
  const [paymentResult, setPaymentResult] = useState(null);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const result = params.get("odeme");
    if (["paid", "failed", "pending"].includes(result)) {
      setPaymentResult(result);
      params.delete("odeme");
      const query = params.toString();
      window.history.replaceState(null, "", window.location.pathname + (query ? `?${query}` : ""));
    }
  }, []);

  useEffect(() => {
    const fetchPlans = async () => {
      try {
        const response = await fetch("/api/wallet/getpricing.php");
        // Sunucu katalog hazır değilken 503 + gerçek bir mesaj dönüyor
        // (E-04). Gövde her iki durumda da JSON zarfı, bu yüzden önce onu
        // okuyup mesajı kullanıyoruz — "HTTP error! status: 503" kullanıcıya
        // hiçbir şey anlatmıyordu.
        const data = await response.json().catch(() => null);

        if (response.ok && data?.success) {
          setPlansData(Array.isArray(data.all_plans) ? data.all_plans : []);
        } else {
          throw new Error(data?.message || "Paket listesi alınamadı.");
        }
      } catch (err) {
        console.error("Fiyat planları yüklenirken hata oluştu:", err);
        setError(err.message);
      } finally {
        setLoading(false);
      }
    };

    fetchPlans();
  }, []);

  /**
   * "₺1.299,00" → 1299. Katalog fiyatı sunucudan biçimlendirilmiş metin
   * olarak geliyor; buradaki ayrıştırma YALNIZCA "ödeme penceresi açılsın
   * mı" kararı için. Tahsil edilecek tutarı sunucu `plans` tablosundan
   * kendisi okuyor — istemci tutar göndermiyor.
   */
  const planAmount = (plan) => {
    const raw = String(plan?.monthly_price ?? "")
      .replace(/[^\d,.-]/g, "")
      .replace(/\./g, "")
      .replace(",", ".");
    const n = parseFloat(raw);
    return Number.isFinite(n) ? n : 0;
  };

  const handleChoosePlan = (plan, index) => {
    if (!userId) {
      toast.warning("Paket seçebilmek için giriş yapmalısınız.");
      return;
    }
    // Ücretsiz plan için tahsilat yok — kart istemek anlamsız olurdu.
    if (planAmount(plan) <= 0) {
      submitPlan(plan.title, index);
      return;
    }
    setPaymentUnavailable(false);
    setPendingPlan({ title: plan.title, priceLabel: plan.monthly_price, index });
  };

  // Faz 7a: ücretli paket Hoppa Ortak Ödeme Sayfası'nda ödeniyor. İstek
  // yalnızca plan adını taşır — kart bilgisi ne toplanıyor ne gönderiliyor
  // (N-17). Sunucu ödeme oturumu açıp Hoppa'nın sayfa adresini döner.
  const startPlanPayment = async () => {
    if (!pendingPlan) return;
    setStartingPayment(true);
    try {
      const formData = new FormData();
      formData.append("data", JSON.stringify({ plan_name: pendingPlan.title }));
      const res = await fetch("/api/wallet/upgradeplan.php", {
        method: "POST",
        body: formData,
        credentials: "include",
      });
      const result = await res.json().catch(() => null);
      if (res.ok && result?.success && result.redirect_url) {
        window.location.assign(result.redirect_url);
        return; // sayfa değişiyor; düğme "açılıyor" durumunda kalsın
      }
      if (res.status === 503) {
        setPaymentUnavailable(true);
      } else {
        toast.error(result?.message || "Ödeme başlatılamadı. Lütfen tekrar deneyin.");
      }
    } catch (err) {
      toast.error("Sunucuyla bağlantı kurulamadı.");
    }
    setStartingPayment(false);
  };

  // Yalnızca ücretsiz plana geçiş (tahsilatsız) bu yoldan gidiyor; ücretli
  // paketler startPlanPayment → Hoppa ödeme sayfası. Kart verisi hiçbir
  // yoldan gönderilmiyor (N-17).
  const submitPlan = async (planTitle, index) => {
    setSelectedPlan(index);
    setUpgrading(index);
    try {
      // user_id BİLİNÇLİ olarak gönderilmiyor: ödeme uç noktası kullanıcıyı
      // oturumdan belirliyor. İstemciden gelen bir user_id, başka bir
      // hesabın paketini satın almaya çalışmanın açık kapısı olurdu.
      const payload = { plan_name: planTitle };

      const formData = new FormData();
      formData.append("data", JSON.stringify(payload));
      const res = await fetch("/api/wallet/upgradeplan.php", {
        method: "POST",
        body: formData,
        credentials: "include",
      });
      const result = await res.json();
      if (result.success) {
        setPendingPlan(null);
        setUpgradedPlan(result.plan_name || planTitle);
      } else {
        // Sağlayıcının mesajı ("Kart limiti yetersiz" gibi) kullanıcıya
        // aynen gösteriliyor; genel bir hata metninden çok daha yararlı.
        toast.error(result.message || "Paket seçimi başarısız oldu.");
      }
    } catch (err) {
      toast.error("Sunucuyla bağlantı kurulamadı.");
    } finally {
      setUpgrading(null);
    }
  };

  const handleContactSales = async () => {
    setSalesContactSending(true);
    try {
      const formData = new FormData();
      formData.append("fullName", "Kurumsal Satış Talebi");
      formData.append("email", "");
      formData.append("subject", "Kurumsal Satış Görüşmesi Talebi");
      formData.append(
        "message",
        userId
          ? `Kullanıcı (ID: ${userId}) kurumsal satış ekibiyle görüşme talep etti.`
          : "Bir kullanıcı kurumsal satış ekibiyle görüşme talep etti.",
      );
      const res = await fetch("/api/contact/contact.php", {
        method: "POST",
        body: formData,
      });
      const result = await res.json();
      if (result.success) {
        setSalesContactSent(true);
        setTimeout(() => setSalesContactSent(false), 4000);
      } else {
        toast.error(result.message || "Talep gönderilemedi.");
      }
    } catch (err) {
      toast.error("Sunucuyla bağlantı kurulamadı.");
    } finally {
      setSalesContactSending(false);
    }
  };

  if (loading) {
    return (
      <div className="min-h-screen bg-zinc-950 text-zinc-100 selection:bg-violet-500 selection:text-white px-4 sm:px-6 lg:px-8 py-12 lg:py-20 font-display">
        <div>
          <PricingPageHeader
            eyebrow="Planlar ve Fiyatlandırma"
            title="Hesabını Yükselt"
          />
          <PricingLoadingState />
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-zinc-950 text-zinc-100 selection:bg-violet-500 selection:text-white px-4 sm:px-6 lg:px-8 py-12 lg:py-20 font-display">
      <div>
        <PricingPageHeader
          eyebrow="Esnek Fiyatlandırma"
          title="Geleceğin Yapay Zeka Altyapısı"
        />

        {/* N-28 — "Yıllık Faturalandırma · %20 İndirim" anahtarı kaldırıldı:
            yıllık ürün ve indirim yok, anahtar yalnızca etiketi değiştiriyordu. */}

        <div>
          {error && (
            <StatusBanner variant="error">
              Paketler yüklenemedi: {error}
            </StatusBanner>
          )}
          {!error && plansData.length === 0 && (
            <StatusBanner variant="error">
              Şu anda gösterilebilecek bir paket yok.
            </StatusBanner>
          )}
          {paymentResult === "paid" && (
            <StatusBanner variant="success">
              Ödemeniz alındı; paketiniz 30 gün için etkinleştirildi.
            </StatusBanner>
          )}
          {paymentResult === "failed" && (
            <StatusBanner variant="error">
              Ödeme tamamlanamadı ve paketiniz değişmedi. Dilerseniz tekrar deneyebilirsiniz.
            </StatusBanner>
          )}
          {paymentResult === "pending" && (
            <StatusBanner variant="error">
              Ödemeniz tamamlanmadı. Ödeme sayfasını yarıda bıraktıysanız paketi yeniden seçebilirsiniz.
            </StatusBanner>
          )}
          {upgradedPlan && (
            <StatusBanner variant="success">
              Tebrikler! "{upgradedPlan}" paketi başarıyla etkinleştirildi.
            </StatusBanner>
          )}

          {/* Pricing Cards Grid */}
          <div className="grid grid-cols-1 gap-8 sm:grid-cols-2 lg:grid-cols-4 pt-4">
            {plansData.map((plan, index) => (
              <PricingCard
                key={index}
                plan={plan}
                isSelected={selectedPlan === index}
                isUpgrading={upgrading === index}
                onChoose={() => handleChoosePlan(plan, index)}
              />
            ))}
          </div>

          <EnterpriseContactFooter
            sending={salesContactSending}
            sent={salesContactSent}
            onContact={handleContactSales}
          />
        </div>
      </div>

      <PlanPaymentModal
        open={!!pendingPlan}
        planTitle={pendingPlan?.title ?? ""}
        priceLabel={pendingPlan?.priceLabel ?? ""}
        onClose={() => setPendingPlan(null)}
        onPay={startPlanPayment}
        starting={startingPayment}
        unavailable={paymentUnavailable}
      />
    </div>
  );
}
