"use client";

import React, { useState, useEffect, useMemo, useContext } from "react";
import { UserContext } from "@/shared/contexts/UserContext";
import {
  ShoppingBag,
  ArrowUpRight,
  Search,
  SlidersHorizontal,
  LayoutGrid,
  List,
  Sparkles,
  Bot,
  Zap,
  CheckCircle2,
  XCircle,
  Clock,
  RefreshCw,
  ChevronRight,
  ExternalLink,
  MessageSquare,
  FileText,
  Filter,
} from "lucide-react";
import { FilterPopover2026 } from "@/shared/ui/filter-popover";

// Safe navigation hook for interactive preview environments
function useRouter() {
  return {
    push: (path) => {
      if (typeof window !== "undefined") {
        window.location.href = path;
      }
    },
  };
}

// Class names merger helper
function cn(...classes) {
  return classes.filter(Boolean).join(" ");
}

// Fallback images and path resolvers
function resolveImg(path, kind = "cover") {
  if (path) {
    return path.startsWith("http") ||
      path.startsWith("data:") ||
      path.startsWith("/")
      ? path
      : "/" + path;
  }
  return kind === "avatar"
    ? "https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=150&auto=format&fit=crop&q=80"
    : "https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=800&auto=format&fit=crop&q=80";
}

function formatDate(value) {
  if (!value) return "Süresiz";
  const d = new Date(String(value).replace(" ", "T"));
  if (isNaN(d.getTime())) return "Geçersiz Tarih";
  return d.toLocaleDateString("tr-TR", {
    day: "numeric",
    month: "short",
    year: "numeric",
  });
}

function getDaysRemaining(value) {
  if (!value) return null;
  const d = new Date(String(value).replace(" ", "T"));
  if (isNaN(d.getTime())) return null;
  const diffTime = d.getTime() - new Date().getTime();
  const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
  return diffDays;
}

