"use client";
import dynamic from "next/dynamic";
import React, { useState, useEffect, useMemo, useContext } from "react";
import { formatDate } from "@/shared/lib/format";
import { UserContext } from "@/shared/contexts/UserContext";
import WalletHero from "./components/WalletHero";
import BalanceOverview from "./components/BalanceOverview";
import WalletTabsBar from "./components/WalletTabsBar";
import TransactionsPanel from "./components/TransactionsPanel";
import Link from "next/link";
import { Lock, RefreshCw } from "lucide-react";
import useMarketplaceRegistration from "@/shared/hooks/useMarketplaceRegistration";

// Only loaded once the user opens the withdrawal modal.
const WithdrawalModal = dynamic(() => import("./components/WithdrawalModal"), { ssr: false });

/**
 * Madde 4 / GK-5 — Bakiyem kapısı. Pazaryeri kaydı (başvuru) olmayan kullanıcı
 * bakiye ve hareketleri görmez; yalnızca müşteri metni ve başvuru bağlantısı.
 * Yalnızca ARAYÜZ kapısı: wallet uç noktalarının sunucu yetkisi değişmedi.
 */
export default function Wallet() {
  const { userId } = useContext(UserContext);
  const registration = useMarketplaceRegistration(userId);

  if (registration.loading) {
    return (
      <div className="min-h-screen bg-luma-base px-4 py-10 text-sm text-zinc-400 sm:px-6 lg:px-8">
        Yükleniyor...
      </div>
    );
  }

  if (registration.error) {
    return (
      <div className="min-h-screen bg-luma-base px-4 py-10 sm:px-6 lg:px-8">
        <div className="flex max-w-xl flex-col items-start gap-3 rounded-2xl border border-rose-500/20 bg-rose-500/10 px-5 py-4 text-sm text-rose-300">
          <span>{registration.error}</span>
          <button
            onClick={registration.refetch}
            className="inline-flex items-center gap-2 rounded-xl border border-rose-400/30 px-3 py-1.5 text-xs font-medium text-rose-200 transition-colors hover:bg-rose-500/15"
          >
            <RefreshCw className="h-3.5 w-3.5" />
            Tekrar dene
          </button>
        </div>
      </div>
    );
  }

  if (!registration.registered) {
    return (
      <div className="min-h-screen bg-luma-base px-4 py-10 sm:px-6 lg:px-8">
        <div className="mx-auto flex max-w-md flex-col items-center gap-4 rounded-3xl border border-white/10 bg-white/[0.02] p-10 text-center">
          <div className="flex h-14 w-14 items-center justify-center rounded-2xl border border-fuchsia-400/15 bg-fuchsia-500/10">
            <Lock className="h-6 w-6 text-fuchsia-300" />
          </div>
          <p className="text-sm leading-relaxed text-white/80">
            Bakiyenize erişebilmek için pazaryeri kaydı gerekmektedir.
          </p>
          <Link
            href="/dashboard/pazaryeri-basvurusu"
            className="rounded-xl bg-gradient-btn px-4 py-2.5 text-xs font-semibold text-white shadow-glow transition-all hover:brightness-110"
          >
            Pazaryeri Başvurusu
          </Link>
        </div>
      </div>
    );
  }

  return <WalletContent />;
}

