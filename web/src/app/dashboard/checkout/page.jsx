"use client"
import React, { useEffect, useState, useContext } from "react";
import { useRouter } from "next/navigation";
import { UserContext } from "@/shared/contexts/UserContext";
import { resolveCoverSrc } from "@/shared/lib/image";
import { formatCurrency } from "@/shared/lib/format";
import { toast } from "@/shared/hooks/use-toast";
import DeleteConfirmModal from "@/shared/ui/DeleteConfirmModal";
// N-17 ara önlemi (2026-10-07): kart formu (CardFields), kart doğrulaması
// ve kartı createsubscription.php'ye gönderen kod KALDIRILDI. Kart verisi
// sunucumuzdan geçiyordu (PCI-DSS, AUDIT N-17); ödeme sağlayıcısı Hoppa'ya
// geçiyor ve hedefte kart verisi bize hiç gelmeyecek (Hoppa ödeme sayfası /
// 3D Secure yönlendirmesi). O zamana kadar bu sayfa ödeme ALMAZ ve hiçbir
// koşulda kart içeren istek göndermez. Sözleşme onayı da yalnızca ödeme
// adımında gerekiyordu; Hoppa akışıyla birlikte geri gelecek.
import {
  ArrowLeft,
  Trash2,
  ShoppingBag,
  ShieldCheck,
  Sparkles,
  CheckCircle2,
  ChevronRight,
  Clock,
  Zap,
} from "lucide-react";

