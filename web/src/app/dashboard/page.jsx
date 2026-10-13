"use client";
import { ModalPortal } from "@/shared/ui/modal-portal";

import React, { useState, useEffect, useMemo, useRef, useContext } from "react";
import { UserContext } from "@/shared/contexts/UserContext";
import owlLogo from "@/images/header-logo-icon.png";
import MarketplaceControlBar from "@/widgets/MarketplaceControlBar";
import CategoryBadge from "@/shared/ui/category-badge";
import { cn } from "@/lib/utils";
import { useRouter } from "next/navigation";
import { requireLogin } from "@/shared/lib/auth-guard";
import { resolveCategory } from "@/shared/lib/categories";
import {
  Sparkles,
  MessageSquare,
  Plus,
  Heart,
  ArrowUpRight,
  ChevronRight,
  X,
  Zap,
  PackageSearch,
  ArrowUp,
  Mic,
  Square,
  ChevronDown,
  Check,
  Search,
  Bot,
  Lock,
  Compass,
} from "lucide-react";
import Link from "next/link";

function formatCompactNumber(n) {
  const num = Number(n) || 0;
  if (num >= 1000000) return (num / 1000000).toFixed(1).replace(".", ",") + "M";
  if (num >= 1000)
    return (
      (num / 1000).toFixed(num % 1000 === 0 ? 0 : 1).replace(".", ",") + "B"
    );
  return String(num);
}

function formatTime(dateString) {
  if (!dateString) return "Bugün";
  const date = new Date(dateString);
  if (isNaN(date.getTime())) return "Bugün";
  const diffDays = Math.ceil(
    Math.abs(new Date() - date) / (1000 * 60 * 60 * 24),
  );
  if (diffDays <= 1) return "Bugün";
  return `${diffDays} gün önce`;
}

function resolveCoverSrc(src) {
  if (!src || src === "default" || src === "0") {
    return "https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=800&auto=format&fit=crop&q=80";
  }
  if (
    src.startsWith("http://") ||
    src.startsWith("https://") ||
    src.startsWith("/")
  ) {
    return src;
  }
  return `/uploads/covers/${src}`;
}

function resolveAvatarSrc(src) {
  if (!src || src === "default" || src === "0") {
    return "https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=150&auto=format&fit=crop&q=80";
  }
  if (
    src.startsWith("http://") ||
    src.startsWith("https://") ||
    src.startsWith("/")
  ) {
    return src;
  }
  return `/uploads/avatars/${src}`;
}

import BentoBotCard from "@/entities/chatbot/ui/BotCard.bento";

function CompactBotCard({ bot, onOpenDetails }) {
  const category = resolveCategory(bot.kategori_id);

  return (
    <div
      onClick={() => onOpenDetails(bot)}
      onKeyDown={(event) => {
        if (
          event.currentTarget !== event.target ||
          !["Enter", " "].includes(event.key)
        )
          return;
        event.preventDefault();
        onOpenDetails(bot);
      }}
      role="button"
      tabIndex={0}
      className={`group relative flex items-center justify-between gap-3 overflow-hidden rounded-2xl border border-white/[0.08] bg-zinc-900/70 p-3 shadow-xl shadow-black/10 transition-[border-color,box-shadow] duration-200 focus-visible:outline-none ${category.hoverBorder} ${category.glow} cursor-pointer`}
    >
      <div className="flex items-center gap-3 min-w-0">
        <div className="h-12 w-12 shrink-0 overflow-hidden rounded-xl border border-white/10 bg-zinc-950">
          <img
            src={bot.image}
            alt={bot.title}
            className="h-full w-full object-cover transition-transform duration-300 group-hover:scale-110"
            onError={(e) => {
              e.currentTarget.src =
                "https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=800&auto=format&fit=crop&q=80";
            }}
          />
        </div>
        <div className="min-w-0">
          <h4 className="text-sm font-bold text-white truncate">{bot.title}</h4>
          <p className="mt-0.5 flex items-center gap-2 truncate text-xs text-zinc-400">
            <CategoryBadge category={bot.kategori_id} size="dot" />
            <span className="truncate">
              {bot.author} • {formatCompactNumber(bot.dialogues)} sohbet
            </span>
          </p>
        </div>
      </div>

      <div className="flex items-center gap-2 shrink-0">
        <div className="text-right">
          <div className="text-xs font-bold font-mono text-white">
            {bot.weeklyPrice > 0 ? `₺${bot.weeklyPrice}` : "Ücretsiz"}
          </div>
        </div>
        <div className="flex h-7 w-7 items-center justify-center rounded-lg border border-white/10 bg-zinc-800/80 text-zinc-300">
          <ArrowUpRight className="h-3.5 w-3.5" />
        </div>
      </div>
    </div>
  );
}

