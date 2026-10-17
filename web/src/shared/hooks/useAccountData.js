"use client";
import { FREE_PLAN_NAME } from "@/shared/lib/pricing";
import { useState, useEffect, useCallback } from "react";

const DEFAULT_ACCOUNT = {
  id: null,
  username: "",
  fullname: "",
  chatbotCount: 0,
  purchasedCount: 0,
  sharedDialogueCount: 0,
  balance: 0,
  transactions: [],
  planName: FREE_PLAN_NAME,
  dailyCoinsRemaining: 0,
  dailyCoinsTotal: 0,
};

// Single shared source for the header/sidebar/wallet account summary.
// Previously DashboardHeader, Sidebar and the wallet page each fetched
// getuserheader.php + getmybalance.php independently on every mount.
export function useAccountData(userId) {
  const [account, setAccount] = useState(DEFAULT_ACCOUNT);

  const refetchAccount = useCallback(async () => {
    if (!userId) return;
    try {
      const res = await fetch(`/api/user/getuserheader.php?id=${userId}`);
      if (res.ok) {
        const result = await res.json();
        if (result.success) {
          setAccount((prev) => ({
            ...prev,
            id: result.id,
            username: result.username,
            fullname: result.fullname,
            chatbotCount: result.chatbotCount ?? 0,
            purchasedCount: result.purchasedCount ?? 0,
            sharedDialogueCount: result.sharedDialogueCount ?? 0,
            planName: result.planName || FREE_PLAN_NAME,
            dailyCoinsRemaining: result.dailyCoinsRemaining ?? 0,
            dailyCoinsTotal: result.dailyCoinsTotal ?? 0,
          }));
        }
      }
    } catch (err) {
      // Keep previous values on failure
    }
  }, [userId]);

  const refetchBalance = useCallback(async () => {
    if (!userId) return;
    try {
      const res = await fetch(`/api/wallet/getmybalance.php?user_id=${userId}`);
      if (res.ok) {
        const result = await res.json();
        if (result.success) {
          setAccount((prev) => ({
            ...prev,
            balance: result.balance ?? 0,
            transactions: Array.isArray(result.transactions) ? result.transactions : [],
          }));
        }
      }
    } catch (err) {
      // Keep previous value on failure
    }
  }, [userId]);

  useEffect(() => {
    if (!userId) return;
    refetchAccount();
    refetchBalance();
  }, [userId, refetchAccount, refetchBalance]);

  // N-50 — günlük coin TEK istemci kaynağı. Sohbet sayfası sunucudan aldığı
  // her değeri (generatereply meta karesi, checkmessageallowance) buraya
  // yazar; kenar çubuğu ve sohbet çipi aynı değeri okur. Eskiden kenar
  // çubuğu sayfa açılışındaki değerde kalıyordu (7/10) ve çip güncel değeri
  // gösteriyordu (5).
  const setDailyCoinsRemaining = useCallback((n) => {
    if (typeof n !== "number") return;
    setAccount((prev) => (prev.dailyCoinsRemaining === n ? prev : { ...prev, dailyCoinsRemaining: n }));
  }, []);

  return { account, refetchAccount, refetchBalance, setDailyCoinsRemaining };
}
