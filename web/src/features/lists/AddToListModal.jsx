'use client';
import { useState, useEffect } from 'react';
import { Dialog, DialogContent, DialogTitle, DialogDescription } from '@/shared/ui/dialog';
import { Plus, ListPlus, Check, ListX } from 'lucide-react';
import { cn } from '@/lib/utils';

export default function AddToListModal({ userId, botId, isOpen, onClose, header = "Listeye Ekle", onCreateList }) {
    const [newListName, setNewListName] = useState('');
    const [allLists, setAllLists] = useState([]); // {id, name, is_in_list}
    const [selectedListIds, setSelectedListIds] = useState([]); // Seçili listelerin ID'leri
    const [initialListIds, setInitialListIds] = useState([]); // İlk açılıştaki durum (karşılaştırma için)
    const [showFeedback, setShowFeedback] = useState(false);
    const [loading, setLoading] = useState(false);
    const [errorMsg, setErrorMsg] = useState('');

    // Listeleri ve botun durumunu çek
    const fetchListsStatus = async () => {
        try {
            const response = await fetch(`/api/social/getbotlists.php?userId=${userId}&botId=${botId}`);
            const result = await response.json();
            if (result.success) {
                setAllLists(result.lists);
                // Sadece botun halihazırda içinde olduğu listelerin ID'lerini al
                const memberIds = result.lists
                    .filter(l => parseInt(l.is_in_list) > 0)
                    .map(l => parseInt(l.id));
                setSelectedListIds(memberIds);
                setInitialListIds(memberIds);
            }
        } catch (error) {
            console.error("Listeler yüklenemedi:", error);
        }
    };

    useEffect(() => {
        if (isOpen && userId && botId) {
            fetchListsStatus();
        }
    }, [isOpen, userId, botId]);

    // Checkbox değişimini yönet
    const handleCheckboxChange = (listId) => {
        setSelectedListIds(prev =>
            prev.includes(listId)
                ? prev.filter(id => id !== listId)
                : [...prev, listId]
        );
    };

    const handleAddNewList = async () => {
        const trimmedName = newListName.trim();
        if (trimmedName && !allLists.some(list => list.name === trimmedName)) {
            const newListData = { name: trimmedName }; // kimlik oturumdan

            try {
                const response = await fetch('/api/social/adduserlist.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ data: JSON.stringify(newListData) })
                });
                const result = await response.json();

                if (result.success) {
                    const newListItem = { id: result.listId, name: trimmedName, is_in_list: 0 };
                    setAllLists(prev => [...prev, newListItem]);
                    setSelectedListIds(prev => [...prev, Number(result.listId)]); // Yeni listeyi otomatik seç
                    setNewListName('');
                    if (onCreateList) onCreateList(newListItem);
                } else {
                    setErrorMsg(result?.message || 'Liste oluşturulamadı.');
                }
            } catch (error) {
                console.error("Liste oluşturma hatası:", error);
                setErrorMsg('Sunucuya ulaşılamadı. Liste oluşturulamadı.');
            }
        }
    };

    /* N-35 — eskiden istekler gönderilip YANITLARINA BAKILMADAN "Kaydedildi"
       deniyor ve pencere kapanıyordu; sunucu reddetse de kullanıcı kaydedildi
       sanıyordu. Şimdi her yanıt kontrol ediliyor: hepsi başarılıysa kapanır;
       biri başarısızsa pencere açık kalır, mesaj gösterilir ve gerçek durum
       sunucudan yeniden okunur (yarım kalan değişiklik görünür). */
    const handleSave = async () => {
        setLoading(true);
        setErrorMsg('');

        const added = selectedListIds.filter(id => !initialListIds.includes(id));
        const removed = initialListIds.filter(id => !selectedListIds.includes(id));

        const post = async (url, listId) => {
            try {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ data: JSON.stringify({ chatbot_id: botId, list_id: listId }) }),
                    credentials: 'include',
                });
                const result = await res.json().catch(() => null);
                return res.ok && result?.success ? null : (result?.message || `HTTP ${res.status}`);
            } catch {
                return 'Sunucuya ulaşılamadı';
            }
        };

        const errors = (await Promise.all([
            ...added.map(listId => post('/api/social/addbottolist.php', listId)),
            ...removed.map(listId => post('/api/social/deletebotfromlist.php', listId)),
        ])).filter(Boolean);

        setLoading(false);
        if (errors.length > 0) {
            setErrorMsg(`Bazı değişiklikler kaydedilemedi: ${errors[0]}`);
            await fetchListsStatus();
            return;
        }
        setShowFeedback(true);
        setTimeout(() => {
            setShowFeedback(false);
            onClose();
        }, 1500);
    };

    const changedCount =
        selectedListIds.filter((id) => !initialListIds.includes(id)).length +
        initialListIds.filter((id) => !selectedListIds.includes(id)).length;

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-[440px] border-white/10 bg-[#0c0c14] p-0">
                <div className="flex items-center gap-3 border-b border-white/[0.06] px-5 py-4">
                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-fuchsia-500/20 bg-fuchsia-500/10 text-fuchsia-400">
                        <ListPlus className="h-[18px] w-[18px]" />
                    </span>
                    <div className="min-w-0 text-left">
                        <DialogTitle className="text-base font-semibold text-white">{header}</DialogTitle>
                        <DialogDescription className="mt-0.5 text-xs text-white/45">
                            Botu listelerine ekle ya da çıkar.
                        </DialogDescription>
                    </div>
                </div>

                <div className="px-5 pb-5 pt-4">
                    {/* Yeni liste — Enter ile de eklenebiliyor, eskiden yalnizca
                        kucuk arti dugmesi vardi ve gorulmuyordu. */}
                    <div className="flex items-center gap-2 rounded-xl border border-white/10 bg-white/[0.03] p-1.5 pl-3.5 transition-colors focus-within:border-fuchsia-400/40">
                        <input
                            type="text"
                            placeholder="Yeni liste oluştur..."
                            value={newListName}
                            data-focus-managed
                            onChange={(e) => setNewListName(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    handleAddNewList();
                                }
                            }}
                            className="min-w-0 flex-1 border-none bg-transparent text-sm text-white outline-none placeholder:text-white/35"
                        />
                        <button
                            type="button"
                            onClick={handleAddNewList}
                            disabled={!newListName.trim()}
                            aria-label="Yeni liste ekle"
                            className={cn(
                                'flex h-8 w-8 shrink-0 items-center justify-center rounded-lg transition-all duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                newListName.trim()
                                    ? 'bg-gradient-btn text-white hover:brightness-110'
                                    : 'cursor-not-allowed bg-white/[0.06] text-white/25',
                            )}
                        >
                            <Plus className="h-4 w-4" />
                        </button>
                    </div>

                    <div className="mt-4 max-h-[280px] space-y-1 overflow-y-auto overscroll-contain pr-1">
                        {allLists.length === 0 ? (
                            <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-white/10 px-4 py-8 text-center">
                                <ListX className="h-6 w-6 text-white/25" />
                                <p className="text-xs text-white/40">
                                    Henüz listen yok. Yukarıdan ilk listeni oluştur.
                                </p>
                            </div>
                        ) : (
                            allLists.map((list) => {
                                const checked = selectedListIds.includes(Number(list.id));
                                return (
                                    <button
                                        key={list.id}
                                        type="button"
                                        onClick={() => handleCheckboxChange(Number(list.id))}
                                        aria-pressed={checked}
                                        className={cn(
                                            'flex w-full items-center justify-between gap-3 rounded-xl border px-3.5 py-2.5 text-left transition-all duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                            checked
                                                ? 'border-fuchsia-400/35 bg-fuchsia-500/[0.1] text-white'
                                                : 'border-transparent bg-white/[0.02] text-white/70 hover:bg-white/[0.06] hover:text-white',
                                        )}
                                    >
                                        <span className="truncate text-sm font-medium">{list.name}</span>
                                        <span
                                            className={cn(
                                                'flex h-5 w-5 shrink-0 items-center justify-center rounded-md border transition-all duration-200',
                                                checked
                                                    ? 'border-transparent bg-gradient-btn text-white'
                                                    : 'border-white/15 bg-transparent',
                                            )}
                                        >
                                            {checked && <Check className="h-3 w-3" strokeWidth={3} />}
                                        </span>
                                    </button>
                                );
                            })
                        )}
                    </div>

                    {errorMsg && (
                        <p role="alert" className="mt-4 rounded-lg border border-rose-500/20 bg-rose-500/10 px-3 py-2 text-xs text-rose-300">
                            {errorMsg}
                        </p>
                    )}
                    <div className="mt-5 flex items-center justify-between gap-3">
                        <span className="text-[11px] text-white/35">
                            {showFeedback
                                ? 'Kaydedildi'
                                : changedCount > 0
                                    ? `${changedCount} değişiklik`
                                    : 'Değişiklik yok'}
                        </span>
                        <div className="flex gap-2">
                            <button
                                type="button"
                                onClick={onClose}
                                className="h-9 rounded-xl bg-white/[0.06] px-4 text-xs font-semibold text-white/80 transition-colors hover:bg-white/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            >
                                İptal
                            </button>
                            <button
                                type="button"
                                onClick={handleSave}
                                disabled={loading || changedCount === 0}
                                className={cn(
                                    'flex h-9 items-center gap-1.5 rounded-xl px-4 text-xs font-semibold transition-all duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                    loading || changedCount === 0
                                        ? 'cursor-not-allowed bg-white/[0.06] text-white/25'
                                        : 'bg-gradient-btn text-white shadow-glow hover:brightness-110',
                                )}
                            >
                                {showFeedback && <Check className="h-3.5 w-3.5" />}
                                {loading ? 'Kaydediliyor...' : showFeedback ? 'Kaydedildi' : 'Kaydet'}
                            </button>
                        </div>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
