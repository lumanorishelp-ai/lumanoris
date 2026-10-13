'use client';
import { useState, useEffect } from 'react';
import Link from 'next/link';
import { Dialog, DialogContent, DialogTitle, DialogDescription } from '@/shared/ui/dialog';
import { Button } from '@/shared/ui/button';
import { Check, Globe2, Store, ArrowLeft } from 'lucide-react';

/**
 * Madde 10 — bağımsız botu yayınlamanın iki yolu:
 *
 *   • Yayınla: bot ücretsiz herkese açık olur (GK-1 / GK-2). Satıcı kaydı
 *     gerekmez; sunucu fiyatsız publishChatbot'u herkese açık limitiyle
 *     sınırlar. Herkes sohbet edebilir, mesajlar kullanıcıların günlük Luma
 *     Coin'inden düşer.
 *   • Pazaryerine Kaydet: ücretli satış şirket başvurusu ister (madde 3). Bu
 *     seçenek YALNIZCA bilgi verir ve başvuru sayfasına götürür; satıcı
 *     durumu yaratmaz, B1'i örtmez.
 *
 * Eskiden bu modal satıcı kaydı olmayan herkese eski kayıt sihirbazını
 * (SellerOnboardingWizard) açıyor, kayıtlı satıcıya ise fiyat soruyordu —
 * yani "herkese açık" ile "satılık" aynı şeydi.
 */
export default function PublishModal({ isOpen, onClose, onPublished, botId }) {
    const [step, setStep] = useState('choose'); // choose | marketplace
    const [publishing, setPublishing] = useState(false);
    const [showFeedback, setShowFeedback] = useState(false);
    const [errorMsg, setErrorMsg] = useState('');

    useEffect(() => {
        if (isOpen) {
            setStep('choose');
            setErrorMsg('');
            setPublishing(false);
        }
    }, [isOpen]);

    const handlePublish = async () => {
        setPublishing(true);
        setErrorMsg('');
        try {
            const formData = new FormData();
            // Fiyat GÖNDERİLMİYOR: sunucu bunu ücretsiz herkese açık yayın sayar.
            formData.append('data', JSON.stringify({ id: botId }));
            const res = await fetch('/api/chatbot/publishchatbot.php', {
                method: 'POST',
                body: formData,
                credentials: 'include',
            });
            const result = await res.json();
            if (result.success) {
                setShowFeedback(true);
                setTimeout(() => {
                    setShowFeedback(false);
                    onPublished?.();
                    onClose();
                }, 1500);
            } else {
                setErrorMsg(result.message || 'Yayınlama başarısız oldu.');
            }
        } catch (err) {
            setErrorMsg('Sunucuya bağlanılamadı.');
        } finally {
            setPublishing(false);
        }
    };

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-[440px] bg-luma-card border-transparent p-6">
                {showFeedback && (
                    <div className="absolute -top-3 left-1/2 flex -translate-x-1/2 items-center gap-1.5 rounded-full bg-emerald-500 px-3 py-1.5 text-xs font-semibold text-white shadow-glow">
                        <Check className="h-3.5 w-3.5" strokeWidth={2.5} /> Chatbot Yayınlandı
                    </div>
                )}

                {step === 'choose' ? (
                    <>
                        <DialogTitle className="mb-1 text-title-sm font-semibold text-white">Chatbotu Yayınla</DialogTitle>
                        <DialogDescription className="mb-5 font-sans text-body font-normal leading-6 text-white/60">
                            Botunuzu nasıl paylaşmak istediğinizi seçin.
                        </DialogDescription>

                        <div className="flex flex-col gap-3">
                            <button
                                type="button"
                                onClick={handlePublish}
                                disabled={publishing}
                                className="flex items-start gap-3 rounded-xl border border-emerald-500/25 bg-emerald-500/[0.06] p-4 text-left transition-colors hover:bg-emerald-500/[0.12] disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                <Globe2 className="mt-0.5 h-5 w-5 shrink-0 text-emerald-300" />
                                <span>
                                    <span className="block text-body-sm font-semibold text-white">
                                        {publishing ? 'Yayınlanıyor…' : 'Yayınla'}
                                    </span>
                                    <span className="block text-caption leading-relaxed text-white/55">
                                        Doğrudan herkese açık olur ve Keşfet&apos;te görünür. Herkes ücretsiz sohbet
                                        edebilir; mesajlar kullanıcıların günlük Luma Coin&apos;inden düşer.
                                    </span>
                                </span>
                            </button>

                            <button
                                type="button"
                                onClick={() => setStep('marketplace')}
                                className="flex items-start gap-3 rounded-xl border border-white/10 bg-white/[0.03] p-4 text-left transition-colors hover:bg-white/[0.07]"
                            >
                                <Store className="mt-0.5 h-5 w-5 shrink-0 text-fuchsia-300" />
                                <span>
                                    <span className="block text-body-sm font-semibold text-white">Pazaryerine Kaydet</span>
                                    <span className="block text-caption leading-relaxed text-white/55">
                                        Botu pazaryerinde ücretli satmak için.
                                    </span>
                                </span>
                            </button>
                        </div>

                        {errorMsg && <div className="mt-4 text-body-sm text-rose-400">{errorMsg}</div>}
                    </>
                ) : (
                    <>
                        <DialogTitle className="mb-1 text-title-sm font-semibold text-white">Pazaryerine Kaydet</DialogTitle>
                        <DialogDescription className="mb-5 font-sans text-body font-normal leading-6 text-white/60">
                            Pazaryerinde ücretli satış yapabilmek için pazaryeri başvurusu gerekmektedir.
                            Başvuru yalnızca şirketlere (şahıs ve kurumsal) açıktır ve ekibimiz tarafından
                            incelenir.
                        </DialogDescription>
                        <div className="flex gap-2.5">
                            <Button
                                onClick={() => setStep('choose')}
                                variant="secondary"
                                className="h-auto flex-1 gap-1.5 border border-transparent bg-white/[0.06] py-3 hover:bg-white/[0.1]"
                            >
                                <ArrowLeft className="h-4 w-4" /> Geri
                            </Button>
                            <Button asChild className="h-auto flex-[2] py-3">
                                <Link href="/dashboard/pazaryeri-basvurusu" onClick={onClose}>
                                    Pazaryeri Başvurusu
                                </Link>
                            </Button>
                        </div>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
