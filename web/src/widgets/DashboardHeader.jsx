import React, { useState, useEffect, useRef, useCallback } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import QuitModal from "@/features/auth/QuitModal";
import { formatCurrency } from "@/shared/lib/format";
import { toast } from "@/shared/hooks/use-toast";
import { logoutAndRedirect } from "@/shared/lib/logout";
import { isPaidPlan } from "@/shared/lib/pricing";
import useMarketplaceRegistration from "@/shared/hooks/useMarketplaceRegistration";
import {
  Search,
  Bell,
  ShoppingCart,
  LogIn,
  User,
  LogOut,
  X,
  Command,
  Settings,
  ChevronDown,
  Wallet,
} from "lucide-react";

const ICON_BTN_FOCUS =
  "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-500/60 focus-visible:ring-offset-2 focus-visible:ring-offset-[#050508]";

function Tooltip({ children, content }) {
  const [show, setShow] = useState(false);

  return (
    <div
      className="relative inline-flex items-center"
      onMouseEnter={() => setShow(true)}
      onMouseLeave={() => setShow(false)}
    >
      {children}
      {show && (
        <div className="absolute top-full mt-2.5 left-1/2 -translate-x-1/2 z-50 px-3 py-1.5 text-caption font-medium text-zinc-200 bg-zinc-950/90 backdrop-blur-xl border border-white/10 rounded-lg shadow-2xl whitespace-nowrap pointer-events-none animate-in fade-in slide-in-from-top-1.5 duration-200 tracking-wide">
          {content}
        </div>
      )}
    </div>
  );
}

function NotificationPopup({ onClose, notifications, loading, onMarkAllRead }) {
  const formatTime = (dateString) => {
    if (!dateString) return "";
    const date = new Date(String(dateString).replace(" ", "T"));
    if (isNaN(date.getTime())) return "";
    const diffInSeconds = Math.floor((Date.now() - date.getTime()) / 1000);
    if (diffInSeconds < 60) return "Şimdi";
    if (diffInSeconds < 3600)
      return `${Math.floor(diffInSeconds / 60)} dk önce`;
    if (diffInSeconds < 86400)
      return `${Math.floor(diffInSeconds / 3600)} saat önce`;
    return date.toLocaleDateString("tr-TR");
  };

  return (
    <div className="absolute right-0 top-full mt-3 z-40 w-80 sm:w-96 max-sm:inset-x-4 max-sm:w-auto rounded-2xl border border-white/10 bg-[#09090E]/95 p-4 shadow-[0_20px_50px_rgba(0,0,0,0.8)] backdrop-blur-2xl ring-1 ring-white/5 animate-in fade-in slide-in-from-top-3 duration-150">
      <div className="flex items-center justify-between pb-3.5 border-b border-white/5">
        <div className="flex items-center gap-2.5">
          <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-violet-500/10 border border-violet-500/20 text-violet-400">
            <Bell className="w-3.5 h-3.5" />
          </div>
          <span className="text-xs font-semibold tracking-wide text-white">
            Bildirimler
          </span>
          <span className="px-2 py-0.5 text-caption font-bold text-violet-300 bg-violet-500/15 border border-violet-500/30 rounded-full">
            {notifications.filter((n) => !n.is_read).length} Yeni
          </span>
        </div>
        <div className="flex items-center gap-2">
          <button
            onClick={onMarkAllRead}
            className="text-caption font-medium text-zinc-400 hover:text-violet-300 transition-colors"
          >
            Tümünü okundu işaretle
          </button>
          <button
            onClick={onClose}
            className="p-1 rounded-lg text-zinc-400 hover:bg-white/10 hover:text-white transition-colors"
          >
            <X className="w-3.5 h-3.5" />
          </button>
        </div>
      </div>

      <div className="mt-3.5 space-y-2 max-h-72 overflow-y-auto pr-1 scrollbar-thin scrollbar-thumb-zinc-800">
        {loading ? (
          <div className="flex flex-col gap-2">
            {Array.from({ length: 3 }).map((_, i) => (
              <div
                key={i}
                className="h-14 animate-pulse rounded-xl bg-white/[0.04]"
              />
            ))}
          </div>
        ) : notifications.length === 0 ? (
          <p className="py-6 text-center text-caption text-zinc-500">
            Henüz bildiriminiz yok.
          </p>
        ) : (
          notifications.map((item) => (
            <div
              key={item.id}
              className={`p-3 rounded-xl border transition-all duration-200 ${
                !item.is_read
                  ? "bg-violet-950/20 border-violet-500/30 shadow-[0_0_15px_rgba(139,92,246,0.08)]"
                  : "bg-zinc-900/30 border-white/5 opacity-70 hover:opacity-100 hover:border-white/10"
              }`}
            >
              <div className="flex items-start justify-between gap-2">
                <span className="text-xs font-semibold text-white tracking-tight">
                  {item.title_tr}
                </span>
                <span className="text-caption font-mono text-zinc-500 shrink-0">
                  {formatTime(item.created_at)}
                </span>
              </div>
              <p className="mt-1 text-caption text-zinc-400 leading-relaxed">
                {item.message_tr}
              </p>
            </div>
          ))
        )}
      </div>
    </div>
  );
}

