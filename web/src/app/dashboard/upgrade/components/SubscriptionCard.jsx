import { CalendarClock, Crown, Receipt } from "lucide-react";
import { cn } from "@/lib/utils";

const fmtDate = (s) => (s ? new Date(String(s).replace(" ", "T")).toLocaleDateString("tr-TR") : "—");
const fmtMoney = (n) => `₺${Number(n).toLocaleString("tr-TR", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const STATUS_TONE = {
  paid: "border-emerald-400/20 bg-emerald-500/10 text-emerald-300",
  refunded: "border-sky-400/20 bg-sky-500/10 text-sky-300",
  partial_refund: "border-sky-400/20 bg-sky-500/10 text-sky-300",
  hoppa_failed: "border-rose-400/20 bg-rose-500/10 text-rose-300",
};

function StatusPill({ status, label }) {
  return (
    <span
      className={cn(
        "inline-flex items-center whitespace-nowrap rounded-full border px-2 py-0.5 text-[11px] font-semibold",
        STATUS_TONE[status] || "border-white/10 bg-white/[0.04] text-white/55",
      )}
    >
      {label}
    </span>
  );
}

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

  const action =
    sub.state === "active" && sub.can_renew && onRebuy
      ? { label: "Yenile (+30 gün)", plan: sub.plan }
      : sub.state === "expired" && onRebuy
        ? { label: `${sub.last_paid_plan} paketini yeniden al`, plan: sub.last_paid_plan }
        : null;

  return (
    <section className="rounded-2xl border border-fuchsia-400/10 bg-gradient-card p-5 shadow-card sm:p-6">
      <div className="flex flex-col gap-5 md:flex-row md:items-center md:justify-between">
        <div className="flex min-w-0 items-center gap-4">
          <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-fuchsia-400/20 bg-fuchsia-500/10">
            <Crown className="h-6 w-6 text-fuchsia-300" />
          </span>
          <div className="min-w-0">
            <p className="text-caption font-bold uppercase tracking-wider text-white/45">Aboneliğim</p>
            <h2 className="truncate font-display text-xl font-bold text-white sm:text-2xl">{sub.plan}</h2>
            <p
              className={cn(
                "text-sm",
                sub.state === "active" && sub.days_left !== null && sub.days_left <= 3 ? "text-amber-300" : "text-white/55",
              )}
            >
              {statusText}
            </p>
          </div>
        </div>

        <div className="flex flex-col gap-3 sm:flex-row sm:items-center md:shrink-0">
          {sub.state === "active" && (
            <dl className="grid grid-cols-2 gap-x-6 gap-y-0.5 rounded-xl border border-white/[0.06] bg-white/[0.02] px-4 py-2.5 text-sm">
              <dt className="text-caption text-white/45">Başlangıç</dt>
              <dt className="text-caption text-white/45">Bitiş</dt>
              <dd className="tabular-nums text-white/85">{fmtDate(sub.started_at)}</dd>
              <dd className="tabular-nums text-white/85">{sub.expires_at ? fmtDate(sub.expires_at) : "Süresiz"}</dd>
            </dl>
          )}
          {action && (
            <button
              type="button"
              onClick={() => onRebuy(action.plan)}
              className="h-11 rounded-xl bg-gradient-btn px-5 text-sm font-semibold text-white shadow-glow transition-all hover:brightness-110"
            >
              {action.label}
            </button>
          )}
        </div>
      </div>

      {sub.state === "active" && (
        <p className="mt-5 flex items-start gap-2 rounded-xl border border-white/[0.06] bg-white/[0.02] px-3.5 py-2.5 text-xs leading-relaxed text-white/55">
          <CalendarClock className="mt-0.5 h-3.5 w-3.5 shrink-0 text-violet-300" />
          <span>
            Paketler otomatik yenilenmez; süre sonunda ücretsiz pakete geçilir. Bitişten 3 gün önce bildirim alırsınız.
            {` Bitişe ${sub.renewal_window_days ?? 7} gün kala yenileyebilirsiniz; kalan günleriniz korunur.`}
          </span>
        </p>
      )}

      <div className="mt-6 border-t border-white/[0.06] pt-5">
        <p className="mb-3 flex items-center gap-2 text-caption font-bold uppercase tracking-wider text-white/45">
          <Receipt className="h-3.5 w-3.5" /> Ödeme geçmişi
        </p>
        {sub.history.length === 0 ? (
          <p className="rounded-xl border border-dashed border-white/10 px-4 py-5 text-center text-sm text-white/45">
            Henüz paket ödemeniz yok.
          </p>
        ) : (
          <>
            <ul className="space-y-2 sm:hidden">
              {sub.history.map((h) => (
                <li key={h.order_id} className="rounded-xl border border-white/[0.06] bg-white/[0.02] px-4 py-3">
                  <div className="flex items-center justify-between gap-3">
                    <span className="truncate text-sm font-semibold text-white">{h.plan || "—"}</span>
                    <StatusPill status={h.status} label={h.status_label} />
                  </div>
                  <div className="mt-1 flex items-center justify-between gap-3 text-xs text-white/55">
                    <span className="tabular-nums">{fmtDate(h.created_at)}</span>
                    <span className="tabular-nums text-white/85">{fmtMoney(h.amount)}</span>
                  </div>
                </li>
              ))}
            </ul>
            <div className="hidden overflow-hidden rounded-xl border border-white/[0.06] sm:block">
              <table className="w-full text-left text-sm">
                <thead className="bg-white/[0.03] text-caption text-white/45">
                  <tr>
                    <th className="px-4 py-2.5 font-medium">Tarih</th>
                    <th className="px-4 py-2.5 font-medium">Paket</th>
                    <th className="px-4 py-2.5 text-right font-medium">Tutar</th>
                    <th className="px-4 py-2.5 text-right font-medium">Durum</th>
                  </tr>
                </thead>
                <tbody className="text-white/85">
                  {sub.history.map((h) => (
                    <tr key={h.order_id} className="border-t border-white/[0.05]">
                      <td className="px-4 py-2.5 tabular-nums">{fmtDate(h.created_at)}</td>
                      <td className="px-4 py-2.5">{h.plan || "—"}</td>
                      <td className="px-4 py-2.5 text-right tabular-nums">{fmtMoney(h.amount)}</td>
                      <td className="px-4 py-2.5 text-right">
                        <StatusPill status={h.status} label={h.status_label} />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </>
        )}
      </div>
    </section>
  );
}
