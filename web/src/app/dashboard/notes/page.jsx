"use client";

import React, { useEffect, useState, useMemo, useContext } from "react";
import dynamic from "next/dynamic";
import { UserContext } from "@/shared/contexts/UserContext";
import CategoryFilter from "@/widgets/CategoryFilter";
import { copyText } from "@/shared/lib/clipboard";
import { toast } from "@/shared/hooks/use-toast";
import {
  Dialog,
  DialogContentBare,
  DialogTitle,
  DialogDescription,
} from "@/shared/ui/dialog";
import {
  ChevronDown,
  Check,
  MoreVertical,
  Share2,
  Trash2,
  EyeOff,
  Flag,
  Sparkles,
  Search,
  MessageSquare,
  BookOpen,
  UserRound,
  ShieldAlert,
  X,
  AlertCircle,
  ExternalLink,
  Lock,
  Layers,
  Filter,
} from "lucide-react";

// Only loaded once the user opens the report dialog.
const ReportModal = dynamic(() => import("@/features/moderation/ReportModal"), { ssr: false });

const resolveAvatarSrc = (seed) => {
  return {
    src:
      seed ||
      `https://api.dicebear.com/7.x/shapes/svg?seed=${encodeURIComponent(seed || "default")}&backgroundColor=111122,1a1a2e,16213e`,
  };
};

const cn = (...classes) => classes.filter(Boolean).join(" ");

/**
 * Paylasilan diyalogun baglantisi.
 *
 * Onceki bicim `/dialogue/<id>` idi ve iki yonden bozuktu: (a) uygulamada
 * `/dialogue` diye bir route YOK, bagalanti 404 donuyordu; (b) kart menusu
 * oraya diyalog id'si degil `conversation_chatbot_id` (bot id'si)
 * geciriyordu. Artik baglanti dogrudan bu diyalogun acildigi yere gidiyor:
 * notlar sayfasi `?dialog=` parametresini okuyup ilgili modali aciyor.
 */
const dialogueShareUrl = (dialogId) =>
  `${typeof window !== "undefined" ? window.location.origin : ""}/dashboard/notes/?dialog=${dialogId}`;

/** Bir kullanicinin herkese acik profili. */
const userProfileUrl = (userId) => `/dashboard/user/?id=${userId}`;

