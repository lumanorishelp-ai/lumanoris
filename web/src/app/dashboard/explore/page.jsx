"use client";
import React, { useEffect, useState, useCallback, useMemo, useRef } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import {
  SearchX,
  Plus,
  Bot,
  MessageSquare,
  Users,
  Layers,
  Sparkles,
  TrendingUp,
} from "lucide-react";
import { EmptyState } from "@/shared/ui/empty-state";
import { Skeleton } from "@/shared/ui/skeleton";
import { resolveCoverSrc } from "@/shared/lib/image";
import BotList from "@/widgets/BotList";
import MarketplaceControlBar from "@/widgets/MarketplaceControlBar";
import { CardGrid } from "@/shared/ui/page-layout";

function formatCompactNumber(n) {
  const num = Number(n) || 0;
  if (num >= 1000000) return (num / 1000000).toFixed(1).replace(".", ",") + "M";
  if (num >= 1000)
    return (num / 1000).toFixed(num % 1000 === 0 ? 0 : 1).replace(".", ",") + "B";
  return String(num);
}

function StatCard2026({
  icon: Icon,
  label,
  value,
  subtext,
  badgeText,
  badgeColor = "violet",
}) {
  return (
    <div className="group relative overflow-hidden rounded-2xl border border-white/[0.08] bg-gradient-to-b from-zinc-900/60 to-zinc-950/80 p-5 backdrop-blur-2xl transition-all duration-300 hover:border-white/20 hover:shadow-2xl hover:shadow-violet-500/5">
      {/* Subtle top border glow sweep */}
      <div className="absolute inset-x-0 top-0 h-[1px] bg-gradient-to-r from-transparent via-white/20 to-transparent opacity-0 transition-opacity duration-300 group-hover:opacity-100" />
      <div className="absolute -right-8 -top-8 h-28 w-28 rounded-full bg-violet-600/10 blur-2xl transition-all duration-500 group-hover:bg-violet-500/20 group-hover:scale-125 pointer-events-none" />

      <div className="flex items-center justify-between gap-3">
        <span className="text-caption font-bold uppercase tracking-wider text-zinc-400">
          {label}
        </span>
        <div className="flex h-9 w-9 items-center justify-center rounded-xl border border-white/10 bg-zinc-900/80 text-violet-400 shadow-inner group-hover:scale-110 group-hover:border-violet-500/40 transition-transform duration-300">
          <Icon className="h-4 w-4" />
        </div>
      </div>

      <div className="mt-4 flex items-baseline justify-between gap-2">
        <div className="text-2xl font-black tracking-tight text-white sm:text-3xl font-mono">
          {value}
        </div>
        {badgeText && (
          <span
            className={`inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-caption font-bold tracking-wide uppercase ${
              badgeColor === "emerald"
                ? "border-emerald-500/30 bg-emerald-500/10 text-emerald-400"
                : "border-violet-500/30 bg-violet-500/10 text-violet-300"
            }`}
          >
            <TrendingUp className="h-2.5 w-2.5" />
            {badgeText}
          </span>
        )}
      </div>

      {subtext && (
        <p className="mt-1 text-xs text-zinc-400 font-medium">{subtext}</p>
      )}
    </div>
  );
}

function formatTime(dateString) {
  const date = new Date(dateString);
  const diffDays = Math.ceil(
    Math.abs(new Date() - date) / (1000 * 60 * 60 * 24),
  );
  if (diffDays <= 1) return "Bugün";
  return `${diffDays} Gün`;
}

