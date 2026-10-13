"use client";
import { ModalPortal } from "@/shared/ui/modal-portal";

import React, { useState, useEffect, useMemo, useContext } from "react";
import { UserContext } from "@/shared/contexts/UserContext";
import {
  Heart,
  Users,
  Search,
  Sparkles,
  SlidersHorizontal,
  LayoutGrid,
  List,
  MessageSquare,
  Zap,
  Star,
  ExternalLink,
  Trash2,
  X,
  Clock,
  ShieldCheck,
  Building2,
  TrendingUp,
  ArrowUpRight,
  Filter,
  Info,
  RefreshCw,
  Share2,
  Bot,
  UserCheck,
} from "lucide-react";
import { FilterPopover2026 } from "@/shared/ui/filter-popover";
import { toast } from "@/shared/hooks/use-toast";

// Safe router hook for interactive preview environments
function useRouter() {
  return {
    push: (path) => {
      if (typeof window !== "undefined") {
        window.location.href = path;
      }
    },
  };
}

// Class name string builder helper
function cn(...classes) {
  return classes.filter(Boolean).join(" ");
}

// Image fallback and path normalizer
function resolveImg(path, kind = "cover") {
  if (path) {
    if (
      path.startsWith("http") ||
      path.startsWith("data:") ||
      path.startsWith("/")
    ) {
      return path;
    }
    return "/" + path;
  }
  return kind === "avatar"
    ? "https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=150&auto=format&fit=crop&q=80"
    : "https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=800&auto=format&fit=crop&q=80";
}

