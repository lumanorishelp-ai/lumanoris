'use client';
import { useEffect, useState } from 'react';
import { Dialog, DialogContent, DialogTitle, DialogDescription } from '@/shared/ui/dialog';
import { Share2, Link2, Check } from 'lucide-react';
import { cn } from '@/lib/utils';
import { copyText } from '@/shared/lib/clipboard';

export default function ShareModal({ isOpen, urlId, onClose, }) {
    const [copied, setCopied] = useState(false);
    const [copyFailed, setCopyFailed] = useState(false);
    const [shareUrl, setShareUrl] = useState('');

    useEffect(() => {
        if (typeof window !== 'undefined') {
            const hasId = urlId !== null && urlId !== undefined && urlId !== '';
            const url = hasId
                // N-41: sohbet sayfası `botId` okur; `botid` bağlantısı botu açmıyordu.
                ? `${window.location.origin}/dashboard/chat/?botId=${urlId}`
                : window.location.href;
            setShareUrl(url);
        }
    }, [urlId]);

    // N-41: "Kopyalandı" yalnızca pano yazması gerçekten tamamlanınca;
    // başarısızlıkta kullanıcıya elle kopyalaması söylenir.
    const showCopyResult = (ok) => {
        setCopyFailed(!ok);
        if (!ok) return;
        setCopied(true);
        setTimeout(() => setCopied(false), 2000); // 2 saniye sonra badge gizlenir
    };

    const handleCopy = async () => {
        showCopyResult(await copyText(shareUrl));
    };

    const handleInstagramShare = () => {
        // Yazma beklenmeden başlatılıyor: aşağıdaki yönlendirme/pencere açma
        // kullanıcı tıklamasıyla aynı anda olmalı, yoksa açılır pencere engellenir.
        const copying = copyText(shareUrl);

        const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);

        if (isMobile) {
            const now = Date.now();
            window.location.href = "instagram://direct";

            setTimeout(() => {
            if (Date.now() - now < 1500) {
                window.open("https://www.instagram.com/direct/inbox/", "_blank");
            }
            }, 1000);
        } else {
            window.open("https://www.instagram.com/direct/inbox/", "_blank");
        }

        copying.then(showCopyResult);
    };

    // Marka SVG'leri korundu ama hepsi ayni olcekte, ayni kareye oturuyor:
    // eskiden farkli boyutlarda ve birbiriyle alakasiz pastel tonlardaydilar.
    const channels = [
        {
            key: 'whatsapp',
            label: 'WhatsApp',
            onClick: () => window.open(`https://wa.me/?text=${encodeURIComponent(shareUrl)}`, '_blank'),
            icon: (
                <svg viewBox="0 0 43 43" className="h-5 w-5" fill="currentColor" aria-hidden="true">
                    <path d="M21.5887 10.1211C15.7761 10.1211 11.0489 14.8479 11.0466 20.6579C11.0458 22.6491 11.603 24.5882 12.6577 26.2659L12.9084 26.6646L11.8438 30.5531L15.8321 29.5069L16.2168 29.7352C17.8348 30.6953 19.6894 31.2033 21.5802 31.2041H21.5845C27.3927 31.2041 32.1198 26.4768 32.1221 20.6663C32.1232 17.8507 31.0281 15.2031 29.0387 13.2113C27.0491 11.2196 24.4033 10.122 21.5887 10.1211ZM27.787 25.1886C27.523 25.9285 26.2574 26.6038 25.6487 26.6948C25.1028 26.7764 24.4123 26.8104 23.6534 26.5693C23.1933 26.4233 22.6033 26.2283 21.8475 25.902C18.6696 24.5297 16.5942 21.3301 16.4358 21.1186C16.2775 20.9073 15.1423 19.4009 15.1423 17.8415C15.1423 16.2824 15.9607 15.5159 16.2511 15.1987C16.5413 14.8817 16.8846 14.8024 17.0958 14.8024C17.3069 14.8024 17.5184 14.8044 17.7029 14.8135C17.8973 14.8234 18.1585 14.7397 18.4157 15.3573C18.6797 15.9917 19.3132 17.551 19.3924 17.7096C19.4717 17.8681 19.5244 18.0531 19.4189 18.2646C19.3133 18.4758 19.2605 18.6081 19.1021 18.793C18.9438 18.978 18.7694 19.206 18.6269 19.348C18.4683 19.506 18.3032 19.6774 18.488 19.9944C18.6728 20.3117 19.3086 21.349 20.2504 22.1891C21.4603 23.2683 22.4811 23.6028 22.7978 23.7614C23.1146 23.9201 23.2994 23.8934 23.4842 23.6821C23.669 23.4707 24.2762 22.7571 24.4873 22.44C24.6985 22.1229 24.9097 22.1758 25.2 22.2814C25.4904 22.3873 27.0479 23.1535 27.3647 23.312C27.6815 23.4706 27.8926 23.55 27.9718 23.6821C28.051 23.8141 28.051 24.4484 27.787 25.1886Z" />
                    <path d="M21.1218 0C9.45692 0 0 9.45521 0 21.1201C0 32.785 9.45692 42.242 21.1218 42.242C32.7859 42.242 42.2428 32.785 42.2428 21.1201C42.2428 9.45521 32.7859 0 21.1218 0ZM21.5854 33.3455H21.5801C19.4583 33.3447 17.3732 32.8123 15.5215 31.8023L8.8005 33.5653L10.5992 26.9956C9.48969 25.0728 8.90591 22.8919 8.90686 20.6573C8.9096 13.6671 14.5972 7.9799 21.5853 7.9799C24.9768 7.98127 28.1601 9.30159 30.5537 11.698C32.9474 14.0944 34.265 17.2797 34.2637 20.6674C34.2607 27.6577 28.5728 33.3455 21.5854 33.3455Z" />
                </svg>
            ),
        },
        {
            key: 'instagram',
            label: 'Instagram',
            onClick: handleInstagramShare,
            icon: (
                <svg viewBox="0 0 42 43" className="h-5 w-5" fill="currentColor" aria-hidden="true">
                    <path fillRule="evenodd" clipRule="evenodd" d="M21 0.158203C32.5902 0.158203 42 9.56801 42 21.1582C42 32.7484 32.5902 42.1582 21 42.1582C9.40981 42.1582 0 32.7484 0 21.1582C0 9.56801 9.40981 0.158203 21 0.158203ZM21 8.0332C17.4355 8.0332 16.9885 8.0483 15.5886 8.1122C14.1916 8.17594 13.2375 8.39783 12.4025 8.72227C11.5395 9.05769 10.8075 9.50648 10.0779 10.2362C9.3482 10.9658 8.89941 11.6978 8.56398 12.5608C8.23955 13.3957 8.01773 14.3498 7.95391 15.7468C7.89009 17.1467 7.875 17.5937 7.875 21.1582C7.875 24.7227 7.89009 25.1697 7.95391 26.5696C8.01773 27.9666 8.23955 28.9207 8.56398 29.7556C8.89941 30.6186 9.3482 31.3506 10.0779 32.0803C10.8075 32.81 11.5395 33.2588 12.4025 33.5942C13.2375 33.9186 14.1916 34.1405 15.5886 34.2042C16.9885 34.2681 17.4355 34.2832 21 34.2832C24.5645 34.2832 25.0115 34.2681 26.4114 34.2042C27.8084 34.1405 28.7625 33.9186 29.5974 33.5942C30.4604 33.2588 31.1924 32.81 31.9221 32.0803C32.6518 31.3506 33.1006 30.6186 33.436 29.7556C33.7604 28.9207 33.9823 27.9666 34.046 26.5696C34.1099 25.1697 34.125 24.7227 34.125 21.1582C34.125 17.5937 34.1099 17.1467 34.046 15.7468C33.9823 14.3498 33.7604 13.3957 33.436 12.5608C33.1006 11.6978 32.6518 10.9658 31.9221 10.2362C31.1924 9.50648 30.4604 9.05769 29.5974 8.72227C28.7625 8.39783 27.8084 8.17594 26.4114 8.1122C25.0115 8.0483 24.5645 8.0332 21 8.0332ZM21 10.3981C24.5045 10.3981 24.9196 10.4115 26.3036 10.4746C27.5833 10.533 28.2782 10.7468 28.7407 10.9265C29.3534 11.1646 29.7906 11.4491 30.2498 11.9084C30.7092 12.3676 30.9936 12.8049 31.2317 13.4175C31.4114 13.88 31.6253 14.5749 31.6836 15.8546C31.7467 17.2386 31.7601 17.6537 31.7601 21.1582C31.7601 24.6627 31.7467 25.0778 31.6836 26.4619C31.6253 27.7415 31.4114 28.4364 31.2317 28.8989C30.9936 29.5116 30.7092 29.9488 30.2498 30.408C29.7906 30.8674 29.3534 31.1518 28.7407 31.3899C28.2782 31.5696 27.5833 31.7835 26.3036 31.8418C24.9199 31.905 24.5048 31.9183 21 31.9183C17.4952 31.9183 17.0802 31.905 15.6964 31.8418C14.4167 31.7835 13.7218 31.5696 13.2593 31.3899C12.6466 31.1518 12.2094 30.8674 11.7501 30.408C11.2908 29.9488 11.0064 29.5116 10.7683 28.8989C10.5886 28.4364 10.3747 27.7415 10.3163 26.4619C10.2532 25.0778 10.2399 24.6627 10.2399 21.1582C10.2399 17.6537 10.2532 17.2386 10.3163 15.8546C10.3747 14.5749 10.5886 13.88 10.7683 13.4175C11.0064 12.8049 11.2908 12.3676 11.7501 11.9084C12.2094 11.4491 12.6466 11.1646 13.2593 10.9265C13.7218 10.7468 14.4167 10.533 15.6964 10.4746C17.0804 10.4115 17.4955 10.3981 21 10.3981ZM21 14.4184C17.2777 14.4184 14.2601 17.4359 14.2601 21.1582C14.2601 24.8805 17.2777 27.8981 21 27.8981C24.7223 27.8981 27.7399 24.8805 27.7399 21.1582C27.7399 17.4359 24.7223 14.4184 21 14.4184ZM21 25.5332C18.5838 25.5332 16.625 23.5744 16.625 21.1582C16.625 18.742 18.5838 16.7832 21 16.7832C23.4162 16.7832 25.375 18.742 25.375 21.1582C25.375 23.5744 23.4162 25.5332 21 25.5332ZM29.5812 14.1521C29.5812 15.0219 28.876 15.727 28.0061 15.727C27.1363 15.727 26.4312 15.0219 26.4312 14.1521C26.4312 13.2822 27.1363 12.5771 28.0061 12.5771C28.876 12.5771 29.5812 13.2822 29.5812 14.1521Z" />
                </svg>
            ),
        },
        {
            key: 'facebook',
            label: 'Facebook',
            onClick: () => window.open(`https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(shareUrl)}`, '_blank'),
            icon: (
                <svg viewBox="0 0 42 43" className="h-5 w-5" fill="currentColor" aria-hidden="true">
                    <path d="M42 21.2861C42 9.68814 32.598 0.286133 21 0.286133C9.40201 0.286133 0 9.68814 0 21.2861C0 31.7678 7.67944 40.4556 17.7188 42.031V27.3564H12.3867V21.2861H17.7188V16.6596C17.7188 11.3964 20.8539 8.48926 25.6508 8.48926C27.9484 8.48926 30.3516 8.89941 30.3516 8.89941V14.0674H27.7035C25.0948 14.0674 24.2812 15.6861 24.2812 17.3468V21.2861H30.1055L29.1744 27.3564H24.2812V42.031C34.3206 40.4556 42 31.7678 42 21.2861Z" />
                </svg>
            ),
        },
        {
            key: 'x',
            label: 'X',
            onClick: () => window.open(`https://twitter.com/intent/tweet?url=${encodeURIComponent(shareUrl)}`, '_blank'),
            icon: (
                <svg viewBox="0 0 22 22" className="h-[18px] w-[18px]" fill="currentColor" aria-hidden="true">
                    <path d="M13.0955 9.3165L21.2864 0H19.3456L12.2303 8.0877L6.5514 0H0L8.5895 12.2311L0 22H1.9407L9.4501 13.4571L15.4486 22H22L13.0955 9.3165ZM10.4365 12.3385L9.5649 11.1198L2.6406 1.4316H5.6219L11.2117 9.2532L12.0797 10.4719L19.3447 20.6381H16.3634L10.4365 12.3385Z" />
                </svg>
            ),
        },
        {
            key: 'mail',
            label: 'E-posta',
            onClick: () => window.open(`mailto:?subject=${encodeURIComponent('Lumanoris | Harika bir model buldum')}&body=${encodeURIComponent(shareUrl)}`, '_self'),
            icon: (
                <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                    <rect x="2" y="4" width="20" height="16" rx="2" />
                    <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7" />
                </svg>
            ),
        },
    ];

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-[440px] border-white/10 bg-[#0c0c14] p-0">
                <div className="flex items-center gap-3 border-b border-white/[0.06] px-5 py-4">
                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-fuchsia-500/20 bg-fuchsia-500/10 text-fuchsia-400">
                        <Share2 className="h-[18px] w-[18px]" />
                    </span>
                    <div className="min-w-0 text-left">
                        <DialogTitle className="text-base font-semibold text-white">Paylaş</DialogTitle>
                        <DialogDescription className="mt-0.5 text-xs text-white/45">
                            Bu botu bağlantıyla ya da uygulamalar üzerinden paylaş.
                        </DialogDescription>
                    </div>
                </div>

                <div className="space-y-5 px-5 pb-5 pt-4">
                    <div>
                        <p className="mb-2 text-[11px] font-semibold uppercase tracking-wider text-white/35">
                            Bağlantı
                        </p>
                        <div className="flex items-center gap-2 rounded-xl border border-white/10 bg-white/[0.03] p-1.5 pl-3 transition-colors focus-within:border-fuchsia-400/40">
                            <Link2 className="h-4 w-4 shrink-0 text-white/30" />
                            <input
                                type="text"
                                readOnly
                                value={shareUrl}
                                onFocus={(e) => e.target.select()}
                                data-focus-managed
                                className="min-w-0 flex-1 truncate border-none bg-transparent font-mono text-xs text-white/70 outline-none"
                            />
                            <button
                                type="button"
                                onClick={handleCopy}
                                className={cn(
                                    'flex h-8 shrink-0 items-center gap-1.5 rounded-lg px-3 text-xs font-semibold transition-all duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                    copied
                                        ? 'bg-emerald-500/15 text-emerald-300'
                                        : 'bg-gradient-btn text-white hover:brightness-110',
                                )}
                            >
                                {copied ? <Check className="h-3.5 w-3.5" /> : null}
                                {copied ? 'Kopyalandı' : 'Kopyala'}
                            </button>
                        </div>
                        {copyFailed && (
                            <p role="alert" className="mt-2 text-xs text-rose-300">
                                Bağlantı kopyalanamadı. Bağlantıyı seçip elle kopyalayın.
                            </p>
                        )}
                    </div>

                    <div>
                        <p className="mb-2 text-[11px] font-semibold uppercase tracking-wider text-white/35">
                            Uygulamalar
                        </p>
                        <div className="grid grid-cols-5 gap-2">
                            {channels.map((c) => (
                                <button
                                    key={c.key}
                                    type="button"
                                    onClick={c.onClick}
                                    aria-label={`${c.label} ile paylaş`}
                                    className="flex flex-col items-center gap-1.5 rounded-xl border border-white/[0.08] bg-white/[0.03] px-1 py-3 text-white/70 transition-all duration-200 hover:-translate-y-0.5 hover:border-fuchsia-400/30 hover:bg-white/[0.07] hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                >
                                    {c.icon}
                                    <span className="text-[10px] font-medium">{c.label}</span>
                                </button>
                            ))}
                        </div>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