function ProfilePopup({ user, profileImage, onLogout, onClose, onNavigate, showBalance }) {
  const go = (href) => {
    onClose();
    onNavigate(href);
  };

  return (
    <div className="absolute right-0 top-full mt-3 z-50 w-80 sm:w-96 rounded-2xl border border-white/10 bg-[#09090E] p-4 shadow-[0_20px_50px_rgba(0,0,0,0.8)] backdrop-blur-2xl ring-1 ring-white/5 animate-in fade-in slide-in-from-top-3 duration-150">
      <div className="flex items-center gap-3.5 pb-3.5 border-b border-white/5">
        <div className="relative w-11 h-11 rounded-full overflow-hidden bg-gradient-to-tr from-violet-600 via-indigo-600 to-fuchsia-600 flex items-center justify-center text-white font-bold text-sm shrink-0 shadow-lg shadow-violet-950/50 ring-1 ring-white/20">
          {profileImage ? (
            <img
              src={profileImage}
              alt={user.fullname || user.username}
              className="w-full h-full object-cover"
            />
          ) : (
            <span>
              {(user.fullname || user.username || "?").charAt(0).toUpperCase()}
            </span>
          )}
          <span className="absolute bottom-0.5 right-0.5 w-2.5 h-2.5 rounded-full bg-emerald-400 border-2 border-[#09090E]" />
        </div>
        <div className="flex flex-col min-w-0 flex-1">
          <div className="flex items-center gap-1.5">
            <span className="text-xs font-semibold text-white truncate tracking-tight">
              {user.fullname || user.username || "Kullanıcı"}
            </span>
            {/* E-05: sabit "Ücretsiz Plan" karşılaştırması, sunucunun kanonik
                adı "Ücretsiz" olduğu için ücretsiz kullanıcıya da Pro rozeti
                gösteriyordu. */}
            {isPaidPlan(user.planName) && (
              <span className="px-1.5 py-0.2 text-caption font-bold uppercase tracking-wider text-fuchsia-400  rounded">
                Pro
              </span>
            )}
          </div>
          <span className="text-caption font-mono text-zinc-400 truncate mt-0.5">
            @{user.username || "lumanoris_user"}
          </span>
        </div>
      </div>

      <div className="grid grid-cols-3 my-3 p-3 rounded-xl bg-white/[0.02] border border-white/5 text-center">
        <div className="min-w-0 px-1.5">
          <div className="text-[10px] font-semibold uppercase tracking-wide text-zinc-500 truncate">
            Botlar
          </div>
          <div className="mt-1 text-base font-bold tabular-nums text-white">
            {user.chatbotCount ?? 0}
          </div>
        </div>
        <div className="min-w-0 px-1.5 border-x border-white/5">
          <div className="text-[10px] font-semibold uppercase tracking-wide text-zinc-500 truncate">
            Lisans
          </div>
          <div className="mt-1 text-base font-bold tabular-nums text-violet-400">
            {user.purchasedCount ?? 0}
          </div>
        </div>
        <div className="min-w-0 px-1.5">
          <div className="text-[10px] font-semibold uppercase tracking-wide text-zinc-500 truncate">
            Diyalog
          </div>
          <div className="mt-1 text-base font-bold tabular-nums text-fuchsia-400">
            {user.sharedDialogueCount ?? 0}
          </div>
        </div>
      </div>

      <div className="space-y-1">
        <button
          type="button"
          onClick={() => go("/dashboard/settings?tab=user")}
          className="flex items-center gap-2.5 w-full px-3 py-2 text-xs font-medium text-zinc-300 rounded-lg hover:bg-white/5 hover:text-white transition-all"
        >
          <User className="w-3.5 h-3.5 shrink-0 text-zinc-400" />
          <span className="truncate">Profil Detayları</span>
        </button>
        <button
          type="button"
          onClick={() => go("/dashboard/wallet")}
          className="flex items-center gap-2.5 w-full px-3 py-2 text-xs font-medium text-zinc-300 rounded-lg hover:bg-white/5 hover:text-white transition-all"
        >
          <Wallet className="w-3.5 h-3.5 shrink-0 text-emerald-400" />
          <span className="truncate">Bakiye &amp; Ödemeler</span>
          {/* Madde 4 / GK-5 — kaydı olmayana bakiye tutarı gösterilmez. */}
          {showBalance && (
            <span className="ml-auto shrink-0 text-caption font-mono font-semibold text-emerald-400 bg-emerald-500/10 px-1.5 py-0.5 rounded border border-emerald-500/20">
              {formatCurrency(user.balance ?? 0)}
            </span>
          )}
        </button>
        <button
          type="button"
          onClick={() => go("/dashboard/settings")}
          className="flex items-center gap-2.5 w-full px-3 py-2 text-xs font-medium text-zinc-300 rounded-lg hover:bg-white/5 hover:text-white transition-all"
        >
          <Settings className="w-3.5 h-3.5 shrink-0 text-zinc-400" />
          <span className="truncate">Sistem Ayarları</span>
        </button>
      </div>

      <div className="mt-3 pt-2.5 border-t border-white/5">
        <button
          onClick={onLogout}
          className="flex items-center justify-between w-full px-3 py-2 text-xs font-medium text-rose-400 rounded-lg hover:bg-rose-500/10 hover:border hover:border-rose-500/20 transition-all"
        >
          <span>Oturumu Kapat</span>
          <LogOut className="w-3.5 h-3.5" />
        </button>
      </div>
    </div>
  );
}

