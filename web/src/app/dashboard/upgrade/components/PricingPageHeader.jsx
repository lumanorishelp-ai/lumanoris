import { Sparkles } from "lucide-react";

/**
 * Centered hero header with an ambient glow — distinct from the app-wide
 * shared/ui/page-layout.jsx PageHeader (which is left-aligned with an
 * eyebrow/title/description/action row), so kept page-local.
 */
export default function PricingPageHeader({ eyebrow, title }) {
  return (
    <div className="text-center max-w-3xl mx-auto mb-16 relative">
      <div className="absolute -top-24 left-1/2 -translate-x-1/2 w-96 h-96 bg-violet-600/15 rounded-full blur-[120px] pointer-events-none" />
      {eyebrow && (
        <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-violet-500/10 border border-violet-500/20 text-violet-300 text-xs font-medium mb-4 backdrop-blur-md">
          <Sparkles className="w-3.5 h-3.5 text-violet-400" />
          {eyebrow}
        </div>
      )}
      <h1 className="text-4xl sm:text-5xl lg:text-6xl font-bold tracking-tight text-white mb-6">
        {title}
      </h1>
      <p className="text-zinc-400 text-base sm:text-lg leading-relaxed">
        Yapay zeka asistanlarınızı ve iş akışlarınızı ölçeklendirmek için en uygun
        planı seçin. Paketler 30 gün geçerlidir ve otomatik yenilenmez.
        {/* N-28 — eskiden "İstediğiniz zaman yükseltin veya iptal edin" yazıyordu;
            iptal akışı yok, paket zaten otomatik yenilenmiyor (ödeme adımındaki
            metinle aynı). */}
      </p>
    </div>
  );
}
