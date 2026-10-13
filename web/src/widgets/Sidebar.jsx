import React, { useEffect, useState } from "react";
import { useRouter, usePathname } from "next/navigation";
import QuitModal from "@/features/auth/QuitModal";
import { toast } from "@/shared/hooks/use-toast";
import { logoutAndRedirect } from "@/shared/lib/logout";
import logo from "@/images/header-logo-icon.png";
import { Lumacoin } from "@/shared/ui/lumacoin";
import { FREE_PLAN_NAME, isPaidPlan } from "@/shared/lib/pricing";
import useMarketplaceRegistration from "@/shared/hooks/useMarketplaceRegistration";
import {
  Plus,
  TrendingUp,
  ChevronLeft,
  ChevronRight,
  Home,
  Bot,
  ShoppingBag,
  Users,
  ListChecks,
  History,
  Wallet,
  NotebookText,
  Settings,
  LogOut,
  Command,
} from "lucide-react";

function formatCurrency(amount) {
  const num = Number(amount) || 0;
  return new Intl.NumberFormat("tr-TR", {
    style: "currency",
    currency: "TRY",
  }).format(num);
}

const STUDIO_ITEMS = [
  {
    href: "/dashboard/chatbots",
    icon: Bot,
    label: "Chatbotlarım",
  },
];

const EXPLORE_ITEMS = [
  { href: "/dashboard", icon: Home, label: "Anasayfa", exact: true },
  {
    href: "/dashboard/purchased",
    icon: ShoppingBag,
    label: "Satın Aldıklarım",
  },
  { href: "/dashboard/following", icon: Users, label: "Takip Edilenler" },
  { href: "/dashboard/list", icon: ListChecks, label: "Liste" },
  { href: "/dashboard/history", icon: History, label: "Geçmişim" },
  { href: "/dashboard/wallet", icon: Wallet, label: "Bakiyem" },
  { href: "/dashboard/notes", icon: NotebookText, label: "Diyalog Defteri" },
];

function MinimalTooltip({ children, text, side = "right" }) {
  const [show, setShow] = useState(false);

  return (
    <div
      className="relative flex items-center"
      onMouseEnter={() => setShow(true)}
      onMouseLeave={() => setShow(false)}
    >
      {children}
      {show && (
        <div
          className={`absolute z-50 px-2.5 py-1 text-caption font-medium text-zinc-200 bg-zinc-900/95 backdrop-blur-md border border-zinc-700/60 rounded-md shadow-xl whitespace-nowrap pointer-events-none transition-all duration-150 animate-in fade-in slide-in-from-left-1 ${
            side === "right" ? "left-full ml-3" : "bottom-full mb-2"
          }`}
        >
          {text}
        </div>
      )}
    </div>
  );
}

const DEFAULT_ACCOUNT = {
  fullname: "",
  username: "",
  chatbotCount: 0,
  balance: 0,
  planName: FREE_PLAN_NAME,
  dailyCoinsRemaining: 0,
  dailyCoinsTotal: 0,
};

