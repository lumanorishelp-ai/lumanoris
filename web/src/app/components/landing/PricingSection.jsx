"use client";

import { Check, CreditCard } from "lucide-react";
import Link from "next/link";
import { useEffect, useState } from "react";
import { Badge } from "@/shared/ui/badge";

/**
 * N-10 — bu bölüm paketleri sabit kodlu DÖRDÜNCÜ bir kopyadan gösteriyordu
 * (Gümüş 0 ₺ / Altın 750 ₺ / Elmas 1.850 ₺, aylık-yıllık anahtarı ve "%20
 * İndirim"). Ne `plans` tablosuyla ne yükseltme sayfasıyla ne de müşterinin
 * paket tablosuyla uyuşuyordu; yıllık fiyat ise hiç satılmıyordu (katalogda
 * `yearly_price` yok, paket 30 günlük tek seferlik satış — D-05).
 *
 * Artık yükseltme sayfasıyla AYNI kaynaktan okunuyor: `getpricing.php`
 * (`plans` + `plan_icerikler`). Fiyat ve özellik metni tek yerde değişir.
 */
const BADGE_VARIANTS = {
  Ücretsiz: "secondary",
  Gümüş: "secondary",
  Altın: "warning",
  Elmas: "default",
};

export default function PricingSection() {
  const [plans, setPlans] = useState([]);
  const [status, setStatus] = useState("loading"); // loading | ready | error

  useEffect(() => {
    let cancelled = false;
    fetch("/api/wallet/getpricing.php", { credentials: "include" })
      .then((res) => res.json())
      .then((data) => {
        if (cancelled) return;
        if (data?.success && Array.isArray(data.all_plans) && data.all_plans.length) {
          setPlans(data.all_plans);
          setStatus("ready");
        } else {
          setStatus("error");
        }
      })
      .catch(() => {
        if (!cancelled) setStatus("error");
      });
    return () => {
      cancelled = true;
    };
  }, []);

  return (
    <section id="pricing" className="max-w-7xl mr-auto ml-auto pt-16 pr-5 pb-16 pl-5 sm:pt-24 sm:pr-6 sm:pb-24 sm:pl-6">
      <div className="text-center mb-12">
        <div className="inline-flex items-center gap-2 px-3 py-1 text-xs font-medium text-fuchsia-300 bg-fuchsia-500/10 ring-1 ring-fuchsia-400/20 rounded-full mb-6">
          <CreditCard className="h-3 w-3" strokeWidth={1.5} />
          Fiyatlandırma
        </div>
        <h2 className="text-3xl sm:text-4xl lg:text-5xl font-display font-semibold text-white tracking-tight leading-tight mb-4">
          Basit ve Şeffaf <span className="text-fuchsia-400">Fiyatlandırma</span>
        </h2>
        <p className="text-lg sm:text-xl text-white/75 max-w-2xl mx-auto">
          İhtiyacınıza göre seçiminizi yapın. Sürpriz ücretler yok.
        </p>
      </div>

      {status === "loading" && (
        <p className="text-center text-sm text-white/50">Paketler yükleniyor…</p>
      )}

      {status === "error" && (
        <div className="mx-auto max-w-md text-center text-sm text-white/60">
          Paket bilgileri şu an yüklenemedi.{" "}
          <Link href="/dashboard/upgrade/" className="text-fuchsia-300 hover:text-fuchsia-200">
            Paketleri görüntüle
          </Link>
        </div>
      )}

      {status === "ready" && (
        <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4 max-w-md mx-auto sm:max-w-none">
          {plans.map((plan) => {
            const popular = Boolean(plan.badge);
            return (
              <div
                key={plan.title}
                className="bg-gradient-card backdrop-blur-xl ring-1 ring-fuchsia-400/10 rounded-3xl p-6 sm:p-8 flex flex-col shadow-card"
              >
                <div className="flex items-center justify-between mb-8">
                  <Badge variant={BADGE_VARIANTS[plan.title] || "default"}>{plan.title}</Badge>
                  {popular && <Badge variant="success">{plan.badge}</Badge>}
                </div>

                <div className="mb-3">
                  <div className="flex items-baseline gap-2">
                    <span className="text-4xl font-display font-semibold tracking-tight text-white">
                      {plan.monthly_price}
                    </span>
                    <span className="text-luma-muted">/ay</span>
                  </div>
                  {plan.description && (
                    <p className="text-white/75 mt-3">{plan.description}</p>
                  )}
                </div>

                <div className="my-8 h-px bg-white/10" />

                <ul className="space-y-3 text-white/75 flex-1">
                  {(plan.features || []).map((feature) => (
                    <li key={feature} className="flex items-start gap-3">
                      <Check className="h-4 w-4 text-fuchsia-400 mt-0.5 shrink-0" strokeWidth={2} />
                      <span>{feature}</span>
                    </li>
                  ))}
                </ul>

                <div className="my-8 h-px bg-white/10" />

                <Link
                  href="/dashboard/upgrade/"
                  className={
                    popular
                      ? "w-full inline-flex items-center justify-center px-6 py-3 text-base font-medium text-white bg-gradient-btn hover:brightness-110 rounded-xl transition shadow-glow"
                      : "w-full inline-flex items-center justify-center px-6 py-3 text-base font-medium text-white/85 bg-white/5 hover:bg-white/10 ring-1 ring-white/10 rounded-xl transition"
                  }
                >
                  Planı Seç
                </Link>
              </div>
            );
          })}
        </div>
      )}
    </section>
  );
}