function WalletContent() {
  const { userId, account, refetchBalance } = useContext(UserContext);
  const [activeTab, setActiveTab] = useState("bakiye");
  const [isModalOpen, setIsModalOpen] = useState(false);
  const balance = account.balance;
  const balanceTx = account.transactions;
  // N-13 — bu state iki UYDURMA siparişle başlıyordu ("Aura Architect
  // Prime" 450 ₺, "Verba SEO & Content Titan" 280 ₺): istek sürerken gerçek
  // kayıt gibi listeleniyor, istek başarısız olursa ekranda kalıyor ve
  // "toplam harcama"ya ekleniyordu. Boş başlıyor; yükleme ve hata ayrı.
  const [payments, setPayments] = useState([]);
  const [paymentsError, setPaymentsError] = useState(null);
  const [loading, setLoading] = useState(true);
  const [searchQuery, setSearchQuery] = useState("");

  useEffect(() => {
    if (!userId) {
      setLoading(false);
      return;
    }
    setLoading(true);
    setPaymentsError(null);
    fetch(`/api/wallet/getmypayments.php?user_id=${userId}`)
      .then((r) => r.json())
      .then((data) => {
        if (data?.success && Array.isArray(data.payments)) {
          setPayments(data.payments);
        } else {
          setPayments([]);
          setPaymentsError(data?.message || "Harcama geçmişi yüklenemedi.");
        }
      })
      .catch((err) => {
        console.error("Ödemeler yüklenemedi:", err);
        setPayments([]);
        setPaymentsError("Sunucuya bağlanılamadı; harcama geçmişi gösterilemiyor.");
      })
      .finally(() => setLoading(false));
  }, [userId]);

  const transactions =
    activeTab === "bakiye"
      ? balanceTx.map((tx, i) => ({
          key: `b-${i}`,
          amount: tx.amount,
          description: tx.created_at
            ? `${tx.description} · ${formatDate(tx.created_at)}`
            : tx.description,
          type: Number(tx.amount) >= 0 ? "income" : "expense",
        }))
      : (() => {
          const orders = new Map();
          for (const row of payments) {
            if (!orders.has(row.order_id)) {
              orders.set(row.order_id, {
                amount: row.total_amount,
                status: row.status,
                created_at: row.created_at,
                titles: [],
              });
            }
            if (row.chatbot_title)
              orders.get(row.order_id).titles.push(row.chatbot_title);
          }
          return Array.from(orders.values()).map((p, i) => {
            // D-06: kalemsiz ödeme = üyelik paketi alımı (upgradePlan hiç
            // `param_marketplace_details` satırı yazmıyor). Bu satırlar artık
            // listeye giriyor; "Sohbet botu" demek yanlış olurdu.
            const names = p.titles.length
              ? p.titles.join(", ")
              : "Üyelik paketi";
            const refunded =
              p.status === "refunded" || p.status === "partial_refund";
            let desc = `${names} satın alındı`;
            if (p.created_at) desc += ` · ${formatDate(p.created_at)}`;
            if (refunded) desc += " · İade edildi";
            return {
              key: `p-${i}`,
              amount: -Math.abs(p.amount),
              description: desc,
              type: "expense",
              refunded,
            };
          });
        })();

  const filteredTransactions = useMemo(() => {
    if (!searchQuery.trim()) return transactions;
    const q = searchQuery.toLowerCase();
    return transactions.filter((t) => t.description.toLowerCase().includes(q));
  }, [transactions, searchQuery]);

  const uniqueOrderCount = new Set(payments.map((p) => p.order_id)).size;
  const totalSpent = (() => {
    const seen = new Set();
    let sum = 0;
    for (const p of payments) {
      if (seen.has(p.order_id)) continue;
      seen.add(p.order_id);
      sum += Math.abs(Number(p.total_amount) || 0);
    }
    return sum;
  })();

  return (
    <div className="min-h-screen bg-luma-base font-sans text-zinc-100 antialiased selection:bg-fuchsia-500/30 selection:text-fuchsia-200">
      {/* Background Glow FX */}
      <div className="fixed inset-0 pointer-events-none overflow-hidden">
        <div className="absolute -top-[20%] left-1/2 -translate-x-1/2 h-[500px] w-[1000px] bg-gradient-to-b from-violet-600/15 via-fuchsia-600/5 to-transparent blur-3xl opacity-80" />
      </div>

      <main className="relative z-10 px-4 py-10 sm:px-6 lg:px-8 space-y-8">
        <WalletHero onWithdraw={() => setIsModalOpen(true)} />

        <BalanceOverview
          balance={balance}
          totalSpent={totalSpent}
          uniqueOrderCount={uniqueOrderCount}
          onWithdraw={() => setIsModalOpen(true)}
        />

        <section className="space-y-6">
          <WalletTabsBar
            activeTab={activeTab}
            onTabChange={setActiveTab}
            searchQuery={searchQuery}
            onSearchChange={setSearchQuery}
          />

          {paymentsError && activeTab !== "bakiye" && (
            <div className="rounded-2xl border border-rose-500/20 bg-rose-500/10 px-5 py-4 text-sm text-rose-300">
              {paymentsError}
            </div>
          )}

          <TransactionsPanel loading={loading} transactions={filteredTransactions} />
        </section>
      </main>

      <WithdrawalModal
        isOpen={isModalOpen}
        onClose={() => setIsModalOpen(false)}
        balance={balance}
        onSuccess={() => refetchBalance()}
      />
    </div>
  );
}
