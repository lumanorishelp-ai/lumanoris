import { CalendarClock, Receipt } from "lucide-react";
import { cn } from "@/lib/utils";

/**
 * N-63 — "Aboneliğim": GET /api/wallet/getmysubscription.php
 * (functions/plans.php → getUserSubscription; paket kartlarıyla aynı kaynak).
 *
 * Paketler otomatik yenilenmez (30 günlük tek seferlik satış). Süresi
 * dolmadan yenileme (N-67, onaylı): bitişe ≤7 gün kala "Yenile", kalan günler
 * korunur. İptal müşteri kararı bekliyor — düğmesi henüz yok.
 */
const fmtDate = (s) => (s ? new Date(String(s).replace(" ", "T")).toLocaleDateString("tr-TR") : "—");
const fmtMoney = (n) => `₺${Number(n).toLocaleString("tr-TR", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

export default function SubscriptionCard({ sub, onRebuy }) {
  if (!sub) return null;

  const statusText =
    sub.state === "active"
      ? sub.expires_at
        ? `${sub.days_left} gün kaldı`
        : "Süresiz"
      : sub.state === "expired"
        ? `"${sub.last_paid_plan}" paketinizin süresi ${fmtDate(sub.expired_at)} tarihinde doldu`
        : "Ücretsiz paket kullanıyorsunuz";

  return (
    <section className="rounded-3xl border border-white/[0.08] bg-zinc-900/50 p-6 backdrop-blur-xl">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <p className="text-caption font-bold uppercase tracking-wider text-zinc-400">Aboneliğim</p>
          <h2 className="mt-1 text-2xl font-extrabold tracking-tight text-white">{sub.plan}</h2>
          <p
            className={cn(
              "mt-1 text-sm",
              sub.state === "active" && sub.days_left !== null && sub.days_left <= 3 ? "text-amber-300" : "text-zinc-400",
            )}
          >
            {statusText}
          </p>
        </div>
        {sub.state === "active" && (
          <dl className="grid grid-cols-2 gap-x-6 gap-y-1 text-sm">
            <dt className="text-zinc-500">Başlangıç</dt>
            <dd className="font-mono text-zinc-200">{fmtDate(sub.started_at)}</dd>
            <dt className="text-zinc-500">Bitiş</dt>
            <dd className="font-mono text-zinc-200">{sub.expires_at ? fmtDate(sub.expires_at) : "Süresiz"}</dd>
          </dl>
        )}
        {sub.state === "active" && sub.can_renew && onRebuy && (
          <button
            type="button"
            onClick={() => onRebuy(sub.plan)}
            className="h-10 shrink-0 rounded-xl bg-gradient-btn px-4 text-xs font-semibold text-white hover:brightness-110"
          >
            Yenile (+30 gün)
          </button>
        )}
        {sub.state === "expired" && onRebuy && (
          <button
            type="button"
            onClick={() => onRebuy(sub.last_paid_plan)}
            className="h-10 shrink-0 rounded-xl bg-gradient-btn px-4 text-xs font-semibold text-white hover:brightness-110"
          >
            {sub.last_paid_plan} paketini yeniden satın al
          </button>
        )}
      </div>

      {sub.state === "active" && (
        <p className="mt-4 flex items-start gap-2 rounded-xl border border-white/[0.06] bg-white/[0.02] px-3.5 py-2.5 text-xs leading-relaxed text-zinc-400">
          <CalendarClock className="mt-0.5 h-3.5 w-3.5 shrink-0 text-violet-400" />
          Paketler otomatik yenilenmez; süre sonunda ücretsiz pakete geçilir. Bitişten 3 gün önce bildirim alırsınız.
          {` Bitişe ${sub.renewal_window_days ?? 7} gün kala yenileyebilirsiniz; kalan günleriniz korunur.`}
        </p>
      )}

      <div className="mt-5">
        <p className="mb-2 flex items-center gap-2 text-caption font-bold uppercase tracking-wider text-zinc-400">
          <Receipt className="h-3.5 w-3.5" /> Ödeme geçmişi
        </p>
        {sub.history.length === 0 ? (
          <p className="text-xs text-zinc-500">Henüz paket ödemesi yok.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[420px] text-left text-xs">
              <thead className="text-zinc-500">
                <tr>
                  <th className="py-1.5 pr-3 font-medium">Tarih</th>
                  <th className="py-1.5 pr-3 font-medium">Paket</th>
                  <th className="py-1.5 pr-3 font-medium">Tutar</th>
                  <th className="py-1.5 font-medium">Durum</th>
                </tr>
              </thead>
              <tbody className="text-zinc-200">
                {sub.history.map((h) => (
                  <tr key={h.order_id} className="border-t border-white/[0.05]">
                    <td className="py-1.5 pr-3 font-mono">{fmtDate(h.created_at)}</td>
                    <td className="py-1.5 pr-3">{h.plan || "—"}</td>
                    <td className="py-1.5 pr-3 font-mono">{fmtMoney(h.amount)}</td>
                    <td className={cn("py-1.5", h.status === "paid" ? "text-emerald-400" : "text-zinc-400")}>
                      {h.status_label}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </section>
  );
}
