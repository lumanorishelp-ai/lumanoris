"use client";
import Image from "next/image";
import { useRouter } from 'next/navigation';
import ShareModal from "@/features/sharing/ShareModal";
import { useEffect, useState } from "react";
import CommentModal from "@/features/comments/CommentModal";
import { postBotComment } from "@/features/comments/api";
import AddToSaleListModal from "@/features/chatbot-mgmt/AddToSaleListModal";
import DeleteConfirmModal from "@/shared/ui/DeleteConfirmModal";
import PublishModal from "@/features/chatbot-mgmt/PublishModal";
import BuyModal from "@/features/purchasing/BuyModal";
import { cn } from "@/lib/utils";
import { resolveAvatarSrc, resolveCoverSrc } from "@/shared/lib/image";
import { Badge } from "@/shared/ui/badge";
import { Card } from "@/shared/ui/card";
import { toast } from "@/shared/hooks/use-toast";
import { ThumbsUp, ThumbsDown, MessageSquare, Share2, MoreVertical, Trash2 } from "lucide-react";
import {
    DropdownMenu, DropdownMenuTrigger, DropdownMenuContent, DropdownMenuItem,
} from "@/shared/ui/dropdown-menu";

export default function ChatbotCard({ id, userId, authorUserId, ownerUserId, isIndependent, title, image, likes, dislikes, comments, dialogs, status, sellerStatus, profileImage, category, weeklyPrice, monthlyPrice, onDelete, onChanged }) {
    const isInactiveSeller = !isIndependent && sellerStatus && sellerStatus !== 'active';
    const isOwn = String(authorUserId) === String(userId);
    const isPurchased = !isOwn && String(ownerUserId) === String(userId);
    const router = useRouter();
    const [shareOpen, setShareOpen] = useState(false);
    const [commentOpen, setCommentOpen] = useState(false);
    const [addOpen, setAddOpen] = useState(false);
    const [publishOpen, setPublishOpen] = useState(false);
    const [unpublishConfirmOpen, setUnpublishConfirmOpen] = useState(false);
    const [buyOpen, setBuyOpen] = useState(false);
    const [confirmDeleteOpen, setConfirmDeleteOpen] = useState(false);
    const [liked, setLiked] = useState(false);
    const [disliked, setDisliked] = useState(false);
    const [likeCount, setLikeCount] = useState(likes || 0);
    const [dislikeCount, setDislikeCount] = useState(dislikes || 0);
    const [commentCount, setCommentCount] = useState(comments || 0);
    const [commentList, setCommentList] = useState([]);
    const [previousDurationWeeks, setPreviousDurationWeeks] = useState(null);

    // "Tekrar Satın Al" should default the purchase popup to whatever
    // duration the buyer had last time — not silently fall back to "Bir
    // Aylık" regardless of what they actually bought (see BuyModal's
    // initialDurationWeeks). ProfileCard.jsx solves the analogous "already
    // in cart" case by reading order_weeks from the cart, but a repurchase
    // has no cart entry (it was cleared on successful checkout) — the
    // signal that exists here is the bot's own past subscription record.
    useEffect(() => {
        if (!isPurchased || !userId || !id) return;
        fetch(`/api/wallet/getsubscription.php?user_id=${userId}&chatbot_id=${id}`)
            .then(res => res.json())
            .then(data => {
                if (data.success && data.duration_weeks) {
                    setPreviousDurationWeeks(data.duration_weeks);
                }
            })
            .catch(err => console.error("Abonelik süresi alınamadı:", err));
    }, [isPurchased, userId, id]);

    const handleLike = async (e) => {
        e.stopPropagation();
        try {
            const res = await fetch("/api/social/likechatbot.php", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: new URLSearchParams({ data: JSON.stringify({ user_id: userId, chatbot_id: id }) }),
            });
            const result = await res.json();
            if (result.success) {
                if (result.action === "liked") {
                    setLiked(true); setLikeCount(p => p + 1);
                    if (disliked) { setDisliked(false); setDislikeCount(p => p - 1); }
                } else if (result.action === "unliked") {
                    setLiked(false); setLikeCount(p => p - 1);
                }
            }
        } catch (err) { console.error("Like API error:", err); }
    };

    const handleDislike = async (e) => {
        e.stopPropagation();
        try {
            const res = await fetch("/api/social/dislikechatbot.php", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: new URLSearchParams({ data: JSON.stringify({ user_id: userId, chatbot_id: id }) }),
            });
            const result = await res.json();
            if (result.success) {
                if (result.action === "disliked") {
                    setDisliked(true); setDislikeCount(p => p + 1);
                    if (liked) { setLiked(false); setLikeCount(p => p - 1); }
                } else if (result.action === "undisliked") {
                    setDisliked(false); setDislikeCount(p => p - 1);
                }
            }
        } catch (err) { console.error("Dislike API error:", err); }
    };

    useEffect(() => {
        if (!id) return;
        // getuserbotstatus.php reads $_GET (session provides identity) and
        // bundles like+dislike+follow into one call — this card renders once
        // per bot in a marketplace grid, so the old 2 separate fetches here
        // multiplied into 2xN requests per page load.
        const checkStatus = async () => {
            try {
                const res = await fetch(`/api/social/getuserbotstatus.php?chatbot_id=${id}`);
                const result = await res.json();
                if (result.success) {
                    setLiked(result.didLike);
                    setDisliked(result.didDisLike);
                }
            } catch (err) { console.error("getuserbotstatus API error:", err); }
        };
        checkStatus();
    }, [id]);

    useEffect(() => {
        if (!id) return;
        fetch(`/api/social/getchatbotcomments.php?chatbot_id=${id}`)
            .then(r => r.json())
            .then(data => setCommentList(data.comments || []))
            .catch(err => console.error("Yorumlar alınamadı:", err));
    }, [id]);

    const handleUnpublish = async () => {
        try {
            const res = await fetch('/api/chatbot/unpublishchatbot.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ data: JSON.stringify({ id, user_id: userId }) }),
            });
            const result = await res.json();
            if (result.success) { if (onChanged) onChanged(); }
            else toast({ variant: "destructive", title: result.message });
        } catch (err) { toast({ variant: "destructive", title: "Yayından kaldırma hatası", description: err.message }); }
        setUnpublishConfirmOpen(false);
    };

    return (
        <>
            <Card
                interactive
                role="button"
                tabIndex={0}
                className={cn(
                    'group relative flex flex-col overflow-hidden p-0',
                    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-luma-base',
                    isInactiveSeller ? 'opacity-70 saturate-50' : '',
                )}
                onClick={() => router.push('/dashboard/chat?botId=' + id)}
                onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        router.push('/dashboard/chat?botId=' + id);
                    }
                }}
            >
                {/* Cover image — same visual family as the marketplace card:
                    big image, hover zoom, floating avatar, status pill. */}
                <div className="relative aspect-[4/3] w-full shrink-0 overflow-hidden bg-luma-input">
                    <Image
                        src={resolveCoverSrc(image)}
                        alt="chatbot"
                        fill
                        className="object-cover transition-transform duration-500 ease-out group-hover:scale-105"
                        sizes="(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 25vw"
                    />
                    <div className="absolute inset-x-0 bottom-0 h-16 bg-gradient-to-t from-black/70 to-transparent" />
                    <div className="absolute inset-0 bg-gradient-to-t from-fuchsia-600/25 via-transparent to-transparent opacity-0 transition-opacity duration-500 group-hover:opacity-100" />

                    <Badge
                        variant={status === "Satın Alındı" ? "success" : "default"}
                        className="absolute right-2.5 top-2.5"
                    >
                        {status}
                    </Badge>

                    {isInactiveSeller && (
                        <button
                            type="button"
                            onClick={(e) => { e.stopPropagation(); router.push('/dashboard/chatbots/create'); }}
                            className="absolute inset-x-0 top-0 z-10 flex items-center justify-center gap-2 bg-amber-500/25 py-1.5 text-center transition-colors hover:bg-amber-500/35"
                        >
                            <span className="text-caption font-semibold text-amber-200">Yayında Değil — Pazaryeri kaydını tamamla</span>
                        </button>
                    )}

                    <div className="absolute -bottom-4 left-3.5 h-9 w-9 overflow-hidden rounded-full ring-2 ring-luma-card">
                        <Image src={resolveAvatarSrc(profileImage)} alt="" width={36} height={36} className="h-full w-full object-cover" />
                    </div>
                </div>

                <div className="flex flex-col gap-2 p-3.5 pt-5">
                    <div className="flex items-center justify-between gap-2">
                        <p className="truncate font-display text-body-lg font-bold text-white">{title}</p>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <button
                                    aria-label="Chatbot menüsü"
                                    className="flex shrink-0 items-center justify-center w-7 h-7 rounded-lg text-white/40 hover:text-white/80 hover:bg-fuchsia-500/10 transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-luma-base"
                                    onClick={(e) => e.stopPropagation()}
                                >
                                    <MoreVertical className="h-4 w-4" />
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" onClick={(e) => e.stopPropagation()}>
                                <DropdownMenuItem
                                    className="text-rose-400 focus:text-rose-400"
                                    onClick={() => setConfirmDeleteOpen(true)}
                                >
                                    <Trash2 className="h-4 w-4" /> Sil
                                </DropdownMenuItem>
                                {isOwn && !isIndependent && (
                                    <DropdownMenuItem onClick={() => setUnpublishConfirmOpen(true)}>
                                        Yayından Kaldır
                                    </DropdownMenuItem>
                                )}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>

                    <span className="text-label text-luma-muted">{dialogs} Diyalog</span>

                    {/* Stats row */}
                    <div className="flex items-center gap-2">
                        <StatBtn active={liked} activeClass="text-fuchsia-300" onClick={handleLike}>
                            <ThumbsUp className="h-3.5 w-3.5" strokeWidth={1.8} />
                            {likeCount}
                        </StatBtn>
                        <StatBtn active={disliked} activeClass="text-rose-400" onClick={handleDislike}>
                            <ThumbsDown className="h-3.5 w-3.5" strokeWidth={1.8} />
                            {dislikeCount}
                        </StatBtn>
                        <StatBtn onClick={(e) => { e.stopPropagation(); setCommentOpen(true); }}>
                            <MessageSquare className="h-3.5 w-3.5" strokeWidth={1.8} />
                            {commentCount}
                        </StatBtn>
                        <StatBtn onClick={(e) => { e.stopPropagation(); setShareOpen(true); }}>
                            <Share2 className="h-3.5 w-3.5" strokeWidth={1.8} />
                            Paylaş
                        </StatBtn>
                    </div>

                    {/* Actions row */}
                    {(isOwn || isPurchased) && (
                        <div className="mt-1 flex items-center gap-2 flex-wrap">
                            {isOwn && (
                                <ActionBtn onClick={(e) => { e.stopPropagation(); router.push('/dashboard/chatbots/create?id=' + id); }}>
                                    Düzenle
                                </ActionBtn>
                            )}
                            {isOwn && isIndependent && (
                                <ActionBtn onClick={(e) => { e.stopPropagation(); setPublishOpen(true); }}>
                                    Herkese Açık Yayınla
                                </ActionBtn>
                            )}
                            {isOwn && !isIndependent && (
                                <ActionBtn onClick={(e) => { e.stopPropagation(); setAddOpen(true); }}>
                                    Satış Listesine Ekle
                                </ActionBtn>
                            )}
                            {isPurchased && (
                                <ActionBtn onClick={(e) => { e.stopPropagation(); setBuyOpen(true); }}>
                                    Tekrar Satın Al
                                </ActionBtn>
                            )}
                        </div>
                    )}
                </div>
            </Card>

            <ShareModal isOpen={shareOpen} urlId={id} onClose={() => setShareOpen(false)} />
            <CommentModal
                isOpen={commentOpen}
                onClose={() => setCommentOpen(false)}
                comments={commentList}
                onSend={async (commentText) => {
                    // N-34: ortak yardımcı; kimlik gönderilmez, sonuç CommentModal'a döner.
                    const result = await postBotComment(id, commentText);
                    if (result.ok) setCommentCount(p => p + 1);
                    else toast({ variant: "destructive", title: "Yorum eklenemedi", description: result.message });
                    return result;
                }}
            />
            <AddToSaleListModal isOpen={addOpen} onClose={() => setAddOpen(false)} botId={id} weeklyPrice={weeklyPrice} monthlyPrice={monthlyPrice} />
            <PublishModal isOpen={publishOpen} onClose={() => setPublishOpen(false)} onPublished={() => { if (onChanged) onChanged(); }} botId={id} userId={userId} weeklyPrice={weeklyPrice} />
            <BuyModal isOpen={buyOpen} onClose={() => setBuyOpen(false)} botData={{ id, isim: title, ucret_haftalik: weeklyPrice, ucret_aylik: monthlyPrice }} userId={userId} initialDurationWeeks={previousDurationWeeks} />
            <DeleteConfirmModal isOpen={confirmDeleteOpen} onClose={() => setConfirmDeleteOpen(false)} onConfirm={() => { setConfirmDeleteOpen(false); if (onDelete) onDelete(); }} />
            <DeleteConfirmModal isOpen={unpublishConfirmOpen} onClose={() => setUnpublishConfirmOpen(false)} onConfirm={handleUnpublish} title="Yayından kaldırmayı onaylıyor musunuz?" description="Chatbotunuz pazaryerinden kaldırılacaktır. Botunuz silinmez, istediğiniz zaman tekrar yayınlayabilirsiniz." confirmLabel="Onayla" />
        </>
    );
}

function StatBtn({ active, activeClass, onClick, children }) {
    return (
        <button
            className={cn(
                'flex items-center gap-1.5 text-label transition-colors px-0 rounded-md',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-luma-base',
                active ? (activeClass ?? 'text-fuchsia-300') : 'text-white/45 hover:text-white/75',
            )}
            onClick={onClick}
        >
            {children}
        </button>
    );
}

function ActionBtn({ onClick, children }) {
    return (
        <button
            className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-fuchsia-500/10 border border-fuchsia-400/20 text-fuchsia-300 text-caption font-semibold hover:bg-fuchsia-500/15 hover:border-fuchsia-400/35 transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-luma-base"
            onClick={onClick}
        >
            {children}
        </button>
    );
}
