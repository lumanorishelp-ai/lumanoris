import { Shield } from "lucide-react";

export default function EnterpriseContactFooter({ sending, sent, onContact }) {
  return (
    <div className="flex flex-col gap-5 rounded-2xl border border-fuchsia-400/10 bg-gradient-card p-5 shadow-card sm:p-6 md:flex-row md:items-center md:justify-between">
      <div className="flex items-center gap-4">
        <div className="w-12 h-12 rounded-xl bg-violet-500/10 border border-violet-500/20 flex items-center justify-center shrink-0">
          <Shield className="w-6 h-6 text-violet-400" />
        </div>
        <div>
          <h4 className="text-white font-semibold text-base">
            Özel Kurumsal İhtiyaçlarınız mı var?
          </h4>
          <p className="text-white/55 text-sm">
            Özel entegrasyonlar, SLA güvenceleri ve size özel fiyatlandırma
            için ekibimizle iletişime geçin.
          </p>
        </div>
      </div>
      <button
        onClick={onContact}
        disabled={sending || sent}
        className="h-11 w-full shrink-0 rounded-xl border border-white/10 bg-white/[0.05] px-5 text-sm font-semibold text-white transition-all duration-200 hover:bg-white/[0.09] active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50 md:w-auto"
      >
        {sent
          ? "Talebiniz Alındı ✓"
          : sending
            ? "Gönderiliyor..."
            : "Kurumsal Satışla Görüş"}
      </button>
    </div>
  );
}
