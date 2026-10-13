"use client";
import { ModalPortal } from "@/shared/ui/modal-portal";

import React, { useEffect, useState, useMemo, useRef, useContext } from "react";
import { UserContext } from "@/shared/contexts/UserContext";
import { toast } from "@/shared/hooks/use-toast";

import {
  Search,
  MessageSquare,
  MoreHorizontal,
  Trash2,
  Pencil,
  Clock,
  Sparkles,
  Calendar,
  X,
  Check,
  ChevronRight,
  Bot,
  AlertTriangle,
  Command,
  ArrowUpRight,
  Filter,
} from "lucide-react";

// Avatar solution function (Flexible fallback without Next image dependency)
function resolveAvatarSrc(src) {
  if (!src || src === "default" || src === "0") {
    return "https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=150&auto=format&fit=crop&q=80";
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

// Turkish Date Formatter
function formatDateToTurkish(dateString) {
  const months = [
    "Ocak",
    "Şubat",
    "Mart",
    "Nisan",
    "Mayıs",
    "Haziran",
    "Temmuz",
    "Ağustos",
    "Eylül",
    "Ekim",
    "Kasım",
    "Aralık",
  ];

  let date;
  if (!dateString || dateString === "0") {
    date = new Date();
  } else {
    date = new Date(dateString);
  }

  if (isNaN(date.getTime())) {
    date = new Date();
  }

  const day = date.getDate();
  const month = months[date.getMonth()];
  const year = date.getFullYear();

  return `${day} ${month} ${year}`;
}

// Time Group Classifier (Bugün, Dün, Bu Hafta, Daha Eski)
function getTimeGroup(dateString) {
  if (!dateString || dateString === "0") return "Bugün";
  const date = new Date(dateString);
  if (isNaN(date.getTime())) return "Daha Eski";

  const now = new Date();
  const todayStart = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  const yesterdayStart = new Date(todayStart);
  yesterdayStart.setDate(yesterdayStart.getDate() - 1);
  const weekStart = new Date(todayStart);
  weekStart.setDate(weekStart.getDate() - 7);

  if (date >= todayStart) return "Bugün";
  if (date >= yesterdayStart) return "Dün";
  if (date >= weekStart) return "Bu Hafta";
  return "Daha Eski";
}

// Delete Confirmation Modal (Linear / Stripe / Raycast Style)
function DeleteConfirmModal({ isOpen, onClose, onConfirm, isDeleting }) {
  if (!isOpen) return null;

  return (
    <ModalPortal onClose={onClose}>
      <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
        {/* Glass Backdrop */}
        <div
          className="fixed inset-0 bg-black/75 backdrop-blur-md transition-opacity animate-in fade-in duration-200"
          onClick={onClose}
        />
  
        {/* Modal Dialog Container */}
        <div className="relative z-10 max-h-[calc(100dvh-2rem)] w-full max-w-md overflow-y-auto overscroll-contain rounded-2xl border border-white/10 bg-zinc-950/90 p-6 shadow-2xl backdrop-blur-2xl ring-1 ring-white/10 animate-in zoom-in-95 duration-200">
          <div className="flex items-start gap-4">
            <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-rose-500/20 bg-rose-500/10 text-rose-400">
              <AlertTriangle className="h-6 w-6" />
            </div>
            <div className="flex-1">
              <h3 className="text-lg font-semibold text-white">Sohbeti Sil</h3>
              <p className="mt-1 text-sm text-zinc-400 leading-relaxed">
                Bu sohbeti silmek istediğinize emin misiniz? Bu işlem kalıcıdır ve
                tüm mesaj geçmişiniz temizlenecektir.
              </p>
            </div>
          </div>
  
          <div className="mt-6 flex items-center justify-end gap-3">
            <button
              type="button"
              onClick={onClose}
              className="rounded-xl border border-white/10 bg-zinc-900/80 px-4 py-2.5 text-xs font-medium text-zinc-300 transition-all hover:bg-zinc-800 hover:text-white focus:outline-none focus:ring-2 focus:ring-zinc-700"
            >
              Vazgeç
            </button>
            <button
              type="button"
              onClick={onConfirm}
              disabled={isDeleting}
              className="flex items-center gap-2 rounded-xl bg-rose-600 px-4 py-2.5 text-xs font-semibold text-white shadow-lg shadow-rose-600/20 transition-all hover:bg-rose-500 hover:shadow-rose-500/30 active:scale-95 disabled:opacity-50"
            >
              {isDeleting ? (
                <div className="h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent" />
              ) : (
                <Trash2 className="h-4 w-4" />
              )}
              Evet, Sil
            </button>
          </div>
        </div>
      </div>
    </ModalPortal>
  );
}

// Skeleton Loader Component (Vercel Style Shimmer)
function HistorySkeleton() {
  return (
    <div className="space-y-4">
      {[1, 2, 3, 4].map((i) => (
        <div
          key={i}
          className="relative flex items-center justify-between rounded-2xl border border-white/[0.06] bg-zinc-900/40 p-4 backdrop-blur-xl animate-pulse"
        >
          <div className="flex items-center gap-4 w-full">
            <div className="h-12 w-12 rounded-xl bg-zinc-800/60 shrink-0" />
            <div className="flex-1 space-y-2">
              <div className="h-4 w-1/3 rounded bg-zinc-800/60" />
              <div className="h-3 w-2/3 rounded bg-zinc-800/40" />
            </div>
          </div>
          <div className="h-8 w-8 rounded-lg bg-zinc-800/40" />
        </div>
      ))}
    </div>
  );
}

// Empty State Component (Raycast Style Minimal Empty Screen)
function EmptyHistoryState({ hasFilter, onClearFilter }) {
  return (
    <div className="flex flex-col items-center justify-center rounded-3xl border border-dashed border-white/10 bg-zinc-900/20 px-6 py-20 text-center backdrop-blur-xl">
      <div className="relative mb-4 flex h-16 w-16 items-center justify-center rounded-2xl border border-white/10 bg-zinc-900/80 text-zinc-400 shadow-xl">
        <MessageSquare className="h-8 w-8 text-violet-400" />
        <div className="absolute -top-1 -right-1 flex h-4 w-4 items-center justify-center rounded-full bg-violet-500 ring-4 ring-zinc-950">
          <Sparkles className="h-2.5 w-2.5 text-white" />
        </div>
      </div>

      <h3 className="text-lg font-semibold text-white">
        {hasFilter
          ? "Aramanızla eşleşen sohbet bulunamadı"
          : "Henüz sohbet geçmişiniz yok"}
      </h3>
      <p className="mt-1 max-w-sm text-sm text-zinc-400 leading-relaxed">
        {hasFilter
          ? "Farklı bir arama terimi deneyebilir veya filtreyi temizleyebilirsiniz."
          : "Yapay zeka asistanlarınızla sohbet başlatarak zaman çizelgenizi oluşturmaya başlayın."}
      </p>

      {hasFilter && (
        <button
          onClick={onClearFilter}
          className="mt-5 inline-flex items-center gap-2 rounded-xl border border-white/10 bg-zinc-800/60 px-4 py-2 text-xs font-medium text-white transition-all hover:bg-zinc-700 hover:border-white/20"
        >
          <X className="h-3.5 w-3.5" />
          Aramayı Temizle
        </button>
      )}
    </div>
  );
}

export function History() {
  const { userId } = useContext(UserContext);
  // State Management (All business logic preserved intact)
  const [editingId, setEditingId] = useState(null);
  const [editingTitle, setEditingTitle] = useState("");
  /* N-37 — burada üç uydurma sohbet ("Yapay Zeka Mimari Analiz", "Stripe
     Entegrasyon Kılavuzu", "Next.js App Router…") ilk durum olarak duruyordu:
     istek başarısızsa kullanıcının geçmişi gibi gösteriliyor, başarılıysa da
     yüklenene kadar görünüyordu. Liste artık yalnızca sunucudan gelir. */
  const [historyItems, setHistoryItems] = useState([]);
  const [loadError, setLoadError] = useState(null);
  const [searchQuery, setSearchQuery] = useState("");
  const [activeMenuId, setActiveMenuId] = useState(null);
  const [deleteTargetId, setDeleteTargetId] = useState(null);
  const [loading, setLoading] = useState(true);
  const [isDeleting, setIsDeleting] = useState(false);

  const searchInputRef = useRef(null);

  // Router Navigation Proxy
  const navigateToChat = (item) => {
    try {
      localStorage.setItem("chatHistory", JSON.stringify(item.messages || []));
      localStorage.setItem(
        "chatTitle",
        item.title || item.conversation_name || "",
      );
      localStorage.setItem("chatId", item.chatId || item.id);
    } catch (e) {
      console.error("LocalStorage error", e);
    }

    const targetUrl = `/dashboard/chat/?botId=${item.chatbot_id}&convId=${item.id}`;
    if (typeof window !== "undefined") {
      window.location.href = targetUrl;
    }
  };

  // Delete Handler (Business logic preserved)
  const handleDelete = async () => {
    if (!deleteTargetId) return;
    setIsDeleting(true);

    try {
      const formData = new FormData();
      formData.append("id", deleteTargetId);

      const res = await fetch("/api/chat/deleteconversation.php", {
        method: "POST",
        body: formData,
      });

      const result = await res.json().catch(() => null);

        /* N-37 — eskiden başarısızlıkta da ("preview resilience") satır
           ekrandan siliniyordu; yenileyince geri geliyordu. */
        if (res.ok && result?.success) {
          setHistoryItems((prev) =>
            prev.filter((item) => item.id !== deleteTargetId),
          );
          toast.success("Sohbet silindi.");
        } else {
          toast.error(result?.message || "Sohbet silinemedi.");
        }
    } catch (err) {
      console.error("Silme işlemi hatası:", err);
      toast.error("Sunucuya ulaşılamadı. Sohbet silinemedi.");
    } finally {
      setIsDeleting(false);
      setDeleteTargetId(null);
      setActiveMenuId(null);
    }
  };

  // Update Conversation Title (Business logic preserved)
  const handleUpdateTitle = async (id, newTitle) => {
    if (!newTitle.trim()) {
      setEditingId(null);
      return;
    }

      /* N-37 — eskiden yanıt hiç okunmuyordu: sunucu reddetse de yeni başlık
         ekranda kalıyordu. Ekran hemen güncellenir, sunucu başarı dönmezse eski
         başlığa geri alınır ve hata gösterilir. */
      const previous = historyItems.find((i) => i.id === id)?.conversation_name ?? "";
      setHistoryItems((prev) =>
        prev.map((i) =>
          i.id === id ? { ...i, conversation_name: newTitle } : i,
        ),
      );
      setEditingId(null);

      const revert = (message) => {
        setHistoryItems((prev) =>
          prev.map((i) => (i.id === id ? { ...i, conversation_name: previous } : i)),
        );
        toast.error(message);
      };

      try {
        const formData = new FormData();
        formData.append(
          "data",
          JSON.stringify({
            id: id,
            conversation_name: newTitle,
          }),
        );

        const res = await fetch("/api/chat/updateconversation.php", {
          method: "POST",
          body: formData,
        });
        const result = await res.json().catch(() => null);
        if (!res.ok || !result?.success) {
          revert(result?.message || "Başlık kaydedilemedi.");
        }
      } catch (err) {
        console.error("API update error:", err);
        revert("Sunucuya ulaşılamadı. Başlık kaydedilemedi.");
      }
  };

  useEffect(() => {
    if (!userId) return;
    setLoading(true);
    fetch(`/api/chat/gethistory.php?user_id=${userId}`)
      .then((response) => {
        if (!response.ok) {
          throw new Error(`HTTP error: ${response.status}`);
        }
        return response.json();
      })
      .then((data) => {
        if (!data?.success || !Array.isArray(data.results)) {
          throw new Error(data?.message || "Geçmiş alınamadı.");
        }
        setLoadError(null);
        setHistoryItems(data.results);
      })
      .catch((error) => {
        console.error("Veri çekilirken sorun oluştu:", error);
        setLoadError("Sohbet geçmişiniz yüklenemedi. Sayfayı yenileyip tekrar deneyin.");
      })
      .finally(() => {
        setLoading(false);
      });
  }, [userId]);

  // Keyboard shortcut listener (⌘K or Ctrl+K)
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

  const filteredItems = useMemo(() => {
    return historyItems.filter((item) => {
      if (!searchQuery.trim()) return true;
      const q = searchQuery.toLowerCase();
      return (
        (item.conversation_name || "").toLowerCase().includes(q) ||
        (item.latest_message || "").toLowerCase().includes(q) ||
        (item.latest_sent_time || "").toLowerCase().includes(q)
      );
    });
  }, [historyItems, searchQuery]);

  // Group items by time period (Bugün, Dün, Bu Hafta, Daha Eski)
  const groupedItems = useMemo(() => {
    const groups = {
      Bugün: [],
      Dün: [],
      "Bu Hafta": [],
      "Daha Eski": [],
    };

    filteredItems.forEach((item) => {
      const group = getTimeGroup(item.latest_sent_time);
      if (groups[group]) {
        groups[group].push(item);
      } else {
        groups["Daha Eski"].push(item);
      }
    });

    return Object.entries(groups).filter(([_, items]) => items.length > 0);
  }, [filteredItems]);

  return (
    <div className="min-h-screen bg-zinc-950 font-sans text-zinc-100 antialiased selection:bg-violet-500/30 selection:text-violet-200">
      {/* Background Ambient Glow FX */}
      <div className="fixed inset-0 pointer-events-none overflow-hidden">
        <div className="absolute -top-[20%] left-1/2 -translate-x-1/2 h-[500px] w-[1000px] bg-gradient-to-b from-violet-600/10 via-fuchsia-600/5 to-transparent blur-3xl opacity-70" />
      </div>

      <main className="relative z-10 px-4 py-10 sm:px-6 lg:px-8">
        {/* Header Section */}
        <header className="mb-8 flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
          <div>
            <div className="inline-flex items-center gap-2 rounded-full border border-violet-500/20 bg-violet-500/10 px-3 py-1 text-xs font-medium text-violet-300 backdrop-blur-md mb-3">
              <Clock className="h-3.5 w-3.5 text-violet-400" />
              <span>Zaman Çizelgesi</span>
            </div>
            <h1 className="font-display text-3xl font-extrabold tracking-tight text-white sm:text-4xl">
              Geçmiş Sohbetler
            </h1>
            <p className="mt-1.5 text-sm text-zinc-400">
              Yapay zeka asistanları ile yaptığınız tüm etkileşimlerinize göz
              atın ve yönetin.
            </p>
          </div>

          {/* Premium SaaS Header Stats Badge */}
          <div className="flex items-center gap-3">
            <div className="flex items-center gap-2.5 rounded-2xl border border-white/10 bg-zinc-900/60 p-3 shadow-lg backdrop-blur-xl">
              <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-violet-500/10 text-violet-400 border border-violet-500/20">
                <MessageSquare className="h-4 w-4" />
              </div>
              <div>
                <div className="text-xs font-medium text-zinc-400">
                  Toplam Sohbet
                </div>
                <div className="text-base font-bold text-white">
                  {historyItems.length}
                </div>
              </div>
            </div>
          </div>
        </header>

        {}
        {/* Sticky Search & Filter Bar */}
        <div className="sticky top-6 z-30 mb-8">
          <div className="relative flex items-center rounded-2xl border border-white/10 bg-zinc-900/80 p-1.5 shadow-2xl backdrop-blur-2xl ring-1 ring-white/5 transition-all duration-300 focus-within:border-violet-500/50 focus-within:ring-violet-500/20">
            <Search className="ml-3.5 h-4 w-4 shrink-0 text-zinc-400" />
            <input
              ref={searchInputRef}
              type="text"
              placeholder="Geçmiş sohbetlerde ve mesajlarda ara..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              data-focus-managed
              className="w-full bg-transparent px-3 py-2.5 text-sm text-white placeholder-zinc-500 focus:outline-none"
            />

            {searchQuery ? (
              <button
                onClick={() => setSearchQuery("")}
                className="mr-2 rounded-lg p-1 text-zinc-400 hover:bg-zinc-800 hover:text-white transition-colors"
                title="Aramayı Temizle"
              >
                <X className="h-4 w-4" />
              </button>
            ) : (
              <div className="mr-3 hidden items-center gap-1 rounded-md border border-white/10 bg-zinc-800/60 px-2 py-0.5 text-caption font-mono font-medium text-zinc-400 sm:flex">
                <Command className="h-3 w-3" /> K
              </div>
            )}
          </div>
        </div>

        {}
        {/* Dynamic Content Area */}
        {loading ? (
          <HistorySkeleton />
        ) : loadError ? (
          <p role="alert" className="rounded-2xl border border-rose-500/20 bg-rose-500/10 px-5 py-4 text-sm text-rose-300">
            {loadError}
          </p>
        ) : filteredItems.length === 0 ? (
          <EmptyHistoryState
            hasFilter={!!searchQuery}
            onClearFilter={() => setSearchQuery("")}
          />
        ) : (
          <div className="space-y-8">
            {groupedItems.map(([timeLabel, items]) => (
              <section key={timeLabel} className="space-y-3">
                {/* Timeline Section Header */}
                <div className="flex items-center gap-3 px-1 py-1">
                  <span className="text-xs font-semibold uppercase tracking-wider text-violet-400">
                    {timeLabel}
                  </span>
                  <div className="h-[1px] flex-1 bg-gradient-to-r from-white/10 via-white/5 to-transparent" />
                  <span className="text-caption font-medium text-zinc-500">
                    {items.length} sohbet
                  </span>
                </div>

                {/* Conversation Cards Grid */}
                <div className="grid gap-3">
                  {items.map((item) => {
                    const isEditing = editingId === item.id;
                    const isMenuOpen = activeMenuId === item.id;

                    return (
                      <div
                        key={item.id}
                        onClick={() => {
                          if (isEditing) return;
                          navigateToChat(item);
                        }}
                        className={`group relative flex flex-col gap-4 rounded-2xl border bg-zinc-900/40 p-4 transition-all duration-300 sm:flex-row sm:items-center sm:justify-between cursor-pointer backdrop-blur-xl ${
                          isMenuOpen
                            ? "z-30 border-violet-500/40 bg-zinc-900/80 shadow-2xl ring-1 ring-violet-500/20"
                            : "z-0 border-white/[0.08] hover:border-white/20 hover:bg-zinc-900/80 hover:shadow-xl hover:shadow-black/40 hover:-translate-y-0.5"
                        }`}
                      >
                        {/* Hover Subtle Gradient Glow */}
                        <div className="absolute inset-0 rounded-2xl bg-gradient-to-r from-violet-500/5 via-fuchsia-500/5 to-transparent opacity-0 transition-opacity duration-300 group-hover:opacity-100 pointer-events-none" />

                        {/* Card Left Side: Avatar & Conversation Info */}
                        <div className="flex items-center gap-4 min-w-0 flex-1">
                          {/* Avatar Container */}
                          <div className="relative shrink-0">
                            <div className="h-12 w-12 overflow-hidden rounded-xl border border-white/10 bg-zinc-800 shadow-md">
                              <img
                                src={resolveAvatarSrc(item.profil_fotografi)}
                                alt={item.conversation_name || "Bot Avatar"}
                                className="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                                onError={(e) => {
                                  e.currentTarget.src =
                                    "https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=150&auto=format&fit=crop&q=80";
                                }}
                              />
                            </div>
                            <div className="absolute -bottom-1 -right-1 flex h-4 w-4 items-center justify-center rounded-full bg-zinc-900 border border-white/10 text-violet-400">
                              <Bot className="h-2.5 w-2.5" />
                            </div>
                          </div>

                          {/* Details */}
                          <div className="min-w-0 flex-1">
                            {isEditing ? (
                              <div
                                onClick={(e) => e.stopPropagation()}
                                className="flex items-center gap-2"
                              >
                                <input
                                  type="text"
                                  value={editingTitle}
                                  onChange={(e) =>
                                    setEditingTitle(e.target.value)
                                  }
                                  onKeyDown={(e) => {
                                    if (e.key === "Enter") {
                                      handleUpdateTitle(item.id, editingTitle);
                                    }
                                    if (e.key === "Escape") {
                                      setEditingId(null);
                                    }
                                  }}
                                  autoFocus
                                  className="w-full rounded-lg border border-violet-500/50 bg-zinc-950 px-3 py-1.5 text-sm font-semibold text-white outline-none ring-2 ring-violet-500/20"
                                />
                                <button
                                  type="button"
                                  onClick={() =>
                                    handleUpdateTitle(item.id, editingTitle)
                                  }
                                  className="rounded-lg bg-violet-600 p-1.5 text-white hover:bg-violet-500"
                                  title="Kaydet"
                                >
                                  <Check className="h-4 w-4" />
                                </button>
                                <button
                                  type="button"
                                  onClick={() => setEditingId(null)}
                                  className="rounded-lg bg-zinc-800 p-1.5 text-zinc-400 hover:bg-zinc-700 hover:text-white"
                                  title="İptal"
                                >
                                  <X className="h-4 w-4" />
                                </button>
                              </div>
                            ) : (
                              <div className="flex items-center gap-2">
                                <h3 className="truncate text-base font-semibold text-zinc-100 group-hover:text-violet-200 transition-colors">
                                  {item.conversation_name || "İsimsiz Sohbet"}
                                </h3>
                              </div>
                            )}

                            {/* Latest Message Preview */}
                            <p className="mt-1 line-clamp-1 text-xs text-zinc-400">
                              {item.latest_message || "Henüz mesaj yok..."}
                            </p>
                          </div>
                        </div>

                        {/* Card Right Side: Date & Options Menu */}
                        <div className="flex items-center justify-between gap-4 border-t border-white/5 pt-3 sm:border-0 sm:pt-0 sm:justify-end shrink-0">
                          {/* Date Badge */}
                          <div className="flex items-center gap-1.5 text-xs font-medium text-zinc-500 group-hover:text-zinc-400 transition-colors">
                            <Calendar className="h-3.5 w-3.5 text-zinc-500" />
                            <span>
                              {formatDateToTurkish(item.latest_sent_time)}
                            </span>
                          </div>

                          {/* Quick Actions & Menu */}
                          <div
                            className="relative flex items-center gap-1"
                            onClick={(e) => e.stopPropagation()}
                          >
                            {/* Action Arrow (Hover Overlay) */}
                            <button
                              onClick={() => navigateToChat(item)}
                              className="hidden rounded-lg p-1.5 text-zinc-400 opacity-0 transition-all hover:bg-white/10 hover:text-white group-hover:opacity-100 sm:block"
                              title="Sohbete Git"
                            >
                              <ArrowUpRight className="h-7 w-7" />
                            </button>

                            {/* Dropdown Menu Trigger Button */}
                            <button
                              onClick={() =>
                                setActiveMenuId(isMenuOpen ? null : item.id)
                              }
                              className={`rounded-lg p-1.5 transition-colors ${
                                isMenuOpen
                                  ? "bg-white/10 text-white"
                                  : "text-zinc-400 hover:bg-white/10 hover:text-white"
                              }`}
                              aria-label="Sohbet seçenekleri"
                            >
                              <MoreHorizontal className="h-7 w-7" />
                            </button>

                            {/* Custom Dropdown Content */}
                            {isMenuOpen && (
                              <div className="absolute right-0 top-full z-50 mt-2 w-48 rounded-xl border border-white/10 bg-zinc-900/95 p-1.5 shadow-2xl backdrop-blur-2xl ring-1 ring-black animate-in fade-in zoom-in-95 duration-150">
                                <button
                                  onClick={() => {
                                    setEditingId(item.id);
                                    setEditingTitle(
                                      item.conversation_name || "",
                                    );
                                    setActiveMenuId(null);
                                  }}
                                  className="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-xs font-medium text-zinc-300 hover:bg-violet-500/10 hover:text-violet-300 transition-colors"
                                >
                                  <Pencil className="h-3.5 w-3.5 text-violet-400" />
                                  Başlığı Düzenle
                                </button>

                                <div className="my-1 h-[1px] bg-white/5" />

                                <button
                                  onClick={() => {
                                    setDeleteTargetId(item.id);
                                    setActiveMenuId(null);
                                  }}
                                  className="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-xs font-medium text-rose-400 hover:bg-rose-500/10 hover:text-rose-300 transition-colors"
                                >
                                  <Trash2 className="h-3.5 w-3.5" />
                                  Sohbeti Sil
                                </button>
                              </div>
                            )}
                          </div>
                        </div>
                      </div>
                    );
                  })}
                </div>
              </section>
            ))}
          </div>
        )}

        {/* Delete Confirmation Modal */}
        <DeleteConfirmModal
          isOpen={!!deleteTargetId}
          onClose={() => setDeleteTargetId(null)}
          onConfirm={handleDelete}
          isDeleting={isDeleting}
        />
      </main>
    </div>
  );
}

export default function App() {
  return <History />;
}