function ListBotCard({ bot, onOpenDetails }) {
  const category = resolveCategory(bot.kategori_id);

  return (
    <div
      onClick={() => onOpenDetails(bot)}
      onKeyDown={(event) => {
        if (
          event.currentTarget !== event.target ||
          !["Enter", " "].includes(event.key)
        )
          return;
        event.preventDefault();
        onOpenDetails(bot);
      }}
      role="button"
      tabIndex={0}
      className={`group relative flex flex-col sm:flex-row items-center justify-between gap-4 overflow-hidden rounded-2xl border border-white/[0.08] bg-zinc-900/70 p-4 shadow-xl shadow-black/10 transition-[border-color,box-shadow] duration-200 focus-visible:outline-none ${category.hoverBorder} ${category.glow} cursor-pointer`}
    >
      <div className="flex items-center gap-4 w-full sm:w-auto flex-1 min-w-0">
        <div className="h-16 w-16 sm:h-20 sm:w-20 shrink-0 overflow-hidden rounded-xl border border-white/10 bg-zinc-950">
          <img
            src={bot.image}
            alt={bot.title}
            className="h-full w-full object-cover transition-transform duration-500 group-hover:scale-110"
            onError={(e) => {
              e.currentTarget.src =
                "https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=800&auto=format&fit=crop&q=80";
            }}
          />
        </div>

        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2 mb-1">
            <span className="text-xs font-semibold text-violet-400">
              {bot.author}
            </span>
            <span className="text-zinc-600">•</span>
            <span className="text-caption text-zinc-400">{bot.time}</span>
          </div>

          <h3 className="text-base font-bold text-white truncate">
            {bot.title}
          </h3>

          <p className="mt-0.5 line-clamp-1 text-xs text-zinc-400">
            {bot.description}
          </p>
        </div>
      </div>

      <div className="flex items-center justify-between sm:justify-end gap-6 w-full sm:w-auto border-t sm:border-0 border-white/5 pt-3 sm:pt-0 shrink-0">
        <div className="flex items-center gap-4 text-xs text-zinc-400 font-medium">
          <span className="flex items-center gap-1">
            <MessageSquare className="h-3.5 w-3.5 text-violet-400" />
            {formatCompactNumber(bot.dialogues)}
          </span>
          <span className="flex items-center gap-1">
            <Heart className="h-3.5 w-3.5 text-rose-400" />
            {formatCompactNumber(bot.likes)}
          </span>
        </div>

        <div className="flex items-center gap-3">
          <div className="text-right">
            <div className="text-xs font-bold font-mono text-white">
              {bot.weeklyPrice > 0 ? `₺${bot.weeklyPrice}` : "Ücretsiz"}
            </div>
            {bot.weeklyPrice > 0 && (
              <div className="text-caption text-zinc-400">/hafta</div>
            )}
          </div>

          <button
            onClick={(e) => {
              e.stopPropagation();
              onOpenDetails(bot);
            }}
            className="flex h-9 w-9 items-center justify-center rounded-xl border border-white/10 bg-zinc-800 text-white transition-colors hover:bg-zinc-700"
          >
            <ChevronRight className="h-4 w-4" />
          </button>
        </div>
      </div>
    </div>
  );
}

function BotDetailModal2026({ bot, onClose }) {
  if (!bot) return null;

  const handleStartChat = () => {
    try {
      localStorage.setItem("chatTitle", bot.title);
      localStorage.setItem("chatId", bot.id);
    } catch (e) {
      console.error(e);
    }
    window.location.href = `/dashboard/chat/?botId=${bot.id}`;
  };

  return (
    <ModalPortal onClose={onClose}>
      <div className="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 lg:p-8">
        {/* Backdrop Glass */}
        <div
          className="fixed inset-0 bg-black/80 backdrop-blur-md transition-opacity animate-in fade-in duration-200"
          onClick={onClose}
        />

        {/* Raycast Style Dialog Modal */}
        <div className="relative z-10 max-h-[calc(100dvh-3rem)] w-full max-w-2xl overflow-y-auto overscroll-contain rounded-3xl border border-white/15 bg-zinc-950/95 p-6 sm:p-8 shadow-2xl backdrop-blur-2xl ring-1 ring-white/10 animate-in zoom-in-95 duration-200">
          {/* Close Button */}
          <button
            onClick={onClose}
            className="absolute right-4 top-4 z-20 flex h-9 w-9 items-center justify-center rounded-full border border-white/10 bg-zinc-900/80 text-zinc-400 hover:bg-zinc-800 hover:text-white transition-colors"
          >
            <X className="h-4 w-4" />
          </button>

          {/* Modal Hero Banner */}
          <div className="relative -mx-6 -mt-6 sm:-mx-8 sm:-mt-8 mb-6 h-48 overflow-hidden bg-zinc-900">
            <img
              src={bot.image}
              alt={bot.title}
              className="h-full w-full object-cover"
            />
            <div className="absolute inset-0 bg-gradient-to-t from-zinc-950 via-zinc-950/40 to-transparent" />

            <div className="absolute bottom-4 left-6 sm:left-8 right-6 flex items-end justify-between">
              <div className="flex items-center gap-3.5">
                <img
                  src={resolveAvatarSrc(bot.avatar)}
                  alt={bot.author}
                  className="h-12 w-12 rounded-2xl border-2 border-white/20 object-cover shadow-xl"
                />
                <div>
                  <h2 className="text-xl font-extrabold text-white sm:text-2xl">
                    {bot.title}
                  </h2>
                  <p className="text-xs text-zinc-300 font-medium">
                    Geliştirici: {bot.author}
                  </p>
                </div>
              </div>
            </div>
          </div>

          {/* Modal Info Details */}
          <div className="space-y-6">
            <div>
              <h4 className="mb-2 text-xs font-bold uppercase tracking-wider text-zinc-400">
                Bot Hakkında
              </h4>
              <p className="text-sm text-zinc-300 leading-relaxed">
                {bot.description ||
                  "Bu bot için henüz detaylı bir açıklama belirtilmedi."}
              </p>
            </div>

            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
              <div className="rounded-2xl border border-white/5 bg-zinc-900/60 p-3 text-center">
                <div className="text-caption text-zinc-400 font-bold uppercase tracking-wider">
                  Toplam Diyalog
                </div>
                <div className="text-lg font-bold font-mono text-white mt-1">
                  {formatCompactNumber(bot.dialogues)}
                </div>
              </div>
              <div className="rounded-2xl border border-white/5 bg-zinc-900/60 p-3 text-center">
                <div className="text-caption text-zinc-400 font-bold uppercase tracking-wider">
                  Beğeni
                </div>
                <div className="text-lg font-bold font-mono text-white mt-1">
                  {formatCompactNumber(bot.likes)}
                </div>
              </div>
              <div className="rounded-2xl border border-white/5 bg-zinc-900/60 p-3 text-center">
                <div className="text-caption text-zinc-400 font-bold uppercase tracking-wider">
                  Takipçi
                </div>
                <div className="text-lg font-bold font-mono text-white mt-1">
                  {formatCompactNumber(bot.followers || 0)}
                </div>
              </div>
              <div className="rounded-2xl border border-white/5 bg-zinc-900/60 p-3 text-center">
                <div className="text-caption text-zinc-400 font-bold uppercase tracking-wider">
                  Haftalık Ücret
                </div>
                <div className="text-lg font-bold font-mono text-emerald-400 mt-1">
                  {bot.weeklyPrice > 0 ? `₺${bot.weeklyPrice}` : "Ücretsiz"}
                </div>
              </div>
            </div>

            {/* Model Capabilities */}
            <div className="rounded-2xl border border-violet-500/20 bg-violet-500/5 p-4 flex items-start gap-3">
              <Zap className="h-5 w-5 text-violet-400 shrink-0 mt-0.5" />
              <div>
                <h5 className="text-xs font-bold text-violet-200">
                  2026 Model Desteği Aktif
                </h5>
                <p className="mt-0.5 text-xs text-violet-300/80 leading-relaxed">
                  Bu asistan en son nesil yapay zeka API’leri ve anlık arama
                  entegrasyonları ile donatılmıştır.
                </p>
              </div>
            </div>

            {/* Action Call-to-action */}
            <div className="flex items-center justify-end gap-3 pt-2">
              <button
                onClick={onClose}
                className="rounded-xl border border-white/10 bg-zinc-900 px-4 py-2.5 text-xs font-semibold text-zinc-300 hover:bg-zinc-800 hover:text-white transition-colors"
              >
                Kapat
              </button>
              <button
                onClick={handleStartChat}
                className="flex items-center gap-2 rounded-xl bg-violet-600 px-5 py-2.5 text-xs font-bold text-white shadow-lg shadow-violet-600/30 transition-all hover:bg-violet-500 active:scale-95"
              >
                <MessageSquare className="h-4 w-4" />
                Sohbeti Başlat
              </button>
            </div>
          </div>
        </div>
      </div>
    </ModalPortal>
  );
}

