'use client';
import Image from 'next/image';
import { useEffect, useRef, useState } from 'react';
import ShareModal from "@/features/sharing/ShareModal";
import ReportModal from "@/features/moderation/ReportModal";
import AddToListModal from "@/features/lists/AddToListModal";
import CommentModal from "@/features/comments/CommentModal";
import { useRouter } from 'next/navigation';
import { Dialog, DialogContent, DialogTitle } from '@/shared/ui/dialog';
import { resolveAvatarSrc } from '@/shared/lib/image';
import { cn } from '@/lib/utils';
import { ThumbsUp, ThumbsDown } from 'lucide-react';
import { toast } from '@/shared/hooks/use-toast';

export default function DialogueModal({ isOpen, onClose, selectedHistory }) {
    const inputRef = useRef(null);
    const outputRef = useRef(null);
    const router = useRouter();
    const [shareOpen, setShareOpen] = useState(false);
    const [reportOpen, setReportOpen] = useState(false);
    const [modalVisible, setModalVisible] = useState(false);
    const [commentOpen, setCommentOpen] = useState(false);
    const [liked, setLiked] = useState(false);
    const [disliked, setDisliked] = useState(false);
    const [likeCount, setLikeCount] = useState(1500);
    const [dislikeCount, setDislikeCount] = useState(20);
    const [comments, setComments] = useState([]);
    const [userId, setUserId] = useState();
    const [commentCount, setCommentCount] = useState(0);

    async function checkSession() {
      try {
        const res = await fetch("/api/auth/sessioncheck.php", {
          credentials: "include", // cookie'yi gönder
        });
        const resultText = await res.text();
        const result = JSON.parse(resultText);

        if (result.authenticated) {
          setUserId(result.user_id);
        } else {
          // router.push("/login"); // Giriş kontrolü geçici olarak devre dışı - proje sonunda düzeltilecek
        }
      } catch (err) {
        console.error("Session check error:", err);
        // router.push("/login"); // Giriş kontrolü geçici olarak devre dışı - proje sonunda düzeltilecek
      }
    }

    // Both endpoints read $_GET (session provides identity) — previously
    // sent as a POST body, so $_GET was always empty and the "did I already
    // react" check never resolved.
    const checkUserLike = async () => {
      try {
        const res = await fetch(`/api/note/diduserlike2.php?dialog_id=${selectedHistory.id}`);
        const result = await res.json();

        if (result.success) {
          setLiked(result.didLike);
        }
      } catch (err) {
        console.error("diduserlike API error:", err);
      }
    };

    const checkUserDisLike = async () => {
      try {
        const res = await fetch(`/api/note/diduserdislike2.php?dialog_id=${selectedHistory.id}`);
        const result = await res.json();

        if (result.success) {
          setDisliked(result.didDisLike);
        }
      } catch (err) {
        console.error("diduserdislike API error:", err);
      }
    };

    useEffect(() => {
        checkSession();
    }, []);

    useEffect(() => {
        const dialogId = selectedHistory.id;
        if (dialogId) {
            fetch(`/api/note/getdialoginteracts.php?id=${dialogId}`)
                .then((response) => response.json())
                .then((data) => {
                    setLikeCount(data.dialog.likes);
                    setDislikeCount(data.dialog.dislikes);
                    setComments(data.comments);
                    setCommentCount(data.comments.length);
                })
                .catch((error) => {
                    console.error("Hata:", error);
                });
        }

        // userId sessioncheck'ten asenkron gelir; gelmeden bu kontrolleri
        // çalıştırmak her zaman "beğenilmemiş/beğenilmemiş" göstermeye
        // neden oluyordu ve userId geldiğinde effect tekrar çalışmıyordu.
        if (!userId) return;
        checkUserLike();
        checkUserDisLike();

    },[selectedHistory, userId]);

    const handleCopy = (ref) => {
        if (!ref.current) return;
        const text = ref.current.innerText;
        navigator.clipboard.writeText(text)
            .then(() => {
            })
            .catch((err) => {
                console.error('Kopyalama başarısız:', err);
            });
    };

    const copyIcon = (
        <svg width="15" height="15" viewBox="0 0 17 17" fill="none" xmlns="http://www.w3.org/2000/svg">
            <g clipPath="url(#clip0_7960_14766)">
                <path d="M14.1667 6.375H7.79167C7.00926 6.375 6.375 7.00926 6.375 7.79167V14.1667C6.375 14.9491 7.00926 15.5833 7.79167 15.5833H14.1667C14.9491 15.5833 15.5833 14.9491 15.5833 14.1667V7.79167C15.5833 7.00926 14.9491 6.375 14.1667 6.375Z" stroke="url(#paint0_linear_7960_14766)" strokeLinecap="round" strokeLinejoin="round" />
                <path d="M3.54102 10.6243H2.83268C2.45696 10.6243 2.09662 10.4751 1.83095 10.2094C1.56527 9.94374 1.41602 9.58341 1.41602 9.20768V2.83268C1.41602 2.45696 1.56527 2.09662 1.83095 1.83095C2.09662 1.56527 2.45696 1.41602 2.83268 1.41602H9.20768C9.58341 1.41602 9.94374 1.56527 10.2094 1.83095C10.4751 2.09662 10.6243 2.45696 10.6243 2.83268V3.54102" stroke="#E879F9" strokeLinecap="round" strokeLinejoin="round" />
            </g>
            <defs>
                <linearGradient id="paint0_linear_7960_14766" x1="16.3709" y1="17.7422" x2="6.4982" y2="4.87085" gradientUnits="userSpaceOnUse">
                    <stop offset="0.211538" stopColor="#A78BFA" />
                    <stop offset="1" stopColor="#E879F9" />
                </linearGradient>
                <clipPath id="clip0_7960_14766">
                    <rect width="17" height="17" fill="white" />
                </clipPath>
            </defs>
        </svg>
    );

    return (
        <>
            <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
                <DialogContent className="max-w-[520px] bg-luma-card border-transparent p-6">
                    <DialogTitle className="mb-4 text-center text-title-sm">Deftere Kaydedilmiş Diyalog</DialogTitle>

                    <div className="flex flex-col gap-4">
                        <div className="rounded-xl bg-luma-input p-4">
                            <div className="mb-2 flex items-center justify-between">
                                <span className="text-xs font-semibold uppercase tracking-wide text-white/50">Girdi</span>
                                <button
                                    onClick={() => handleCopy(inputRef)}
                                    className="rounded-lg p-1 transition-colors hover:bg-white/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    aria-label="Girdiyi kopyala"
                                >
                                    {copyIcon}
                                </button>
                            </div>
                            <div ref={inputRef} className="text-body leading-relaxed text-white">
                                <p>{selectedHistory.input_message}</p>
                            </div>
                        </div>

                        <div className="rounded-xl bg-luma-input p-4">
                            <div className="mb-2 flex items-center justify-between">
                                <span className="text-xs font-semibold uppercase tracking-wide text-white/50">Çıktı</span>
                                <button
                                    onClick={() => handleCopy(outputRef)}
                                    className="rounded-lg p-1 transition-colors hover:bg-white/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    aria-label="Çıktıyı kopyala"
                                >
                                    {copyIcon}
                                </button>
                            </div>
                            <div ref={outputRef} className="text-body leading-relaxed text-white">
                                <p>{selectedHistory.output_message}</p>
                            </div>
                        </div>

                        <div className="flex flex-wrap items-center gap-4 rounded-xl bg-luma-input px-4 py-3 text-white/70">
                            <div className="flex items-center gap-2">
                                <button
                                    onClick={async () => {
                                        try {
                                        const res = await fetch("/api/note/likedialog.php", {
                                            method: "POST",
                                            headers: { "Content-Type": "application/x-www-form-urlencoded" },
                                            body: new URLSearchParams({
                                            data: JSON.stringify({
                                                user_id: userId,
                                                dialog_id: selectedHistory.id,
                                            }),
                                            }),
                                        });
                                        const result = await res.json();

                                        if (result.success) {
                                            if (result.action === "liked") {
                                            setLiked(true);
                                            setLikeCount((prev) => prev + 1);
                                            if (disliked) {
                                                setDisliked(false);
                                                setDislikeCount((prev) => prev - 1);
                                            }
                                            } else if (result.action === "unliked") {
                                            setLiked(false);
                                            setLikeCount((prev) => prev - 1);
                                            }
                                        }
                                        } catch (err) {
                                        console.error("Like API error:", err);
                                        }
                                    }}
                                    className={cn("transition-transform hover:scale-110 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring rounded-md", liked && "text-fuchsia-400")}
                                    aria-label="Beğen"
                                >
                                    <ThumbsUp className="h-[22px] w-[22px]" strokeWidth={1.8} />
                                </button>
                                <span className="text-sm">{likeCount}</span>
                            </div>
                            <div className="h-5 w-px bg-white/15" />
                            <div className="flex items-center gap-2">
                                <button
                                    onClick={async () => {
                                        try {
                                        const res = await fetch("/api/note/dislikedialog.php", {
                                            method: "POST",
                                            headers: { "Content-Type": "application/x-www-form-urlencoded" },
                                            body: new URLSearchParams({
                                            data: JSON.stringify({
                                                user_id: userId,
                                                dialog_id: selectedHistory.id,
                                            }),
                                            }),
                                        });
                                        const result = await res.json();

                                        if (result.success) {
                                            if (result.action === "disliked") {
                                            setDisliked(true);
                                            setDislikeCount((prev) => prev + 1);
                                            if (disliked) {
                                                setLiked(false);
                                                setLikeCount((prev) => prev - 1);
                                            }
                                            } else if (result.action === "undisliked") {
                                            setDisliked(false);
                                            setDislikeCount((prev) => prev - 1);
                                            }
                                        }
                                        } catch (err) {
                                        console.error("Like API error:", err);
                                        }
                                    }}
                                    className={cn("transition-transform hover:scale-110 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring rounded-md", disliked && "text-rose-400")}
                                    aria-label="Beğenme"
                                >
                                    <ThumbsDown className="h-[22px] w-[22px]" strokeWidth={1.8} />
                                </button>
                                <span className="text-sm">{dislikeCount}</span>
                            </div>

                            <button
                                onClick={(e) => { e.stopPropagation(); setShareOpen(true); }}
                                className="flex items-center gap-1.5 text-sm transition-colors hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring rounded-md"
                            >
                                <svg width="20" height="19" viewBox="0 0 25 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path opacity="0.5" d="M19.7988 6C19.7988 5.46957 19.5881 4.96086 19.213 4.58579C18.838 4.21071 18.3293 4 17.7988 4C17.2684 4 16.7597 4.21071 16.3846 4.58579C16.0095 4.96086 15.7988 5.46957 15.7988 6C15.7988 6.53043 16.0095 7.03914 16.3846 7.41421C16.7597 7.78929 17.2684 8 17.7988 8C18.3293 8 18.838 7.78929 19.213 7.41421C19.5881 7.03914 19.7988 6.53043 19.7988 6ZM8.79883 12C8.79883 11.4696 8.58811 10.9609 8.21304 10.5858C7.83797 10.2107 7.32926 10 6.79883 10C6.2684 10 5.75969 10.2107 5.38461 10.5858C5.00954 10.9609 4.79883 11.4696 4.79883 12C4.79883 12.5304 5.00954 13.0391 5.38461 13.4142C5.75969 13.7893 6.2684 14 6.79883 14C7.32926 14 7.83797 13.7893 8.21304 13.4142C8.58811 13.0391 8.79883 12.5304 8.79883 12ZM17.7988 16C18.3293 16 18.838 16.2107 19.213 16.5858C19.5881 16.9609 19.7988 17.4696 19.7988 18C19.7988 18.5304 19.5881 19.0391 19.213 19.4142C18.838 19.7893 18.3293 20 17.7988 20C17.2684 20 16.7597 19.7893 16.3846 19.4142C16.0095 19.0391 15.7988 18.5304 15.7988 18C15.7988 17.4696 16.0095 16.9609 16.3846 16.5858C16.7597 16.2107 17.2684 16 17.7988 16Z" fill="currentColor" />
                                    <path fillRule="evenodd" clipRule="evenodd" d="M17.7997 3.25C18.2253 3.24999 18.645 3.34875 19.026 3.5385C19.4069 3.72825 19.7386 4.00381 19.9949 4.34351C20.2512 4.6832 20.4252 5.07775 20.5032 5.49611C20.5812 5.91447 20.561 6.34522 20.4443 6.75445C20.3275 7.16369 20.1174 7.54024 19.8304 7.85448C19.5434 8.16872 19.1874 8.41207 18.7904 8.56537C18.3934 8.71867 17.9663 8.77775 17.5426 8.73795C17.1189 8.69815 16.7102 8.56055 16.3487 8.336C16.32 8.35485 16.2903 8.37221 16.2597 8.388L9.54671 11.868C9.55058 11.956 9.55058 12.044 9.54671 12.132L9.55971 12.14L15.9757 15.942C16.4553 15.5168 17.0683 15.2725 17.7089 15.2512C18.3494 15.23 18.9773 15.4331 19.484 15.8256C19.9907 16.218 20.3444 16.7751 20.484 17.4007C20.6236 18.0262 20.5404 18.6808 20.2486 19.2515C19.9569 19.8221 19.475 20.273 18.8862 20.5261C18.2974 20.7793 17.6387 20.8188 17.0239 20.6379C16.409 20.457 15.8767 20.0671 15.5188 19.5354C15.1609 19.0037 14.9999 18.3637 15.0637 17.726L8.71971 13.968C8.33218 14.3463 7.84159 14.6017 7.30946 14.7023C6.77734 14.8028 6.22737 14.744 5.72852 14.5332C5.22968 14.3225 4.80417 13.9691 4.50536 13.5175C4.20654 13.0658 4.04773 12.536 4.04883 11.9945C4.04994 11.4529 4.21091 10.9238 4.51156 10.4734C4.81221 10.0229 5.23915 9.67131 5.73885 9.46257C6.23855 9.25384 6.78875 9.19728 7.32046 9.30001C7.85218 9.40273 8.34172 9.66016 8.72771 10.04L15.1427 6.714C15.0331 6.30635 15.0188 5.87895 15.101 5.46489C15.1831 5.05084 15.3595 4.66125 15.6164 4.32631C15.8733 3.99137 16.2038 3.72007 16.5825 3.53342C16.9611 3.34678 17.3776 3.2498 17.7997 3.25ZM16.7437 17.331C16.7157 17.4105 16.6777 17.4861 16.6307 17.556C16.5164 17.8555 16.5216 18.1876 16.6451 18.4834C16.7687 18.7792 17.0012 19.0163 17.2946 19.1456C17.588 19.2748 17.9198 19.2864 18.2215 19.1779C18.5232 19.0694 18.7717 18.8492 18.9156 18.5627C19.0595 18.2761 19.0878 17.9453 18.9947 17.6385C18.9015 17.3317 18.6941 17.0725 18.4152 16.9143C18.1363 16.7562 17.8073 16.7112 17.4962 16.7888C17.1851 16.8663 16.9157 17.0604 16.7437 17.331ZM19.0497 6C19.0497 5.66848 18.918 5.35054 18.6836 5.11612C18.4492 4.8817 18.1312 4.75 17.7997 4.75C17.4682 4.75 17.1502 4.8817 16.9158 5.11612C16.6814 5.35054 16.5497 5.66848 16.5497 6C16.5497 6.33152 16.6814 6.64946 16.9158 6.88388C17.1502 7.1183 17.4682 7.25 17.7997 7.25C18.1312 7.25 18.4492 7.1183 18.6836 6.88388C18.918 6.64946 19.0497 6.33152 19.0497 6ZM8.04971 12C8.04971 11.6685 7.91801 11.3505 7.68359 11.1161C7.44917 10.8817 7.13123 10.75 6.79971 10.75C6.46819 10.75 6.15025 10.8817 5.91583 11.1161C5.68141 11.3505 5.54971 11.6685 5.54971 12C5.54971 12.3315 5.68141 12.6495 5.91583 12.8839C6.15025 13.1183 6.46819 13.25 6.79971 13.25C7.13123 13.25 7.44917 13.1183 7.68359 12.8839C7.91801 12.6495 8.04971 12.3315 8.04971 12Z" fill="currentColor" />
                                </svg>
                                Paylaş
                            </button>

                            <button
                                onClick={(e) => { e.stopPropagation(); setCommentOpen(true); }}
                                className="flex items-center gap-1.5 text-sm transition-colors hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring rounded-md"
                            >
                                <svg width="20" height="19" viewBox="0 0 25 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path opacity="0.2" d="M21.5 6V18C21.5 18.1989 21.421 18.3897 21.2803 18.5303C21.1397 18.671 20.9489 18.75 20.75 18.75H8L4.73281 21.5728C4.62357 21.6647 4.49038 21.7235 4.34887 21.7423C4.20737 21.7612 4.06343 21.7392 3.93397 21.6791C3.80451 21.6189 3.6949 21.523 3.61803 21.4028C3.54116 21.2825 3.50021 21.1428 3.5 21V6C3.5 5.80109 3.57902 5.61032 3.71967 5.46967C3.86032 5.32902 4.05109 5.25 4.25 5.25H20.75C20.9489 5.25 21.1397 5.32902 21.2803 5.46967C21.421 5.61032 21.5 5.80109 21.5 6Z" fill="currentColor" />
                                    <path d="M20.75 4.5H4.25003C3.8522 4.5 3.47067 4.65804 3.18937 4.93934C2.90806 5.22064 2.75003 5.60218 2.75003 6V21C2.7483 21.286 2.82921 21.5665 2.98305 21.8076C3.13689 22.0488 3.3571 22.2404 3.61721 22.3594C3.81543 22.4517 4.03138 22.4997 4.25003 22.5C4.60214 22.4991 4.94256 22.3735 5.21096 22.1456L5.2194 22.1391L8.28128 19.5H20.75C21.1479 19.5 21.5294 19.342 21.8107 19.0607C22.092 18.7794 22.25 18.3978 22.25 18V6C22.25 5.60218 22.092 5.22064 21.8107 4.93934C21.5294 4.65804 21.1479 4.5 20.75 4.5ZM20.75 18H8.00003C7.81994 18.0001 7.6459 18.065 7.50971 18.1828L4.25003 21V6H20.75V18Z" fill="currentColor" />
                                </svg>
                                {comments.length} Yorum
                            </button>

                            <button
                                onClick={(e) => { e.stopPropagation(); setReportOpen(true); }}
                                className="flex items-center gap-1.5 text-sm transition-colors hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring rounded-md"
                            >
                                <svg width="16" height="15" viewBox="0 0 19 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <g clipPath="url(#clip0_7960_14816)">
                                        <path opacity="0.3" d="M6.50234 2L2.40234 6.1V11.9L6.50234 16H12.3023L16.4023 11.9V6.1L12.3023 2H6.50234ZM9.40234 14C8.85234 14 8.40234 13.55 8.40234 13C8.40234 12.45 8.85234 12 9.40234 12C9.95234 12 10.4023 12.45 10.4023 13C10.4023 13.55 9.95234 14 9.40234 14ZM10.4023 11H8.40234V4H10.4023V11Z" fill="currentColor" />
                                        <path d="M13.1304 0H5.67039L0.400391 5.27V12.73L5.67039 18H13.1304L18.4004 12.73V5.27L13.1304 0ZM16.4004 11.9L12.3004 16H6.50039L2.40039 11.9V6.1L6.50039 2H12.3004L16.4004 6.1V11.9Z" fill="currentColor" />
                                        <path d="M9.40234 14C9.95463 14 10.4023 13.5523 10.4023 13C10.4023 12.4477 9.95463 12 9.40234 12C8.85006 12 8.40234 12.4477 8.40234 13C8.40234 13.5523 8.85006 14 9.40234 14Z" fill="currentColor" />
                                        <path d="M8.40234 4H10.4023V11H8.40234V4Z" fill="currentColor" />
                                    </g>
                                    <defs>
                                        <clipPath id="clip0_7960_14816">
                                            <rect width="18" height="18" fill="white" transform="translate(0.400391)" />
                                        </clipPath>
                                    </defs>
                                </svg>
                                Bildir
                            </button>
                        </div>

                        <div
                            onClick={() => {
                                const username = selectedHistory.owner_kullanici_adi;
                                const role = selectedHistory.chatbot_isim;
                                const avatarUrl = selectedHistory.chatbot_profil_fotografi;

                                const params = new URLSearchParams({
                                    username,
                                    role,
                                    avatar: avatarUrl,
                                });

                                router.push(`/dashboard/chat?${params.toString()}`);
                            }}
                            className="flex cursor-pointer items-center gap-3 rounded-xl p-2 text-left transition-colors hover:bg-white/5"
                        >
                            <div className="h-12 w-12 shrink-0 overflow-hidden rounded-full">
                                <Image src={resolveAvatarSrc(selectedHistory.chatbot_profil_fotografi)} alt="avatar" width={48} height={48} className="h-full w-full object-cover" />
                            </div>
                            <div>
                                <p className="font-display text-body font-semibold text-white">{selectedHistory.chatbot_isim}</p>
                                <p className="text-xs text-white/50">{selectedHistory.owner_kullanici_adi}</p>
                            </div>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
            <ShareModal isOpen={shareOpen} urlId={selectedHistory.conversation_chatbot_id} onClose={() => setShareOpen(false)} />
            <ReportModal isOpen={reportOpen} onClose={() => setReportOpen(false)} />
            <AddToListModal
                isOpen={modalVisible}
                onClose={() => setModalVisible(false)}
                lists={[]}
            />
            <CommentModal
                isOpen={commentOpen}
                onClose={() => setCommentOpen(false)}
                comments={comments}
                onSend={async (commentText) => {
                    // N-34: kimlik gönderilmez (sunucu oturumdan alır); sonuç CommentModal'a döner.
                    const payload = {
                        dialog_id: selectedHistory.id,
                        comment: commentText
                    };

                    try
                    {
                        const formData = new FormData();
                        formData.append("data", JSON.stringify(payload));

                        const res = await fetch("/api/note/addcomment2.php", {
                            method: "POST",
                            body: formData
                        });
                        const resultText = await res.text();
                        const result = JSON.parse(resultText);
                        if (result.success)
                        {
                            setCommentCount(prev => prev + 1);
                            return { ok: true };
                        }
                        toast({ variant: "destructive", title: "Yorum eklenemedi", description: result.message });
                        return { ok: false, message: result.message };
                    }
                    catch (err)
                    {
                        toast({ variant: "destructive", title: "Yorum eklenemedi", description: err.message });
                        return { ok: false, message: err.message };
                    }
                }}
            />
        </>
    );
}