export default function Explore() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const [activeCategory, setActiveCategory] = useState("Tümü");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const [categories, setCategories] = useState([
    { id: 0, kategori_adi_tr: "Tümü" },
  ]);
  const [apiBots, setApiBots] = useState([]);
  const [searchTerm, setSearchTerm] = useState("");
  const [sortType, setSortType] = useState("onerilen");
  const searchInputRef = useRef(null);

  // Anasayfadaki kontrol paneliyle aynı ⌘K/Ctrl+K kısayolu.
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

  const fetchCategories = useCallback(async () => {
    try {
      const response = await fetch("/api/content/getcategories.php");
      if (!response.ok)
        throw new Error(`Kategori HTTP error! status: ${response.status}`);

      const data = await response.json();
      if (data.success === false)
        throw new Error(data.message || "Kategori API'sinden hata alındı.");

      // ERR-003: getcategories artık zarflı ({success, categories}).
      setCategories([{ id: 0, kategori_adi_tr: "Tümü" }, ...(data.categories ?? [])]);
    } catch (e) {
      console.warn("Kategoriler yüklenemedi. Sadece 'Tümü' gösterilecek.", e);
    }
  }, []);

  const fetchAllBots = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await fetch(`/api/chatbot/getchatbots.php?search=`);
      if (!response.ok)
        throw new Error(`Bot HTTP error! status: ${response.status}`);

      const data = await response.json();
      if (!data.success)
        throw new Error(data.message || "Bot API'sinden hata alındı.");

      setApiBots(Array.isArray(data.bots) ? data.bots : []);
    } catch (e) {
      console.error("Botlar çekilirken hata oluştu:", e);
      setError(e.message);
      setApiBots([]);
    } finally {
      setLoading(false);
    }
  }, []);

  /* Eskiden burada `?from=list&name=…` "listeye ekle" modu vardı: seçilen
     botları yalnızca tarayıcının localStorage'ına yazıyordu (sunucudaki
     listelere değil) ve uygulamada bu adrese giden bağlantı yoktu. Kaldırıldı
     (2026-10-10); listeye ekleme kartlardaki "Listeye Ekle" penceresinden. */
  useEffect(() => {
    setSearchTerm(searchParams.get("search") || "");
  }, [searchParams]);

  useEffect(() => {
    fetchCategories();
  }, [fetchCategories]);

  useEffect(() => {
    fetchAllBots();
  }, [fetchAllBots]);

  const mappedBots = apiBots.map((bot) => ({
    id: bot.id,
    title: bot.isim,
    description: bot.aciklama,
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
    // Ortak kartin bekledigi alanlar — anasayfadaki mapping ile ayni.
    author:
      (bot.owner_name === "SYSTEM" ? "Lumanoris" : bot.owner_name) || "Anonim",
    // N-26: rozet yalnızca gerçek satış kaydı varsa (sunucu `has_sales`);
    // eskiden sabit `durum = 1` yüzünden her bota basılıyordu.
    badge: Number(bot.has_sales) === 1
      ? { type: "sold", label: "Daha Önce Satıldı" }
      : null,
    // N-15: puan verisi yok (API döndürmüyor); sabit 4.9 uydurmaydı.
    rating: null,
  }));

  let filteredBots = mappedBots;

  if (activeCategory !== "Tümü") {
    const cat = categories.find((c) => c.kategori_adi_tr === activeCategory);
    filteredBots = cat
      ? filteredBots.filter((b) => Number(b.kategori_id) === Number(cat.id))
      : filteredBots;
  }

  if (searchTerm.trim()) {
    const q = searchTerm.trim().toLocaleLowerCase("tr");
    filteredBots = filteredBots.filter((b) =>
      b.title?.toLocaleLowerCase("tr").includes(q),
    );
  }

  const sortedBots = [...filteredBots];
  switch (sortType) {
    case "fiyat_artan":
      sortedBots.sort((a, b) => a.weeklyPrice - b.weeklyPrice);
      break;
    case "fiyat_azalan":
      sortedBots.sort((a, b) => b.weeklyPrice - a.weeklyPrice);
      break;
    case "favoriler":
      sortedBots.sort((a, b) => b.likes - a.likes);
      break;
    case "liste":
      sortedBots.sort((a, b) => b.saves - a.saves);
      break;
    case "yeni":
      sortedBots.sort(
        (a, b) => new Date(b.publishedAt) - new Date(a.publishedAt),
      );
      break;
    case "diyalog":
      sortedBots.sort((a, b) => b.dialogues - a.dialogues);
      break;
    case "degerlendirme":
      sortedBots.sort((a, b) => b.likes + b.comments - (a.likes + a.comments));
      break;
    default:
      sortedBots.sort(
        (a, b) => b.dialogues * 2 + b.likes - (a.dialogues * 2 + a.likes),
      );
      break; // 'onerilen'
  }

  // Real, marketplace-wide metrics — same computation as the dashboard home
  // page's stat row (allBots.reduce over dialogues/followers), so the two
  // pages never disagree on what "toplam diyalog" etc. means.
  const totalDialogues = useMemo(
    () => mappedBots.reduce((sum, b) => sum + (Number(b.dialogues) || 0), 0),
    [mappedBots],
  );
  const totalFollowers = useMemo(
    () => mappedBots.reduce((sum, b) => sum + (Number(b.followers) || 0), 0),
    [mappedBots],
  );
  const categoryCount = Math.max(0, categories.length - 1);

  return (
    <div className="min-h-screen bg-zinc-950 text-zinc-100 antialiased selection:bg-fuchsia-500/30 selection:text-fuchsia-200 p-4 sm:p-8 font-sans pb-24">
      {/* Background Ambient Glow Effects */}
      <div className="pointer-events-none fixed inset-0 overflow-hidden">
        <div className="absolute -top-40 right-1/3 h-[500px] w-[500px] rounded-full bg-gradient-to-br from-violet-600/10 via-fuchsia-600/10 to-transparent blur-[140px]" />
        <div className="absolute top-[50%] right-[-100px] h-[400px] w-[400px] rounded-full bg-cyan-600/10 blur-[140px]" />
      </div>

      <div className="relative space-y-8">
        {/* Anasayfadan taşınan pazaryeri başlığı ve istatistik kartları —
            anasayfa artık sohbet kompozitörüyle açılıyor, marketin genel
            görünümü buraya, Keşfet'in kendi sayfasına taşındı. */}
        <header className="flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
          <div>
            <div className="mb-3 inline-flex items-center gap-2 rounded-full border border-violet-500/30 bg-violet-500/10 px-3.5 py-1 text-xs font-bold text-violet-300 shadow-lg shadow-violet-500/5 backdrop-blur-md">
              <Sparkles className="h-3.5 w-3.5 text-violet-400" />
              <span>2026 AI Agent Marketplace</span>
            </div>
            <h1 className="font-display text-3xl font-black tracking-tight text-white sm:text-4xl lg:text-5xl">
              Keşfet
            </h1>
            <p className="mt-2 max-w-xl text-sm leading-relaxed text-zinc-400">
              En son nesil yapay zeka asistanlarını keşfedin, özel yeteneklerle
              entegre edin veya kendi botunuzu pazarda yayınlayın.
            </p>
          </div>

          <div className="flex shrink-0 items-center gap-3">
            <a
              href="/dashboard/chatbots/create"
              className="inline-flex items-center gap-2 rounded-2xl bg-gradient-btn px-5 py-3 text-xs font-bold text-white shadow-glow transition-all duration-200 hover:-translate-y-0.5 hover:brightness-110 active:translate-y-0"
            >
              <Plus className="h-4 w-4" />
              <span>Yeni Bot Oluştur</span>
            </a>
          </div>
        </header>

        {/* Real Metrics Grid — N-28: sabit trend yüzdeleri ("+12% bu ay",
            "+24%") kaldırıldı; geçmişe dönük sayı tutulmuyor, gerçek veri yok. */}
        <section className="grid grid-cols-2 gap-4 lg:grid-cols-4">
          <StatCard2026
            icon={Bot}
            label="Toplam Chatbot"
            value={loading ? "—" : formatCompactNumber(mappedBots.length)}
            subtext="Aktif pazar asistanı"
          />
          <StatCard2026
            icon={MessageSquare}
            label="Toplam Diyalog"
            value={loading ? "—" : formatCompactNumber(totalDialogues)}
            subtext="Geliştirici Etkileşimi"
          />
          <StatCard2026
            icon={Users}
            label="Toplam Takipçi"
            value={loading ? "—" : formatCompactNumber(totalFollowers)}
            subtext="Topluluk Bağlantısı"
          />
          <StatCard2026
            icon={Layers}
            label="Kategoriler"
            value={loading ? "—" : formatCompactNumber(categoryCount)}
            subtext="Uzmanlık Alanları"
          />
        </section>

        {error && (
          <p className="text-rose-400">Veri yüklenemedi: {error}</p>
        )}

        <MarketplaceControlBar
          query={searchTerm}
          onQueryChange={setSearchTerm}
          sort={sortType}
          onSortChange={setSortType}
          categories={categories}
          selected={activeCategory}
          onSelectCategory={setActiveCategory}
          searchInputRef={searchInputRef}
        />

        {loading ? (
          <CardGrid>
            {Array.from({ length: 8 }).map((_, i) => (
              <div
                key={i}
                className="flex flex-col overflow-hidden rounded-2xl bg-luma-card"
              >
                <Skeleton className="aspect-[4/3] w-full rounded-none" />
                <div className="flex flex-col gap-2 p-3.5">
                  <Skeleton className="h-3.5 w-2/3" />
                  <Skeleton className="h-2.5 w-full" />
                  <Skeleton className="h-2.5 w-1/3" />
                </div>
              </div>
            ))}
          </CardGrid>
        ) : sortedBots.length === 0 ? (
          <EmptyState
            icon={SearchX}
            title="Bu kategoriye/aramaya uygun bot bulunamadı."
            className="w-full"
          />
        ) : (
          <BotList
            bots={sortedBots}
            onOpenDetails={(bot) => router.push(`/dashboard/chat/?botId=${bot.id}`)}
          />
        )}
      </div>

    </div>
  );
}
