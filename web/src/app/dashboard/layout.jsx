'use client';
import { React, useEffect, useState } from 'react';
import { usePathname, useRouter } from 'next/navigation';
import Header from "@/widgets/DashboardHeader";
import NavbarMobile from "@/widgets/Navbar";
import { Sidebar } from "@/widgets/Sidebar";
import { useAccountData } from "@/shared/hooks/useAccountData";
import { UserContext } from "@/shared/contexts/UserContext";

export default function DashboardLayout({ children }) {
    const router = useRouter();
    const pathname = usePathname();
    const [userId, setUserId] = useState(null);
    const [authReady, setAuthReady] = useState(false);
    const { account, refetchAccount, refetchBalance, setDailyCoinsRemaining } = useAccountData(userId);

    // Checkout is a focused, distraction-free flow (common e-commerce
    // pattern) — no main nav sidebar/mobile navbar while paying.
    const hideNav = pathname?.startsWith('/dashboard/checkout');

    /* Misafir gezinme.
     *
     * Eskiden oturumu olmayan HERKES koşulsuz /login'e atılıyordu. Bunun iki
     * sonucu vardı: (a) kullanıcı siteyi görmeden kayıt olmak zorundaydı,
     * (b) bileşenlerde yazılmış olan `requireLogin()` eylem kapıları hiç
     * çalışmıyordu — misafir zaten içeri giremediği için ulaşılamaz kodlardı.
     *
     * Artık yalnızca GEZİNME yüzeyleri misafire açık. Cüzdan, ayarlar,
     * sohbet, bot yönetimi gibi doğası gereği kişisel olan rotalar eskisi
     * gibi girişe zorluyor — bunları açmak, boş/401 dolu ekranlar göstermek
     * olurdu.
     *
     * Beyaz liste ROTA BAZLI ve kapalı uçlu: listede olmayan her şey korumalı
     * kabul ediliyor. Yeni bir özel sayfa eklendiğinde kimsenin bir şey
     * yapması gerekmiyor; açılması gereken sayfa bilinçli olarak buraya
     * eklenir. */
    const guestAllowed =
        pathname === "/dashboard" ||
        pathname === "/dashboard/" ||
        pathname?.startsWith("/dashboard/explore");

    useEffect(() => {
        let cancelled = false;

        async function checkSession() {
            try {
                const res = await fetch("/api/auth/sessioncheck.php", { credentials: "include" });
                const result = await res.json();
                if (cancelled) return;

                if (result.authenticated) {
                    setUserId(result.user_id);
                    setAuthReady(true);
                    return;
                }
                if (guestAllowed) {
                    // userId null kalıyor: sayfalar kişisel istekleri zaten
                    // `if (!userId) return` ile atlıyor, eylemler de
                    // requireLogin() ile girişe yönlendiriyor.
                    setUserId(null);
                    setAuthReady(true);
                    return;
                }
                router.replace("/login");
            } catch (err) {
                if (cancelled) return;
                // Ağ hatasında misafir sayfalarını da kilitlemiyoruz; kişisel
                // veri zaten sunucu tarafında korunuyor.
                if (guestAllowed) {
                    setUserId(null);
                    setAuthReady(true);
                    return;
                }
                router.replace("/login");
            }
        }

        checkSession();
        return () => {
            cancelled = true;
        };
    }, [router, guestAllowed]);

    if (!authReady) {
        return (
            <div className="flex items-center justify-center min-h-screen text-white/60 text-body">
                <span className="animate-pulse">Oturum kontrol ediliyor...</span>
            </div>
        );
    }

    return (
        <UserContext.Provider value={{ userId, account, refetchAccount, refetchBalance, setDailyCoinsRemaining }}>
            <a
                href="#main-content"
                className="sr-only focus-visible:not-sr-only focus-visible:fixed focus-visible:top-2 focus-visible:left-2 focus-visible:z-[999] focus-visible:rounded-lg focus-visible:bg-luma-panel focus-visible:px-4 focus-visible:py-2 focus-visible:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
                İçeriğe geç
            </a>
            <div className="relative flex h-screen overflow-hidden bg-luma-base">
                {/* Ambient glow layer — a quiet hint of depth, not a colored
                    wash. Black stays dominant; the two blobs are faint and
                    tucked into corners so they read as ambient light rather
                    than a background color. Fixed so it doesn't scroll with
                    page content; z-0 so it always sits behind the real UI
                    regardless of paint-order quirks between positioned and
                    non-positioned siblings. */}
                <div className="pointer-events-none fixed inset-0 z-0 overflow-hidden">
                    <div className="absolute -top-40 left-[20%] h-[820px] w-[820px] rounded-full bg-fuchsia-600/[0.07] blur-[160px]" />
                    <div className="absolute bottom-[-30%] right-[0%] h-[720px] w-[720px] rounded-full bg-violet-600/[0.06] blur-[160px]" />
                    <div className="absolute inset-0 bg-[radial-gradient(circle_at_1px_1px,rgba(255,255,255,0.035)_1px,transparent_0)] bg-[length:28px_28px]" />
                </div>

                <div className="relative z-10 flex h-full w-full">
                    {/* Sidebar — hidden on mobile, shown md+ (and hidden entirely during checkout) */}
                    {!hideNav && (
                        <div className="hidden md:flex shrink-0">
                            <Sidebar userId={userId} account={account} />
                        </div>
                    )}

                    {/* Main area */}
                    <div className="flex flex-col flex-1 min-w-0 overflow-y-auto">
                        {/* Mobile navbar */}
                        {!hideNav && (
                            <div className="md:hidden">
                                <NavbarMobile userId={userId} />
                            </div>
                        )}

                        <main className="flex flex-col flex-1 min-h-0">
                            {/* Ödeme akışı dikkat dağıtmayan tek odaklı bir ekran:
                                kenar çubuğu ve mobil navbar zaten hideNav ile
                                gizleniyordu, üst başlık ise koşulun dışında
                                kalmıştı. Sayfa kendi "Sepete Dön" düğmesini ve
                                TLS rozetini taşıyor. */}
                            {!hideNav && <Header userId={userId} account={account} />}
                            <div id="main-content" className="flex-1 w-full max-w-[1600px] mx-auto">
                                {children}
                            </div>
                        </main>
                    </div>
                </div>
            </div>
        </UserContext.Provider>
    );
}