function LoadingSkeleton() {
  return (
    <div className="space-y-8 animate-in fade-in duration-300">
      {/* Header Skeleton */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-6 border-b border-white/[0.08]">
        <div className="space-y-2">
          <div className="h-4 w-28 bg-white/[0.08] rounded-full animate-pulse" />
          <div className="h-8 w-64 bg-white/[0.08] rounded-xl animate-pulse" />
        </div>
        <div className="h-10 w-40 bg-white/[0.08] rounded-xl animate-pulse" />
      </div>

      {/* Stats Bar Skeleton */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        {Array.from({ length: 4 }).map((_, i) => (
          <div
            key={i}
            className="h-24 bg-white/[0.03] border border-white/[0.06] rounded-2xl p-4 animate-pulse space-y-2"
          >
            <div className="h-3 w-16 bg-white/[0.08] rounded" />
            <div className="h-7 w-20 bg-white/[0.08] rounded-lg" />
          </div>
        ))}
      </div>

      {/* Controls Skeleton */}
      <div className="h-12 bg-white/[0.03] border border-white/[0.06] rounded-2xl animate-pulse" />

      {/* Grid Skeleton */}
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
              <div className="h-3 w-20 bg-white/[0.06] rounded animate-pulse" />
              <div className="h-3 w-16 bg-white/[0.06] rounded animate-pulse" />
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

export default function SatinAldiklarim() {
  const { userId } = useContext(UserContext);
  const [bots, setBots] = useState([]);
  const [categories, setCategories] = useState([]);
  const [loading, setLoading] = useState(true);
  const [fetchError, setFetchError] = useState(null);

  // Search & Filter state
  const [searchQuery, setSearchQuery] = useState("");
  const [selectedCategory, setSelectedCategory] = useState("all");
  const [statusFilter, setStatusFilter] = useState("all"); // 'all', 'active', 'expired'
  const [viewMode, setViewMode] = useState("grid"); // 'grid' | 'list'

  const router = useRouter();

  useEffect(() => {
    if (!userId) {
      setLoading(false);
      return;
    }

    const fetchSubscriptions = fetch(
      `/api/wallet/getmysubscriptions.php?user_id=${userId}`,
    )
      .then((res) => res.text())
      .then((dataText) => {
        try {
          const data = JSON.parse(dataText);
          if (data?.success && Array.isArray(data.subscriptions)) {
            setBots(data.subscriptions);
          } else {
            setBots([]);
            setFetchError(data?.message || "Satın alınanlar yüklenemedi.");
          }
        } catch (e) {
          console.error("Subscription parse error:", e);
          setBots([]);
          setFetchError("Beklenmeyen sunucu yanıtı.");
        }
      })
      .catch((err) => {
        console.error("Satın alınanlar yüklenemedi:", err);
        setBots([]);
        setFetchError("Sunucuya bağlanılamadı.");
      });

    const fetchCategories = fetch("/api/content/getcategories.php")
      .then((res) => res.text())
      .then((text) => {
        try {
          const data = JSON.parse(text);
          if (Array.isArray(data)) setCategories(data);
        } catch (e) {
          console.error("Kategori parse hatası:", e);
        }
      })
      .catch((err) => console.error("Kategori fetch hatası:", err));

    Promise.all([fetchSubscriptions, fetchCategories]).finally(() =>
      setLoading(false),
    );
  }, [userId]);

  const filteredBots = useMemo(() => {
    return bots.filter((bot) => {
      const matchesSearch =
        bot.isim?.toLowerCase().includes(searchQuery.toLowerCase()) ||
        bot.chatbot_id?.toLowerCase().includes(searchQuery.toLowerCase());

      const matchesCat =
        selectedCategory === "all" ||
        String(bot.kategori_id) === String(selectedCategory);

      const isActive = Number(bot.is_active) === 1;
      const matchesStatus =
        statusFilter === "all" ||
        (statusFilter === "active" && isActive) ||
        (statusFilter === "expired" && !isActive);

      return matchesSearch && matchesCat && matchesStatus;
    });
  }, [bots, searchQuery, selectedCategory, statusFilter]);

  const activeCount = useMemo(
    () => bots.filter((b) => Number(b.is_active) === 1).length,
    [bots],
  );
  const expiredCount = useMemo(
    () => bots.filter((b) => Number(b.is_active) !== 1).length,
    [bots],
  );

  if (loading) {
    return (
      <div className="min-h-screen bg-zinc-950 text-zinc-100 p-4 sm:p-8 font-sans">
        <LoadingSkeleton />
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-zinc-950 text-zinc-100 antialiased selection:bg-fuchsia-500/30 selection:text-fuchsia-200 p-4 sm:p-8 font-sans pb-24">
      {/* Background Ambient Glows */}
      <div className="pointer-events-none fixed inset-0 overflow-hidden">
        <div className="absolute -top-40 right-1/4 h-[500px] w-[500px] rounded-full bg-gradient-to-br from-fuchsia-600/10 via-violet-600/10 to-transparent blur-[140px]" />
        <div className="absolute top-[40%] left-[-100px] h-[400px] w-[400px] rounded-full bg-cyan-600/10 blur-[140px]" />
      </div>

      <div className="relative space-y-8">
        {}
        <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-4 pb-6 border-b border-white/[0.08]">
          <div className="space-y-2">
            <div className="inline-flex items-center gap-2 rounded-full bg-fuchsia-500/10 border border-fuchsia-500/20 px-3 py-1 text-xs font-mono font-semibold text-fuchsia-300">
              <Sparkles className="w-3.5 h-3.5" />
              <span>AI Kütüphanem</span>
            </div>
            <h1 className="font-display text-3xl sm:text-4xl font-extrabold tracking-tight text-white">
              Satın Aldıklarım
            </h1>
            <p className="text-sm text-zinc-400 max-w-xl">
              Pazaryerinden edindiğiniz özel yapay zeka asistanları ve aktif
              lisanslarınız.
            </p>
          </div>

          <button
            onClick={() => router.push("/dashboard/explore")}
            className="group inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-btn px-5 py-3 text-xs font-semibold text-white shadow-glow hover:brightness-110 hover:-translate-y-0.5 active:translate-y-0 transition-all duration-200 cursor-pointer shrink-0"
          >
            <ShoppingBag className="w-4 h-4" />
            <span>Keşfet</span>
            <ArrowUpRight className="w-4 h-4 opacity-70 group-hover:translate-x-0.5 group-hover:-translate-y-0.5 transition-transform" />
          </button>
        </div>

        {}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <div className="rounded-2xl border border-white/[0.08] bg-zinc-900/60 backdrop-blur-xl p-4 space-y-1">
            <p className="text-caption font-mono text-zinc-500 uppercase tracking-wider">
              Toplam Bot
            </p>
            <p className="text-2xl font-bold text-white tracking-tight">
              {bots.length}
            </p>
          </div>

          <div className="rounded-2xl border border-emerald-500/20 bg-emerald-500/[0.03] backdrop-blur-xl p-4 space-y-1">
            <p className="text-caption font-mono text-emerald-400 uppercase tracking-wider flex items-center gap-1.5">
              <CheckCircle2 className="w-3.5 h-3.5" /> Aktif Lisanslar
            </p>
            <p className="text-2xl font-bold text-emerald-300 tracking-tight">
              {activeCount}
            </p>
          </div>

          <div className="rounded-2xl border border-rose-500/20 bg-rose-500/[0.03] backdrop-blur-xl p-4 space-y-1">
            <p className="text-caption font-mono text-rose-400 uppercase tracking-wider flex items-center gap-1.5">
              <XCircle className="w-3.5 h-3.5" /> Süresi Dolan
            </p>
            <p className="text-2xl font-bold text-rose-300 tracking-tight">
              {expiredCount}
            </p>
          </div>

          <div className="rounded-2xl border border-white/[0.08] bg-zinc-900/60 backdrop-blur-xl p-4 space-y-1">
            {/* Eskiden sabit "Koruma Tipi: Gelişmiş RAG" yazıyordu; ürün RAG ya
                da vektör arama yapmıyor. Gerçek: botun belge/URL metni
                (AppConfig::MAX_TRAINING_CHARS = 60.000 karakter) her mesajda
                olduğu gibi modele veriliyor (ChatController). */}
            <p className="text-caption font-mono text-zinc-500 uppercase tracking-wider flex items-center gap-1.5">
              <FileText className="w-3.5 h-3.5 text-violet-400" /> Bot Eğitimi
            </p>
            <p className="text-xs font-medium leading-snug text-zinc-200 mt-1">
              Belge ve URL metni modele verilir (en fazla 60.000 karakter)
            </p>
          </div>
        </div>

        {}
        <div className="relative z-20 flex flex-col md:flex-row items-center justify-between gap-4 p-2 rounded-2xl border border-white/[0.08] bg-zinc-900/80 backdrop-blur-xl">
          {/* Search Input */}
          <div className="relative w-full md:w-80">
            <Search className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-zinc-500" />
            <input
              type="text"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder="Bot ismi veya ID ara..."
              className="w-full bg-black/40 border border-white/[0.06] rounded-xl pl-10 pr-4 py-2.5 text-xs text-white placeholder:text-zinc-600 focus:outline-none focus:border-fuchsia-500/50 transition"
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

          {/* Filter Options */}
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

            {/* Status Segment Control */}
            <div className="flex items-center bg-black/50 p-1 rounded-xl border border-white/[0.08] text-xs font-medium">
              <button
                onClick={() => setStatusFilter("all")}
                className={cn(
                  "px-3 py-1.5 rounded-lg transition cursor-pointer",
                  statusFilter === "all"
                    ? "bg-white/10 text-white"
                    : "text-zinc-500 hover:text-zinc-300",
                )}
              >
                Tümü
              </button>
              <button
                onClick={() => setStatusFilter("active")}
                className={cn(
                  "px-3 py-1.5 rounded-lg transition cursor-pointer",
                  statusFilter === "active"
                    ? "bg-emerald-500/20 text-emerald-300"
                    : "text-zinc-500 hover:text-zinc-300",
                )}
              >
                Aktif
              </button>
              <button
                onClick={() => setStatusFilter("expired")}
                className={cn(
                  "px-3 py-1.5 rounded-lg transition cursor-pointer",
                  statusFilter === "expired"
                    ? "bg-rose-500/20 text-rose-300"
                    : "text-zinc-500 hover:text-zinc-300",
                )}
              >
                Süresi Dolan
              </button>
            </div>

            {/* View Toggle */}
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

        {}
        {fetchError && (
          <div className="mb-6 rounded-2xl border border-rose-500/20 bg-rose-500/10 px-5 py-4 text-sm text-rose-300">
            {fetchError}
          </div>
        )}

        {filteredBots.length === 0 ? (
          <div className="rounded-2xl border border-dashed border-white/10 bg-zinc-900/30 p-12 text-center space-y-4 animate-in fade-in duration-200">
            <div className="w-16 h-16 rounded-2xl bg-fuchsia-500/10 text-fuchsia-400 border border-fuchsia-500/20 mx-auto flex items-center justify-center">
              <ShoppingBag className="w-8 h-8" />
            </div>
            <div className="max-w-sm mx-auto space-y-1">
              <h3 className="text-lg font-bold text-white">Bot Bulunamadı</h3>
              <p className="text-xs text-zinc-400 leading-relaxed">
                {searchQuery ||
                selectedCategory !== "all" ||
                statusFilter !== "all"
                  ? "Aradığınız filtre kriterlerine uygun satın alınmış chatbot bulunmuyor."
                  : "Henüz bir sohbet botu satın almadınız. Pazaryerinden ihtiyacınıza en uygun asistanı seçebilirsiniz."}
              </p>
            </div>
            {searchQuery ||
            selectedCategory !== "all" ||
            statusFilter !== "all" ? (
              <button
                onClick={() => {
                  setSearchQuery("");
                  setSelectedCategory("all");
                  setStatusFilter("all");
                }}
                className="px-4 py-2 rounded-xl bg-white/10 text-xs font-semibold text-white hover:bg-white/20 transition cursor-pointer"
              >
                Filtreleri Sıfırla
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
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">
            {filteredBots.map((bot) => {
              const cat = categories.find(
                (c) => String(c.id) === String(bot.kategori_id),
              );
              const categoryLabel = cat ? cat.kategori_adi_tr : "Genel";
              const active = Number(bot.is_active) === 1;
              const daysLeft = getDaysRemaining(bot.expiry_date);

              return (
                <div
                  key={bot.id}
                  role="button"
                  tabIndex={0}
                  onClick={() =>
                    router.push("/dashboard/chat?botId=" + bot.chatbot_id)
                  }
                  onKeyDown={(e) => {
                    if (e.key === "Enter" || e.key === " ") {
                      e.preventDefault();
                      router.push("/dashboard/chat?botId=" + bot.chatbot_id);
                    }
                  }}
                  className="group relative rounded-2xl border border-white/[0.08] bg-zinc-900/60 backdrop-blur-xl overflow-hidden hover:border-fuchsia-500/40 hover:bg-zinc-900/90 hover:shadow-[0_0_30px_-5px_rgba(217,70,239,0.15)] hover:-translate-y-0.5 transition-all duration-300 ease-out cursor-pointer flex flex-col justify-between"
                >
                  {/* Top Cover Section */}
                  <div>
                    <div className="relative aspect-[16/9] w-full bg-zinc-950 overflow-hidden">
                      <img
                        src={resolveImg(bot.kapak_fotografi)}
                        alt={bot.isim}
                        className="h-full w-full object-cover transition-transform duration-700 ease-out group-hover:scale-105"
                      />
                      <div className="absolute inset-0 bg-gradient-to-t from-zinc-950 via-zinc-950/20 to-transparent" />

                      {/* Status Badge */}
                      <div className="absolute right-3 top-3">
                        <span
                          className={cn(
                            "inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-caption font-semibold border backdrop-blur-md shadow-md",
                            active
                              ? "bg-emerald-500/20 text-emerald-300 border-emerald-500/30"
                              : "bg-rose-500/20 text-rose-300 border-rose-500/30",
                          )}
                        >
                          <span
                            className={cn(
                              "w-1.5 h-1.5 rounded-full",
                              active
                                ? "bg-emerald-400 animate-pulse"
                                : "bg-rose-400",
                            )}
                          />
                          {active ? "Aktif" : "Süresi Bitti"}
                        </span>
                      </div>

                      {/* Avatar Overlay */}
                      <div className="absolute -bottom-4 left-4 h-10 w-10 overflow-hidden rounded-xl border-2 border-zinc-900 bg-zinc-950 shadow-lg">
                        <img
                          src={resolveImg(bot.profil_fotografi, "avatar")}
                          alt=""
                          className="h-full w-full object-cover"
                        />
                      </div>
                    </div>

                    {/* Card Content Body */}
                    <div className="p-4 pt-6 space-y-2">
                      <div className="flex items-center gap-1.5 text-caption font-mono text-fuchsia-400">
                        <Zap className="w-3 h-3" />
                        <span>#{categoryLabel}</span>
                      </div>

                      <h3 className="font-bold text-sm text-white group-hover:text-fuchsia-200 transition-colors line-clamp-1">
                        {bot.isim}
                      </h3>

                      <p className="text-caption text-zinc-400 flex items-center gap-1 font-mono">
                        <Clock className="w-3 h-3 text-zinc-500" />
                        <span>
                          {active ? "Bitiş" : "Sona Erdi"}:{" "}
                          {formatDate(bot.expiry_date)}
                        </span>
                      </p>
                    </div>
                  </div>

                  {/* Card Bottom Action */}
                  <div className="p-4 pt-2 border-t border-white/[0.06] mt-2 flex items-center justify-between">
                    {active && daysLeft !== null && daysLeft > 0 ? (
                      <span className="text-caption font-mono text-emerald-400 bg-emerald-500/10 px-2 py-0.5 rounded border border-emerald-500/20">
                        {daysLeft} gün kaldı
                      </span>
                    ) : (
                      <span className="text-caption font-mono text-zinc-500">
                        ID: {bot.chatbot_id}
                      </span>
                    )}

                    <span className="flex items-center gap-1 text-xs font-bold text-fuchsia-300 group-hover:text-fuchsia-200">
                      Sohbet Et
                      <ArrowUpRight className="w-3.5 h-3.5 opacity-70 group-hover:translate-x-0.5 group-hover:-translate-y-0.5 transition-transform" />
                    </span>
                  </div>
                </div>
              );
            })}
          </div>
        ) : (
          <div className="space-y-3">
            {filteredBots.map((bot) => {
              const cat = categories.find(
                (c) => String(c.id) === String(bot.kategori_id),
              );
              const categoryLabel = cat ? cat.kategori_adi_tr : "Genel";
              const active = Number(bot.is_active) === 1;

              return (
                <div
                  key={bot.id}
                  onClick={() =>
                    router.push("/dashboard/chat?botId=" + bot.chatbot_id)
                  }
                  className="group rounded-2xl border border-white/[0.08] bg-zinc-900/60 p-4 hover:border-fuchsia-500/40 hover:bg-zinc-900/90 transition-all duration-200 cursor-pointer flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4"
                >
                  <div className="flex items-center gap-4">
                    <img
                      src={resolveImg(bot.profil_fotografi, "avatar")}
                      alt=""
                      className="w-12 h-12 rounded-xl object-cover border border-white/10 shrink-0"
                    />
                    <div>
                      <div className="flex items-center gap-2">
                        <h4 className="text-sm font-bold text-white group-hover:text-fuchsia-300 transition-colors">
                          {bot.isim}
                        </h4>
                        <span className="text-caption font-mono text-fuchsia-400 bg-fuchsia-500/10 px-2 py-0.5 rounded border border-fuchsia-500/20">
                          {categoryLabel}
                        </span>
                      </div>
                      <p className="text-xs text-zinc-400 mt-1 flex items-center gap-2 font-mono">
                        <span>Bitiş: {formatDate(bot.expiry_date)}</span>
                        <span>•</span>
                        <span>Bot ID: {bot.chatbot_id}</span>
                      </p>
                    </div>
                  </div>

                  <div className="flex items-center gap-4 w-full sm:w-auto justify-between sm:justify-end border-t sm:border-t-0 border-white/5 pt-3 sm:pt-0">
                    <span
                      className={cn(
                        "inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-caption font-semibold border",
                        active
                          ? "bg-emerald-500/10 text-emerald-400 border-emerald-500/20"
                          : "bg-rose-500/10 text-rose-400 border-rose-500/20",
                      )}
                    >
                      {active ? "Aktif Lisans" : "Süresi Dolan"}
                    </span>

                    <button className="flex items-center gap-1.5 px-4 py-2 rounded-xl bg-white/10 hover:bg-fuchsia-600 text-xs font-semibold text-white transition cursor-pointer">
                      <MessageSquare className="w-3.5 h-3.5" />
                      <span>Sohbete Başlat</span>
                    </button>
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </div>
    </div>
  );
}
