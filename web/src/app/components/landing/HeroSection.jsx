import { ArrowRight, Play, Sparkles, Compass } from "lucide-react";
import Link from "next/link";
import HeroChatCard from "./HeroChatCard";
import WatchVideoButton from "./WatchVideoButton";

/* Kaynaktaki üç istatistik metni birebir korundu. */
const STATS = [
  { value: "10k+", label: "Apps Generated" },
  { value: "99.9%", label: "Uptime" },
  { value: "2min", label: "Avg. Build Time" },
];

/**
 * Server component: yalnızca `HeroChatCard` (yazma animasyonu + tilt) ve
 * video butonu client tarafında.
 */
export default function HeroSection() {
  return (
    <div className="relative isolate overflow-hidden">
      <section className="lg:pt-32 lg:pb-32 max-w-7xl mr-auto ml-auto pt-28 pr-5 pb-16 pl-5 sm:pt-24 sm:pr-6 sm:pb-24 sm:pl-6">
        <div className="grid lg:grid-cols-12 gap-12 lg:gap-16 items-center">
          {/* Sol: kopya */}
          <div className="lg:col-span-7">
            <div
              className="opacity-0 blur-sm translate-y-8"
              style={{ animation: "fadeSlideIn 0.8s ease-out 0.2s forwards" }}
            >
              <div className="inline-flex items-center gap-2 px-3 py-1 text-xs font-medium text-fuchsia-300 bg-fuchsia-500/10 ring-1 ring-fuchsia-400/20 rounded-full mb-6">
                <Sparkles className="h-3 w-3" strokeWidth={1.5} />
                Sizinle Beraber Büyüyen Bir Platform
              </div>

              <h1 className="text-4xl sm:text-5xl lg:text-7xl font-display font-semibold text-white tracking-tight leading-[1.05] mb-6">
                Çözüm Arayanların ve Üretenlerin
                <br />
                <span className="text-fuchsia-400">Ortak Platformu</span>
              </h1>

              <p className="text-lg sm:text-xl text-white/75 leading-relaxed max-w-2xl mb-8">
                {/* COMP-006: "gelir elde edebildiği" → "paylaşabildiği".
                    Vizyon cümlesinin öznesi üretim; kazanç bir sonuç, vaat
                    değil. Pazaryeri ve komisyon modeli aşağıdaki fiyat ve
                    satıcı bölümlerinde olgusal olarak anlatılıyor. */}
                LUMANORIS olarak vizyonumuz, yapay zekâyı yalnızca kullanılan bir teknoloji olmaktan
                çıkarıp herkesin üretebildiği, geliştirebildiği ve paylaşabildiği küresel bir
                dijital ekonominin temeline dönüştürmektir.
              </p>

              <div className="flex flex-col sm:flex-row gap-4 mb-12">
                {/* Kaynakta düz teal zemin + koyu metin vardı. Fuchsia'nın düz
                    hâlinde beyaz metin 3.45:1 kalıyor (AA değil); projenin
                    kendi CTA gradient'i 4.7:1 üstünde — Button `default`
                    variant'ıyla da aynı görünüm. */}
                <Link
                  href="/register/"
                  className="inline-flex items-center justify-center px-6 py-3 text-base font-medium text-white bg-gradient-btn hover:brightness-110 rounded-lg transition shadow-glow"
                >
                  Hemen Başla
                  <ArrowRight className="ml-2 h-4 w-4" strokeWidth={1.5} />
                </Link>
                {/* "Keşfet" (N-45: eskiden "Hemen Keşfet"): kayıt İSTEMEDEN pazaryerine götürüyor.
                    "Hemen Başla" kayda gidiyor; ikisi bilerek ayrı, çünkü
                    revize listesinin amacı kullanıcının siteyi görmeden
                    kişisel verisini paylaşmak zorunda kalmaması. */}
                <Link
                  href="/dashboard/"
                  className="inline-flex items-center justify-center px-6 py-3 text-base font-medium text-white/85 hover:text-white bg-white/5 hover:bg-white/10 ring-1 ring-white/20 rounded-lg transition"
                >
                  Keşfet
                  <Compass className="ml-2 h-4 w-4" strokeWidth={1.5} />
                </Link>
                <WatchVideoButton className="inline-flex items-center justify-center px-6 py-3 text-base font-medium text-white/85 hover:text-white bg-white/5 hover:bg-white/10 ring-1 ring-white/20 rounded-lg transition">
                  Nasıl Çalışır?
                  <Play className="ml-2 h-4 w-4" strokeWidth={1.5} />
                </WatchVideoButton>
              </div>

              <div className="grid grid-cols-3 gap-4 sm:gap-8 pt-8 border-t border-white/10">
                {STATS.map((stat) => (
                  <div key={stat.label}>
                    <div className="text-xl sm:text-2xl font-display font-semibold text-white">{stat.value}</div>
                    <div className="text-xs sm:text-sm text-luma-muted">{stat.label}</div>
                  </div>
                ))}
              </div>
            </div>
          </div>

          {/* Sağ: sohbet arayüzü */}
          <div
            className="lg:col-span-5 opacity-0 blur-sm translate-x-8"
            style={{ animation: "fadeSlideIn 0.8s ease-out 0.6s forwards" }}
          >
            <HeroChatCard />
          </div>
        </div>
      </section>
    </div>
  );
}
