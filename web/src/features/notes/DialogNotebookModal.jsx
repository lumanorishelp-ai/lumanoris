"use client";
import { useState } from "react";
import { Dialog, DialogContent, DialogTitle, DialogDescription } from '@/shared/ui/dialog';
import { Button } from '@/shared/ui/button';
import { toast } from '@/shared/hooks/use-toast';
import { BookMarked } from 'lucide-react';

export default function DialogNotebookModal({ userId, botId, inputMessage, outputMessage, messageId, isOpen, onClose, onPublish }) {
    const [title, setTitle] = useState("");
    const [showFeedback, setShowFeedback] = useState(false);
    const [isSubmitting, setIsSubmitting] = useState(false); // Çift tıklamayı önlemek için

    const handlePublish = async () => {
        if (!title.trim() || isSubmitting) return;
        setIsSubmitting(true);

        try {
            // N-30 — soru/cevap METNİ gönderilmiyor: sunucu, kullanıcının kendi
            // geçmişindeki bu bot mesajından ikisini de DB'den kopyalıyor ve
            // bota sohbet erişimini denetliyor. inputMessage/outputMessage
            // yalnızca önizleme için.
            const payload = {
                message_id: messageId,
                name: title,
            };

            const formData = new FormData();
            formData.append("data", JSON.stringify(payload));

            const res = await fetch("/api/note/adddialogbook.php", {
                method: "POST",
                body: formData
            });

            const restext = await res.text();
            const result = JSON.parse(restext);

             if (result.success) {
                if (onPublish) onPublish(title);
                setTitle("");
                setShowFeedback(true);
                setTimeout(() => {
                    setShowFeedback(false);
                    onClose();
                }, 1800);
            } else {
                toast({ variant: "destructive", title: "Hata", description: result.message });
            }
        }
        catch (err) {
            console.error("Yayınlama hatası:", err);
            toast({ variant: "destructive", title: "Bir hata oluştu." });
        } finally {
            setIsSubmitting(false);
        }
    };

    return (
        <>
            {/* Feedback Badge */}
            {showFeedback && (
                <div className="fixed bottom-6 right-6 px-3 py-1.5 rounded-lg bg-fuchsia-400 text-white text-body-sm font-medium pointer-events-none z-[999999] animate-[fadeInOut_2s_ease_forwards]">
                    Başarıyla yayınlandı ✅
                </div>
            )}

            {/* Tasarım, yorum/paylaş pop-up'larıyla (features/comments/
                CommentModal.jsx) aynı iskelete getirildi: `p-0` gövde,
                ikon + başlık + alt başlıktan oluşan ayrılmış bir üst şerit,
                dolgulu içerik ve üstten çizgiyle ayrılmış eylem satırı.
                Önceki hâli `text-center` + `bg-luma-card` ile diğer
                pop-up'lardan görünür biçimde ayrışıyordu. */}
            <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
                <DialogContent className="max-w-[440px] border-white/10 bg-[#0c0c14] p-0">
                    <div className="flex items-center gap-3 border-b border-white/[0.06] px-5 py-4">
                        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-fuchsia-500/20 bg-fuchsia-500/10 text-fuchsia-400">
                            <BookMarked className="h-[18px] w-[18px]" />
                        </span>
                        <div className="min-w-0 text-left">
                            <DialogTitle className="text-base font-semibold text-white">Diyalog Defterine Ekle</DialogTitle>
                            <DialogDescription className="mt-0.5 text-xs text-white/45">
                                Başlık verin ve diyaloğu Diyalog Defteri sayfasında paylaşın.
                            </DialogDescription>
                        </div>
                    </div>

                    <div className="space-y-4 px-5 py-4">
                        <div className="flex w-full items-center rounded-xl border border-white/[0.08] bg-white/[0.03] px-4 py-3 focus-within:border-fuchsia-500/40">
                            <input
                                type="text"
                                placeholder="Diyalog başlığı"
                                value={title}
                                onChange={(e) => setTitle(e.target.value)}
                                className="w-full bg-transparent text-sm text-white placeholder:text-white/35 focus:outline-none"
                            />
                        </div>

                        {/* The copy above says "share", but nothing told the user
                            that the message pair itself — not just the title —
                            becomes readable by every other user. Spell it out at
                            the point of no return. */}
                        <div className="rounded-xl border border-amber-400/25 bg-amber-400/[0.07] px-4 py-3 text-left">
                            <p className="text-xs leading-relaxed text-amber-200/90">
                                <span className="font-semibold">Bu bir herkese açık paylaşımdır.</span>{" "}
                                Yayınladığınızda sorunuz ve yapay zekânın yanıtı, kullanıcı adınızla
                                birlikte Diyalog Defteri sayfasında tüm kullanıcılara görünür olur.
                            </p>
                        </div>
                    </div>

                    <div className="flex items-center justify-end gap-2 border-t border-white/[0.06] px-5 py-3.5">
                        <Button
                            onClick={onClose}
                            variant="secondary"
                            className="h-auto border border-transparent bg-white/[0.06] px-4 py-2.5 text-xs hover:bg-white/[0.1]"
                        >
                            İptal
                        </Button>
                        <Button
                            onClick={handlePublish}
                            disabled={!title.trim() || isSubmitting}
                            className="h-auto px-4 py-2.5 text-xs disabled:opacity-50"
                        >
                            {isSubmitting ? "Yayınlanıyor…" : "Yayınla"}
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}
