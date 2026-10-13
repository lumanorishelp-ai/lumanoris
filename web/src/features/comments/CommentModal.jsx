'use client';
import { useEffect, useState } from 'react';
import { Dialog, DialogContent, DialogTitle, DialogDescription } from '@/shared/ui/dialog';
import { MessageCircle, MessagesSquare, ArrowUp } from 'lucide-react';
import { cn } from '@/lib/utils';

export default function CommentModal({ isOpen, onClose, comments = [], onSend }) {
    const [input, setInput] = useState('');
    const [localComments, setLocalComments] = useState(comments);
    const [expandedComments, setExpandedComments] = useState(new Set());
    const [sending, setSending] = useState(false);
    const [sendError, setSendError] = useState('');

    useEffect(() => {
        setLocalComments(comments);
    }, [comments]);

    /* N-34 — yorum eskiden istek sonucuna bakılmadan "Siz · Şimdi" olarak
       listeye ekleniyordu; sunucu reddettiğinde de listede kalıyordu.
       Şimdi: satır "Gönderiliyor…" olarak eklenir, `onSend` { ok: true }
       dönerse kesinleşir; aksi hâlde satır geri alınır, yazılan metin girişe
       geri konur ve hata mesajı pencerede kalır. `onSend` bu sözleşmeyi
       uygulamıyorsa (dönüş yoksa) yorum kaydedilmemiş sayılır. */
    const handleSend = async () => {
        const trimmed = input.trim();
        if (!trimmed || sending) return;

        const pendingKey = `pending-${Date.now()}`;
        const pending = {
            _key: pendingKey,
            comment: trimmed,
            comment_owner: 'Siz',
            commented_at: 'Gönderiliyor…'
        };

        setSendError('');
        setSending(true);
        setLocalComments(prev => [...prev, pending]);
        setInput('');

        let result;
        try {
            result = await onSend?.(trimmed);
        } catch (err) {
            result = { ok: false, message: err?.message };
        }

        if (result?.ok) {
            setLocalComments(prev => prev.map(c => (c._key === pendingKey ? { ...c, commented_at: 'Şimdi' } : c)));
        } else {
            setLocalComments(prev => prev.filter(c => c._key !== pendingKey));
            setInput(trimmed);
            setSendError(result?.message || 'Yorum kaydedilemedi.');
        }
        setSending(false);
    };

    const toggleCommentExpansion = (index) => {
        setExpandedComments(prev => {
            const newSet = new Set(prev);
            if (newSet.has(index)) {
                newSet.delete(index);
            } else {
                newSet.add(index);
            }
            return newSet;
        });
    };

    // HATAYI ENGELLEYEN GÜVENLİ FONKSİYON
    const truncateText = (text, maxLines = 3) => {
        // Eğer text string değilse veya boşsa hatayı önlemek için hemen boş değer dön
        if (!text || typeof text !== 'string') {
            return { text: '', isTruncated: false };
        }

        const words = text.split(' ');
        const maxWords = maxLines * 8;

        if (words.length <= maxWords) {
            return { text, isTruncated: false };
        }

        return {
            text: words.slice(0, maxWords).join(' ') + '...',
            isTruncated: true
        };
    };

    // Avatar icin isimden turetilen sabit bir ton: her yorumcu kendi rengini
    // korusun, eskiden hepsi ayni bos gradient daireydi.
    const avatarTint = (name) => {
        const tints = [
            'from-fuchsia-500 to-violet-500',
            'from-violet-500 to-indigo-500',
            'from-cyan-500 to-blue-500',
            'from-amber-500 to-orange-500',
            'from-emerald-500 to-teal-500',
            'from-rose-500 to-pink-500',
        ];
        let sum = 0;
        for (let i = 0; i < name.length; i += 1) sum += name.charCodeAt(i);
        return tints[sum % tints.length];
    };

    const canSend = Boolean(input.trim()) && !sending;

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-[440px] border-white/10 bg-[#0c0c14] p-0">
                <div className="flex items-center gap-3 border-b border-white/[0.06] px-5 py-4">
                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-fuchsia-500/20 bg-fuchsia-500/10 text-fuchsia-400">
                        <MessageCircle className="h-[18px] w-[18px]" />
                    </span>
                    <div className="min-w-0 text-left">
                        <DialogTitle className="text-base font-semibold text-white">Yorumlar</DialogTitle>
                        <DialogDescription className="mt-0.5 text-xs text-white/45">
                            {localComments.length > 0
                                ? `${localComments.length} yorum`
                                : 'Henüz yorum yok'}
                        </DialogDescription>
                    </div>
                </div>

                <div className="max-h-[340px] space-y-1 overflow-y-auto overscroll-contain px-3 py-3">
                    {localComments.length === 0 ? (
                        <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-white/10 px-4 py-10 text-center">
                            <MessagesSquare className="h-6 w-6 text-white/25" />
                            <p className="text-xs text-white/40">
                                İlk yorumu sen bırak.
                            </p>
                        </div>
                    ) : (
                        localComments.map((comment, index) => {
                            // Farkli uclardan farkli anahtarlar geliyor; ikisini de karsila.
                            const commentBody = comment.comment || comment.text || '';
                            const owner = comment.comment_owner || comment.author || 'Bilinmiyor';
                            const date = comment.commented_at || comment.date || '';

                            const { text, isTruncated } = truncateText(commentBody);
                            const isExpanded = expandedComments.has(index);

                            return (
                                <div
                                    key={index}
                                    className="flex items-start gap-3 rounded-xl px-2.5 py-2.5 transition-colors duration-150 hover:bg-white/[0.04]"
                                >
                                    <span
                                        className={cn(
                                            'flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gradient-to-br text-[11px] font-bold uppercase text-white',
                                            avatarTint(owner),
                                        )}
                                    >
                                        {owner.charAt(0)}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-baseline gap-2">
                                            <span className="truncate text-xs font-semibold text-white/85">{owner}</span>
                                            {date && (
                                                <span className="shrink-0 text-[11px] text-white/30">{date}</span>
                                            )}
                                        </div>
                                        <p className="mt-1 whitespace-pre-wrap break-words text-[13px] leading-relaxed text-white/70">
                                            {isExpanded ? commentBody : text}
                                        </p>
                                        {isTruncated && (
                                            <button
                                                type="button"
                                                onClick={() => toggleCommentExpansion(index)}
                                                className="mt-1 text-[11px] font-semibold text-fuchsia-400 transition-colors hover:text-fuchsia-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                            >
                                                {isExpanded ? 'Daha az göster' : 'Devamını oku'}
                                            </button>
                                        )}
                                    </div>
                                </div>
                            );
                        })
                    )}
                </div>

                <div className="border-t border-white/[0.06] px-5 py-4">
                    {sendError && (
                        <p role="alert" className="mb-2.5 rounded-lg border border-rose-500/20 bg-rose-500/10 px-3 py-2 text-xs text-rose-300">
                            Yorum eklenemedi — {sendError}
                        </p>
                    )}
                    <div className="flex items-center gap-2 rounded-xl border border-white/10 bg-white/[0.03] p-1.5 pl-3.5 transition-colors focus-within:border-fuchsia-400/40">
                        <input
                            type="text"
                            placeholder="Yorum bırak..."
                            value={input}
                            data-focus-managed
                            onChange={(e) => setInput(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    handleSend();
                                }
                            }}
                            className="min-w-0 flex-1 border-none bg-transparent text-sm text-white outline-none placeholder:text-white/35"
                        />
                        <button
                            type="button"
                            onClick={handleSend}
                            disabled={!canSend}
                            aria-label="Yorumu gönder"
                            className={cn(
                                'flex h-8 w-8 shrink-0 items-center justify-center rounded-lg transition-all duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                canSend
                                    ? 'bg-gradient-btn text-white hover:brightness-110'
                                    : 'cursor-not-allowed bg-white/[0.06] text-white/25',
                            )}
                        >
                            <ArrowUp className="h-4 w-4" strokeWidth={2.5} />
                        </button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
