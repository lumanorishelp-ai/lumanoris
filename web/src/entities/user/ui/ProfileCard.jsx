import { useState, useEffect } from 'react'
import dynamic from 'next/dynamic';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/shared/ui/dialog';
import { useRouter } from 'next/navigation';
import {
    ThumbsUp, ThumbsDown, Share2, MessageCircle, ListPlus,
    ShoppingCart, EyeOff, Flag, ArrowRight, ChevronRight, Check,
} from 'lucide-react';
import CategoryBadge from '@/shared/ui/category-badge';
import { cn } from '@/lib/utils';
import { toast } from '@/shared/hooks/use-toast';
import { requireLogin } from '@/shared/lib/auth-guard';
import { resolveAvatarSrc, normalizeImagePath } from '@/shared/lib/image';
import { useAbortableEffect, isAbortError } from '@/shared/hooks/useAbortableEffect';

// Only loaded once the user actually opens one of these — this card renders
// unconditionally on every chat page load, so keeping these static meant all
// seven modals' code shipped with every chat visit regardless of use.
const ShareModal = dynamic(() => import('@/features/sharing/ShareModal'), { ssr: false });
const ReportModal = dynamic(() => import('@/features/moderation/ReportModal'), { ssr: false });
const AddToListModal = dynamic(() => import('@/features/lists/AddToListModal'), { ssr: false });
const CommentModal = dynamic(() => import('@/features/comments/CommentModal'), { ssr: false });
import { postBotComment } from '@/features/comments/api';
const DeleteConfirmModal = dynamic(() => import('@/shared/ui/DeleteConfirmModal'), { ssr: false });

function formatCompact(n) {
    const num = Number(n) || 0;
    if (num >= 1000) return (num / 1000).toFixed(num % 1000 === 0 ? 0 : 1).replace('.', ',') + ' B';
    return String(num);
}

/**
 * Eylem çubuğundaki tek düğme. Eskiden bu eylemlerin tamamı "…" menüsünün
 * içinde saklıydı: kullanıcı beğeni/yorum sayılarını görmek için bile menüyü
 * açmak zorundaydı. Artık hepsi sayaçlarıyla birlikte açıkta.
 *
 * Varsayılan olarak YALNIZCA İKON çiziliyor ("Beğen", "Beğenme", "Paylaş"
 * gibi yazılar gösterilmiyor). `label` yine ZORUNLU ve kaldırılmadı, çünkü:
 *   • `aria-label` olarak veriliyor — yazı gizlenince düğmenin erişilebilir
 *     adı da kaybolurdu; ekran okuyucu "düğme" demekten başka bir şey
 *     söyleyemezdi.
 *   • `title` olarak veriliyor — fareyle üzerine gelince ipucu çıkıyor, yani
 *     ikonun ne yaptığı tahmine kalmıyor.
 *
 * Sayaçlar (beğeni/yorum sayısı) yazı DEĞİL veri olduğu için duruyor.
 *
 * `showLabel` yalnızca yazının kendisi bilgi taşıdığı yerlerde açılıyor —
 * bugün kullanan yer yok (fiyatlı "Satın Al" hapı GK-14 ile kaldırıldı).
 */
function ActionPill({ icon: Icon, label, count, active, activeTone = 'fuchsia', tone = 'default', disabled, onClick, className, showLabel = false }) {
    const hasCount = typeof count === 'number';

    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            aria-label={label}
            title={label}
            aria-pressed={typeof active === 'boolean' ? active : undefined}
            className={cn(
                'flex h-8 shrink-0 items-center rounded-full border font-display text-label font-medium transition-all duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                // Yazısız ve sayaçsız düğme kare olmalı; `px-3` bırakılsaydı
                // içinde tek bir ikonla yayvan bir hap gibi dururdu.
                showLabel || hasCount ? 'gap-1.5 px-3' : 'w-8 justify-center',
                active
                    ? activeTone === 'rose'
                        ? 'border-rose-400/40 bg-rose-500/[0.12] text-rose-200'
                        : 'border-fuchsia-400/40 bg-fuchsia-500/[0.12] text-fuchsia-200'
                    : tone === 'muted'
                        ? 'border-transparent text-white/35 hover:bg-white/[0.05] hover:text-white/70'
                        : 'border-white/[0.08] bg-white/[0.03] text-white/70 hover:border-white/15 hover:bg-white/[0.07] hover:text-white',
                disabled && 'cursor-default opacity-50 hover:border-white/[0.08] hover:bg-white/[0.03]',
                className,
            )}
        >
            <Icon className="h-3.5 w-3.5 shrink-0" />
            {showLabel && <span className="whitespace-nowrap">{label}</span>}
            {hasCount && (
                <span className="tabular-nums text-current opacity-55">{formatCompact(count)}</span>
            )}
        </button>
    );
}

