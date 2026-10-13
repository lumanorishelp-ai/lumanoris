'use client';
import { useState, useEffect } from 'react';
import { Dialog, DialogContent, DialogTitle, DialogDescription } from '@/shared/ui/dialog';
import { Checkbox } from '@/shared/ui/checkbox';
import { Button } from '@/shared/ui/button';

export default function WithdrawalModal({ isOpen, onClose, moneyAmount, userId, onWithdrawn }) {
    const [iban, setIban] = useState('');
    const [amount, setAmount] = useState('');
    const [selectedOptions, setSelectedOptions] = useState([]);
    const [amountError, setAmountError] = useState('');
    const [ibanError, setIbanError] = useState('');
    const [loading, setLoading] = useState(false);
    const [submitError, setSubmitError] = useState('');

    // Modal State'leri
    const [showConfirmModal, setShowConfirmModal] = useState(false);
    const [showSuccessModal, setShowSuccessModal] = useState(false);
    const [pendingPayload, setPendingPayload] = useState(null);

    const options = ['Tümünü çek'];

    // 1. IBAN Değerini Veritabanından Çekme (Ayarlar > Banka ve Güvenlik'te kayıtlı olan)
    useEffect(() => {
        if (isOpen && userId) {
            const fetchIban = async () => {
                setLoading(true);
                try {
                    const res = await fetch(`/api/wallet/getiban.php?userId=${userId}`);
                    const result = await res.json();
                    // getiban.php returns {success, iban}; iban can be null.
                    // Previously the whole wrapper object was stored as the
                    // iban state, which crashed the render below (`{iban}`)
                    // the instant this modal opened.
                    if (result.success && result.iban) {
                        setIban(result.iban);
                        setIbanError('');
                    } else {
                        setIbanError('Para çekebilmek için kayıtlı bir IBAN gerekir. IBAN bilginiz Pazaryeri Başvurusu ile alınır.');
                    }
                } catch (err) {
                    console.error("IBAN çekme hatası: ", err);
                } finally {
                    setLoading(false);
                }
            };
            fetchIban();
        }
    }, [isOpen, userId]);

    const handleCheckboxChange = (label) => {
        if (selectedOptions.includes(label)) {
            setSelectedOptions(selectedOptions.filter((item) => item !== label));
            setAmount('');
        } else {
            setSelectedOptions([...selectedOptions, label]);
            setAmount(moneyAmount);
        }
    };

    // Para Çek Butonu -> Onay Modalını Aç
    const handleWithdraw = () => {
        const realAmount = selectedOptions.includes('Tümünü çek') ? moneyAmount : parseFloat(amount);
        setPendingPayload({ amount: realAmount });
        setShowConfirmModal(true);
    };

    // Onay -> Gerçek API çağrısı -> Başarı Modalını Aç
    const confirmWithdraw = async () => {
        if (!pendingPayload || !iban) return;
        setSubmitError('');
        setLoading(true);

        try {
            // withdraw.php reads $_POST['data'] as JSON (form-encoded body),
            // not a raw application/json request body — the previous
            // JSON.stringify + Content-Type: application/json meant $_POST
            // was always empty and this request could never succeed.
            const formData = new FormData();
            formData.append('data', JSON.stringify({ iban, amount: pendingPayload.amount }));
            const res = await fetch('/api/wallet/withdraw.php', {
                method: 'POST',
                body: formData,
            });
            const result = await res.json();

            if (!result.success) {
                setSubmitError(result.message || 'Talep oluşturulamadı.');
                setShowConfirmModal(false);
                setLoading(false);
                return;
            }

            setShowConfirmModal(false);
            setShowSuccessModal(true);
            if (onWithdrawn) onWithdrawn();

            setTimeout(() => {
                setShowSuccessModal(false);
                setAmount('');
                setSelectedOptions([]);
                setPendingPayload(null);
                onClose();
            }, 4000);
        } catch (err) {
            setSubmitError('Bağlantı hatası: ' + err.message);
            setShowConfirmModal(false);
        } finally {
            setLoading(false);
        }
    };

    const isFormValid = () => {
        const hasIban = !!iban;
        const valAmount = parseFloat(amount);
        const isAmountValid =
            selectedOptions.includes('Tümünü çek') ||
            (!isNaN(valAmount) && valAmount > 0 && valAmount <= moneyAmount);

        return hasIban && isAmountValid && !loading;
    };

    return (
        <>
            {/* ANA PARA ÇEKME MODALI */}
            <Dialog open={isOpen && !showConfirmModal && !showSuccessModal} onOpenChange={(open) => !open && onClose()}>
                <DialogContent className="max-w-[450px] bg-luma-card border-transparent p-6 text-center">
                    <DialogTitle className="mb-4 text-title-sm">Para çek</DialogTitle>

                    <div className="text-left">
                        {iban ? (
                            <div className="mb-3 rounded-xl bg-luma-input px-4 py-2.5 text-sm text-white/85">
                                Kayıtlı IBAN: <strong className="text-white">{iban}</strong>
                            </div>
                        ) : (
                            ibanError && <div className="mb-3 text-body-sm text-rose-400">{ibanError}</div>
                        )}

                        {submitError && <div className="mb-3 text-body-sm text-rose-400">{submitError}</div>}

                        <div className="rounded-xl bg-luma-input px-4 py-3">
                            <input
                                type="number"
                                placeholder="Miktar"
                                value={amount}
                                onChange={(e) => {
                                    let value = e.target.value;
                                    if (Number(value) > Number(moneyAmount)) {
                                        setAmount(moneyAmount);
                                        setAmountError(`Max: ${moneyAmount}`);
                                    } else {
                                        setAmount(value);
                                        setAmountError('');
                                    }
                                }}
                                disabled={selectedOptions.includes('Tümünü çek')}
                                className="w-full bg-transparent font-display text-body-lg text-white placeholder:text-white/40 focus:outline-none disabled:opacity-50"
                            />
                        </div>
                        {amountError && <div className="mt-1 text-body-sm text-rose-400">{amountError}</div>}

                        <div className="mt-4 flex flex-col">
                            {options.map((label) => (
                                <label key={label} className="flex cursor-pointer items-center gap-3 rounded-xl p-2 text-sm text-white transition-colors hover:bg-white/5">
                                    <Checkbox
                                        checked={selectedOptions.includes(label)}
                                        onCheckedChange={() => handleCheckboxChange(label)}
                                    />
                                    {label} ({moneyAmount} TL)
                                </label>
                            ))}
                        </div>

                        <div className="mt-5">
                            <Button
                                onClick={handleWithdraw}
                                disabled={!isFormValid()}
                                className="h-auto w-full py-3 text-body-lg"
                            >
                                {loading ? "Bekleyin..." : "Para çekme talebi ver"}
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>

            {/* ONAY MODALI */}
            <Dialog open={showConfirmModal} onOpenChange={(open) => !open && setShowConfirmModal(false)}>
                <DialogContent className="max-w-[420px] bg-luma-card border-transparent p-6">
                    <DialogTitle className="mb-6 text-left text-title-sm">Para Çekme Onayı</DialogTitle>

                    <div className="mb-6 rounded-xl border border-transparent bg-white/[0.04] p-4">
                        <p className="flex justify-between">
                            <span className="text-white/50">Tutar:</span>
                            <strong className="text-fuchsia-400">{pendingPayload?.amount} ₺</strong>
                        </p>
                    </div>

                    <div className="flex justify-end gap-3">
                        <Button
                            onClick={() => setShowConfirmModal(false)}
                            variant="secondary"
                            className="h-auto border-transparent bg-white/10 px-4 py-2.5 text-body hover:border-transparent hover:bg-white/20"
                        >
                            Vazgeç
                        </Button>
                        <Button onClick={confirmWithdraw} className="h-auto px-4 py-2.5 text-body">
                            Onayla ve Çek
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>

            {/* BAŞARI MODALI (PurchaseSuccessModal ile aynı yapı) */}
            <Dialog open={showSuccessModal} onOpenChange={(open) => !open && setShowSuccessModal(false)}>
                <DialogContent className="max-w-[440px] bg-luma-card border-transparent p-6 text-center">
                    <div className="flex flex-col items-center">
                        <div className="mb-3 text-emerald-500" aria-hidden="true">
                            <svg width="88" height="89" viewBox="0 0 108 109" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path opacity="0.5" d="M9.37953 12.8796C9.67623 12.0353 10.2961 11.3434 11.1029 10.956C11.9096 10.5687 12.8371 10.5176 13.6815 10.8141L15.036 11.2911C17.8125 12.2676 20.166 13.0956 22.0245 14.0046C24.0045 14.9811 25.7055 16.1781 26.9835 18.0501C28.2525 19.9041 28.779 21.9426 29.0175 24.1791C29.1225 25.1781 29.1885 26.2851 29.2155 27.5001H77.0865C84.669 27.5001 91.4955 27.5001 93.4935 30.0966C95.4915 32.6931 94.7085 36.6081 93.147 44.4336L90.897 55.3461C89.4795 62.2221 88.773 65.6646 86.289 67.6851C83.805 69.7101 80.295 69.7101 73.2705 69.7101H49.407C36.852 69.7101 30.579 69.7101 26.682 65.5971C22.785 61.4841 22.497 57.1191 22.497 43.8801V32.1711C22.497 28.8411 22.497 26.6136 22.308 24.9036C22.128 23.2701 21.8175 22.4511 21.408 21.8571C21.0165 21.2766 20.418 20.7321 19.05 20.0661C17.5965 19.3551 15.621 18.6531 12.615 17.5956L11.445 17.1861C11.0261 17.0396 10.6401 16.812 10.3092 16.5162C9.97831 16.2204 9.70902 15.8622 9.51675 15.4622C9.32447 15.0622 9.21298 14.6281 9.18866 14.185C9.16435 13.7418 9.22767 13.2982 9.37503 12.8796" fill="currentColor" />
                                <path d="M33.75 81.5C35.5402 81.5 37.2571 82.2112 38.523 83.477C39.7888 84.7429 40.5 86.4598 40.5 88.25C40.5 90.0402 39.7888 91.7571 38.523 93.023C37.2571 94.2888 35.5402 95 33.75 95C31.9598 95 30.2429 94.2888 28.977 93.023C27.7112 91.7571 27 90.0402 27 88.25C27 86.4598 27.7112 84.7429 28.977 83.477C30.2429 82.2112 31.9598 81.5 33.75 81.5ZM74.25 81.5C76.0402 81.5 77.7571 82.2112 79.023 83.477C80.2888 84.7429 81 86.4598 81 88.25C81 90.0402 80.2888 91.7571 79.023 93.023C77.7571 94.2888 76.0402 95 74.25 95C72.4598 95 70.7429 94.2888 69.477 93.023C68.2112 91.7571 67.5 90.0402 67.5 88.25C67.5 86.4598 68.2112 84.7429 69.477 83.477C70.7429 82.2112 72.4598 81.5 74.25 81.5ZM69.939 43.3265C70.2578 43.0083 70.5096 42.6295 70.6797 42.2124C70.8498 41.7953 70.9346 41.3484 70.9293 40.898C70.924 40.4476 70.8285 40.0029 70.6486 39.59C70.4687 39.177 70.208 38.8043 69.8818 38.4937C69.5556 38.1831 69.1705 37.941 68.7493 37.7815C68.328 37.6221 67.8791 37.5486 67.429 37.5653C66.9789 37.5821 66.5367 37.6888 66.1285 37.8791C65.7202 38.0694 65.3542 38.3395 65.052 38.6735L54.639 49.6085L51.939 46.7735C51.6368 46.4395 51.2708 46.1694 50.8625 45.9791C50.4543 45.7888 50.0121 45.6821 49.562 45.6653C49.1119 45.6486 48.663 45.7221 48.2417 45.8815C47.8205 46.041 47.4354 46.2831 47.1092 46.5937C46.783 46.9043 46.5223 47.277 46.3424 47.69C46.1625 48.1029 46.067 48.5477 46.0617 48.998C46.0564 49.4484 46.1412 49.8953 46.3113 50.3124C46.4814 50.7295 46.7332 51.1083 47.052 51.4265L52.1955 56.8265C52.5107 57.1573 52.8898 57.4207 53.3098 57.6007C53.7299 57.7806 54.182 57.8734 54.639 57.8734C55.096 57.8734 55.5481 57.7806 55.9682 57.6007C56.3882 57.4207 56.7673 57.1573 57.0825 56.8265L69.939 43.3265Z" fill="currentColor" />
                            </svg>
                        </div>
                        <DialogTitle className="mb-2.5 text-xl font-semibold text-emerald-500">
                            İşlem Başarılı
                        </DialogTitle>
                        <DialogDescription className="mb-8 font-display text-body-lg font-semibold leading-relaxed text-white">
                            Para çekme talebiniz başarıyla oluşturuldu ve işleme alındı.
                            Ödemeniz hesabınıza 7 iş günü içerisinde aktarılacaktır.
                        </DialogDescription>
                        <Button
                            onClick={() => {
                                setShowSuccessModal(false);
                                onClose();
                            }}
                            variant="outline"
                            className="h-auto w-full border-emerald-500 bg-emerald-500/10 py-3 text-body-lg text-emerald-500 hover:border-emerald-400 hover:bg-emerald-500/20 hover:text-emerald-400"
                        >
                            Tamam
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}