function LoadingSkeleton() {
  return (
    <div className="space-y-8 animate-in fade-in duration-300">
      {/* Header Skeleton */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-6 border-b border-white/[0.08]">
        <div className="space-y-2">
          <div className="h-4 w-32 bg-white/[0.08] rounded-full animate-pulse" />
          <div className="h-8 w-64 bg-white/[0.08] rounded-xl animate-pulse" />
        </div>
        <div className="h-10 w-40 bg-white/[0.08] rounded-xl animate-pulse" />
      </div>

      {/* Metrics Bar Skeleton */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        {Array.from({ length: 4 }).map((_, i) => (
          <div
            key={i}
            className="h-24 bg-white/[0.03] border border-white/[0.06] rounded-2xl p-4 animate-pulse space-y-2"
          >
            <div className="h-3 w-20 bg-white/[0.08] rounded" />
            <div className="h-7 w-24 bg-white/[0.08] rounded-lg" />
          </div>
        ))}
      </div>

      {/* Filter Control Bar Skeleton */}
      <div className="h-14 bg-white/[0.03] border border-white/[0.06] rounded-2xl animate-pulse" />

      {/* Grid Cards Skeleton */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">
        {Array.from({ length: 8 }).map((_, i) => (
          <div
            key={i}
            className="rounded-2xl border border-white/[0.06] bg-zinc-900/40 p-4 space-y-4"
          >
            <div className="h-36 w-full bg-white/[0.06] rounded-xl animate-pulse" />
            <div className="h-4 w-3/4 bg-white/[0.06] rounded animate-pulse" />
            <div className="h-3 w-1/2 bg-white/[0.06] rounded animate-pulse" />
            <div className="pt-2 flex justify-between">
              <div className="h-3 w-16 bg-white/[0.06] rounded animate-pulse" />
              <div className="h-3 w-20 bg-white/[0.06] rounded animate-pulse" />
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

function UnfollowModal({ bot, isOpen, onClose, onConfirm }) {
  if (!isOpen || !bot) return null;

  return (
    <ModalPortal onClose={onClose}>
      <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-md animate-in fade-in duration-200">
        <div className="relative w-full max-w-md rounded-2xl bg-zinc-900 border border-white/10 p-6 shadow-2xl space-y-5 max-h-[calc(100dvh-2rem)] overflow-y-auto overscroll-contain">
          <div className="flex items-center gap-3">
            <div className="p-3 rounded-xl bg-rose-500/10 text-rose-400 border border-rose-500/20 shrink-0">
              <Trash2 className="w-5 h-5" />
            </div>
            <div>
              <h3 className="text-base font-bold text-white">Takibi Bırak</h3>
              <p className="text-xs text-zinc-400 mt-0.5">
                "{bot.isim}" asistanını takip edilenler listenizden çıkarmak
                istiyor musunuz?
              </p>
            </div>
          </div>
  
          <div className="p-3.5 rounded-xl bg-zinc-950/60 border border-white/5 flex items-center gap-3">
            <img
              src={resolveImg(bot.profil_fotografi, "avatar")}
              alt=""
              className="w-10 h-10 rounded-lg object-cover border border-white/10"
            />
            <div>
              <p className="text-xs font-bold text-white">{bot.isim}</p>
              <p className="text-caption text-zinc-500">
                Geliştirici: {bot.gelistirici_adi || "Bilinmiyor"}
              </p>
            </div>
          </div>
  
          <div className="flex items-center gap-3 pt-2">
            <button
              onClick={onClose}
              className="flex-1 py-2.5 rounded-xl border border-white/10 text-xs font-semibold text-zinc-300 hover:bg-white/5 transition cursor-pointer"
            >
              Vazgeç
            </button>
            <button
              onClick={() => {
                onConfirm(bot.id);
                onClose();
              }}
              className="flex-1 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-500 text-xs font-semibold text-white transition cursor-pointer"
            >
              Takibi Bırak
            </button>
          </div>
        </div>
      </div>
    </ModalPortal>
  );
}

function BotQuickDetailModal({ bot, isOpen, onClose, router }) {
  if (!isOpen || !bot) return null;

  return (
    <ModalPortal onClose={onClose}>
      <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-md animate-in fade-in duration-200">
        <div className="relative w-full max-w-xl rounded-2xl bg-zinc-900 border border-white/10 p-6 shadow-2xl space-y-6 overflow-hidden max-h-[90vh] overflow-y-auto">
          {/* Glow backdrop */}
          <div className="pointer-events-none absolute -top-20 -right-20 h-40 w-40 rounded-full bg-fuchsia-500/20 blur-3xl" />
  
          <div className="flex items-start justify-between border-b border-white/[0.08] pb-4">
            <div className="flex items-center gap-3">
              <img
                src={resolveImg(bot.profil_fotografi, "avatar")}
                alt=""
                className="w-12 h-12 rounded-xl object-cover border border-white/10"
              />
              <div>
                <div className="flex items-center gap-2">
                  <h3 className="text-base font-bold text-white">{bot.isim}</h3>
                  <span className="text-caption font-mono text-fuchsia-400 bg-fuchsia-500/10 px-2 py-0.5 rounded border border-fuchsia-500/20">
                    {bot.kategori_adi || "Genel AI"}
                  </span>
                </div>
                <p className="text-xs text-zinc-400 mt-0.5">
                  Geliştirici:{" "}
                  <span className="text-zinc-200 font-medium">
                    {bot.gelistirici_adi}
                  </span>
                </p>
              </div>
            </div>
  
            <button
              onClick={onClose}
              className="p-1.5 rounded-lg text-zinc-400 hover:text-white hover:bg-white/10 transition cursor-pointer"
            >
              <X className="w-5 h-5" />
            </button>
          </div>
  
          {/* Cover Banner */}
          <div className="relative aspect-[21/9] w-full rounded-xl overflow-hidden bg-zinc-950 border border-white/5">
            <img
              src={resolveImg(bot.kapak_fotografi)}
              alt=""
              className="w-full h-full object-cover"
            />
            <div className="absolute inset-0 bg-gradient-to-t from-zinc-950 via-zinc-950/30 to-transparent" />
          </div>
  
          {/* Description */}
          <div className="space-y-2">
            <h4 className="text-xs font-mono uppercase text-zinc-400 font-semibold tracking-wider">
              Hakkında
            </h4>
            <p className="text-xs text-zinc-300 leading-relaxed bg-zinc-950/60 p-3.5 rounded-xl border border-white/5">
              {/* Açıklaması olmayan botta eskiden "özel veri kaynakları ve
                  gelişmiş RAG altyapısı" iddiası çıkıyordu; ürün RAG yapmıyor. */}
              {bot.aciklama || "Bu asistan için açıklama eklenmemiş."}
            </p>
          </div>
  
          {/* Technical Specs */}
          {/* Üçüncü kutu "Model: GPT-4o Omnimodal" yazıyordu; kaldırıldı.
              Izgara 3 sütundan 2'ye indi, aksi hâlde kalan iki kutu yarım
              satırda asılı kalırdı. */}
          {/* N-15 — puanı olmayan botta sabit "4.9", takipçisi olmayanda
              "1.2k" yazıyordu (uydurma veri). Puan kutusu yalnızca puan varsa. */}
          <div className={bot.puan != null ? "grid grid-cols-2 gap-3" : "grid grid-cols-1 gap-3"}>
            {bot.puan != null && (
              <div className="p-3 rounded-xl bg-zinc-950/60 border border-white/5 text-center">
                <p className="text-caption text-zinc-500 uppercase font-mono">
                  Puan
                </p>
                <p className="text-sm font-bold text-amber-400 flex items-center justify-center gap-1 mt-0.5">
                  <Star className="w-3.5 h-3.5 fill-amber-400" />{" "}
                  {bot.puan}
                </p>
              </div>
            )}
            <div className="p-3 rounded-xl bg-zinc-950/60 border border-white/5 text-center">
              <p className="text-caption text-zinc-500 uppercase font-mono">
                Takipçi
              </p>
              <p className="text-sm font-bold text-white mt-0.5">
                {Number(bot.takipci_sayisi) || 0}
              </p>
            </div>
          </div>
  
          {/* Actions */}
          <div className="flex items-center gap-3 pt-2">
            <button
              onClick={() =>
                router.push("/dashboard/chat?botId=" + bot.chatbot_id)
              }
              className="flex-1 py-3 rounded-xl bg-gradient-btn text-xs font-bold text-white shadow-glow hover:brightness-110 hover:-translate-y-0.5 active:translate-y-0 transition-all duration-200 cursor-pointer flex items-center justify-center gap-2"
            >
              <MessageSquare className="w-4 h-4" />
              <span>Sohbet Başlat</span>
            </button>
          </div>
        </div>
      </div>
    </ModalPortal>
  );
}

export default function Following() {
  const { userId } = useContext(UserContext);
  const [followedBots, setFollowedBots] = useState([]);
  const [categories, setCategories] = useState([]);
  const [loading, setLoading] = useState(true);
  const [fetchError, setFetchError] = useState(null);

  // Search, Filter & View States
  const [searchQuery, setSearchQuery] = useState("");
  const [selectedCategory, setSelectedCategory] = useState("all");
  const [sortBy, setSortBy] = useState("newest"); // 'newest' | 'popular' | 'rating'
  const [viewMode, setViewMode] = useState("grid"); // 'grid' | 'list'

  // Modals & Toast State
  const [unfollowModalBot, setUnfollowModalBot] = useState(null);
  const [detailModalBot, setDetailModalBot] = useState(null);

  const router = useRouter();

  useEffect(() => {
    if (!userId) {
      setLoading(false);
      return;
    }

    // Fetch Followed Bots Endpoint
    const fetchFollowed = fetch(
      `/api/social/getfollowedbots.php?user_id=${userId}`,
    )
      .then((res) => res.text())
      .then((text) => {
        try {
          const data = JSON.parse(text);
          if (data?.success && Array.isArray(data.bots)) {
            setFollowedBots(data.bots);
          } else {
            setFollowedBots([]);
            setFetchError(data?.message || "Takip edilen botlar yüklenemedi.");
          }
        } catch (e) {
          console.error("Followed bots parse error:", e);
          setFollowedBots([]);
          setFetchError("Beklenmeyen sunucu yanıtı.");
        }
      })
      .catch((err) => {
        console.error("Takip edilen botlar yüklenemedi:", err);
        setFollowedBots([]);
        setFetchError("Sunucuya bağlanılamadı.");
      });

    // Fetch Categories
    const fetchCats = fetch("/api/content/getcategories.php")
      .then((res) => res.text())
      .then((text) => {
        try {
          const data = JSON.parse(text);
          // ERR-003: getcategories artık zarflı ({success, categories}).
        if (Array.isArray(data?.categories)) setCategories(data.categories);
        } catch (e) {
          console.error("Kategori parse hatası:", e);
        }
      })
      .catch((err) => console.error("Kategoriler fetch hatası:", err));

    Promise.all([fetchFollowed, fetchCats]).finally(() => setLoading(false));
  }, [userId]);

  const openChat = (bot) => router.push("/dashboard/chat?botId=" + bot.chatbot_id);

  /* Kartın TAMAMI sohbete götürüyor: "Sohbet Et" kartın birincil eylemi ve
     ızgara kartı zaten `cursor-pointer` gösteriyordu ama hiçbir onClick'i
     yoktu — imleç "tıklanabilir" diyor, kart hiçbir şey yapmıyordu. Aynı
     desen kardeş sayfada da var (dashboard/purchased).

     Kart bir <div> olduğu için klavye erişimi elle veriliyor: role + tabIndex
     + Enter/Space. Bunlar olmadan fare kullanmayan biri karta hiç ulaşamaz.

     Kartın İÇİNDEKİ kontroller (takibi bırak, başlıktaki hızlı detay) kendi
     işlerini yapmaya devam ediyor; her biri `stopPropagation` ile olayı
     durduruyor — aksi hâlde tıklama karta baloncuklanır ve kullanıcıyı
     istemediği hâlde sohbete fırlatırdı. */
  const cardNavProps = (bot) => ({
    role: "button",
    tabIndex: 0,
    onClick: () => openChat(bot),
    onKeyDown: (e) => {
      if (e.key === "Enter" || e.key === " ") {
        e.preventDefault();
        openChat(bot);
      }
    },
  });

  /* N-35 — eskiden bu işlev sunucuya HİÇ istek atmıyordu: kart yalnızca
     ekrandan siliniyor ve "çıkarıldı" deniyordu, sayfa yenilenince bot geri
     geliyordu. Şimdi açık "unfollow" eylemi gönderiliyor (toggle değil: bayat
     bir sayfa botu yeniden takip edemez) ve kart yalnızca sunucu başarı
     dönünce kalkıyor; hata olursa kart yerinde kalır, mesaj gösterilir. */
  const handleUnfollow = async (botId) => {
    try {
      const res = await fetch("/api/social/followchatbot.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({
          data: JSON.stringify({ chatbot_id: botId, action: "unfollow" }),
        }),
        credentials: "include",
      });
      const result = await res.json().catch(() => null);
      if (!res.ok || !result?.success || result.following !== false) {
        toast.error(result?.message || `Takip bırakılamadı (HTTP ${res.status}).`);
        return;
      }
      setFollowedBots((prev) => prev.filter((b) => b.id !== botId));
      toast.success("Bot takip edilenler listenizden çıkarıldı.");
    } catch {
      toast.error("Sunucuya ulaşılamadı. Takip bırakılamadı.");
    }
  };

  const filteredBots = useMemo(() => {
    return followedBots
      .filter((bot) => {
        const matchesSearch =
          bot.isim?.toLowerCase().includes(searchQuery.toLowerCase()) ||
          bot.gelistirici_adi
            ?.toLowerCase()
            .includes(searchQuery.toLowerCase()) ||
          bot.chatbot_id?.toLowerCase().includes(searchQuery.toLowerCase());

        const matchesCat =
          selectedCategory === "all" ||
          String(bot.kategori_id) === String(selectedCategory);

        return matchesSearch && matchesCat;
      })
      .sort((a, b) => {
        if (sortBy === "popular") {
          return (
            (parseInt(b.takipci_sayisi) || 0) -
            (parseInt(a.takipci_sayisi) || 0)
          );
        }
        if (sortBy === "rating") {
          return (parseFloat(b.puan) || 0) - (parseFloat(a.puan) || 0);
        }
        // Newest
        return new Date(b.created_at || 0) - new Date(a.created_at || 0);
      });
  }, [followedBots, searchQuery, selectedCategory, sortBy]);

  // Unique creators count
  const uniqueCreatorsCount = useMemo(() => {
    const creators = new Set(followedBots.map((b) => b.gelistirici_adi));
    return creators.size;
  }, [followedBots]);

  if (loading) {
    return (
      <div className="min-h-screen bg-zinc-950 text-zinc-100 p-4 sm:p-8 font-sans">
        <LoadingSkeleton />
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-zinc-950 text-zinc-100 antialiased selection:bg-fuchsia-500/30 selection:text-fuchsia-200 p-4 sm:p-8 font-sans pb-24">
      {/* Background Ambient Glow Effects */}
      <div className="pointer-events-none fixed inset-0 overflow-hidden">
        <div className="absolute -top-40 right-1/3 h-[500px] w-[500px] rounded-full bg-gradient-to-br from-violet-600/10 via-fuchsia-600/10 to-transparent blur-[140px]" />
        <div className="absolute top-[50%] right-[-100px] h-[400px] w-[400px] rounded-full bg-cyan-600/10 blur-[140px]" />
      </div>

      <div className="relative space-y-8">
        {/* Page Header */}
        <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-4 pb-6 border-b border-white/[0.08]">
          <div className="space-y-2">
            <div className="inline-flex items-center gap-2 rounded-full bg-violet-500/10 border border-violet-500/20 px-3 py-1 text-xs font-mono font-semibold text-violet-300">
              <Heart className="w-3.5 h-3.5 fill-violet-400 text-violet-400" />
              <span>Favori Asistanlarım</span>
            </div>
            <h1 className="font-display text-3xl sm:text-4xl font-extrabold tracking-tight text-white">
              Takip Ettiklerim
            </h1>
            <p className="text-sm text-zinc-400 max-w-xl">
              Pazaryerinden takibe aldığınız özel yapay zeka asistanları ve
              yetenekli geliştiriciler.
            </p>
          </div>

          <button
            onClick={() => router.push("/dashboard/explore")}
            className="group inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-btn px-5 py-3 text-xs font-semibold text-white shadow-glow hover:brightness-110 hover:-translate-y-0.5 active:translate-y-0 transition-all duration-200 cursor-pointer shrink-0"
          >
            <Sparkles className="w-4 h-4" />
            <span>Keşfet</span>
            <ArrowUpRight className="w-4 h-4 opacity-70 group-hover:translate-x-0.5 group-hover:-translate-y-0.5 transition-transform" />
          </button>
        </div>

        {/* Quick Stats Summary Bar */}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <div className="rounded-2xl border border-white/[0.08] bg-zinc-900/60 backdrop-blur-xl p-4 space-y-1">
            <p className="text-caption font-mono text-zinc-500 uppercase tracking-wider">
              Takip Edilen
            </p>
            <p className="text-2xl font-bold text-white tracking-tight">
              {followedBots.length}
            </p>
          </div>

          <div className="rounded-2xl border border-violet-500/20 bg-violet-500/[0.03] backdrop-blur-xl p-4 space-y-1">
            <p className="text-caption font-mono text-violet-400 uppercase tracking-wider flex items-center gap-1.5">
              <Building2 className="w-3.5 h-3.5" /> Üreticiler
            </p>
            <p className="text-2xl font-bold text-violet-300 tracking-tight">
              {uniqueCreatorsCount}
            </p>
          </div>

          <div className="rounded-2xl border border-fuchsia-500/20 bg-fuchsia-500/[0.03] backdrop-blur-xl p-4 space-y-1">
            <p className="text-caption font-mono text-fuchsia-400 uppercase tracking-wider flex items-center gap-1.5">
              <Star className="w-3.5 h-3.5" /> Ort. Derecelendirme
            </p>
            <p className="text-2xl font-bold text-fuchsia-300 tracking-tight">
              4.85 / 5.0
            </p>
          </div>

          <div className="rounded-2xl border border-white/[0.08] bg-zinc-900/60 backdrop-blur-xl p-4 space-y-1">
            <p className="text-caption font-mono text-zinc-500 uppercase tracking-wider flex items-center gap-1.5">
              <ShieldCheck className="w-3.5 h-3.5 text-emerald-400" />{" "}
              Güncelleme
            </p>
            <p className="text-sm font-semibold text-zinc-200 mt-1">
              Anlık Model Sync
            </p>
          </div>
        </div>

        {/* Filter & Control Bar */}
        <div className="relative z-20 flex flex-col md:flex-row items-center justify-between gap-4 p-2 rounded-2xl border border-white/[0.08] bg-zinc-900/80 backdrop-blur-xl">
          {/* Search Box */}
          <div className="relative w-full md:w-80">
            <Search className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-zinc-500" />
            <input
              type="text"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder="Bot ismi veya üretici ara..."
              className="w-full bg-black/40 border border-white/[0.06] rounded-xl pl-10 pr-4 py-2.5 text-xs text-white placeholder:text-zinc-600 focus:outline-none focus:border-fuchsia-500/60 focus:ring-2 focus:ring-fuchsia-500/20 transition"
            />
            {searchQuery && (
              <button
                onClick={() => setSearchQuery("")}
                className="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-zinc-500 hover:text-white"
              >
                Temizle
              </button>
            )}
          </div>

          {/* Category & Sort controls */}
          <div className="flex flex-wrap items-center gap-3 w-full md:w-auto justify-between md:justify-end">
            {/* Category Dropdown */}
            <FilterPopover2026
              icon={SlidersHorizontal}
              prefixLabel="Kategori:"
              menuLabel="Kategori Seç"
              value={selectedCategory}
              onChange={setSelectedCategory}
              options={[
                { id: "all", label: "Tüm Kategoriler" },
                ...categories.map((c) => ({
                  id: c.id,
                  label: c.kategori_adi_tr,
                })),
              ]}
            />

            {/* Sort Dropdown */}
            <FilterPopover2026
              icon={Filter}
              prefixLabel="Sırala:"
              menuLabel="Sıralama Kriteri"
              value={sortBy}
              onChange={setSortBy}
              options={[
                { id: "newest", label: "En Yeni Eklenenler" },
                { id: "popular", label: "En Popüler (Takipçi)" },
                { id: "rating", label: "En Yüksek Puanlı" },
              ]}
            />

            {/* View Mode Switcher */}
            <div className="hidden sm:flex items-center bg-black/50 p-1 rounded-xl border border-white/[0.08]">
              <button
                onClick={() => setViewMode("grid")}
                className={cn(
                  "p-1.5 rounded-lg transition cursor-pointer",
                  viewMode === "grid"
                    ? "bg-white/10 text-white"
                    : "text-zinc-500 hover:text-zinc-300",
                )}
              >
                <LayoutGrid className="w-5 h-5" />
              </button>
              <button
                onClick={() => setViewMode("list")}
                className={cn(
                  "p-1.5 rounded-lg transition cursor-pointer",
                  viewMode === "list"
                    ? "bg-white/10 text-white"
                    : "text-zinc-500 hover:text-zinc-300",
                )}
              >
                <List className="w-5 h-5" />
              </button>
            </div>
          </div>
        </div>

        {fetchError && (
          <div className="mb-6 rounded-2xl border border-rose-500/20 bg-rose-500/10 px-5 py-4 text-sm text-rose-300">
            {fetchError}
          </div>
        )}

        {filteredBots.length === 0 ? (
          <div className="rounded-2xl border border-dashed border-white/10 bg-zinc-900/30 p-12 text-center space-y-4 animate-in fade-in duration-200">
            <div className="w-16 h-16 rounded-2xl bg-violet-500/10 text-violet-400 border border-violet-500/20 mx-auto flex items-center justify-center">
              <Heart className="w-8 h-8" />
            </div>
            <div className="max-w-sm mx-auto space-y-1">
              <h3 className="text-lg font-bold text-white">
                Takip Edilen Bot Bulunamadı
              </h3>
              <p className="text-xs text-zinc-400 leading-relaxed">
                {searchQuery || selectedCategory !== "all"
                  ? "Arama ve filtreleme kriterlerinize uygun takip edilen chatbot bulunmuyor."
                  : "Henüz bir sohbet botunu takibe almadınız. Pazaryerinden ilgi duyduğunuz asistanları favorilerinize ekleyebilirsiniz."}
              </p>
            </div>
            {searchQuery || selectedCategory !== "all" ? (
              <button
                onClick={() => {
                  setSearchQuery("");
                  setSelectedCategory("all");
                }}
                className="px-4 py-2 rounded-xl bg-white/10 text-xs font-semibold text-white hover:bg-white/20 transition cursor-pointer"
              >
                Filtreleri Temizle
              </button>
            ) : (
              <button
                onClick={() => router.push("/dashboard/explore")}
                className="px-6 py-3 rounded-xl bg-gradient-btn text-xs font-bold text-white shadow-glow hover:brightness-110 hover:-translate-y-0.5 active:translate-y-0 transition-all duration-200 cursor-pointer"
              >
                Keşfet
              </button>
            )}
          </div>
        ) : viewMode === "grid" ? (
          /* Grid View Mode */
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">
            {filteredBots.map((bot) => (
              <div
                key={bot.id}
                {...cardNavProps(bot)}
                className="group relative rounded-2xl border border-white/[0.08] bg-zinc-900/60 backdrop-blur-xl overflow-hidden hover:border-violet-500/40 hover:bg-zinc-900/90 hover:shadow-[0_0_30px_-5px_rgba(139,92,246,0.15)] hover:-translate-y-0.5 transition-all duration-300 ease-out cursor-pointer flex flex-col justify-between"
              >
                {/* Top Cover Banner */}
                <div>
                  <div className="relative aspect-[16/9] w-full bg-zinc-950">
                    <div className="absolute inset-0 overflow-hidden">
                      <img
                        src={resolveImg(bot.kapak_fotografi)}
                        alt={bot.isim}
                        className="h-full w-full object-cover transition-transform duration-700 ease-out group-hover:scale-105"
                      />
                      <div className="absolute inset-0 bg-gradient-to-t from-zinc-950 via-zinc-950/30 to-transparent" />
                    </div>

                    {/* Unfollow Quick Button */}
                    <button
                      onClick={(e) => {
                        e.stopPropagation();
                        setUnfollowModalBot(bot);
                      }}
                      title="Takibi Bırak"
                      className="absolute right-3 top-3 p-2 rounded-full bg-black/60 border border-white/10 text-rose-400 hover:bg-rose-500 hover:text-white transition cursor-pointer backdrop-blur-md"
                    >
                      <Heart className="w-3.5 h-3.5 fill-rose-500 text-rose-500" />
                    </button>

                    {/* Avatar Overlay — sits outside the image's own
                        overflow-hidden wrapper above so its -bottom-4 overlap
                        isn't clipped by it. */}
                    <div className="absolute -bottom-4 left-4 z-10 h-10 w-10 overflow-hidden rounded-xl border-2 border-zinc-900 bg-zinc-950 shadow-lg">
                      <img
                        src={resolveImg(bot.profil_fotografi, "avatar")}
                        alt=""
                        className="h-full w-full object-cover"
                      />
                    </div>
                  </div>

                  {/* Content Body */}
                  <div className="p-4 pt-6 space-y-2">
                    <div className="flex items-center justify-between text-caption font-mono text-violet-400">
                      <span className="flex items-center gap-1">
                        <Zap className="w-3 h-3" />#
                        {bot.kategori_adi || "AI Bot"}
                      </span>
                      {bot.puan != null && (
                        <span className="flex items-center gap-1 text-amber-400">
                          <Star className="w-3 h-3 fill-amber-400" />
                          {bot.puan}
                        </span>
                      )}
                    </div>

                    <h3
                      onClick={(e) => {
                        e.stopPropagation();
                        setDetailModalBot(bot);
                      }}
                      className="font-bold text-sm text-white group-hover:text-violet-200 transition-colors line-clamp-1 hover:underline"
                    >
                      {bot.isim}
                    </h3>

                    <p className="text-caption text-zinc-400 line-clamp-2 leading-relaxed">
                      {bot.aciklama}
                    </p>
                  </div>
                </div>

                {/* Footer Controls */}
                <div className="p-4 pt-2 border-t border-white/[0.06] mt-2 flex items-center justify-between">
                  <span className="text-caption font-mono text-zinc-500 flex items-center gap-1">
                    <Users className="w-3 h-3 text-zinc-600" />
                    {Number(bot.takipci_sayisi) || 0} Takipçi
                  </span>

                  <button
                    onClick={(e) => {
                      e.stopPropagation();
                      openChat(bot);
                    }}
                    className="flex items-center gap-1 text-xs font-bold text-violet-300 group-hover:text-violet-200 cursor-pointer"
                  >
                    <span>Sohbet Et</span>
                    <ArrowUpRight className="w-3.5 h-3.5 opacity-70 group-hover:translate-x-0.5 group-hover:-translate-y-0.5 transition-transform" />
                  </button>
                </div>
              </div>
            ))}
          </div>
        ) : (
          /* List View Mode */
          <div className="space-y-3">
            {filteredBots.map((bot) => (
              <div
                key={bot.id}
                {...cardNavProps(bot)}
                className="group rounded-2xl border border-white/[0.08] bg-zinc-900/60 p-4 hover:border-violet-500/40 hover:bg-zinc-900/90 transition-all duration-200 cursor-pointer flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4"
              >
                <div className="flex items-center gap-4">
                  <img
                    src={resolveImg(bot.profil_fotografi, "avatar")}
                    alt=""
                    className="w-12 h-12 rounded-xl object-cover border border-white/10 shrink-0"
                  />
                  <div>
                    <div className="flex items-center gap-2">
                      <h4
                        onClick={(e) => {
                          e.stopPropagation();
                          setDetailModalBot(bot);
                        }}
                        className="text-sm font-bold text-white group-hover:text-violet-300 transition-colors cursor-pointer hover:underline"
                      >
                        {bot.isim}
                      </h4>
                      <span className="text-caption font-mono text-violet-400 bg-violet-500/10 px-2 py-0.5 rounded border border-violet-500/20">
                        {bot.kategori_adi || "Genel"}
                      </span>
                    </div>
                    <p className="text-xs text-zinc-400 mt-0.5 line-clamp-1 max-w-lg">
                      {bot.aciklama}
                    </p>
                  </div>
                </div>

                <div className="flex items-center gap-3 w-full sm:w-auto justify-between sm:justify-end border-t sm:border-t-0 border-white/5 pt-3 sm:pt-0">
                  <button
                    onClick={(e) => {
                      e.stopPropagation();
                      setUnfollowModalBot(bot);
                    }}
                    className="p-2 rounded-xl border border-white/10 text-rose-400 hover:bg-rose-500/10 transition cursor-pointer"
                    title="Takibi Bırak"
                  >
                    <Heart className="w-4 h-4 fill-rose-500 text-rose-500" />
                  </button>

                  <button
                    onClick={(e) => {
                      e.stopPropagation();
                      openChat(bot);
                    }}
                    className="flex items-center gap-1.5 px-4 py-2 rounded-xl bg-violet-600 hover:bg-violet-500 text-xs font-semibold text-white transition cursor-pointer"
                  >
                    <MessageSquare className="w-3.5 h-3.5" />
                    <span>Sohbet Et</span>
                  </button>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Interactive Modals */}
      <UnfollowModal
        bot={unfollowModalBot}
        isOpen={!!unfollowModalBot}
        onClose={() => setUnfollowModalBot(null)}
        onConfirm={handleUnfollow}
      />

      <BotQuickDetailModal
        bot={detailModalBot}
        isOpen={!!detailModalBot}
        onClose={() => setDetailModalBot(null)}
        router={router}
      />
    </div>
  );
}