export default function Checkout() {
  const router = useRouter();
  const { userId } = useContext(UserContext);
  const [cartItems, setCartItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [step, setStep] = useState(1);
  const [confirmedItems, setConfirmedItems] = useState([]);
  const [deleteTargetId, setDeleteTargetId] = useState(null);

  useEffect(() => {
    if (!userId) {
      setLoading(false);
      return;
    }
    async function fetchCart() {
      try {
        const res = await fetch("/api/marketplace/getcart.php", { credentials: "include" });
        const result = await res.json();
        const rows = Array.isArray(result?.cart) ? result.cart : [];
        setCartItems(
          rows.map((row) => {
            const weeklyPrice = Number(row.price) || 0;
            return {
              id: row.id,
              chatbot_id: row.chatbot_id,
              title: row.title,
              description: row.category ? `Kategori: ${row.category}` : "",
              image: resolveCoverSrc(row.image),
              price: weeklyPrice,
              monthlyPrice: Number(row.monthlyPrice) || weeklyPrice * 4,
              duration_weeks: Number(row.order_weeks) || 4,
              // Authoritative line total from the server — never recomputed here.
              lineTotal: Number(row.lineTotal) || 0,
            };
          }),
        );
      } catch (err) {
        console.error("Cart fetch error:", err);
        toast.error("Sepetiniz yüklenemedi.");
      } finally {
        setLoading(false);
      }
    }
    fetchCart();
  }, [userId]);

  const handleRemove = async (cartId) => {
    try {
      const formData = new FormData();
      formData.append("data", JSON.stringify({ id: cartId }));
      const res = await fetch("/api/marketplace/deletecart.php", {
        method: "POST",
        body: formData,
        credentials: "include",
      });
      const result = await res.json();
      if (!result.success) {
        toast.error(result.message || "Ürün sepetten kaldırılamadı.");
        return;
      }
      setCartItems((prev) => prev.filter((item) => item.id !== cartId));
      toast.success("Model lisansı sepetinizden kaldırıldı.");
    } catch (error) {
      console.error("Removal error:", error);
      toast.error("Ürün sepetten kaldırılamadı.");
    }
  };

  const handleConfirm = () => {
    if (cartItems.length === 0) return;
    setConfirmedItems(cartItems);
    setStep(2);
    window.scrollTo({ top: 0, behavior: "smooth" });
  };

  // The server is the only authority on price: getCart returns a finished
  // lineTotal per row (weekly x weeks, or the stored monthly price), and
  // createSubscription charges the same figure through the same helper. The
  // page must not re-derive it — an independent second calculation here is
  // exactly what let checkout advertise a total the backend never charged.
  // Step 2 bills the snapshot handleConfirm actually submits.
  const billableItems = step === 2 ? confirmedItems : cartItems;
  const subtotal = billableItems.reduce((acc, item) => acc + item.lineTotal, 0);
  const total = subtotal;

  if (loading) {
    return (
      <div className="min-h-screen bg-luma-base text-zinc-100 p-6 md:p-12 flex items-center justify-center font-sans">
        <div className="w-full max-w-4xl space-y-6 animate-pulse">
          <div className="h-8 w-32 bg-zinc-800 rounded-lg" />
          <div className="h-12 w-64 bg-zinc-800 rounded-xl" />
          <div className="grid grid-cols-1 md:grid-cols-3 gap-6 pt-6">
            <div className="md:col-span-2 space-y-4">
              <div className="h-36 bg-zinc-900 rounded-2xl border border-zinc-800" />
              <div className="h-36 bg-zinc-900 rounded-2xl border border-zinc-800" />
            </div>
            <div className="h-72 bg-zinc-900 rounded-2xl border border-zinc-800" />
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-luma-base text-zinc-100 selection:bg-fuchsia-500/30 selection:text-fuchsia-200 font-sans relative overflow-x-hidden">
      {/* `max-w-7xl` BU div'in üzerindeydi ve aynı div sayfanın arka planını
          (`bg-luma-base` + `min-h-screen`) taşıyor. Yani arka plan 1280px'de
          bitiyordu ve `mx-auto` da olmadığı için sola yaslıydı: daha geniş
          ekranlarda sağda arka planı olmayan bir şerit kalıyordu. Aynı hata
          ortam ışığını da bozuyordu — `left-1/2` kısıtlanmış kabın ortasına
          göre hesaplandığı için parıltı ekranın ortasına değil soluna
          düşüyordu.

          Genişlik sınırı ait olduğu yere, İÇERİK kaplarına taşındı; kök artık
          tam genişlikte. Kardeş sayfalar (notes, settings) ve bu dosyanın
          kendi yüklenme iskeleti de tam genişlikte kök + dolgulu içerik
          deseninde. */}

      {/* Background ambient lighting effects */}
      <div className="absolute top-0 left-1/2 -translate-x-1/2 w-[1000px] h-[400px] bg-gradient-to-b from-fuchsia-600/10 via-violet-600/5 to-transparent blur-3xl pointer-events-none" />

      {}
      <header className="mx-auto max-w-7xl px-6 pt-10 pb-6 flex items-center justify-between relative z-10">
        <button
          type="button"
          onClick={() => (step === 2 ? setStep(1) : window.history.back())}
          className="group flex items-center gap-2 text-xs font-medium text-zinc-400 hover:text-white transition-all bg-zinc-900/50 hover:bg-zinc-800/80 px-3.5 py-2 rounded-xl border border-zinc-800/80 backdrop-blur-md shadow-sm"
        >
          <ArrowLeft className="w-3.5 h-3.5 transition-transform group-hover:-translate-x-0.5" />
          <span>{step === 2 ? "Sepete Dön" : "Pazaryerine Dön"}</span>
        </button>

        <div className="flex items-center gap-2 px-3 py-1.5 rounded-full bg-zinc-900/80 border border-zinc-800/80 text-caption text-zinc-400 backdrop-blur-md">
          <div className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse" />
          <span>Güvenli 256-bit TLS Oturumu</span>
        </div>
      </header>

      {}
      <main className="mx-auto max-w-7xl px-6 pb-24 relative z-10">
        <div className="mb-10">
          <div className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-fuchsia-500/10 border border-fuchsia-500/20 text-fuchsia-300 text-xs font-medium mb-3">
            <Sparkles className="w-3.5 h-3.5" />
            <span>Güvenli Ödeme</span>
          </div>
          <h1 className="text-3xl md:text-4xl font-bold tracking-tight text-white">
            {step === 1 ? "Sepetinizi Gözden Geçirin" : "Abonelik ve Ödemeyi Onaylayın"}
          </h1>
          <p className="text-sm text-zinc-400 mt-1">
            {step === 1
              ? "Seçtiğiniz yapay zeka modellerini ve lisans sürelerini gözden geçirin."
              : `${confirmedItems.length} ürün için ödemenizi tamamlayın.`}
          </p>
        </div>

        {}
        <div className="flex items-center gap-3 mb-8 pb-4 border-b border-zinc-800/60">
          <div
            className={`flex items-center gap-2 text-xs font-semibold ${step >= 1 ? "text-fuchsia-300" : "text-zinc-500"}`}
          >
            <span
              className={`w-6 h-6 rounded-full flex items-center justify-center text-caption border ${step >= 1 ? "border-fuchsia-500/50 bg-fuchsia-500/10 text-fuchsia-300" : "border-zinc-800 bg-zinc-900 text-zinc-500"}`}
            >
              1
            </span>
            <span>Sepet Özeti</span>
          </div>
          <ChevronRight className="w-4 h-4 text-zinc-700" />
          <div
            className={`flex items-center gap-2 text-xs font-semibold ${step >= 2 ? "text-fuchsia-300" : "text-zinc-500"}`}
          >
            <span
              className={`w-6 h-6 rounded-full flex items-center justify-center text-caption border ${step >= 2 ? "border-fuchsia-500/50 bg-fuchsia-500/10 text-fuchsia-300" : "border-zinc-800 bg-zinc-900 text-zinc-500"}`}
            >
              2
            </span>
            <span>Güvenli Ödeme</span>
          </div>
        </div>

        {}
        {cartItems.length === 0 && step === 1 ? (
          <div className="rounded-3xl border border-zinc-800/80 bg-zinc-900/40 p-12 text-center backdrop-blur-xl max-w-xl mx-auto my-12 shadow-2xl">
            <div className="w-16 h-16 rounded-2xl bg-zinc-800/60 border border-zinc-700/50 flex items-center justify-center mx-auto mb-5 text-zinc-400">
              <ShoppingBag className="w-8 h-8" />
            </div>
            <h3 className="text-xl font-semibold text-white mb-2">
              Sepetiniz şu anda boş
            </h3>
            <p className="text-sm text-zinc-400 mb-8 max-w-sm mx-auto">
              Yüksek performanslı yapay zeka modellerini ve geliştirici
              paketlerini keşfetmek için pazaryerimize göz atın.
            </p>
            <button
              onClick={() => router.push("/dashboard/explore")}
              className="px-6 py-3 rounded-xl bg-fuchsia-600 hover:bg-fuchsia-500 text-white font-medium text-xs shadow-lg shadow-fuchsia-600/20 transition-all transform hover:-translate-y-0.5 active:translate-y-0"
            >
              Keşfet
            </button>
          </div>
        ) : step === 1 ? (
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
            {/* Cart Items List */}
            <div className="lg:col-span-8 space-y-4">
              {cartItems.map((item) => (
                <div
                  key={item.id}
                  className="group relative rounded-2xl border border-zinc-800/80 bg-zinc-900/60 hover:bg-zinc-900/90 p-5 backdrop-blur-xl transition-all duration-300 hover:border-zinc-700/80 shadow-xl"
                >
                  <div className="flex flex-col sm:flex-row items-start sm:items-center gap-5">
                    <div className="relative w-full sm:w-28 h-28 rounded-xl overflow-hidden bg-zinc-800 shrink-0 border border-zinc-700/50">
                      <img
                        src={item.image}
                        alt={item.title}
                        className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
                      />
                      <div className="absolute inset-0 bg-gradient-to-t from-black/40 via-transparent to-transparent" />
                    </div>

                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2 mb-1">
                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-zinc-800/80 text-caption font-medium text-zinc-300 border border-zinc-700/40">
                          <Clock className="w-3 h-3 text-fuchsia-400" />
                          {item.duration_weeks} Hafta Erişim
                        </span>
                      </div>
                      <h3 className="text-base font-semibold text-white tracking-tight truncate">
                        {item.title}
                      </h3>
                      <p className="text-xs text-zinc-400 line-clamp-2 mt-1">
                        {item.description}
                      </p>
                    </div>

                    <div className="flex sm:flex-col items-center sm:items-end justify-between w-full sm:w-auto pt-4 sm:pt-0 border-t sm:border-t-0 border-zinc-800/80 gap-3">
                      <div className="text-right">
                        <span className="text-lg font-bold text-white">
                          {formatCurrency(item.lineTotal)}
                        </span>
                        <p className="text-caption text-zinc-500">
                          {formatCurrency(item.price)} / hafta
                        </p>
                      </div>

                      <button
                        onClick={() => setDeleteTargetId(item.id)}
                        className="p-2 rounded-lg bg-zinc-800/50 hover:bg-red-500/10 text-zinc-400 hover:text-red-400 transition-colors border border-transparent hover:border-red-500/20"
                        title="Ürünü kaldır"
                      >
                        <Trash2 className="w-4 h-4" />
                      </button>
                    </div>
                  </div>
                </div>
              ))}
            </div>

            {/* Order Summary Sidebar */}
            <div className="lg:col-span-4 sticky top-6">
              <div className="rounded-3xl border border-zinc-800/80 bg-zinc-900/60 p-6 backdrop-blur-xl shadow-2xl relative overflow-hidden">
                <div className="absolute -right-12 -top-12 w-32 h-32 bg-fuchsia-500/10 rounded-full blur-2xl pointer-events-none" />

                <h3 className="text-sm font-semibold text-white mb-4 flex items-center gap-2">
                  <Zap className="w-4 h-4 text-fuchsia-400" />
                  Sipariş Özeti
                </h3>

                <div className="space-y-3 pb-4 border-b border-zinc-800/80 text-xs">
                  <div className="flex justify-between text-zinc-400">
                    <span>Ara Toplam ({cartItems.length} ürün)</span>
                    <span className="text-zinc-200 font-medium">
                      {formatCurrency(subtotal)}
                    </span>
                  </div>
                </div>

                <div className="pt-4 pb-6 flex items-center justify-between">
                  <span className="text-sm font-semibold text-white">
                    Ödenecek Tutar
                  </span>
                  <div className="text-right">
                    <span className="text-2xl font-bold tracking-tight text-white">
                      {formatCurrency(total)}
                    </span>
                    <p className="text-caption text-zinc-400">
                      Güvenli şekilde faturalandırılır
                    </p>
                  </div>
                </div>

                <button
                  onClick={handleConfirm}
                  className="w-full py-3.5 px-4 rounded-xl bg-gradient-btn hover:brightness-110 hover:-translate-y-0.5 active:translate-y-0 text-white font-semibold text-xs shadow-glow transition-all duration-200 flex items-center justify-center gap-2 group cursor-pointer"
                >
                  <span>Ödemeye Geç</span>
                  <ChevronRight className="w-4 h-4 transition-transform group-hover:translate-x-1" />
                </button>

                <div className="mt-4 flex items-center justify-center gap-2 text-caption text-zinc-400">
                  <ShieldCheck className="w-3.5 h-3.5 text-emerald-400" />
                  <span>30 Gün Para İade Garantisi</span>
                </div>
              </div>
            </div>
          </div>
        ) : (
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
            <div className="lg:col-span-7 space-y-6">
              {/* Confirmed items list recap */}
              <div className="rounded-3xl border border-zinc-800/80 bg-zinc-900/60 p-6 backdrop-blur-xl shadow-2xl">
                <h3 className="text-sm font-semibold text-white mb-4 flex items-center gap-2">
                  <CheckCircle2 className="w-4 h-4 text-emerald-400" />
                  Doğrulanmış Sağlama Kalemleri ({confirmedItems.length})
                </h3>

                <div className="space-y-3">
                  {confirmedItems.map((item, idx) => (
                    <div
                      key={idx}
                      className="flex items-center justify-between p-3 rounded-xl bg-zinc-950/50 border border-zinc-800/60"
                    >
                      <div className="flex items-center gap-3">
                        <img
                          src={item.image}
                          alt={item.title}
                          className="w-10 h-10 rounded-lg object-cover"
                        />
                        <div>
                          <p className="text-xs font-semibold text-white">
                            {item.title}
                          </p>
                          <p className="text-caption text-zinc-400">
                            {item.duration_weeks} hafta tahsisi
                          </p>
                        </div>
                      </div>
                      <span className="text-xs font-bold text-white">
                        {formatCurrency(item.price * item.duration_weeks)}
                      </span>
                    </div>
                  ))}
                </div>
              </div>

              {/* N-17 ara önlemi: ödeme yöntemi seçimi, kart formu ve
                  "Ödemeyi Onayla" düğmesi kaldırıldı. */}
              <div className="rounded-3xl border border-amber-500/20 bg-amber-500/[0.06] p-6 backdrop-blur-xl shadow-2xl">
                <h3 className="text-sm font-semibold text-white mb-2 flex items-center gap-2">
                  <Clock className="w-4 h-4 text-amber-400" />
                  Ödeme altyapısı hazırlanıyor
                </h3>
                <p className="text-sm leading-relaxed text-amber-100/85">
                  Pazaryerinden satın alma, yeni ödeme altyapımız devreye girdiğinde
                  açılacak. Şu an ödeme alınmıyor ve kart bilgisi istenmiyor. Sepetiniz
                  korunuyor.
                </p>
              </div>
            </div>

            {/* Final Summary Card */}
            <div className="lg:col-span-5 sticky top-6">
              <div className="rounded-3xl border border-zinc-800/80 bg-zinc-900/60 p-6 backdrop-blur-xl shadow-2xl">
                <h3 className="text-sm font-semibold text-white mb-4">
                  Ödeme Dökümü
                </h3>

                <div className="space-y-3 pb-4 border-b border-zinc-800/80 text-xs">
                  <div className="flex justify-between text-zinc-400">
                    <span>Hesap No</span>
                    <span className="text-zinc-200 font-mono">{userId}</span>
                  </div>
                  <div className="flex justify-between text-zinc-400">
                    <span>Ara Toplam</span>
                    <span className="text-zinc-200 font-medium">
                      {formatCurrency(subtotal)}
                    </span>
                  </div>
                </div>

                <div className="pt-4 flex items-center justify-between">
                  <span className="text-sm font-bold text-white">
                    Toplam Tutar
                  </span>
                  <span className="text-2xl font-bold tracking-tight text-white">
                    {formatCurrency(total)}
                  </span>
                </div>

                <div className="mt-6 p-4 rounded-2xl bg-zinc-950/60 border border-zinc-800/60 text-caption text-zinc-400 space-y-2">
                  <div className="flex items-center gap-2 text-zinc-300 font-medium">
                    <Sparkles className="w-3.5 h-3.5 text-fuchsia-400" />
                    <span>Anında Bulut Sağlama</span>
                  </div>
                  <p className="text-zinc-500 leading-relaxed">
                    Ödeme tamamlandığında API anahtarlarınız ve küme uç
                    noktalarınız panelinizde hemen oluşturulacaktır.
                  </p>
                </div>
              </div>
            </div>
          </div>
        )}
      </main>


      <DeleteConfirmModal
        isOpen={!!deleteTargetId}
        onClose={() => setDeleteTargetId(null)}
        onConfirm={() => {
          handleRemove(deleteTargetId);
          setDeleteTargetId(null);
        }}
        title="Ürünü sepetten kaldır"
        description={<>Bu ürün sepetinizden kaldırılacaktır.</>}
        confirmLabel="Kaldır"
      />
    </div>
  );
}