export default function ProfileCard({bot, comments}) {
    const [isFollowing, setIsFollowing] = useState(false);
    const [shareOpen, setShareOpen] = useState(false);
    const [reportOpen, setReportOpen] = useState(false);
    const [modalVisible, setModalVisible] = useState(false);
    const [commentOpen, setCommentOpen] = useState(false);
    const [liked, setLiked] = useState(false);
    const [likeCount, setLikeCount] = useState(bot.likes || 0);
    const [disliked, setDisliked] = useState(false);
    const [dislikeCount, setDislikeCount] = useState(bot.dislikes || 0); // varsayılan değer
    const router = useRouter();
    const [cartAdded, setCartAdded] = useState(false);
    const [isInCart, setIsInCart] = useState(false);
    const [userId, setUserId] = useState(null);
    const [userLists, setUserLists] = useState([]);
    const [commentCount, setCommentCount] = useState(comments.length);
    const [pastConversations, setPastConversations] = useState([]);
    const [notInterestedConfirmOpen, setNotInterestedConfirmOpen] = useState(false);
    const [infoOpen, setInfoOpen] = useState(false);
    const [profile, setProfile] = useState({
        id: null,
        title: "",
        author: "",
        seller: "",
        description: "",
        image: "",
        price: 0,
        priceType: "TL",
        duration: "1",
        follows: 0,
        commentCount: 0,
    });
    // Bot verisi geldiğinde/değiştiğinde profile'ı doldur. Bu eşleme eksikti:
    // profile.id null kaldığı için followchatbot/likechatbot/addcomment/
    // addtocart/list çağrılarının tümü chatbot_id=null ile gidiyordu ve
    // başlıkta bot adı hiç görünmüyordu.
    useEffect(() => {
        if (!bot?.id) return;
        setProfile({
            id: bot.id,
            title: bot.isim || "",
            author: bot.author_username || "",
            seller: bot.owner_username || "",
            description: bot.aciklama || "",
            image: bot.profil_fotografi ? formatImage(bot.profil_fotografi) : "",
            price: Number(bot.ucret_haftalik) || 0,
            priceType: "TL",
            duration: "1",
            follows: Number(bot.follows) || 0,
            commentCount: Array.isArray(comments) ? comments.length : 0,
        });
    }, [bot, comments]);

    // F-01/A-03 — burada her deger `data:image/jpeg;base64,` ile sariliyordu,
    // ama sutunda base64 degil `assets/profil_fotografi/<ad>.jpg` goreli yolu
    // var; sonuc bozuk bir data URI ve kirik gorseldi. Normalizasyon artik
    // paylasilan yardimcida (shared/lib/image.js).
    const formatImage = (img) => normalizeImagePath(img);
    // REACT-001: oturum kontrolü artık iptal edilebilir. Kullanıcı cevap
    // gelmeden sayfadan ayrılırsa setUserId çalışmıyor.
    useAbortableEffect((signal, isActive) => {
        (async () => {
            try {
                const res = await fetch("/api/auth/sessioncheck.php", {
                    credentials: "include", // cookie'yi gönder
                    signal,
                });
                const result = await res.json();
                if (isActive() && result.authenticated) {
                    setUserId(result.user_id);
                }
            } catch (err) {
                if (!isAbortError(err)) console.error("Session check error:", err);
            }
        })();
    }, [router]);

    // REACT-001: sepet durumu — bot değiştiğinde eski istek iptal ediliyor.
    // İptal edilmediğinde iki cevap yarışıyor ve yavaş olan kazanabiliyordu,
    // yani bir önceki botun sepet durumu ekranda kalabiliyordu.
    useAbortableEffect((signal, isActive) => {
        if (!userId || !profile.id) return;

        (async () => {
            try {
                const response = await fetch(`/api/marketplace/getcart.php?user_id=${userId}`, { signal });
                const data = await response.json();
                if (!isActive()) return;

                if (data?.success && Array.isArray(data.cart)) {
                    const existing = data.cart.find(item => Number(item.chatbot_id) === Number(profile.id));
                    if (existing) {
                        setIsInCart(true);
                    }
                }
            } catch (error) {
                if (!isAbortError(error)) console.error("Sepet durumu alınamadı:", error);
            }
        })();
    }, [userId, profile.id]);

    // "Örnek Geçmiş" — this bot's own past conversations with the current
    // user, so they can jump straight back into one instead of starting over.
    useAbortableEffect((signal, isActive) => {
        if (!userId || !profile.id) return;

        (async () => {
            try {
                const res = await fetch(`/api/chat/gethistory.php?user_id=${userId}`, { signal });
                const data = await res.json();
                if (!isActive()) return;

                const items = Array.isArray(data?.results)
                    ? data.results.filter(item => Number(item.chatbot_id) === Number(profile.id))
                    : [];
                setPastConversations(items.slice(0, 3));
            } catch (err) {
                if (!isAbortError(err)) console.error("Geçmiş sohbetler alınamadı:", err);
            }
        })();
    }, [userId, profile.id]);

    useAbortableEffect((signal, isActive) => {
        if (!userId) return;

        (async () => {
            try {
                const response = await fetch(`/api/social/getuserlists.php?id=${userId}`, { signal });
                const data = await response.json();
                if (!isActive()) return;

                if (Array.isArray(data?.lists)) {
                    const minimalLists = data.lists.map(list => ({
                        id: list.id,
                        userId,
                        name: list.name,
                    }));
                    setUserLists(minimalLists);
                }
            } catch (error) {
                if (!isAbortError(error)) console.error("Kullanıcı listeleri alınamadı:", error);
            }
        })();
    }, [userId]);

    useAbortableEffect((signal, isActive) => {
        // Deps used to be [profile.id, userId] — neither is actually read
        // inside this fetch (it uses bot.id directly, and the backend reads
        // identity from the session cookie via optionalAuth(), not a request
        // param), but both values change asynchronously right after mount
        // (profile.id: null -> bot.id once the [bot,comments] effect above
        // runs; userId: undefined -> real id once the session check
        // resolves) — each transition re-ran this effect, firing the same
        // request 3 times. bot.id is already stable by the time this
        // component mounts (the parent only renders it once bot exists).
        if (!bot.id) return;

        (async () => {
            try {
                const res = await fetch(`/api/social/getuserbotstatus.php?chatbot_id=${bot.id}`, { signal });
                const result = await res.json();
                if (!isActive()) return;

                if (result.success) {
                    setLiked(result.didLike);
                    setDisliked(result.didDisLike);
                    setIsFollowing(result.didFollow);
                }
            } catch (err) {
                if (!isAbortError(err)) console.error("getuserbotstatus API error:", err);
            }
        })();
    }, [bot.id]);

    const handleAddToCart = async (e) => {
    e?.stopPropagation && e.stopPropagation();

    if (!requireLogin(userId, router)) return;
    if (!profile.id) {
        return;
    }

    const payload = {
        user_id: userId,
        chatbot_id: profile.id,
        order_weeks: 1
    };

    const formData = new FormData();
    formData.append('data', JSON.stringify(payload));

    try {
        const response = await fetch('/api/marketplace/addtocart.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            setCartAdded(true);
            setIsInCart(true);
            window.dispatchEvent(new Event('cartUpdated'));
            // 2 saniye sonra "Sepete eklendi" bildirimini kapatır
            setTimeout(() => {
                setCartAdded(false);
            }, 2000);
        } else {
            toast({ variant: "destructive", title: "Sepete eklenemedi", description: result.message || "Bilinmeyen bir hata oluştu." });
        }
    } catch (error) {
        console.error("Sepet hatası:", error);
        toast({ variant: "destructive", title: "Bağlantı hatası", description: "Sunucuyla bağlantı kurulamadı." });
    }
};

    const handleNotInterested = () => {
        if (!requireLogin(userId, router)) return;
        setNotInterestedConfirmOpen(true);
    };

    const confirmNotInterested = async () => {
        setNotInterestedConfirmOpen(false);

        // 2. Gönderilecek veriyi hazırla
        // bot.kategori_id veya o an hangi kategorideyse onun ID'sini alıyoruz
        const payload = {
            user_id: userId,
            category_id: bot?.kategori_id || selectedCategory?.id
        };

        const formData = new FormData();
        formData.append('data', JSON.stringify(payload));

        try {
            const response = await fetch('/api/social/adduninterest.php', {
                method: 'POST',
                body: formData
            });

            const resultText = await response.text();
            const result = JSON.parse(resultText);
            //const result = await response.json();

            if (result.success) {
                toast({ variant: "success", title: "Teşekkürler", description: "Geri bildiriminiz alındı." });
                // 3. Başarılıysa Dashboard'a yönlendir
                router.push("/dashboard");
            } else {
                toast({ variant: "destructive", title: "Bir hata oluştu", description: result.message });
            }
        } catch (error) {
            console.error("Hata:", error);
            toast({ variant: "destructive", title: "Bağlantı hatası", description: "Sunucuyla bağlantı kurulamadı." });
        }
    };

    const avatarSrc = profile.image || resolveAvatarSrc(null);
    // Faz 4 / GK-2 — ücretsiz herkese açık bot: herkes sohbet eder, satın
    // alınmaz (sunucu addToCart'ta da reddediyor). Rozet gösterilir,
    // "Sepete Ekle" gizlenir.
    const isFreePublic =
        Number(bot.is_independent) === 0 &&
        !(Number(bot.ucret_haftalik) > 0) &&
        !(Number(bot.ucret_aylik) > 0);
    // N-24 — "Sepete Ekle" yalnızca başkasının herkese açık, FİYATLI botunda.
    // Sahibin kendi botu ve özel (bağımsız) bot satın alınamaz; sunucu da
    // addToCart'ta reddediyordu ama düğme görünüp 422 hatası veriyordu.
    const canAddToCart =
        !bot.is_owner && Number(bot.is_independent) === 0 && !isFreePublic;

    // N-40 — takip/beğeni/beğenmeme isteği. Ekran yalnızca sunucu `success`
    // dönünce değişir; eskiden başarısızlıkta hiçbir şey olmuyor, kullanıcı
    // düğmeye bastığını sanıp hata görmüyordu. Başarısızlıkta null döner.
    const postRelation = async (endpoint, failTitle) => {
        try {
            const res = await fetch(endpoint, {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: new URLSearchParams({
                    data: JSON.stringify({ user_id: userId, chatbot_id: profile.id }),
                }),
            });
            const result = await res.json().catch(() => null);
            if (res.ok && result?.success) return result;
            toast({ variant: "destructive", title: failTitle, description: result?.message || `Sunucu hatası (HTTP ${res.status}).` });
        } catch (err) {
            console.error("Social API error:", err);
            toast({ variant: "destructive", title: failTitle, description: "Sunucuyla bağlantı kurulamadı." });
        }
        return null;
    };

    const followToggle = async () => {
        if (!requireLogin(userId, router)) return;
        const result = await postRelation("/api/social/followchatbot.php", "Takip güncellenemedi");
        if (!result) return;
        if (result.action === "follow") {
            setIsFollowing(true);
            setProfile((prev) => ({ ...prev, follows: prev.follows + 1 }));
        } else if (result.action === "unfollowed") {
            setIsFollowing(false);
            setProfile((prev) => ({ ...prev, follows: prev.follows - 1 }));
        }
    };

    const toggleLike = async () => {
        if (!requireLogin(userId, router)) return;
        const result = await postRelation("/api/social/likechatbot.php", "Beğeni güncellenemedi");
        if (!result) return;
        if (result.action === "liked") {
            setLiked(true);
            setLikeCount((prev) => prev + 1);
            if (disliked) { setDisliked(false); setDislikeCount((prev) => prev - 1); }
        } else if (result.action === "unliked") {
            setLiked(false);
            setLikeCount((prev) => prev - 1);
        }
    };

    const toggleDislike = async () => {
        if (!requireLogin(userId, router)) return;
        const result = await postRelation("/api/social/dislikechatbot.php", "Beğenmeme güncellenemedi");
        if (!result) return;
        if (result.action === "disliked") {
            setDisliked(true);
            setDislikeCount((prev) => prev + 1);
            if (liked) { setLiked(false); setLikeCount((prev) => prev - 1); }
        } else if (result.action === "undisliked") {
            setDisliked(false);
            setDislikeCount((prev) => prev - 1);
        }
    };

    return (
        <>
            {/* Sohbetin üstünde kalıcı olarak duran ince bir başlık — botun
                tüm pazaryeri paneli (açıklama, yorumlar, beğeni, paylaş vb.)
                artık burada değil; kullanıcının dikkati sohbette kalsın diye
                isme tıklanınca açılan bir diyaloğa taşındı. */}
            {/* Sohbetin üstünde duran başlık. İki sıra: üstte kimlik +
                birincil eylemler, altta eskiden "…" menüsünde saklı duran
                eylemlerin tamamı açıkta. Dar ekranda alt sıra yatay kayar. */}
            <div className="sticky top-0 z-10 border-b border-white/[0.06] bg-luma-base/85 backdrop-blur-xl">
                <div className="flex items-center justify-between gap-3 px-4 pt-3 md:px-16">
                    <button
                        onClick={() => setInfoOpen(true)}
                        className="group -m-1.5 flex min-w-0 items-center gap-3 rounded-xl p-1.5 text-left transition-colors hover:bg-white/[0.04] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <div className="h-10 w-10 shrink-0 overflow-hidden rounded-full ring-1 ring-fuchsia-400/25">
                            <img src={avatarSrc} alt="" className="h-full w-full object-cover" />
                        </div>
                        <div className="min-w-0">
                            <p className="flex items-center gap-1 truncate font-display text-body font-bold text-white">
                                {profile.title || bot.isim || "Chatbot"}
                                {isFreePublic && (
                                    <span className="ml-1 shrink-0 rounded-full border border-violet-400/30 bg-violet-500/10 px-2 py-0.5 text-[10px] font-semibold text-violet-300">
                                        Ücretsiz
                                    </span>
                                )}
                                <ChevronRight className="h-3.5 w-3.5 shrink-0 text-white/30 transition-transform group-hover:translate-x-0.5" />
                            </p>
                            <p className="flex items-center gap-2 truncate text-label text-white/45">
                                <CategoryBadge category={bot.kategori_id} />
                                <span className="truncate">
                                    {formatCompact(profile.follows)} takipçi · {formatCompact(bot.toplam_chats)} diyalog
                                </span>
                            </p>
                        </div>
                    </button>

                    <div className="flex shrink-0 items-center gap-2">
                        <button
                            onClick={followToggle}
                            className={cn(
                                "flex h-9 shrink-0 items-center gap-1.5 rounded-full border-[1.5px] border-transparent px-4 font-display text-label font-bold text-white transition-all duration-200 hover:scale-[1.02] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                                isFollowing
                                    ? "bg-origin-border [background-clip:padding-box,border-box] [background-image:linear-gradient(#18171F,#18171F),linear-gradient(150deg,#D946EF,#E879F9)]"
                                    : "bg-white/[0.04] hover:bg-white/[0.08]",
                            )}
                        >
                            {isFollowing && <Check className="h-3.5 w-3.5" />}
                            {isFollowing ? "Takipte" : "Takip Et"}
                        </button>
                    </div>
                </div>

                {/* Eskiden "…" menüsünün içindekilerin tamamı */}
                <div className="scrollbar-none flex items-center gap-1.5 overflow-x-auto px-4 pb-2.5 pt-2.5 md:px-16">
                    <ActionPill icon={ThumbsUp} label="Beğen" count={likeCount} active={liked} onClick={toggleLike} />
                    <ActionPill icon={ThumbsDown} label="Beğenme" count={dislikeCount} active={disliked} activeTone="rose" onClick={toggleDislike} />
                    <ActionPill icon={MessageCircle} label="Yorumlar" count={commentCount} onClick={() => setCommentOpen(true)} />
                    <ActionPill icon={Share2} label="Paylaş" onClick={() => setShareOpen(true)} />
                    <ActionPill icon={ListPlus} label="Listeye Ekle" onClick={() => setModalVisible(true)} />
                    {canAddToCart && (
                        <ActionPill
                            icon={ShoppingCart}
                            label={isInCart ? "Sepette" : "Sepete Ekle"}
                            active={isInCart}
                            disabled={isInCart}
                            onClick={handleAddToCart}
                        />
                    )}
                    <span className="mx-1 h-5 w-px shrink-0 bg-white/10" />

                    <ActionPill icon={EyeOff} label="İlgilenmiyorum" tone="muted" onClick={handleNotInterested} />
                    <ActionPill icon={Flag} label="Bildir" tone="muted" onClick={() => setReportOpen(true)} />
                </div>
            </div>

            {/* "Bot Hakkında" — pazaryeri profilinin tam hâli artık burada,
                istek üzerine açılıyor; sohbet ekranına kalıcı yük bindirmiyor. */}
            <Dialog open={infoOpen} onOpenChange={setInfoOpen}>
                <DialogContent className="max-w-md border-fuchsia-400/15">
                    <DialogHeader>
                        <div className="flex items-center gap-3.5">
                            <div className="h-14 w-14 shrink-0 overflow-hidden rounded-2xl ring-2 ring-fuchsia-400/20">
                                <img src={avatarSrc} alt="" className="h-full w-full object-cover" />
                            </div>
                            <div className="min-w-0">
                                <DialogTitle className="truncate">{profile.title}</DialogTitle>
                                <p className="text-body-sm text-white/55">
                                    {formatCompact(profile.follows)} takipçi · {formatCompact(bot.toplam_chats)} diyalog
                                </p>
                            </div>
                        </div>
                    </DialogHeader>

                    <p className="text-left text-sm leading-relaxed text-white/80">
                        {profile.description}
                    </p>

                    {pastConversations.length > 0 && (
                        <div className="flex w-full flex-col gap-2">
                            <p className="text-caption font-display font-semibold uppercase tracking-[0.1em] text-white/40">Geçmiş Sohbetler</p>
                            {pastConversations.map((item) => (
                                <button
                                    key={item.id}
                                    onClick={() => {
                                        setInfoOpen(false);
                                        router.push(`/dashboard/chat/?botId=${profile.id}&convId=${item.id}`);
                                    }}
                                    className="flex w-full items-center justify-between rounded-lg bg-white/[0.04] px-4 py-2.5 text-left transition-colors duration-200 hover:bg-white/[0.08] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                >
                                    <span className="truncate text-body-sm text-white/70">{item.conversation_name}</span>
                                    <ArrowRight className="h-4 w-4 shrink-0 text-white/40" />
                                </button>
                            ))}
                        </div>
                    )}

                </DialogContent>
            </Dialog>

            <CommentModal
                isOpen={commentOpen}
                onClose={() => setCommentOpen(false)}
                comments={comments}
                onSend={async (commentText) => {
                    if (!requireLogin(userId, router)) return { ok: false, message: "Yorum için giriş yapın." };
                    // N-34: kimlik gönderilmez (sunucu oturumdan alır); sonuç
                    // CommentModal'a döner, satırı o kesinleştirir ya da geri alır.
                    const result = await postBotComment(profile.id, commentText);
                    if (result.ok) {
                        setCommentCount(prev => prev + 1);
                    } else {
                        toast({ variant: "destructive", title: "Yorum eklenemedi", description: result.message });
                    }
                    return result;
                }}
                />

            <ShareModal isOpen={shareOpen} onClose={() => setShareOpen(false)} />
            <ReportModal isOpen={reportOpen} onClose={() => setReportOpen(false)} />
            <AddToListModal userId={userId} botId={profile.id}
                isOpen={modalVisible}
                onClose={() => setModalVisible(false)}
                lists={userLists}
            />
            <DeleteConfirmModal
                isOpen={notInterestedConfirmOpen}
                onClose={() => setNotInterestedConfirmOpen(false)}
                onConfirm={confirmNotInterested}
                title="İlgilenmiyor musunuz?"
                description="Bu kategoriyle ilgilenmediğinizi bildirmek istediğinize emin misiniz? Bu kategorideki içerikleri artık daha az göreceksiniz."
                confirmLabel="Onayla"
            />
            {cartAdded && (
                <div className="fixed bottom-6 right-6 bg-fuchsia-400 text-white px-3 py-1.5 rounded-lg text-body-sm font-medium animate-[fadeInOut_2s_ease_forwards] pointer-events-none z-[999999]">
                    Sepete eklendi!
                </div>
            )}
        </>
    )
}