function SkeletonGrid2026() {
  return (
    <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
      {Array.from({ length: 8 }).map((_, i) => (
        <div
          key={i}
          className="flex flex-col overflow-hidden rounded-3xl border border-white/5 bg-zinc-900/30 p-4 backdrop-blur-2xl animate-pulse"
        >
          <div className="aspect-[16/10] w-full rounded-2xl bg-zinc-800/60 mb-4" />
          <div className="h-4 w-1/3 rounded bg-zinc-800/60 mb-2" />
          <div className="h-5 w-2/3 rounded bg-zinc-800/80 mb-3" />
          <div className="h-3 w-full rounded bg-zinc-800/40 mb-1" />
          <div className="h-3 w-4/5 rounded bg-zinc-800/40 mb-6" />
          <div className="mt-auto flex justify-between border-t border-white/5 pt-3">
            <div className="h-4 w-12 rounded bg-zinc-800/60" />
            <div className="h-4 w-16 rounded bg-zinc-800/60" />
          </div>
        </div>
      ))}
    </div>
  );
}

function EmptyState2026({ onClearFilters }) {
  return (
    <div className="flex flex-col items-center justify-center rounded-3xl border border-dashed border-white/10 bg-zinc-900/20 px-6 py-20 text-center backdrop-blur-2xl">
      <div className="relative mb-5 flex h-20 w-20 items-center justify-center rounded-2xl border border-white/10 bg-zinc-900/80 text-violet-400 shadow-2xl">
        <PackageSearch className="h-10 w-10 text-violet-400" />
        <div className="absolute -top-1 -right-1 flex h-5 w-5 items-center justify-center rounded-full bg-violet-500 ring-4 ring-zinc-950">
          <Sparkles className="h-3 w-3 text-white" />
        </div>
      </div>

      <h3 className="text-xl font-bold text-white">Bot Bulunamadı</h3>
      <p className="mt-2 max-w-sm text-sm text-zinc-400 leading-relaxed">
        Arama kriterlerinizle veya seçtiğiniz kategoriyle eşleşen yapay zeka
        asistanı bulunamadı.
      </p>

      <div className="mt-6 flex flex-wrap items-center justify-center gap-3">
        <button
          onClick={onClearFilters}
          className="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-zinc-800/80 px-4 py-2.5 text-xs font-semibold text-white transition-all hover:bg-zinc-700 hover:border-white/20"
        >
          <X className="h-3.5 w-3.5" />
          Filtreleri Temizle
        </button>
        <a
          href="/dashboard/chatbots/create"
          className="inline-flex items-center gap-2 rounded-xl bg-violet-600 px-4 py-2.5 text-xs font-semibold text-white shadow-lg shadow-violet-600/20 transition-all hover:bg-violet-500"
        >
          <Plus className="h-3.5 w-3.5" />
          İlk Botu Sen Oluştur
        </a>
      </div>
    </div>
  );
}

/**
 * Ana sayfanın giriş alanı: ortada Lumanoris baykuşu, altında "yeni sohbete
 * başla" kompozitörü. Pazaryeri istatistik başlığı buradan /dashboard/explore
 * sayfasına taşındı; anasayfa artık doğrudan sohbetle açılıyor.
 *
 * Gönderim, sohbet sayfasının zaten desteklediği ?prompt= parametresini
 * kullanır: chat sayfası bu metinle yeni bir konuşma açıp ilk mesajı kendisi
 * gönderir.
 */