const DEFAULT_ACCOUNT = {
  id: null,
  username: "",
  fullname: "",
  chatbotCount: 0,
  purchasedCount: 0,
  sharedDialogueCount: 0,
  balance: 0,
};

export default function Header({
  userId = null,
  onNavigate,
  account = DEFAULT_ACCOUNT,
}) {
  const router = useRouter();
  const navigate = onNavigate || ((href) => router.push(href));
  const registration = useMarketplaceRegistration(userId);
  const [showProfile, setShowProfile] = useState(false);
  const [showNotification, setShowNotification] = useState(false);
  const [profileImage, setProfileImage] = useState(null);
  const [searchQuery, setSearchQuery] = useState("");
  const [cartCount, setCartCount] = useState(0);
  const [notifications, setNotifications] = useState([]);
  const [notificationsLoading, setNotificationsLoading] = useState(true);
  const [quitOpen, setQuitOpen] = useState(false);
  const profileMenuRef = useRef(null);
  const notificationMenuRef = useRef(null);
  const notificationBtnRef = useRef(null);
  const profileBtnRef = useRef(null);

  const searchInputRef = useRef(null);

  const fetchCartCount = async () => {
    const targetId = userId || account.id;
    if (!targetId) return;
    try {
      const res = await fetch(
        `/api/marketplace/getcartcount.php?user_id=${targetId}`,
      );
      if (res.ok) {
        const result = await res.json();
        if (result.success) setCartCount(result.count);
      }
    } catch (err) {
      // Graceful fallback for standalone canvas preview
    }
  };

  useEffect(() => {
    if (!userId) return;
    fetchCartCount();
    const handleCartUpdate = () => fetchCartCount();
    window.addEventListener("cartUpdated", handleCartUpdate);
    const interval = setInterval(fetchCartCount, 30000);
    return () => {
      window.removeEventListener("cartUpdated", handleCartUpdate);
      clearInterval(interval);
    };
  }, [userId]);

  const fetchNotifications = useCallback(async () => {
    if (!userId) return;
    try {
      const res = await fetch("/api/notification/getnotification.php", {
        credentials: "include",
      });
      const result = await res.json();
      if (result.success && Array.isArray(result.notifications)) {
        setNotifications(result.notifications);
      }
    } catch (err) {
      console.error("Bildirimler yüklenemedi:", err);
    } finally {
      setNotificationsLoading(false);
    }
  }, [userId]);

  useEffect(() => {
    if (!userId) return;
    fetchNotifications();
    const interval = setInterval(fetchNotifications, 30000);
    return () => clearInterval(interval);
  }, [userId, fetchNotifications]);

  const unreadNotificationCount = notifications.filter(
    (n) => !n.is_read,
  ).length;

  /* N-38 — eskiden rozet hemen sıfırlanıyor, istekler yanıtına bakılmadan
     gönderiliyordu; sunucu reddederse yenilemede bildirimler yine "okunmamış"
     çıkıyordu. Şimdi yalnızca sunucunun onayladığı bildirimler okundu olur;
     biri başarısızsa mesaj gösterilir. */
  const markAllNotificationsRead = async () => {
    const unread = notifications.filter((n) => !n.is_read);
    if (unread.length === 0) return;
    const results = await Promise.all(
      unread.map(async (n) => {
        try {
          const formData = new FormData();
          formData.append("data", JSON.stringify({ id: n.id }));
          const res = await fetch("/api/notification/readnotification.php", {
            method: "POST",
            body: formData,
            credentials: "include",
          });
          const result = await res.json().catch(() => null);
          return res.ok && result?.success ? n.id : null;
        } catch (err) {
          console.error("Bildirim okundu işaretlenemedi:", err);
          return null;
        }
      }),
    );
    const done = new Set(results.filter((id) => id !== null));
    if (done.size > 0) {
      setNotifications((prev) =>
        prev.map((n) => (done.has(n.id) ? { ...n, is_read: true } : n)),
      );
    }
    if (done.size < unread.length) {
      toast.error("Bazı bildirimler okundu olarak işaretlenemedi. Lütfen tekrar deneyin.");
    }
  };

  useEffect(() => {
    if (!userId) return;
    async function fetchDbPhoto() {
      try {
        const res = await fetch(`/api/user/user_getphoto.php?id=${userId}`);
        if (res.ok) {
          const result = await res.json();
          const dbImage = result.success ? result.avatar || null : null;
          setProfileImage(dbImage);
          if (dbImage) localStorage.setItem("userProfileImage", dbImage);
          else localStorage.removeItem("userProfileImage");
        }
      } catch (err) {
        // Safe fallback
      }
    }
    fetchDbPhoto();
    // Settings sayfasında fotoğraf yüklemesi başarılı olunca bu event
    // dispatch ediliyor, header sayfa yenilenmeden güncel fotoğrafı çeker.
    window.addEventListener("profileUpdated", fetchDbPhoto);
    return () => window.removeEventListener("profileUpdated", fetchDbPhoto);
  }, [userId]);

  useEffect(() => {
    const handleKeyDown = (e) => {
      if ((e.metaKey || e.ctrlKey) && e.key === "k") {
        e.preventDefault();
        searchInputRef.current?.focus();
      }
    };
    window.addEventListener("keydown", handleKeyDown);
    return () => window.removeEventListener("keydown", handleKeyDown);
  }, []);

  useEffect(() => {
    if (!showProfile) return;
    function handleClickOutside(e) {
      if (
        profileMenuRef.current &&
        !profileMenuRef.current.contains(e.target)
      ) {
        setShowProfile(false);
      }
    }
    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, [showProfile]);

  /* Bildirim panelinde bu yoktu: profil menüsü dışarı tıklamayla kapanırken
     bildirimler yalnızca çarpı ya da Esc ile kapanıyordu. Yukarıdaki profil
     efektinin birebir aynısı.

     Dinlenen düğüm panel değil, tetikleyici düğmeyi DE içeren sarmalayıcı:
     yalnızca paneli dinleseydik zilin kendisi "dışarısı" sayılırdı, mousedown
     paneli kapatır, ardından gelen click tekrar açardı — düğme işlevsiz
     görünürdü.

     Kapatırken `closeNotification()` değil doğrudan `setShowNotification`
     kullanılıyor: o yardımcı odağı zile geri veriyor ve kullanıcı başka bir
     yere tıklamışken odağı geri çalmak yanlış olurdu. Esc yolu odağı geri
     vermeye devam ediyor, çünkü orada kullanıcı klavyede kalıyor. */
  useEffect(() => {
    if (!showNotification) return;
    function handleClickOutside(e) {
      if (
        notificationMenuRef.current &&
        !notificationMenuRef.current.contains(e.target)
      ) {
        setShowNotification(false);
      }
    }
    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, [showNotification]);

  const closeNotification = () => {
    setShowNotification(false);
    notificationBtnRef.current?.focus();
  };
  const closeProfile = () => {
    setShowProfile(false);
    profileBtnRef.current?.focus();
  };

  useEffect(() => {
    if (!showNotification && !showProfile) return;
    function handleEscape(e) {
      if (e.key !== "Escape") return;
      if (showNotification) closeNotification();
      if (showProfile) closeProfile();
    }
    document.addEventListener("keydown", handleEscape);
    return () => document.removeEventListener("keydown", handleEscape);
  }, [showNotification, showProfile]);

  const goToExplore = () => {
    navigate(
      `/dashboard/explore?search=${encodeURIComponent(searchQuery.trim())}`,
    );
  };

  const handleSearchKey = (e) => {
    if (e.key === "Enter" && searchQuery.trim() !== "") goToExplore();
  };

  const handleLogout = () => setQuitOpen(true);

  const handleConfirmLogout = async () => {
    setQuitOpen(false);
    // N-39: yalnızca sunucu çıkışı onaylarsa /login'e gider.
    const result = await logoutAndRedirect();
    if (!result.ok) toast.error(result.message);
  };

  return (
    <>
      <header className="sticky top-0 z-40 flex h-20 shrink-0 items-center justify-between gap-6 px-4 md:px-8 bg-[#050508]/85 backdrop-blur-2xl border-b border-white/[0.06] shadow-[0_4px_30px_rgba(0,0,0,0.5)]">
        {/* Brand Logo / Studio Indicator & Command Bar */}
        <div className="flex flex-1 min-w-0 items-center max-w-[580px] gap-4">
          <div className="group relative flex h-11 w-full items-center gap-3 rounded-2xl bg-white/[0.03] px-4 ring-1 ring-inset ring-white/[0.08] transition-all duration-300 focus-within:bg-gradient-to-r focus-within:from-fuchsia-500/[0.05] focus-within:to-violet-500/[0.05] focus-within:ring-fuchsia-500/50 focus-within:shadow-[0_0_25px_rgba(217,70,239,0.15)]">
            <button
              type="button"
              onClick={goToExplore}
              aria-label="Keşfet"
              className={`flex shrink-0 items-center justify-center text-zinc-400 transition-colors duration-200 hover:text-white ${ICON_BTN_FOCUS}`}
            >
              <Search className="h-4 w-4" strokeWidth={2} />
            </button>

            <input
              ref={searchInputRef}
              type="text"
              placeholder="Chatbot veya diyalog ara... (Cmd + K)"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              onKeyDown={handleSearchKey}
              data-focus-managed
              className="min-w-0 flex-1 bg-transparent text-body-sm text-white outline-none placeholder:text-zinc-500 font-medium"
            />

            <div className="hidden sm:flex items-center gap-1 px-2 py-0.5 rounded-lg bg-white/5 text-caption text-zinc-400 font-mono border border-white/10 shrink-0 shadow-inner">
              <Command className="w-2.5 h-2.5" /> K
            </div>
          </div>
          {/* N-45: arama kutusunun yanındaki "Keşfet" düğmesi kaldırıldı
              (müşteri isteği); Keşfet'e kenar çubuğundan ve aramadan gidiliyor. */}
        </div>

        {/* Action Controls & Profile Menu */}
        <div className="flex items-center gap-2.5 sm:gap-3.5">
          {/* Misafir kullanıcı: bildirim/sepet/profil kişisel veriye bağlı,
              hepsi 401 döner ve boş görünürdü. Onların yerine tek bir
              "Giriş Yap" duruyor — revize listesinin istediği sağ üst düğme.
              Erken `return` ile aşağıdaki bloğun tamamı hiç render edilmiyor. */}
          {!userId ? (
            <Link
              href="/login/"
              className="flex h-10 items-center gap-2 rounded-2xl border border-fuchsia-500/30 bg-fuchsia-500/10 px-4 text-xs font-semibold text-fuchsia-200 transition-colors hover:border-fuchsia-500/50 hover:bg-fuchsia-500/20 hover:text-white"
            >
              <LogIn className="h-4 w-4" strokeWidth={1.75} />
              <span>Giriş Yap</span>
            </Link>
          ) : (
            <>
              {/* Notifications Trigger */}
              {/* N-43: dar ekranda `relative` yalnızca sm üstünde — panel o
                  zaman başlığa göre konumlanır (inset-x-4); zile göre
                  konumlanınca 390 px'te sola taşıp kesiliyordu. */}
              <div className="sm:relative" ref={notificationMenuRef}>
                <Tooltip content="Bildirimler">
                  <button
                    ref={notificationBtnRef}
                    onClick={() => {
                      setShowNotification(!showNotification);
                      setShowProfile(false);
                    }}
                    aria-label="Bildirimler"
                    aria-haspopup="dialog"
                    aria-expanded={showNotification}
                    className={`relative flex h-10 w-10 items-center justify-center rounded-2xl bg-white/[0.03] border border-white/[0.08] text-zinc-300 transition-all duration-200 hover:bg-violet-500/10 hover:border-violet-500/30 hover:text-violet-300 hover:shadow-[0_0_15px_rgba(139,92,246,0.15)] ${ICON_BTN_FOCUS}`}
                  >
                    <Bell className="h-4 w-4" strokeWidth={1.75} />
                    {unreadNotificationCount > 0 && (
                      <>
                        <span className="absolute top-2.5 right-2.5 flex h-2 w-2 rounded-full bg-fuchsia-500 animate-ping" />
                        <span className="absolute top-2.5 right-2.5 flex h-2 w-2 rounded-full bg-fuchsia-500" />
                      </>
                    )}
                  </button>
                </Tooltip>

                {showNotification && (
                  <NotificationPopup
                    onClose={closeNotification}
                    notifications={notifications}
                    loading={notificationsLoading}
                    onMarkAllRead={markAllNotificationsRead}
                  />
                )}
              </div>

              {/* Cart Trigger */}
              <Tooltip content="Sepetim & Lisanslar">
                <button
                  onClick={() => navigate("/dashboard/checkout")}
                  aria-label="Sepetim"
                  className={`relative flex h-10 w-10 items-center justify-center rounded-2xl bg-white/[0.03] border border-white/[0.08] text-zinc-300 transition-all duration-200 hover:bg-fuchsia-500/10 hover:border-fuchsia-500/30 hover:text-fuchsia-300 hover:shadow-[0_0_15px_rgba(217,70,239,0.15)] ${ICON_BTN_FOCUS}`}
                >
                  <ShoppingCart className="h-4 w-4" strokeWidth={1.75} />
                  {cartCount > 0 && (
                    <span className="absolute -top-1 -right-1 flex h-[19px] min-w-[19px] items-center justify-center rounded-full bg-gradient-to-r from-fuchsia-600 to-pink-600 px-1 text-caption font-bold leading-none tabular-nums text-white shadow-lg shadow-fuchsia-950/80 border border-white/20">
                      {cartCount}
                    </span>
                  )}
                </button>
              </Tooltip>

              {/* User Profile Menu Trigger */}
              <div className="relative ml-1 sm:ml-2" ref={profileMenuRef}>
                <button
                  ref={profileBtnRef}
                  aria-label="Profil menüsü"
                  aria-haspopup="dialog"
                  aria-expanded={showProfile}
                  onClick={() => setShowProfile((prev) => !prev)}
                  className={`flex items-center gap-2.5 p-1.5 rounded-2xl bg-white/[0.03] border border-white/[0.08] transition-all duration-200 hover:border-violet-500/40 hover:bg-white/[0.06] hover:shadow-[0_0_20px_rgba(139,92,246,0.15)] ${ICON_BTN_FOCUS}`}
                >
                  <div className="relative h-8 w-8 overflow-hidden rounded-full bg-gradient-to-tr from-violet-600 to-indigo-600 flex items-center justify-center text-white text-xs font-bold shrink-0 ring-1 ring-white/20">
                    {profileImage ? (
                      <img
                        src={profileImage}
                        alt=""
                        className="h-full w-full object-cover"
                      />
                    ) : (
                      <span>
                        {(account.fullname || account.username || "?")
                          .charAt(0)
                          .toUpperCase()}
                      </span>
                    )}
                  </div>
                  <ChevronDown className="w-3.5 h-3.5 text-zinc-400 mr-1 hidden sm:block transition-transform duration-200" />
                </button>

                {showProfile && (
                  <ProfilePopup
                    user={account}
                    profileImage={profileImage}
                    onLogout={handleLogout}
                    onClose={closeProfile}
                    onNavigate={navigate}
                    showBalance={registration.registered}
                  />
                )}
              </div>
            </>
          )}
        </div>
      </header>

      {/* Logout Confirmation Modal */}
      <QuitModal
        isOpen={quitOpen}
        onClose={() => setQuitOpen(false)}
        onConfirm={handleConfirmLogout}
      />
    </>
  );
}