function DialogueModal({ isOpen, onClose, selectedHistory, onShare }) {
  if (!isOpen || !selectedHistory) return null;

  return (
    <Dialog open={isOpen} onOpenChange={(open) => { if (!open) onClose(); }}>
      <DialogContentBare className="w-[calc(100vw-2rem)] max-w-2xl">
        <div className="relative flex max-h-[85vh] w-full flex-col overflow-hidden rounded-3xl border border-white/10 bg-[#0c0c14]/95 shadow-2xl shadow-fuchsia-950/30">
        <div className="flex items-center justify-between px-6 py-5 border-b border-white/10 bg-white/[0.02]">
          <div className="flex items-center gap-3">
            {/* Genel bir mesaj ikonu yerine botun kendi profil fotoğrafı:
                diyaloğun hangi bota ait olduğu başlıkta yazıyla zaten var,
                görselle birlikte bir bakışta anlaşılıyor. Fotoğraf yoksa
                eski ikona düşüyoruz — kırık bir <img> göstermek yerine. */}
            {selectedHistory.chatbot_profil_fotografi ? (
              <img
                src={resolveAvatarSrc(selectedHistory.chatbot_profil_fotografi).src}
                alt=""
                className="h-10 w-10 shrink-0 rounded-2xl object-cover ring-1 ring-fuchsia-500/20"
              />
            ) : (
              <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-fuchsia-500/10 border border-fuchsia-500/20 text-fuchsia-400">
                <MessageSquare className="h-5 w-5" />
              </div>
            )}
            <div>
              <DialogTitle className="text-base font-semibold tracking-tight text-white">
                {selectedHistory.title || "Diyalog Detayı"}
              </DialogTitle>
              {/* `owner_kullanici_adi` BOTUN sahibi; diyalogu paylasan
                  ayri bir kisi (asagidaki satir). Ikisi ayni sanilmasin. */}
              <DialogDescription className="text-xs text-white/40">
                {selectedHistory.chatbot_isim} •{" "}
                {selectedHistory.owner_kullanici_adi}
              </DialogDescription>
            </div>
          </div>
          <button
            onClick={onClose}
            className="flex h-8 w-8 items-center justify-center rounded-xl bg-white/5 text-white/60 hover:bg-white/10 hover:text-white transition-colors"
          >
            <X className="h-4 w-4" />
          </button>
        </div>

        <div className="flex-1 overflow-y-auto p-6 space-y-6">
          <div className="space-y-2">
            <span className="text-caption font-medium uppercase tracking-wider text-fuchsia-400">
              Kullanıcı İsteği
            </span>
            <div className="rounded-2xl bg-white/[0.03] border border-white/5 p-4 text-sm text-white/90 leading-relaxed">
              {selectedHistory.input_message}
            </div>
          </div>

          <div className="space-y-2">
            <span className="text-caption font-medium uppercase tracking-wider text-purple-400">
              Asistan Yanıtı
            </span>
            <div className="max-h-[300px] overflow-y-auto overscroll-contain rounded-2xl bg-fuchsia-950/20 border border-fuchsia-500/15 p-4 text-sm text-white/90 leading-relaxed">
              {selectedHistory.output_message || "Yanıt bekleniyor..."}
            </div>
          </div>
        </div>

        <div className="flex flex-col gap-3 border-t border-white/10 bg-white/[0.02] px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
          {/* N-05 (M0-4) — bu düğme diyalogu PAYLASAN kullanicinin
              profiline gidiyordu; musteri beklentisi diyalogun ait oldugu
              botun SOHBET sayfasi. `chatbot_isim` LEFT JOIN'den geliyor:
              bot silinmisse null, o zaman olu bir sohbete goturmemek icin
              dugme cizilmiyor. Paylasanin profili ikincil bir metin
              baglantisi olarak korunuyor — kalip kalmayacagi musteriye
              soruldu (Faz 0 karar kaydi K-1). */}
          <div className="flex flex-wrap items-center gap-2">
            {selectedHistory.chatbot_id && selectedHistory.chatbot_isim ? (
              <a
                href={`/dashboard/chat?botId=${selectedHistory.chatbot_id}`}
                className="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-3.5 py-2.5 text-xs font-medium text-white/80 transition-colors hover:bg-white/10 hover:text-white"
              >
                <MessageSquare className="h-4 w-4 text-fuchsia-400" />
                <span className="max-w-[12rem] truncate">
                  {selectedHistory.chatbot_isim} ile sohbet et
                </span>
              </a>
            ) : null}
            {selectedHistory.user_id && selectedHistory.sharer_kullanici_adi ? (
              <a
                href={userProfileUrl(selectedHistory.user_id)}
                className="inline-flex items-center gap-1.5 px-1.5 py-2.5 text-xs text-white/45 transition-colors hover:text-white/80"
              >
                <UserRound className="h-3.5 w-3.5" />
                <span className="max-w-[10rem] truncate">
                  @{selectedHistory.sharer_kullanici_adi}
                </span>
              </a>
            ) : null}

            <button
              onClick={() => onShare?.(selectedHistory)}
              className="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-3.5 py-2.5 text-xs font-medium text-white/80 transition-colors hover:bg-white/10 hover:text-white"
            >
              <Share2 className="h-4 w-4 text-fuchsia-400" />
              Paylaş
            </button>
          </div>

          <button
            onClick={onClose}
            className="rounded-xl bg-white/10 px-5 py-2.5 text-xs font-medium text-white hover:bg-white/15 transition-colors"
          >
            Kapat
          </button>
        </div>
        </div>
      </DialogContentBare>
    </Dialog>
  );
}