function NewChatHero({
  bot,
  value,
  onChange,
  onSubmit,
  loading,
  pickerBots,
  pickerLoading,
  pickerError,
  onSelectBot,
  onBrowseMarketplace,
}) {
  const textareaRef = useRef(null);
  const botMenuRef = useRef(null);
  const [botMenuOpen, setBotMenuOpen] = useState(false);

  /* Menü dışarı tıklamayla ve Esc ile kapanıyor. Bunlar olmadan panel
     açık kalıp altındaki kompozitörün üstünü örtüyor. */
  useEffect(() => {
    if (!botMenuOpen) return;

    const onPointerDown = (event) => {
      if (!botMenuRef.current?.contains(event.target)) setBotMenuOpen(false);
    };
    const onKeyDown = (event) => {
      if (event.key === "Escape") setBotMenuOpen(false);
    };

    document.addEventListener("mousedown", onPointerDown);
    document.addEventListener("keydown", onKeyDown);
    return () => {
      document.removeEventListener("mousedown", onPointerDown);
      document.removeEventListener("keydown", onKeyDown);
    };
  }, [botMenuOpen]);
  const speechRecognitionRef = useRef(null);
  const [isListening, setIsListening] = useState(false);
  const [voiceError, setVoiceError] = useState("");

  // Tek satır yüksekliğinde başlar, içeriğe göre büyür, tavana gelince kendi
  // içinde kayar. Eski hâli 67px'e sabitlenmişti: tek satırlık bir mesajda
  // bile kocaman boş bir şerit duruyordu.
  const autoGrow = () => {
    const el = textareaRef.current;
    if (!el) return;
    el.style.height = "auto";
    el.style.height = `${Math.min(el.scrollHeight, 200)}px`;
  };

  const canSend = Boolean(value.trim() && bot);

  const toggleVoiceInput = () => {
    if (isListening) {
      speechRecognitionRef.current?.stop();
      return;
    }

    const SpeechRecognition =
      window.SpeechRecognition || window.webkitSpeechRecognition;

    if (!SpeechRecognition) {
      setVoiceError("Sesle yazma bu tarayıcıda desteklenmiyor.");
      return;
    }

    const recognition = new SpeechRecognition();
    const initialValue = value.trim();
    recognition.lang = "tr-TR";
    recognition.interimResults = true;
    recognition.continuous = false;

    recognition.onstart = () => {
      setVoiceError("");
      setIsListening(true);
    };
    recognition.onresult = (event) => {
      const transcript = Array.from(event.results)
        .map((result) => result[0].transcript)
        .join("")
        .trim();
      onChange([initialValue, transcript].filter(Boolean).join(" "));
      requestAnimationFrame(autoGrow);
    };
    recognition.onerror = (event) => {
      if (event.error !== "aborted" && event.error !== "no-speech") {
        setVoiceError("Ses algılanamadı. Lütfen tekrar deneyin.");
      }
    };
    recognition.onend = () => {
      speechRecognitionRef.current = null;
      setIsListening(false);
    };

    speechRecognitionRef.current = recognition;
    recognition.start();
  };

  useEffect(
    () => () => {
      speechRecognitionRef.current?.abort();
    },
    [],
  );

  return (
    <section className="relative mb-12 flex flex-col items-center pt-8 sm:pt-14">
      {/* Baykuş işareti + halesi */}
      <div className="relative mb-9 flex h-24 w-24 items-center justify-center">
        <div className="pointer-events-none absolute h-32 w-32 rounded-full bg-violet-600/25 blur-[64px]" />
        <div className="pointer-events-none absolute h-20 w-20 rounded-full bg-fuchsia-500/35 blur-[38px]" />
        <img
          src={owlLogo.src}
          alt="Lumanoris"
          className="relative h-16 w-16 object-contain drop-shadow-[0_0_18px_rgba(217,70,239,0.55)]"
        />
      </div>

      {/* Kompozitör: metin alanı üstte, eylemler altta kendi sırasında. Tek
          sıraya dizildiğinde ikon/metin/düğme arasında büyük boşluklar kalıyor
          ve kutu boş görünüyordu. */}
      <div className="w-full max-w-3xl">
        <div className="rounded-[26px] bg-gradient-to-br from-fuchsia-500/25 via-violet-500/15 to-white/[0.06] p-px transition-all duration-300 focus-within:from-fuchsia-400/70 focus-within:via-violet-400/40 focus-within:shadow-[0_0_45px_-10px_rgba(217,70,239,0.5)]">
          <div className="rounded-[25px] bg-[#0a0a12] px-3.5 pb-3 pt-3">
            {/* data-focus-managed: global.css tüm textarea'lara focus'ta
                çerçeve + gölge dayatıyor; halka zaten sarmalayıcıda olduğu
                için o kural burada devre dışı (çift çerçeve olmasın). */}
            <div className="relative">
              <textarea
                ref={textareaRef}
                value={value}
                rows={1}
                placeholder="Yeni sohbete başla..."
                data-focus-managed
                onChange={(e) => {
                  onChange(e.target.value);
                  autoGrow();
                }}
                onInput={autoGrow}
                onKeyDown={(e) => {
                  if (e.key === "Enter" && !e.shiftKey) {
                    e.preventDefault();
                    onSubmit();
                  }
                }}
                className="block max-h-[200px] w-full resize-none border-none bg-transparent px-2 py-2 pr-24 font-sans text-[15px] leading-6 text-white outline-none placeholder:text-zinc-500"
              />

              <div className="absolute right-0 top-0 flex items-center gap-2">
                <button
                  type="button"
                  onClick={toggleVoiceInput}
                  aria-label={isListening ? "Sesle yazmayı durdur" : "Sesle yaz"}
                  aria-pressed={isListening}
                  title={isListening ? "Dinleniyor — durdurmak için tıklayın" : "Sesle yaz"}
                  className={`flex h-9 w-9 items-center justify-center rounded-full border transition-colors ${
                    isListening
                      ? "border-rose-400/60 bg-rose-500/15 text-rose-300 animate-pulse"
                      : "border-white/10 bg-white/[0.04] text-zinc-400 hover:border-fuchsia-400/50 hover:text-fuchsia-300"
                  }`}
                >
                  {isListening ? <Square className="h-3.5 w-3.5 fill-current" /> : <Mic className="h-4 w-4" />}
                </button>
                <button
                  type="button"
                  onClick={onSubmit}
                  disabled={!canSend}
                  aria-label="Gönder"
                  className={`flex h-9 w-9 items-center justify-center rounded-full transition-all duration-200 ${
                    canSend
                      ? "bg-gradient-btn text-white shadow-glow hover:scale-105 active:scale-95"
                      : "cursor-not-allowed bg-white/[0.06] text-zinc-600"
                  }`}
                >
                  <ArrowUp className="h-[18px] w-[18px]" strokeWidth={2.5} />
                </button>
              </div>
            </div>

            <div className="mt-1.5 flex items-center gap-3">
              {/* Hedef bot çipi bir açılır liste. Madde 6 / GK-13: liste
                  TAKİP EDİLEN botlar (eskiden satın alınanlardı). Erişimi
                  olanlar seçilebilir; olmayanlar kilitli ve "Profili gör"
                  ile. Platform botu Lumanoris AI varsayılan. */}
              <div className="relative" ref={botMenuRef}>
                <button
                  type="button"
                  onClick={() => setBotMenuOpen((open) => !open)}
                  disabled={loading}
                  title="Başka bir bot seç"
                  aria-haspopup="listbox"
                  aria-expanded={botMenuOpen}
                  className="flex min-w-0 items-center gap-2 rounded-full border border-white/10 bg-white/[0.04] py-1 pl-1 pr-2.5 transition-colors hover:border-violet-500/40 hover:bg-white/[0.08] disabled:cursor-default disabled:opacity-60"
                >
                  {loading ? (
                    <>
                      <span className="h-6 w-6 animate-pulse rounded-full bg-white/10" />
                      <span className="text-xs text-zinc-500">
                        Botlar yükleniyor…
                      </span>
                    </>
                  ) : bot ? (
                    <>
                      <img
                        src={resolveAvatarSrc(bot.avatar)}
                        alt=""
                        className="h-6 w-6 rounded-full object-cover"
                      />
                      <span className="max-w-[150px] truncate text-xs font-medium text-zinc-200 sm:max-w-[260px]">
                        {bot.title}
                      </span>
                      <ChevronDown className="h-3.5 w-3.5 shrink-0 text-zinc-500" />
                    </>
                  ) : (
                    <>
                      <span className="flex h-6 w-6 items-center justify-center rounded-full bg-white/10 text-zinc-400">
                        <Bot className="h-3.5 w-3.5" />
                      </span>
                      <span className="text-xs font-medium text-zinc-300">
                        Bot seç
                      </span>
                      <ChevronDown className="h-3.5 w-3.5 shrink-0 text-zinc-500" />
                    </>
                  )}
                </button>

                {botMenuOpen && (
                  <div
                    role="listbox"
                    className="absolute bottom-full left-0 z-40 mb-2 max-h-72 w-72 overflow-y-auto rounded-2xl border border-white/10 bg-[#0c0c14]/95 p-1.5 shadow-2xl backdrop-blur-xl"
                  >
                    <p className="px-3 py-2 text-caption uppercase tracking-wider text-zinc-500">
                      Takip Ettiklerim
                    </p>

                    {pickerLoading ? (
                      <p className="px-3 pb-2 text-xs text-zinc-500">
                        Takip ettiğiniz botlar yükleniyor…
                      </p>
                    ) : pickerError ? (
                      <p className="px-3 pb-2 text-xs leading-relaxed text-rose-300">
                        {pickerError}
                      </p>
                    ) : (
                      <>
                        {pickerBots.map((item) => {
                          const isCurrent = Number(bot?.id) === Number(item.id);
                          const avatar = (
                            <img
                              src={resolveAvatarSrc(item.avatar)}
                              alt=""
                              className="h-7 w-7 shrink-0 rounded-full object-cover"
                            />
                          );

                          /* Erişimi olmayan takip edilen bot: seçilemez,
                             kilitli; yerine botun profiline (sohbet
                             sayfasındaki profil kartı) bağlantı. */
                          if (!item.accessible) {
                            return (
                              <div
                                key={item.id}
                                className="flex w-full items-center gap-2.5 rounded-xl px-3 py-2 opacity-70"
                              >
                                {avatar}
                                <span className="min-w-0 flex-1">
                                  <span className="flex items-center gap-1.5 truncate text-xs font-medium text-zinc-300">
                                    <Lock className="h-3 w-3 shrink-0 text-zinc-500" />
                                    <span className="truncate">{item.title}</span>
                                  </span>
                                </span>
                                <Link
                                  href={`/dashboard/chat?botId=${item.id}`}
                                  onClick={() => setBotMenuOpen(false)}
                                  className="shrink-0 rounded-lg px-2 py-1 text-caption font-medium text-violet-300 transition-colors hover:bg-white/5 hover:text-violet-200"
                                >
                                  Profili gör
                                </Link>
                              </div>
                            );
                          }

                          return (
                            <button
                              key={item.id}
                              type="button"
                              role="option"
                              aria-selected={isCurrent}
                              onClick={() => {
                                onSelectBot(item.id);
                                setBotMenuOpen(false);
                              }}
                              className={cn(
                                "flex w-full items-center gap-2.5 rounded-xl px-3 py-2 text-left transition-colors",
                                isCurrent ? "bg-violet-500/15" : "hover:bg-white/5",
                              )}
                            >
                              {avatar}
                              <span className="min-w-0 flex-1 truncate text-xs font-medium text-zinc-200">
                                {item.title}
                              </span>
                              {isCurrent && (
                                <Check className="h-3.5 w-3.5 shrink-0 text-violet-400" />
                              )}
                            </button>
                          );
                        })}

                        {/* GK-13 — platform botu dışında takip yoksa boş
                            durum + Keşfet bağlantısı. */}
                        {!pickerBots.some((b) => !b.isHouse) && (
                          <div className="px-3 pb-2 pt-1">
                            <p className="text-xs leading-relaxed text-zinc-400">
                              Henüz takip ettiğiniz bir bot yok. Takip ettiğiniz
                              botlar burada listelenir.
                            </p>
                            <Link
                              href="/dashboard/explore"
                              onClick={() => setBotMenuOpen(false)}
                              className="mt-1.5 inline-flex items-center gap-1.5 text-xs font-medium text-violet-300 hover:text-violet-200"
                            >
                              <Compass className="h-3.5 w-3.5" />
                              Keşfet
                            </Link>
                          </div>
                        )}
                      </>
                    )}

                    {/* Eski davranış kaybolmasın: çip daha önce pazaryeri
                        aramasına kaydırıyordu, o eylem buraya taşındı. */}
                    <div className="mt-1 border-t border-white/5 pt-1">
                      <button
                        type="button"
                        onClick={() => {
                          setBotMenuOpen(false);
                          onBrowseMarketplace();
                        }}
                        className="flex w-full items-center gap-2 rounded-xl px-3 py-2 text-xs font-medium text-zinc-300 transition-colors hover:bg-white/5 hover:text-white"
                      >
                        <Search className="h-3.5 w-3.5 text-violet-400" />
                        Pazaryerinde ara
                      </button>
                    </div>
                  </div>
                )}
              </div>
            </div>
            {voiceError && <p className="mt-2 px-2 text-xs text-rose-300">{voiceError}</p>}
          </div>
        </div>
      </div>
    </section>
  );
}