export function Sidebar({
  isMobileOpen = false,
  onNavigate,
  userId = null,
  account = DEFAULT_ACCOUNT,
}) {
  const router = useRouter();
  const pathname = usePathname();
  const [collapsed, setCollapsed] = useState(false);
  const [isQuitModalOpen, setIsQuitModalOpen] = useState(false);

  // Madde 5 / GK-12 — "Satın Aldıklarım" yalnızca en az bir aboneliği
  // (süresi dolmuşlar dahil) olan kullanıcıya görünür. Kaynak mevcut
  // getmysubscriptions.php (tam satın alma geçmişini döndürüyor); yeni uç
  // nokta yok. null = henüz bilinmiyor → öğe gizli (sonradan belirmesi,
  // görünüp kaybolmasından iyi). İstek başarısız olursa öğe GÖSTERİLİR:
  // bir ağ hatası kullanıcının satın aldıklarına giden yolu saklamamalı.
  const [hasPurchases, setHasPurchases] = useState(null);
  // Madde 4 / GK-5 — kaydı olmayana bakiye tutarı gösterilmez.
  const registration = useMarketplaceRegistration(userId);
  useEffect(() => {
    if (!userId) {
      setHasPurchases(false);
      return;
    }
    let cancelled = false;
    fetch("/api/wallet/getmysubscriptions.php", { credentials: "include" })
      .then((res) => res.json())
      .then((data) => {
        if (cancelled) return;
        setHasPurchases(
          data?.success && Array.isArray(data.subscriptions)
            ? data.subscriptions.length > 0
            : true,
        );
      })
      .catch(() => {
        if (!cancelled) setHasPurchases(true);
      });
    return () => {
      cancelled = true;
    };
  }, [userId]);

  const exploreItems = EXPLORE_ITEMS.filter(
    (item) => item.href !== "/dashboard/purchased" || hasPurchases === true,
  );

  const displayName = account.fullname || account.username || "Kullanıcı";
  const avatarLetter = (account.fullname || account.username || "?")
    .charAt(0)
    .toUpperCase();

  const isActive = (href, exact = false) =>
    exact
      ? pathname === href || pathname === href + "/"
      : pathname.startsWith(href);

  const handleNavigate = (href) => {
    router.push(href);
    if (onNavigate) onNavigate(href);
  };

  const handleCollapseToggle = () => {
    const nextCollapsed = !collapsed;
    setCollapsed(nextCollapsed);
    if (typeof window !== "undefined") {
      window.dispatchEvent(
        new CustomEvent("logoClicked", { detail: { clicked: nextCollapsed } }),
      );
    }
  };

  const renderNavItem = (item, category) => {
    const { href, icon: Icon, label, exact, badge, pill } = item;
    const active = isActive(href, exact);
    const isStudio = category === "studio";

    const itemContent = (
      <button
        type="button"
        onClick={() => handleNavigate(href)}
        className={`group relative flex items-center w-full rounded-xl text-left transition-all duration-200 ease-out focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-500/60 ${
          collapsed ? "justify-center p-2.5" : "px-3 py-2.5"
        } ${
          active
            ? "bg-gradient-to-r from-white/[0.08] to-white/[0.02] text-white font-medium shadow-[inset_0_1px_0_0_rgba(255,255,255,0.1)] ring-1 ring-white/10"
            : "text-zinc-400 hover:text-zinc-100 hover:bg-white/[0.03]"
        }`}
      >
        {/* Active side indicator glow */}
        {active && (
          <span
            className={`absolute left-0 top-1/2 -translate-y-1/2 w-1 h-5 rounded-r-full transition-all duration-300 ${
              isStudio
                ? "bg-gradient-to-b from-violet-400 to-indigo-500 shadow-[0_0_12px_rgba(139,92,246,0.8)]"
                : "bg-gradient-to-b from-fuchsia-400 to-pink-500 shadow-[0_0_12px_rgba(217,70,239,0.8)]"
            }`}
          />
        )}

        <span
          className={`flex items-center justify-center shrink-0 rounded-lg transition-all duration-200 ${
            collapsed ? "w-6 h-6" : "w-5 h-5 mr-3"
          } ${
            active
              ? isStudio
                ? "text-violet-400"
                : "text-fuchsia-400"
              : "text-zinc-500 group-hover:text-zinc-300"
          }`}
        >
          <Icon
            className="w-[18px] h-[18px]"
            strokeWidth={active ? 2.2 : 1.75}
          />
        </span>

        {!collapsed && (
          <div className="flex items-center justify-between flex-1 min-w-0">
            <span className="truncate text-body-sm tracking-tight">
              {label}
            </span>
            {badge && (
              <span className="px-1.5 py-0.5 text-caption font-medium text-violet-300 bg-violet-500/10 border border-violet-500/20 rounded-md">
                {badge}
              </span>
            )}
            {pill && (
              <span className="px-1.5 py-0.5 text-caption font-semibold text-emerald-400 bg-emerald-500/10 border border-emerald-500/20 rounded-md">
                {pill}
              </span>
            )}
          </div>
        )}
      </button>
    );

    return (
      <li key={href} className="w-full">
        {collapsed ? (
          <MinimalTooltip text={label} side="right">
            {itemContent}
          </MinimalTooltip>
        ) : (
          itemContent
        )}
      </li>
    );
  };

  return (
    <div
      className={`relative h-screen sticky top-0 z-50 shrink-0 select-none transition-all duration-300 ease-out ${
        collapsed ? "w-[76px]" : "w-[260px]"
      } ${
        isMobileOpen ? "translate-x-0" : "max-md:-translate-x-full"
      } max-md:fixed max-md:left-0 max-md:top-0 max-md:z-[1000]`}
    >
      <aside className="relative flex flex-col justify-between h-full w-full overflow-hidden bg-[#08080C] border-r border-zinc-800/60 shadow-2xl backdrop-blur-2xl">
        {/* Subtle Ambient Background Glows */}
        <div className="pointer-events-none absolute -top-20 -left-20 w-56 h-56 rounded-full bg-violet-600/10 blur-[90px]" />
        <div className="pointer-events-none absolute bottom-10 -right-10 w-48 h-48 rounded-full bg-fuchsia-600/10 blur-[80px]" />

        {}
        <div className="flex flex-col px-3.5 pt-5 pb-2">
          {/* Logo & Workspace Title */}
          <div
            className={`flex items-center justify-between mb-6 ${
              collapsed ? "justify-center px-0" : "px-1"
            }`}
          >
            <button
              onClick={() => handleNavigate("/dashboard")}
              className="flex items-center gap-3 group text-left focus:outline-none"
            >
              <div className="relative flex items-center justify-center shrink-0 w-9 h-9 rounded-xl   shadow-md  transition-colors">
                <div className="pointer-events-none absolute h-2 w-2 rounded-full bg-violet-600/25 blur-[12px]" />
                <div className="pointer-events-none absolute h-4 w-4 rounded-full bg-fuchsia-500/35 blur-[16px]" />
                <img
                  src={logo.src}
                  alt="Lumanoris"
                  className="relative w-7 h-7 object-contain transition-transform group-hover:scale-110"
                />
              </div>
              {!collapsed && (
                <div className="flex flex-col min-w-0">
                  <div className="flex items-center gap-1.5">
                    <span className="font-semibold text-sm text-white tracking-tight truncate">
                      Lumanoris
                    </span>
                    {/* E-05: "Ücretsiz Plan" ile sabit karşılaştırma yapılıyordu;
                        sunucunun kanonik adı "Ücretsiz" olduğu için ücretsiz
                        kullanıcıya Pro rozeti gösteriliyordu. */}
                    {isPaidPlan(account.planName) && (
                      <span className="px-1.5 py-0.2 text-caption font-bold uppercase tracking-wider text-fuchsia-400  rounded">
                        Pro
                      </span>
                    )}
                  </div>
                  <span className="text-caption text-zinc-500 truncate">
                    AI Stüdyo & Market
                  </span>
                </div>
              )}
            </button>
          </div>

          {}
          <div className="mb-5">
            <button
              type="button"
              onClick={() => handleNavigate("/dashboard/chatbots/create")}
              className={`group relative w-full flex items-center justify-center gap-2 rounded-xl transition-all duration-200 overflow-hidden shadow-glow ${
                collapsed ? "p-2.5" : "py-2.5 px-3.5"
              } bg-gradient-btn hover:brightness-110 hover:-translate-y-0.5 active:translate-y-0 border border-white/20`}
            >
              {/* Shimmer overlay */}
              <div className="absolute inset-0 bg-gradient-to-r from-transparent via-white/15 to-transparent -translate-x-full group-hover:translate-x-full transition-transform duration-1000 ease-in-out" />

              <Plus
                className="w-4 h-4 text-white shrink-0 group-hover:rotate-90 transition-transform duration-300"
                strokeWidth={2.5}
              />
              {!collapsed && (
                <div className="flex items-center justify-between w-full">
                  <span className="text-xs font-semibold text-white tracking-tight">
                    Yeni Chatbot
                  </span>
                  <div className="flex items-center gap-0.5 px-1.5 py-0.5 rounded bg-black/20 text-caption text-white/70 font-mono">
                    <Command className="w-2.5 h-2.5" /> N
                  </div>
                </div>
              )}
            </button>
          </div>

          {}
          <div className="space-y-4 overflow-y-auto max-h-[calc(100vh-280px)] scrollbar-none pr-0.5">
            <div>
              {!collapsed && (
                <div className="px-2 mb-2 flex items-center justify-between">
                  <span className="text-caption font-bold uppercase tracking-[0.12em] text-zinc-400">
                    Stüdyo
                  </span>
                  <span className="w-1.5 h-1.5 rounded-full bg-violet-400/80 animate-pulse" />
                </div>
              )}
              <ul className="space-y-1 list-none p-0 m-0">
                {STUDIO_ITEMS.map((item) =>
                  renderNavItem(
                    {
                      ...item,
                      badge: `${account.chatbotCount} Bot`,
                    },
                    "studio",
                  ),
                )}
              </ul>
            </div>

            {}
            <div>
              {!collapsed && (
                <div className="px-2 mb-2 flex items-center justify-between">
                  <span className="text-caption font-bold uppercase tracking-[0.12em] text-zinc-400">
                    Keşfet
                  </span>
                  <span className="w-1.5 h-1.5 rounded-full bg-fuchsia-400/80" />
                </div>
              )}
              <ul className="space-y-1 list-none p-0 m-0">
                {exploreItems.map((item) =>
                  renderNavItem(
                    item.href === "/dashboard/wallet" && registration.registered
                      ? { ...item, pill: formatCurrency(account.balance) }
                      : item,
                    "explore",
                  ),
                )}
              </ul>
            </div>
          </div>
        </div>

        {}
        <div className="p-3 mt-auto space-y-2 border-t border-zinc-800/50 bg-gradient-to-b from-transparent to-black/40">
          {/* Settings Link */}
          {(() => {
            const settingsActive = isActive("/dashboard/settings");
            const settingsBtn = (
              <button
                type="button"
                onClick={() => handleNavigate("/dashboard/settings")}
                className={`group flex items-center w-full rounded-xl transition-all duration-200 ${
                  collapsed ? "justify-center p-2.5" : "px-3 py-2"
                } ${
                  settingsActive
                    ? "bg-white/10 text-white font-medium ring-1 ring-white/10"
                    : "text-zinc-400 hover:text-white hover:bg-white/[0.04]"
                }`}
              >
                <Settings
                  className={`w-[18px] h-[18px] shrink-0 transition-transform duration-300 group-hover:rotate-45 ${
                    collapsed ? "" : "mr-3"
                  }`}
                />
                {!collapsed && (
                  <span className="text-xs truncate">Ayarlar</span>
                )}
              </button>
            );

            return collapsed ? (
              <MinimalTooltip text="Ayarlar">{settingsBtn}</MinimalTooltip>
            ) : (
              settingsBtn
            );
          })()}

          {/* Plan / Daily Token Widget (When Expanded) */}
          {!collapsed && (
            <div className="relative overflow-hidden p-3 rounded-xl bg-gradient-to-br from-violet-950/40 via-zinc-900/60 to-zinc-950 border border-violet-500/20 group">
              <div className="flex items-center justify-between mb-2">
                <div className="flex items-center gap-1.5 text-xs font-medium text-violet-300">
                  <Lumacoin size={14} />
                  <span>{account.planName}</span>
                </div>
                {/* Yuzde yerine gercek sayi: veri zaten elimizde ve
                    "%100 Token" bir para birimi gibi okunmuyordu. */}
                <span className="text-caption font-mono text-zinc-400">
                  {account.dailyCoinsTotal > 0
                    ? `${account.dailyCoinsRemaining}/${account.dailyCoinsTotal} LMC`
                    : "0 LMC"}
                </span>
              </div>
              <div className="w-full h-1.5 rounded-full bg-zinc-800 overflow-hidden mb-3">
                <div
                  className="h-full bg-gradient-to-r from-violet-500 to-fuchsia-500 rounded-full"
                  style={{
                    width: `${account.dailyCoinsTotal > 0 ? Math.round((account.dailyCoinsRemaining / account.dailyCoinsTotal) * 100) : 0}%`,
                  }}
                />
              </div>
              <button
                type="button"
                onClick={() => handleNavigate("/dashboard/upgrade")}
                className="w-full py-1.5 px-2.5 text-caption font-semibold text-white bg-violet-600/30 hover:bg-violet-600/50 border border-violet-500/30 rounded-lg transition-all text-center flex items-center justify-center gap-1"
              >
                <TrendingUp className="w-3 h-3 text-violet-300" />
                <span>Hesabı Yükselt</span>
              </button>
            </div>
          )}

          {/* User Account / Quit Trigger */}
          <div className="pt-1 border-t border-zinc-800/40">
            <button
              type="button"
              onClick={() => setIsQuitModalOpen(true)}
              className={`flex items-center w-full rounded-xl p-1.5 text-left hover:bg-rose-500/10 text-zinc-400 hover:text-rose-400 transition-colors group ${
                collapsed ? "justify-center" : "justify-between"
              }`}
            >
              <div className="flex items-center gap-2.5 min-w-0">
                <div className="w-7 h-7 rounded-full bg-gradient-to-tr from-violet-600 to-indigo-600 flex items-center justify-center text-white text-xs font-semibold shrink-0 shadow-sm">
                  {avatarLetter}
                </div>
                {!collapsed && (
                  <div className="flex flex-col min-w-0">
                    <span className="text-xs font-medium text-zinc-200 truncate group-hover:text-rose-300">
                      {displayName}
                    </span>
                    <span className="text-caption text-zinc-500 truncate">
                      {account.username ? `@${account.username}` : ""}
                    </span>
                  </div>
                )}
              </div>
              {!collapsed && (
                <LogOut className="w-4 h-4 opacity-50 group-hover:opacity-100 shrink-0" />
              )}
            </button>
          </div>
        </div>
      </aside>

      {}
      <button
        type="button"
        onClick={handleCollapseToggle}
        className="group absolute top-8 -right-3.5 z-50 flex items-center justify-center w-7 h-7 rounded-full bg-[#12121A] border border-zinc-700/80 text-zinc-400 shadow-xl hover:text-white hover:border-violet-500/50 hover:bg-violet-600/20 active:scale-95 transition-all max-md:hidden"
        aria-label={collapsed ? "Genişlet" : "Daralt"}
      >
        {collapsed ? (
          <ChevronRight className="w-4 h-4 transition-transform group-hover:translate-x-0.5" />
        ) : (
          <ChevronLeft className="w-4 h-4 transition-transform group-hover:-translate-x-0.5" />
        )}
      </button>

      {/* Logout Confirmation Modal */}
      <QuitModal
        isOpen={isQuitModalOpen}
        onClose={() => setIsQuitModalOpen(false)}
        onConfirm={async () => {
          setIsQuitModalOpen(false);
          // N-39: yalnızca sunucu çıkışı onaylarsa /login'e gider.
          const result = await logoutAndRedirect();
          if (!result.ok) toast.error(result.message);
        }}
      />
    </div>
  );
}
