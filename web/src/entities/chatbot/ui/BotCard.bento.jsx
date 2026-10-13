"use client";
import { useContext, useState } from "react";
import dynamic from "next/dynamic";
import { useRouter } from "next/navigation";
import { toast } from "@/shared/hooks/use-toast";
import { UserContext } from "@/shared/contexts/UserContext";
import { requireLogin } from "@/shared/lib/auth-guard";
import {
  Tag,
  Bookmark,
  Heart,
  Star,
  MessageSquare,
  ArrowUpRight,
} from "lucide-react";
import CategoryBadge from "@/shared/ui/category-badge";
import { resolveCategory } from "@/shared/lib/categories";

const AddToListModal = dynamic(() => import("@/features/lists/AddToListModal"), { ssr: false });

function formatCompactNumber(n) {
  const num = Number(n) || 0;
  if (num >= 1000000) return (num / 1000000).toFixed(1).replace(".", ",") + "M";
  if (num >= 1000)
    return (num / 1000).toFixed(num % 1000 === 0 ? 0 : 1).replace(".", ",") + "B";
  return String(num);
}

function resolveAvatarSrc(src) {
  if (!src || src === "default" || src === "0") {
    return "https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=150&auto=format&fit=crop&q=80";
  }
  if (src.startsWith("http://") || src.startsWith("https://") || src.startsWith("/")) {
    return src;
  }
  return `/uploads/avatars/${src}`;
}

/**
 * Pazaryeri bot karti. Anasayfa ve Kesfet ayni karti kullanir — daha once
 * iki ayri bilesen vardi (BentoBotCard ve MarketplaceListCard) ve gorunumleri
 * birbirinden ayrilmisti.
 *
 */