function ShareModal({ isOpen, dialogId, onClose }) {
  const [copied, setCopied] = useState(false);
  const [copyFailed, setCopyFailed] = useState(false);
  if (!isOpen) return null;

  const shareUrl = dialogueShareUrl(dialogId);

  // N-41: "Kopyalandı" yalnızca pano yazması gerçekten tamamlanınca.
  const handleCopy = async () => {
    const ok = await copyText(shareUrl);
    setCopyFailed(!ok);
    if (!ok) return;
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  return (
    <Dialog open={isOpen} onOpenChange={(open) => { if (!open) onClose(); }}>
      <DialogContentBare className="w-[calc(100vw-2rem)] max-w-md">
        <div className="relative w-full rounded-3xl border border-white/10 bg-[#0c0c14]/95 p-6 shadow-2xl shadow-fuchsia-950/30">
        <div className="flex items-center justify-between mb-4">
          <DialogTitle className="flex items-center gap-2.5 text-base text-white font-semibold">
            <Share2 className="h-5 w-5 text-fuchsia-400" />
            <span>Diyaloğu Paylaş</span>
          </DialogTitle>
          <button onClick={onClose} className="text-white/40 hover:text-white">
            <X className="h-4 w-4" />
          </button>
        </div>
        <DialogDescription className="text-xs text-white/50 mb-4">
          Bu diyaloğu bağlantı yoluyla başkalarıyla güvenle paylaşabilirsiniz.
        </DialogDescription>
        <div className="flex items-center gap-2 rounded-xl bg-white/5 border border-white/10 p-2 mb-4">
          <input
            type="text"
            readOnly
            value={shareUrl}
            className="w-full bg-transparent px-2 text-xs text-white/80 focus:outline-none"
          />
          <button
            onClick={handleCopy}
            className="rounded-lg bg-fuchsia-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-fuchsia-500 transition-colors shrink-0"
          >
            {copied ? "Kopyalandı!" : "Kopyala"}
          </button>
        </div>
        {copyFailed && (
          <p role="alert" className="-mt-2 text-xs text-rose-300">
            Bağlantı kopyalanamadı. Bağlantıyı seçip elle kopyalayın.
          </p>
        )}
        </div>
      </DialogContentBare>
    </Dialog>
  );
}

function DeleteConfirmModal({
  isOpen,
  onClose,
  onConfirm,
  title = "Silme Onayı",
  description = "Bu öğeyi kalıcı olarak silmek istediğinizden emin misiniz?",
  confirmLabel = "Sil",
}) {
  if (!isOpen) return null;

  return (
    <Dialog open={isOpen} onOpenChange={(open) => { if (!open) onClose(); }}>
      <DialogContentBare className="w-[calc(100vw-2rem)] max-w-sm">
        <div className="relative w-full rounded-3xl border border-white/10 bg-[#0c0c14]/95 p-6 shadow-2xl text-center">
        <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-rose-500/10 border border-rose-500/20 text-rose-400 mb-4">
          <AlertCircle className="h-6 w-6" />
        </div>
        <DialogTitle className="mb-1 text-base font-semibold text-white">{title}</DialogTitle>
        <DialogDescription className="mb-6 text-xs text-white/50">{description}</DialogDescription>
        <div className="flex gap-3">
          <button
            onClick={onClose}
            className="flex-1 rounded-xl bg-white/5 py-2.5 text-xs font-medium text-white/80 hover:bg-white/10 transition-colors"
          >
            Vazgeç
          </button>
          <button
            onClick={onConfirm}
            className="flex-1 rounded-xl bg-rose-600 py-2.5 text-xs font-medium text-white hover:bg-rose-500 transition-colors shadow-lg shadow-rose-950/40"
          >
            {confirmLabel}
          </button>
        </div>
        </div>
      </DialogContentBare>
    </Dialog>
  );
}

function NotesEmpty() {
  return (
    <div className="flex flex-col items-center justify-center py-20 px-4 text-center">
      <div className="flex h-16 w-16 items-center justify-center rounded-3xl bg-fuchsia-500/10 border border-fuchsia-500/20 text-fuchsia-400 mb-4 shadow-xl shadow-fuchsia-950/30">
        <BookOpen className="h-8 w-8" />
      </div>
      <h3 className="text-lg font-semibold text-white mb-1">
        Diyalog Bulunamadı
      </h3>
      <p className="text-xs text-white/40 max-w-sm">
        Aradığınız kriterlere uygun kayıt bulunmuyor. Yeni bir diyalog başlatın
        veya filtreleri değiştirin.
      </p>
    </div>
  );
}

export default function DialoguePage() {
  const { userId } = useContext(UserContext);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [filtered, setFiltered] = useState("Tümü");
  const [tab, setTab] = useState("all");
  // Paylasilan sey diyalogun KENDISI; eskiden buraya botun id'si
  // yaziliyordu ve uretilen baglanti yanlis kaynagi gosteriyordu.
  const [shareDialogId, setShareDialogId] = useState(null);
  const [selectedHistory, selectHistory] = useState(null);
  const [hiddenBotIds, setHiddenBotIds] = useState([]);
  const [categories, setCategories] = useState([]);
  const [histories, setHistories] = useState([]);

  const [shareOpen, setShareOpen] = useState(false);
  const [reportOpen, setReportOpen] = useState(false);
  const [reportTargetId, setReportTargetId] = useState(null);
  const [showFeedbackBadge, setShowFeedbackBadge] = useState(false);
  const [deleteModalVisible, setDeleteModalVisible] = useState(false);
  const [deleteTargetIndex, setDeleteTargetIndex] = useState(null);
  const [hideBotConfirmTarget, setHideBotConfirmTarget] = useState(null);

  const handleCloseModal = () => setIsModalOpen(false);

  useEffect(() => {
    fetch("/api/content/getcategories.php").then(async (res) => {
      try {
        const data = await res.json();
        if (Array.isArray(data?.categories)) {
          setCategories([{ id: "all", kategori_adi_tr: "Tümü" }, ...data.categories]);
        }
      } catch (e) {
        console.error(e);
      }
    });
  }, []);

  useEffect(() => {
    if (!userId) return;

    fetch(`/api/social/gethide.php?user_id=${userId}`).then(async (res) => {
      try {
        const data = await res.json();
        if (Array.isArray(data?.hidden)) {
          setHiddenBotIds(data.hidden.map(Number));
        }
      } catch (e) {
        console.error("Hide list error:", e);
      }
    });

    fetch("/api/note/getdialogues.php").then(async (res) => {
      const text = await res.text();
      try {
        const data = JSON.parse(text);
        if (Array.isArray(data?.dialogues)) {
          setHistories(data.dialogues);
        }
      } catch (e) {
        console.error(e);
      }
    });
  }, [userId]);

  /* Paylasilan baglanti (`?dialog=<id>`) dogrudan o diyalogun modalini
     acar — paylas dugmesinin vaadi bu. Diyaloglar yuklendikten SONRA
     calisiyor, cunku aranan kayit `histories` icinde.

     Filtrelenmis listede degil ham `histories` icinde ariyoruz: paylasilan
     diyalog aliciinin aktif sekmesine ya da kategori filtresine uymayabilir,
     ama baglantiya tiklayan onu yine de gormeli.

     Bir kez calisir: modal kapatildiktan sonra parametre hala URL'de oldugu
     icin tekrar acilmasin diye `opened` bayragi tutuluyor. */
  const [sharedDialogOpened, setSharedDialogOpened] = useState(false);

  useEffect(() => {
    if (sharedDialogOpened || histories.length === 0) return;

    const wanted = new URLSearchParams(window.location.search).get("dialog");
    if (!wanted) return;

    const match = histories.find((h) => String(h.id) === String(wanted));
    setSharedDialogOpened(true);
    if (!match) return;

    selectHistory({ ...match, title: match.name || "Yeni Diyalog" });
    setIsModalOpen(true);
  }, [histories, sharedDialogOpened]);

  const filteredCards = useMemo(() => {
    let sourceCards = histories.filter(
      (h) => !hiddenBotIds.includes(h.conversation_chatbot_id),
    );

    if (tab === "mine") {
      sourceCards = sourceCards.filter((h) => h.user_id === userId);
    }

    const selectedCatObj = categories.find(
      (c) => c.kategori_adi_tr === filtered,
    );

    const finalFiltered =
      filtered === "Tümü" || !selectedCatObj
        ? sourceCards
        : sourceCards.filter(
            (card) => card.chatbot_kategori_id == selectedCatObj.id,
          );

    return finalFiltered.map((historyItem) => ({
      ...historyItem,
      title: historyItem.name || "Yeni Diyalog",
      description:
        (historyItem.input_message || "") +
        "... " +
        (historyItem.output_message || "Yanıt bekleniyor..."),
      tag: historyItem.chatbot_isim,
      id: historyItem.id,
    }));
  }, [histories, hiddenBotIds, tab, userId, categories, filtered]);

  const handleHideBot = (e, targetChatbotId) => {
    e.stopPropagation();
    setHideBotConfirmTarget(targetChatbotId);
  };

  const confirmHideBot = async () => {
    const targetChatbotId = hideBotConfirmTarget;
    setHideBotConfirmTarget(null);

    const payload = { user_id: userId, chatbot_id: targetChatbotId };
    const formData = new FormData();
    formData.append("data", JSON.stringify(payload));

    try {
      const response = await fetch("/api/social/addhide.php", {
        method: "POST",
        body: formData,
      });
      const result = await response.json().catch(() => null);

      // N-42: kart yalnızca sunucu gizlemeyi onaylarsa kalkar; eskiden
      // başarısızlıkta hiçbir şey olmuyor, kullanıcı hata görmüyordu.
      if (response.ok && result?.success) {
        setHiddenBotIds((prev) => [...prev, targetChatbotId]);
        setHistories((prev) =>
          prev.filter((h) => h.conversation_chatbot_id !== targetChatbotId),
        );
        setShowFeedbackBadge(true);
        setTimeout(() => setShowFeedbackBadge(false), 2000);
      } else {
        toast.error(result?.message || `Bot gizlenemedi (HTTP ${response.status}). Lütfen tekrar deneyin.`);
      }
    } catch (error) {
      console.error("Hata:", error);
      toast.error("Sunucuya ulaşılamadı. Bot gizlenemedi.");
    }
  };

  const handleDelete = (indexToRemove) => {
    const cardToRemove = filteredCards[indexToRemove];
    if (!cardToRemove) return;
    const historyId = cardToRemove.id;
    setHistories((prev) => prev.filter((h) => h.id !== historyId));
    setDeleteModalVisible(false);
    setDeleteTargetIndex(null);
  };

  const sortOptions = [
    { label: "Tüm Paylaşılanlar", value: "all" },
    { label: "Paylaştıklarım", value: "mine" },
  ];

  return (
    <div className="min-h-screen bg-[#07070b] text-white selection:bg-fuchsia-500/30 selection:text-fuchsia-200 font-sans antialiased">
      {/* Background glow ambiance */}
      <div className="absolute top-0 left-1/2 -translate-x-1/2 w-full max-w-7xl h-96 bg-gradient-to-b from-fuchsia-600/10 via-purple-900/5 to-transparent blur-3xl pointer-events-none" />

      <main className="relative px-4 sm:px-6 lg:px-8 py-10 space-y-8">
        {/* Page Header */}
        <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4 border-b border-white/10 pb-6">
          <div className="space-y-1">
            <div className="flex items-center gap-2">
              <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-fuchsia-500/10 border border-fuchsia-500/20 text-caption font-semibold text-fuchsia-300 tracking-wider uppercase">
                <Sparkles className="h-3 w-3" /> Arşiv
              </span>
            </div>
            <h1 className="font-display text-3xl font-bold tracking-tight text-white sm:text-4xl">
              Diyalog Defteri
            </h1>
            <p className="text-sm text-white/50">
              Yapay zeka asistanlarınızla gerçekleştirdiğiniz geçmiş diyalogları
              inceleyin ve yönetin.
            </p>
          </div>

          <div className="flex items-center gap-3">
            {/* Filter Dropdown */}
            <div className="relative group">
              <div className="flex items-center gap-2 rounded-2xl border border-fuchsia-500/30 bg-fuchsia-500/10 px-5 py-3 text-xs font-semibold text-fuchsia-200 transition-all hover:bg-fuchsia-500/20 shadow-lg shadow-fuchsia-950/20 cursor-pointer">
                <span>
                  {sortOptions.find((o) => o.value === tab)?.label ||
                    "Filtrele"}
                </span>
                <ChevronDown className="h-4 w-4" />
              </div>
              <div className="absolute right-0 mt-2 w-48 rounded-2xl border border-white/10 bg-[#0c0c14]/95 p-1.5 shadow-2xl backdrop-blur-xl opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all z-30">
                {sortOptions.map((opt) => (
                  <button
                    key={opt.value}
                    onClick={() => setTab(opt.value)}
                    className={cn(
                      "w-full flex items-center justify-between rounded-xl px-3 py-2 text-xs font-medium transition-colors",
                      tab === opt.value
                        ? "bg-fuchsia-600 text-white"
                        : "text-white/70 hover:bg-white/5 hover:text-white",
                    )}
                  >
                    <span>{opt.label}</span>
                    {tab === opt.value && <Check className="h-3.5 w-3.5" />}
                  </button>
                ))}
              </div>
            </div>
          </div>
        </div>

        {/* Category Filter Bar */}
        {categories.length > 0 && (
          <CategoryFilter
            categories={categories}
            onSelect={(catName) => setFiltered(catName)}
            selected={filtered}
          />
        )}

        {}
        {filteredCards.length === 0 ? (
          <NotesEmpty />
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            {filteredCards.map((card, index) => (
              <div
                key={card.id}
                role="button"
                tabIndex={0}
                onClick={() => {
                  selectHistory(card);
                  setIsModalOpen(true);
                }}
                onKeyDown={(e) => {
                  if (e.key === "Enter" || e.key === " ") {
                    e.preventDefault();
                    selectHistory(card);
                    setIsModalOpen(true);
                  }
                }}
                className="group relative flex flex-col justify-between rounded-3xl border border-white/10 bg-white/[0.02] p-5 transition-all duration-300 ease-out hover:border-fuchsia-500/40 hover:bg-white/[0.04] hover:shadow-2xl hover:shadow-fuchsia-950/30 hover:-translate-y-0.5 cursor-pointer"
              >
                {/* Content preview */}
                <div className="space-y-3">
                  <div className="relative max-h-[160px] overflow-hidden rounded-2xl bg-luma-base p-4 border border-white/5">
                    <div className="absolute inset-0 bg-gradient-to-t from-luma-base via-transparent to-transparent pointer-events-none" />
                    <h3 className="text-sm font-semibold text-white/90 group-hover:text-fuchsia-300 transition-colors">
                      {card.title}
                    </h3>
                    <p className="mt-1 text-xs text-white/50 line-clamp-3 leading-relaxed">
                      {card.description}
                    </p>
                  </div>
                </div>

                {/* Footer info & Actions */}
                <div className="flex items-center justify-between pt-4 mt-4 border-t border-white/5">
                  <div className="flex items-center gap-3 min-w-0">
                    <img
                      src={
                        card.chatbot_profil_fotografi ||
                        resolveAvatarSrc(null).src
                      }
                      alt=""
                      className="h-8 w-8 shrink-0 rounded-full object-cover ring-2 ring-fuchsia-500/20"
                    />
                    <div className="min-w-0">
                      <p className="truncate text-xs font-semibold text-white/90">
                        {card.name}
                      </p>
                      <p className="truncate text-caption text-white/40">
                        {card.chatbot_isim} • {card.owner_kullanici_adi}
                      </p>
                    </div>
                  </div>

                  {/* Actions Dropdown */}
                  <div className="relative group/menu">
                    <button
                      className="flex h-8 w-8 items-center justify-center rounded-xl bg-white/5 text-white/50 transition-all hover:bg-fuchsia-500/20 hover:text-white"
                      onClick={(e) => e.stopPropagation()}
                      aria-label="Diğer seçenekler"
                    >
                      <MoreVertical className="h-4 w-4" />
                    </button>
                    <div className="absolute right-0 bottom-full mb-2 w-48 rounded-2xl border border-white/10 bg-[#0c0c14]/95 p-1.5 shadow-2xl backdrop-blur-xl opacity-0 invisible group-hover/menu:opacity-100 group-hover/menu:visible transition-all z-30">
                      {tab === "mine" ? (
                        <>
                          <button
                            onClick={(e) => {
                              e.stopPropagation();
                              setShareDialogId(card.id);
                              setShareOpen(true);
                            }}
                            className="w-full flex items-center gap-2 rounded-xl px-3 py-2 text-xs font-medium text-white/80 hover:bg-white/5 hover:text-white transition-colors"
                          >
                            <Share2 className="h-4 w-4 text-fuchsia-400" />{" "}
                            Paylaş
                          </button>
                          <div className="my-1 border-t border-white/5" />
                          <button
                            onClick={(e) => {
                              e.stopPropagation();
                              setDeleteTargetIndex(index);
                              setDeleteModalVisible(true);
                            }}
                            className="w-full flex items-center gap-2 rounded-xl px-3 py-2 text-xs font-medium text-rose-400 hover:bg-rose-500/10 transition-colors"
                          >
                            <Trash2 className="h-4 w-4" /> Sil
                          </button>
                        </>
                      ) : (
                        <>
                          <button
                            onClick={(e) => {
                              e.stopPropagation();
                              setShareDialogId(card.id);
                              setShareOpen(true);
                            }}
                            className="w-full flex items-center gap-2 rounded-xl px-3 py-2 text-xs font-medium text-white/80 hover:bg-white/5 hover:text-white transition-colors"
                          >
                            <Share2 className="h-4 w-4 text-fuchsia-400" />{" "}
                            Paylaş
                          </button>
                          <div className="my-1 border-t border-white/5" />
                          <button
                            onClick={(e) =>
                              handleHideBot(e, card.conversation_chatbot_id)
                            }
                            className="w-full flex items-center gap-2 rounded-xl px-3 py-2 text-xs font-medium text-white/80 hover:bg-white/5 hover:text-white transition-colors"
                          >
                            <EyeOff className="h-4 w-4 text-purple-400" /> Bu
                            Profili Önermeyin
                          </button>
                          <button
                            onClick={(e) => {
                              e.stopPropagation();
                              setReportTargetId(card.conversation_chatbot_id);
                              setReportOpen(true);
                            }}
                            className="w-full flex items-center gap-2 rounded-xl px-3 py-2 text-xs font-medium text-rose-400 hover:bg-rose-500/10 transition-colors"
                          >
                            <Flag className="h-4 w-4" /> Bildir
                          </button>
                        </>
                      )}
                    </div>
                  </div>
                </div>
              </div>
            ))}
          </div>
        )}

        {}
        <DialogueModal
          isOpen={isModalOpen}
          onClose={handleCloseModal}
          selectedHistory={selectedHistory}
          onShare={(dialogue) => {
            setShareDialogId(dialogue.id);
            setShareOpen(true);
          }}
        />

        <ShareModal
          isOpen={shareOpen}
          dialogId={shareDialogId}
          onClose={() => {
            setShareOpen(false);
            setShareDialogId(null);
          }}
        />

        <ReportModal
          isOpen={reportOpen}
          repId={reportTargetId}
          onClose={() => setReportOpen(false)}
        />

        <DeleteConfirmModal
          isOpen={deleteModalVisible}
          onClose={() => setDeleteModalVisible(false)}
          onConfirm={() => {
            handleDelete(deleteTargetIndex);
            setDeleteModalVisible(false);
            setDeleteTargetIndex(null);
          }}
        />

        <DeleteConfirmModal
          isOpen={hideBotConfirmTarget !== null}
          onClose={() => setHideBotConfirmTarget(null)}
          onConfirm={confirmHideBot}
          title="Bu profili gizle"
          description="Bu botu bir daha size önermeyeceğiz. Onaylıyor musunuz?"
          confirmLabel="Onayla"
        />

        {showFeedbackBadge && (
          <div className="fixed bottom-6 right-6 px-4 py-2 rounded-2xl bg-fuchsia-600 text-white text-xs font-semibold shadow-2xl shadow-fuchsia-950/60 pointer-events-none z-[999999] animate-[fadeInOut_2s_ease_forwards] border border-fuchsia-400/30 flex items-center gap-2">
            <Sparkles className="h-4 w-4" /> Uyarınız başarıyla alındı
          </div>
        )}
      </main>
    </div>
  );
}