/* Kompozitörün varsayılan botu. Ada göre eşleştiriliyor (id değil), çünkü
   id kuruluma göre değişiyor. Karşılaştırma Türkçe büyük harfe çevrilerek
   yapılıyor: JS varsayılanında "i" harfi "I" olur, Türkçede "İ". */
const HOUSE_BOT_NAME = "LUMANORIS AI";

export function MainDashboard2026() {
  const { userId } = useContext(UserContext);
  const router = useRouter();

  // State definitions (preserving exact original business logic)
  const [allBots, setAllBots] = useState([]);
  const [categories, setCategories] = useState([]);
  const [selectedCategory, setSelectedCategory] = useState("Tümü");
  const [searchQuery, setSearchQuery] = useState("");
  const [sort, setSort] = useState("onerilen");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  // New UI view states
  const [viewMode, setViewMode] = useState("bento"); // 'bento' | 'compact' | 'list'
  const [selectedBotModal, setSelectedBotModal] = useState(null);

  // Anasayfa kompozitörü
  const [heroPrompt, setHeroPrompt] = useState("");
  const [recentBotId, setRecentBotId] = useState(null);
  // Madde 6 / GK-13 — seçici artık TAKİP EDİLEN botlardan besleniyor.
  const [pickerBots, setPickerBots] = useState([]);
  const [pickerLoading, setPickerLoading] = useState(true);
  const [pickerError, setPickerError] = useState(null);
  // Kullanıcının çipten AÇIKÇA seçtiği bot; her türlü otomatik tahmini ezer.
  const [pickedBotId, setPickedBotId] = useState(null);

  const searchInputRef = useRef(null);

  // 1. Fetch categories
  useEffect(() => {
    fetch("/api/content/getcategories.php")
      .then(async (res) => {
        try {
          const data = JSON.parse(await res.text());
          if (Array.isArray(data?.categories)) {
            setCategories([
              { id: "all", kategori_adi_tr: "Tümü" },
              ...data.categories,
            ]);
          } else {
            setCategories([{ id: "all", kategori_adi_tr: "Tümü" }]);
          }
        } catch (e) {
          console.error("Kategori yükleme hatası:", e);
          setCategories([{ id: "all", kategori_adi_tr: "Tümü" }]);
        }
      })
      .catch(() => {
        setCategories([{ id: "all", kategori_adi_tr: "Tümü" }]);
      });
  }, []);

  // 2. Fetch chatbots & social filters (Exact preserved business logic)
  useEffect(() => {
    const fetchData = async () => {
      setLoading(true);
      setError(null);
      try {
        const [botsRes, unRes, hideRes, listsRes] = await Promise.all([
          fetch(`/api/chatbot/getchatbots.php`),
          userId
            ? fetch(`/api/social/getuninterest.php?id=${userId}`)
            : Promise.resolve(null),
          userId
            ? fetch(`/api/social/gethide.php?user_id=${userId}`)
            : Promise.resolve(null),
          userId
            ? fetch(`/api/social/getuserlists.php?id=${userId}`)
            : Promise.resolve(null),
        ]);

        const botsData = await botsRes.json();
        const unData = unRes ? await unRes.json() : [];
        const hideData = hideRes ? await hideRes.json() : [];
        const listsData = listsRes ? await listsRes.json() : [];

        const uninterestedCategoryIds = Array.isArray(unData?.categories)
          ? unData.categories.map(Number)
          : [];
        const hiddenBotIds = Array.isArray(hideData?.hidden)
          ? hideData.hidden.map(Number)
          : [];

        if (Array.isArray(botsData?.bots)) {
          const mapped = botsData.bots
            .filter(
              (bot) =>
                !uninterestedCategoryIds.includes(Number(bot.kategori_id)) &&
                !hiddenBotIds.includes(Number(bot.id)),
            )
            .map((bot) => ({
              id: bot.id,
              title: bot.isim,
              description: bot.aciklama,
              author:
                (bot.owner_name === "SYSTEM" ? "Lumanoris" : bot.owner_name) ||
                "Anonim",
              dialogues: bot.toplam_chats,
              time: formatTime(bot.yayimlanma_tarih),
              publishedAt: bot.yayimlanma_tarih,
              avatar: bot.profil_fotografi,
              image: resolveCoverSrc(bot.kapak_fotografi),
              kategori_id: bot.kategori_id,
              followers: bot.toplam_follows,
              likes: bot.toplam_likes,
              // N-35: kalbin başlangıç durumu sunucudan (oturumsuz → false).
              likedByMe: Number(bot.liked_by_me) === 1,
              comments: bot.toplam_comments,
              saves: bot.toplam_lists,
              weeklyPrice: Number(bot.ucret_haftalik) || 0,
              // N-26: yalnızca gerçek satış kaydı varsa (sunucu `has_sales`).
              badge: Number(bot.has_sales) === 1
                ? { type: "sold", label: "Daha Önce Satıldı" }
                : null,
              // N-15: puan verisi yok; sabit 4.9 uydurmaydı.
              rating: null,
              userLists: Array.isArray(listsData?.lists) ? listsData.lists : [],
            }));
          setAllBots(mapped);
        } else {
          setAllBots([]);
          setError(botsData?.message || "Chatbotlar yüklenemedi.");
        }
      } catch (err) {
        console.error("Veri işleme hatası:", err);
        setAllBots([]);
        setError("Sunucuya bağlanılamadı.");
      } finally {
        setLoading(false);
      }
    };
    fetchData();
  }, [userId]);

  /* Madde 6 / GK-13 — çipin açılır listesi TAKİP EDİLEN botlar.
     • getfollowedbots.php: takip edilenler (+ platform botu Lumanoris AI,
       sunucu onu her hesaba örtük takip olarak ekliyor).
     • Kilit: her botun `can_chat` alanı. N-27 — sunucu onu sohbet kapısıyla
       (generatereply) AYNI kuraldan, userHasAccess('chat') ile hesaplıyor:
       sahip, aktif abone ve ücretsiz herkese açık bot (GK-2). Eskiden kilit
       getchatbotsmenu.php'den (yalnızca sahip + abone) türetiliyordu; takip
       edilen ücretsiz bot, sohbet edilebildiği halde kilitli görünüyordu.
     • Lumanoris AI varsayılan bot olarak her zaman seçilebilir. */
  useEffect(() => {
    if (!userId) {
      setPickerBots([]);
      setPickerLoading(false);
      return;
    }
    let cancelled = false;
    setPickerLoading(true);
    setPickerError(null);

    const getJson = (url) =>
      fetch(url, { credentials: "include" }).then((res) => res.json());

    getJson("/api/social/getfollowedbots.php")
      .then((followed) => {
        if (cancelled) return;
        if (!followed?.success || !Array.isArray(followed.bots)) {
          throw new Error(followed?.message || "Takip edilen botlar yüklenemedi.");
        }
        setPickerBots(
          followed.bots.map((b) => {
            const isHouse =
              (b.isim || "").trim().toLocaleUpperCase("tr-TR") === HOUSE_BOT_NAME;
            return {
              id: b.id,
              title: b.isim,
              avatar: b.profil_fotografi,
              isHouse,
              accessible: isHouse || b.can_chat === true,
            };
          }),
        );
      })
      .catch((err) => {
        if (cancelled) return;
        console.error("Takip edilen botlar yüklenemedi:", err);
        setPickerBots([]);
        setPickerError("Takip ettiğiniz botlar yüklenemedi.");
      })
      .finally(() => {
        if (!cancelled) setPickerLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [userId]);

  // Kompozitörün konuşacağı botu belirlemek için son sohbeti çek.
  useEffect(() => {
    if (!userId) return;
    let cancelled = false;
    fetch(`/api/chat/gethistory.php?user_id=${userId}`)
      .then((res) => res.json())
      .then((data) => {
        if (cancelled) return;
        const latest = Array.isArray(data?.results) ? data.results[0] : null;
        if (latest?.chatbot_id) setRecentBotId(Number(latest.chatbot_id));
      })
      .catch(() => {
        /* geçmiş okunamazsa kompozitör en popüler bota düşer */
      });
    return () => {
      cancelled = true;
    };
  }, [userId]);

  // Command K Shortcut
  useEffect(() => {
    const handleKeyDown = (e) => {
      if ((e.metaKey || e.ctrlKey) && e.key === "k") {
        e.preventDefault();
        searchInputRef.current?.focus();
      }
    };
    window.addEventListener("keydown", handleKeyDown);
    return () => window.removeEventListener("keydown", handleKeyDown);
  }, []);

  // Compute filtered & sorted bots list
  const bots = useMemo(() => {
    let result = allBots;

    if (selectedCategory !== "Tümü") {
      const cat = categories.find(
        (c) => c.kategori_adi_tr === selectedCategory,
      );
      result = cat
        ? result.filter((b) => String(b.kategori_id) === String(cat.id))
        : result;
    }

    if (searchQuery.trim()) {
      const q = searchQuery.trim().toLocaleLowerCase("tr");
      result = result.filter(
        (b) =>
          (b.title || "").toLocaleLowerCase("tr").includes(q) ||
          (b.description || "").toLocaleLowerCase("tr").includes(q) ||
          (b.author || "").toLocaleLowerCase("tr").includes(q),
      );
    }

    const sorted = [...result];
    switch (sort) {
      case "fiyat_artan":
        sorted.sort((a, b) => a.weeklyPrice - b.weeklyPrice);
        break;
      case "fiyat_azalan":
        sorted.sort((a, b) => b.weeklyPrice - a.weeklyPrice);
        break;
      case "favoriler":
        sorted.sort((a, b) => (b.likes || 0) - (a.likes || 0));
        break;
      case "liste":
        sorted.sort((a, b) => (b.saves || 0) - (a.saves || 0));
        break;
      case "yeni":
        sorted.sort(
          (a, b) => new Date(b.publishedAt) - new Date(a.publishedAt),
        );
        break;
      case "diyalog":
        sorted.sort((a, b) => (b.dialogues || 0) - (a.dialogues || 0));
        break;
      case "degerlendirme":
        sorted.sort(
          (a, b) =>
            (b.likes || 0) +
            (b.comments || 0) -
            ((a.likes || 0) + (a.comments || 0)),
        );
        break;
      default:
        break; // 'onerilen'
    }
    return sorted;
  }, [allBots, selectedCategory, categories, searchQuery, sort]);

  // Kompozitörün hedef botu: önce kullanıcının en son konuştuğu bot, o yoksa
  // pazaryerinin en çok diyalog almış botu. İkisi de yoksa gönderim kapalı.
  const heroBot = useMemo(() => {
    /* Kullanıcının menüden seçtiği her şeyden önce gelir. Satın alınan bot
       pazaryeri listesinde bulunmayabilir (yayından kaldırılmış olabilir),
       o yüzden önce satın alınanlarda aranıyor. */
    if (pickedBotId !== null) {
      const picked =
        pickerBots.find(
          (b) => b.accessible && Number(b.id) === Number(pickedBotId),
        ) || allBots.find((b) => Number(b.id) === Number(pickedBotId));
      if (picked) return picked;
    }

    /* GK-13 — pazaryeri listesi boşken de (B1: getPublished aktif satıcı
       ister) varsayılan Lumanoris AI olabilsin diye ev botu takip
       listesinde de aranıyor; sunucu onu her hesaba ekliyor. */
    const followedHouse = pickerBots.find((b) => b.isHouse);

    if (!allBots.length) return followedHouse || null;

    /* Varsayılan artık LUMANORIS AI — platformun kendi asistanı. Eskiden
       "son konuştuğun bot, o da yoksa en çok diyalog almış bot" seçiliyordu;
       yani kullanıcı hiçbir şey seçmeden rastgele bir satıcının botuyla
       konuşmaya başlıyordu. Kimliğe göre değil ADA göre arıyoruz: id
       kurulumdan kuruluma değişir, tohum verideki 5 numara başka bir
       veritabanında başka bir bota ait olabilir. */
    const house = allBots.find(
      (b) => (b.title || "").trim().toLocaleUpperCase("tr-TR") === HOUSE_BOT_NAME,
    );
    if (house) return house;
    if (followedHouse) return followedHouse;

    // Ev botu bulunamazsa (silinmiş/yayından kalkmış) eski davranış devrede.
    const recent = allBots.find((b) => Number(b.id) === recentBotId);
    if (recent) return recent;
    return [...allBots].sort(
      (a, b) => (Number(b.dialogues) || 0) - (Number(a.dialogues) || 0),
    )[0];
  }, [allBots, recentBotId, pickedBotId, pickerBots]);

  // Kompozitördeki bot çipi buraya bağlı: aşağıdaki pazaryeri aramasına
  // kaydırıp odaklanır, böylece "başka bir bot için aşağıdan seç" cümlesi
  // yerine gerçekten seçtiren bir eylem oluyor.
  const focusBotSearch = () => {
    const el = searchInputRef.current;
    if (!el) return;
    el.scrollIntoView({ behavior: "smooth", block: "center" });
    el.focus({ preventScroll: true });
  };

  const startHeroChat = () => {
    const text = heroPrompt.trim();
    if (!text || !heroBot) return;
    /* Misafir sohbete başlayamaz — sohbet kişisel veri üretir ve mesaj hakkı
       tüketir. Doğrudan girişe yönlendiriyoruz; aksi hâlde /dashboard/chat'e
       gidip orada panel iskeletiyle karşılaşıp login'e atılırdı. */
    if (!requireLogin(userId, router)) return;
    try {
      localStorage.setItem("chatTitle", heroBot.title);
      localStorage.setItem("chatId", heroBot.id);
    } catch (e) {
      console.error(e);
    }
    window.location.href = `/dashboard/chat/?botId=${heroBot.id}&prompt=${encodeURIComponent(text)}`;
  };

  return (
    <div className="min-h-screen bg-zinc-950 text-zinc-100 selection:bg-violet-500/30 selection:text-violet-200 antialiased font-sans">
      {/* Background Ambient FX */}
      <div className="fixed inset-0 pointer-events-none overflow-hidden">
        <div className="absolute -top-[25%] left-1/2 -translate-x-1/2 h-[600px] w-[1200px] bg-gradient-to-b from-violet-600/10 via-fuchsia-600/5 to-transparent blur-3xl opacity-80" />
      </div>

      <main className="relative z-10 px-4 py-8 sm:px-6 lg:px-8">
        {/* Pazaryeri başlığı ve istatistik kartları /dashboard/explore
            sayfasına taşındı; anasayfa artık sohbetle açılıyor. */}
        <NewChatHero
          bot={heroBot}
          value={heroPrompt}
          onChange={setHeroPrompt}
          onSubmit={startHeroChat}
          loading={loading}
          pickerBots={pickerBots}
          pickerLoading={pickerLoading}
          pickerError={pickerError}
          onSelectBot={setPickedBotId}
          onBrowseMarketplace={focusBotSearch}
        />

        <MarketplaceControlBar
          query={searchQuery}
          onQueryChange={setSearchQuery}
          sort={sort}
          onSortChange={setSort}
          categories={categories}
          selected={selectedCategory}
          onSelectCategory={setSelectedCategory}
          viewMode={viewMode}
          onViewModeChange={setViewMode}
          searchInputRef={searchInputRef}
          className="mb-8"
        />

        {error && (
          <div className="mb-6 rounded-2xl border border-rose-500/20 bg-rose-500/10 px-5 py-4 text-sm text-rose-300">
            {error}
          </div>
        )}

        {/* Dynamic Bot Feed Grid / Compact / List */}
        {loading ? (
          <SkeletonGrid2026 />
        ) : bots.length === 0 ? (
          <EmptyState2026
            onClearFilters={() => {
              setSelectedCategory("Tümü");
              setSearchQuery("");
            }}
          />
        ) : viewMode === "bento" ? (
          <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            {bots.map((bot) => (
              <BentoBotCard
                key={bot.id}
                bot={bot}
                onOpenDetails={(b) => setSelectedBotModal(b)}
              />
            ))}
          </div>
        ) : viewMode === "compact" ? (
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {bots.map((bot) => (
              <CompactBotCard
                key={bot.id}
                bot={bot}
                onOpenDetails={(b) => setSelectedBotModal(b)}
              />
            ))}
          </div>
        ) : (
          <div className="space-y-3">
            {bots.map((bot) => (
              <ListBotCard
                key={bot.id}
                bot={bot}
                onOpenDetails={(b) => setSelectedBotModal(b)}
              />
            ))}
          </div>
        )}

        {/* Quick Preview Modal */}
        <BotDetailModal2026
          bot={selectedBotModal}
          onClose={() => setSelectedBotModal(null)}
        />
      </main>
    </div>
  );
}

export default function App() {
  return <MainDashboard2026 />;
}