export default function BotCard({
  bot,
  onOpenDetails,
}) {
  const { userId } = useContext(UserContext) || {};
  const router = useRouter();
  // N-35: başlangıç durumu sunucudan (getchatbots.php → liked_by_me).
  const [isLiked, setIsLiked] = useState(Boolean(bot.likedByMe));
  const [likeBusy, setLikeBusy] = useState(false);
  const [listOpen, setListOpen] = useState(false);
  const [likesCount, setLikesCount] = useState(bot.likes || 0);
  const category = resolveCategory(bot.kategori_id);

  /* N-35 — kalp ve yer imi eskiden yalnızca yerel state değiştiriyordu
     (sunucuya hiç yazmıyordu; sayfa yenilenince her şey sıfırlanıyordu).
     Kalp: açık "like"/"unlike" eylemi (idempotent); arayüz yalnızca sunucu
     başarı dönünce değişir, hata olursa mesaj gösterilir. Yer imi: gerçek
     listelere yazan "Listeye Ekle" penceresini açar. İkisi de oturum ister. */
  const toggleLike = async (e) => {
    e.stopPropagation();
    if (!requireLogin(userId, router) || likeBusy) return;
    const want = isLiked ? "unlike" : "like";
    setLikeBusy(true);
    try {
      const res = await fetch("/api/social/likechatbot.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({ data: JSON.stringify({ chatbot_id: bot.id, action: want }) }),
        credentials: "include",
      });
      const result = await res.json().catch(() => null);
      if (!res.ok || !result?.success) {
        toast.error(result?.message || `Beğeni kaydedilemedi (HTTP ${res.status}).`);
        return;
      }
      const nowLiked = Boolean(result.liked);
      if (result.action !== "unchanged") {
        setLikesCount((prev) => Math.max(0, prev + (nowLiked ? 1 : -1)));
      }
      setIsLiked(nowLiked);
    } catch {
      toast.error("Sunucuya ulaşılamadı. Beğeni kaydedilemedi.");
    } finally {
      setLikeBusy(false);
    }
  };

  const openListModal = (e) => {
    e.stopPropagation();
    if (!requireLogin(userId, router)) return;
    setListOpen(true);
  };

  return (
    <>
    <div
      onClick={() => onOpenDetails?.(bot)}
      onKeyDown={(event) => {
        if (event.currentTarget !== event.target || !["Enter", " "].includes(event.key)) return;
        event.preventDefault();
        onOpenDetails?.(bot);
      }}
      role="button"
      tabIndex={0}
      className={`group relative flex flex-col overflow-hidden rounded-3xl border border-white/[0.08] bg-gradient-to-b from-zinc-900/70 to-zinc-950/90 shadow-xl shadow-black/10 transition-[border-color,box-shadow] duration-200 focus-visible:outline-none ${category.hoverBorder} ${category.glow} cursor-pointer`}
    >
      {/* Top Border Glow Sweep */}
      <div className={`absolute inset-x-0 top-0 z-10 h-px bg-gradient-to-r from-transparent ${category.sweep} to-transparent opacity-0 transition-opacity duration-200 group-hover:opacity-100`} />

      {/* Cover Image Header */}
      <div className="relative aspect-[16/10] w-full overflow-hidden bg-zinc-950">
        <img
          src={bot.image}
          alt={bot.title}
          className="h-full w-full object-cover transition-transform duration-700 ease-out group-hover:scale-105"
          onError={(e) => {
            e.currentTarget.src =
              "https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=800&auto=format&fit=crop&q=80";
          }}
        />
        <div className="absolute inset-0 bg-gradient-to-t from-zinc-950 via-zinc-950/30 to-transparent" />

        {/* Top Badges overlay */}
        <div className="absolute left-3.5 top-3.5 right-3.5 z-10 flex min-w-0 items-center justify-between gap-2">
          {/* N-26: rozet yoksa hiçbir şey çizilmez (eski "Doğrulanmış" yedeği de
              bir beyandı). Boş span sağdaki düğmeleri sağa yaslı tutar. */}
          {bot.badge ? (
            <span
              className={`inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-caption font-bold tracking-wide backdrop-blur-md shadow-xl border ${
                bot.badge.type === "sold"
                  ? "border-amber-500/30 bg-amber-500/20 text-amber-300"
                  : "border-violet-500/30 bg-violet-500/20 text-violet-200"
              }`}
            >
              <Tag className="h-3 w-3" />
              {bot.badge.label}
            </span>
          ) : (
            <span />
          )}


          <div className="flex items-center gap-1.5">
            <button
              onClick={openListModal}
              className="flex h-8 w-8 items-center justify-center rounded-full border border-white/10 bg-zinc-950/70 text-zinc-300 backdrop-blur-md transition-all hover:bg-zinc-900 hover:text-white"
              title="Listeye Ekle"
              aria-label="Listeye Ekle"
            >
              <Bookmark className="h-3.5 w-3.5" fill="none" />
            </button>
            <button
              onClick={toggleLike}
              disabled={likeBusy}
              aria-label={isLiked ? "Beğeniyi kaldır" : "Beğen"}
              aria-pressed={isLiked}
              className={`flex h-8 w-8 items-center justify-center rounded-full border backdrop-blur-md transition-all ${
                isLiked
                  ? "border-rose-500/60 bg-rose-600 text-white shadow-lg shadow-rose-600/40 scale-105"
                  : "border-white/10 bg-zinc-950/70 text-zinc-300 hover:bg-zinc-900 hover:text-white"
              }`}
              title="Beğen"
            >
              <Heart
                className="h-3.5 w-3.5"
                fill={isLiked ? "currentColor" : "none"}
              />
            </button>
          </div>
        </div>

        {/* Price & Rating Tag */}
        <div className="absolute bottom-3 right-3 left-3 flex items-center justify-between">
          {/* N-15 — puanı olmayan botta sabit "4.9" gösteriliyordu (uydurma
              veri). Puan yalnızca gerçekten varsa çiziliyor. */}
          {bot.rating != null && (
            <div className="inline-flex items-center gap-1 rounded-lg border border-white/10 bg-zinc-950/80 px-2 py-0.5 text-caption font-semibold text-amber-300 backdrop-blur-md">
              <Star className="h-3 w-3 fill-amber-400 text-amber-400" />
              <span>{bot.rating}</span>
            </div>
          )}

          <div className="ml-auto rounded-xl border border-white/15 bg-zinc-950/90 px-3 py-1 text-xs font-bold font-mono text-white backdrop-blur-md shadow-xl">
            {bot.weeklyPrice > 0 ? (
              <span className="text-emerald-400">
                ₺{bot.weeklyPrice}
                <span className="text-caption text-zinc-400 font-normal">
                  {" "}
                  /hafta
                </span>
              </span>
            ) : (
              <span className="text-violet-400">Ücretsiz</span>
            )}
          </div>
        </div>
      </div>

      {/* Card Body */}
      <div className="flex flex-1 flex-col p-5">
        {/* Author Details */}
        <div className="flex items-center gap-2 mb-2">
          <img
            src={resolveAvatarSrc(bot.avatar)}
            alt={bot.author}
            className="h-5 w-5 rounded-full object-cover border border-white/20 shadow-sm"
            onError={(e) => {
              e.currentTarget.src =
                "https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=150&auto=format&fit=crop&q=80";
            }}
          />
          <span className="text-xs font-medium text-zinc-300 truncate">
            {bot.author}
          </span>
          <span className="text-zinc-600">•</span>
          <span className="text-caption text-zinc-400">{bot.time}</span>
        </div>

        {/* Title */}
        <h3 className="text-base font-bold text-white line-clamp-1">
          {bot.title}
        </h3>

        {/* Kategori rozeti burada: tepedeki serit rozet + iki dugmeyle
            dolu oldugu icin etiket kirpiliyor, yalnizca ikon kaliyordu. */}
        <div className="mt-2">
          <CategoryBadge category={bot.kategori_id} />
        </div>

        {/* Description */}
        <p className="mt-2 line-clamp-2 text-xs text-zinc-400 leading-relaxed flex-1">
          {bot.description ||
            "Bu yapay zeka asistanı için herhangi bir açıklama girilmedi."}
        </p>

        {/* Footer Metrics */}
        <div className="mt-5 flex items-center justify-between border-t border-white/5 pt-3.5 text-xs font-medium text-zinc-400">
          <div className="flex items-center gap-3">
            <span className="flex items-center gap-1 hover:text-zinc-200 transition-colors">
              <MessageSquare className="h-3.5 w-3.5 text-violet-400" />
              {formatCompactNumber(bot.dialogues)}
            </span>
            <span className="flex items-center gap-1 hover:text-zinc-200 transition-colors">
              <Heart className="h-3.5 w-3.5 text-rose-400" />
              {formatCompactNumber(likesCount)}
            </span>
          </div>

          <div className="flex items-center gap-1 text-xs font-bold text-violet-400 group-hover:translate-x-1 transition-transform">
            <span>Sohbet Et</span>
            <ArrowUpRight className="h-3.5 w-3.5" />
          </div>
        </div>
      </div>
    </div>
    {/* N-35: kartın DIŞINDA — portal içi tıklamalar React ağacında karta kabarcıklanıp detayı açmasın. */}
    {listOpen && (
      <AddToListModal userId={userId} botId={bot.id} isOpen={listOpen} onClose={() => setListOpen(false)} />
    )}
    </>
  );
}
