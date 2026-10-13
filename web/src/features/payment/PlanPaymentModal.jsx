"use client";
import React from "react";
import { Dialog, DialogContent, DialogTitle } from "@/shared/ui/dialog";
import { Clock, Loader2, Lock } from "lucide-react";

/**
 * Üyelik paketi ödeme penceresi — Faz 7a (Hoppa Ortak Ödeme Sayfası).
 *
 * Bu pencere KART BİLGİSİ TOPLAMAZ (N-17). "Ödemeye geç" sunucudan bir
 * ödeme oturumu ister ve kullanıcı Hoppa'nın güvenli ödeme sayfasına
 * yönlendirilir; kart orada girilir ve Lumanoris'e hiç ulaşmaz. Sonuç
 * Hoppa'dan sunucuya döner ve sunucu ödemeyi Hoppa'ya sorarak kesinleştirir.
 *
 * Ödeme altyapısı kapalıysa (PAYMENT_PROVIDER=none → 503) `unavailable`
 * ile "Ödeme altyapısı hazırlanıyor" gösterilir.
 */
export default function PlanPaymentModal({
  open,
  planTitle,
  priceLabel,
  onClose,
  onPay,
  starting = false,
  unavailable = false,
}) {
  return (
    <Dialog open={open} onOpenChange={(o) => !o && !starting && onClose()}>
      <DialogContent className="max-w-[460px] bg-luma-card border-zinc-800 p-6">
        <DialogTitle className="text-base font-semibold text-white">
          {planTitle} paketi
        </DialogTitle>

        <div className="mt-1 flex items-baseline justify-between border-b border-zinc-800/80 pb-4">
          <span className="text-caption text-zinc-400">30 günlük tutar</span>
          <span className="text-xl font-bold tracking-tight text-white">{priceLabel}</span>
        </div>

        {unavailable ? (
          <>
            <div className="mt-5 flex items-start gap-3 rounded-2xl border border-amber-500/20 bg-amber-500/[0.06] p-4">
              <Clock className="mt-0.5 h-4 w-4 shrink-0 text-amber-400" />
              <div className="text-sm leading-relaxed text-amber-100/85">
                <p className="font-semibold text-white">Ödeme altyapısı hazırlanıyor</p>
                <p className="mt-1">
                  Paket satın alma, ödeme altyapımız devreye girdiğinde açılacak.
                  Şu an ödeme alınmıyor.
                </p>
              </div>
            </div>
            <button
              type="button"
              onClick={onClose}
              className="mt-5 w-full rounded-xl border border-white/10 bg-white/[0.04] py-3 text-xs font-semibold text-white transition-colors hover:bg-white/[0.08]"
            >
              Tamam
            </button>
          </>
        ) : (
          <>
            <div className="mt-5 flex items-start gap-3 rounded-2xl border border-zinc-800/80 bg-zinc-950/50 p-4">
              <Lock className="mt-0.5 h-4 w-4 shrink-0 text-emerald-400" />
              <p className="text-sm leading-relaxed text-zinc-300">
                Kart bilgilerinizi hoppa&apos;nın güvenli ödeme sayfasında gireceksiniz;
                bu bilgiler Lumanoris&apos;e ulaşmaz. Paket 30 gün geçerlidir ve otomatik
                yenilenmez.
              </p>
            </div>
            <button
              type="button"
              onClick={onPay}
              disabled={starting}
              className="mt-5 flex w-full items-center justify-center gap-2 rounded-xl bg-white py-3 text-xs font-semibold text-black transition-opacity hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60"
            >
              {starting && <Loader2 className="h-3.5 w-3.5 animate-spin" />}
              {starting ? "Ödeme sayfası açılıyor…" : "Ödemeye geç"}
            </button>
          </>
        )}
      </DialogContent>
    </Dialog>
  );
}
