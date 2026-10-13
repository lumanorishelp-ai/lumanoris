import { useState, useEffect } from "react";
import { ArrowDownToLine } from "lucide-react";
import { formatCurrency } from "@/shared/lib/format";
import { toast } from "@/shared/hooks/use-toast";
import { Dialog, DialogContent, DialogTitle, DialogDescription } from "@/shared/ui/dialog";
import { Button } from "@/shared/ui/button";

export default function WithdrawalModal({ isOpen, onClose, balance, onSuccess }) {
  const [amount, setAmount] = useState("");
  const [loading, setLoading] = useState(false);
  const [iban, setIban] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    if (!isOpen) return;
    setError(null);
    fetch("/api/wallet/getiban.php", { credentials: "include" })
      .then((res) => res.json())
      .then((result) => setIban(result.success ? result.iban : null))
      .catch((err) => {
        console.error("IBAN fetch error:", err);
        setIban(null);
      });
  }, [isOpen]);

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!iban) {
      setError("IBAN bilginiz Pazaryeri Başvurusu ile alınır; kayıtlı IBAN'ınız bulunamadı.");
      return;
    }
    const numericAmount = parseFloat(amount);
    if (!numericAmount || numericAmount <= 0) {
      setError("Geçerli bir tutar girin.");
      return;
    }
    if (numericAmount > balance) {
      setError("Tutar kullanılabilir bakiyeden fazla olamaz.");
      return;
    }
    setError(null);
    setLoading(true);
    try {
      const formData = new FormData();
      formData.append("data", JSON.stringify({ iban, amount: numericAmount }));
      const res = await fetch("/api/wallet/withdraw.php", {
        method: "POST",
        body: formData,
        credentials: "include",
      });
      const result = await res.json();
      if (result.success) {
        toast.success(`${formatCurrency(numericAmount)} tutarındaki para çekme talebiniz alındı.`);
        setAmount("");
        onSuccess?.();
        onClose();
      } else {
        setError(result.message || "Talep oluşturulamadı.");
      }
    } catch (err) {
      console.error("Withdrawal error:", err);
      setError("Sunucuya bağlanılamadı.");
    } finally {
      setLoading(false);
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-w-md bg-luma-card border-transparent p-6">
        <div className="mb-4 flex items-center gap-2.5">
          <div className="flex h-10 w-10 items-center justify-center rounded-xl border border-fuchsia-500/30 bg-fuchsia-500/10 text-fuchsia-400">
            <ArrowDownToLine className="h-5 w-5" />
          </div>
          <div>
            <DialogTitle className="text-base font-bold">Para Çekme Talebi</DialogTitle>
            <DialogDescription className="text-xs">
              Bakiyenizi banka hesabınıza aktarın
            </DialogDescription>
          </div>
        </div>
        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className="block text-xs font-semibold text-white/50 uppercase tracking-wider mb-2">
              Çekilecek Tutar (₺)
            </label>
            <div className="relative">
              <span className="absolute left-3.5 top-1/2 -translate-y-1/2 text-white/50 font-bold">
                ₺
              </span>
              <input
                type="number"
                value={amount}
                onChange={(e) => setAmount(e.target.value)}
                placeholder="0.00"
                className="w-full rounded-2xl border border-white/10 bg-luma-input pl-9 pr-4 py-3 text-sm text-white font-mono focus:border-fuchsia-500/60 focus:outline-none focus:ring-2 focus:ring-fuchsia-500/20"
              />
            </div>
            <p className="mt-1.5 text-xs text-white/40">
              Kullanılabilir Bakiye:{" "}
              <span className="text-emerald-400 font-bold font-mono">
                {formatCurrency(balance)}
              </span>
            </p>
          </div>
          {iban === null ? (
            <p className="text-xs text-amber-400 bg-amber-500/10 border border-amber-500/20 rounded-xl px-4 py-3">
              Para çekebilmek için kayıtlı bir IBAN gerekir. IBAN bilginiz Pazaryeri Başvurusu ile alınır.
            </p>
          ) : (
            <p className="text-xs text-white/40">
              Gönderilecek IBAN:{" "}
              <span className="text-white/70 font-mono">{iban}</span>
            </p>
          )}
          {error && (
            <p className="text-xs text-rose-400 bg-rose-500/10 border border-rose-500/20 rounded-xl px-4 py-3">
              {error}
            </p>
          )}
          <div className="flex justify-end gap-3 pt-2">
            <Button type="button" onClick={onClose} variant="secondary" className="h-auto px-4 py-2.5 text-xs">
              Vazgeç
            </Button>
            <Button type="submit" disabled={loading || !iban} className="h-auto px-5 py-2.5 text-xs">
              {loading ? "İşleniyor..." : "Talep Oluştur"}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
}
