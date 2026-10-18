import React from "react";
import { LogOut } from "lucide-react";
import { cn } from "@/lib/utils";

export default function ProfilePopup({ user, onLogout }) {
    const [profileImage, setProfileImage] = React.useState(null);

    React.useEffect(() => {
        if (typeof window !== "undefined") {
            const savedImage = localStorage.getItem('userProfileImage');
            setProfileImage(savedImage);

            const handleProfileImageUpdated = () => {
                const updatedImage = localStorage.getItem('userProfileImage');
                setProfileImage(updatedImage);
            };

            window.addEventListener('profileImageUpdated', handleProfileImageUpdated);
            return () => {
                window.removeEventListener('profileImageUpdated', handleProfileImageUpdated);
            };
        }
    }, []);

    // DB, gerçek kaynak: yukarıdaki localStorage değeri sadece hızlı-önizlemedir.
    React.useEffect(() => {
        if (!user?.id) return;
        async function fetchDbPhoto() {
            try {
                const res = await fetch(`/api/user/user_getphoto.php?id=${user.id}`);
                const result = await res.json();
                if (result.success) setProfileImage(result.avatar || null);
            } catch (err) {
                console.error("Profil fotoğrafı alınamadı:", err);
            }
        }
        fetchDbPhoto();
    }, [user?.id]);

    return (
        <div className="flex min-w-[248px] max-w-[calc(100vw-24px)] flex-col gap-3.5 rounded-2xl border border-fuchsia-400/10 bg-gradient-card p-4 shadow-card">
            <div className="flex w-full items-center gap-3">
                <div className="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-full bg-gradient-luma ring-2 ring-fuchsia-400/20">
                    {profileImage ? (
                        <img src={profileImage} alt="" className="h-full w-full object-cover" />
                    ) : null}
                </div>
                <div className="min-w-0">
                    <div className="truncate font-display text-body font-semibold text-white">{user.username}</div>
                    <div className="truncate text-caption text-white/45">{user.fullname}</div>
                </div>
            </div>

            <div className="grid grid-cols-3 rounded-xl border border-white/[0.06] bg-white/[0.03] py-2.5">
                <div className="flex flex-col items-center gap-0.5 px-1 text-center">
                    <span className="font-display text-title-sm font-bold leading-none text-white">{user.chatbotCount}</span>
                    <span className="text-caption leading-tight text-white/45">Chatbot</span>
                </div>
                <div className="flex flex-col items-center gap-0.5 border-x border-white/[0.06] px-1 text-center">
                    <span className="font-display text-title-sm font-bold leading-none text-white">{user.purchasedCount || 0}</span>
                    <span className="text-caption leading-tight text-white/45">Satın Alınan</span>
                </div>
                <div className="flex flex-col items-center gap-0.5 px-1 text-center">
                    <span className="font-display text-title-sm font-bold leading-none text-white">{user.sharedDialogueCount || 0}</span>
                    <span className="text-caption leading-tight text-white/45">Diyalog</span>
                </div>
            </div>
            <button
                className={cn(
                    "flex items-center justify-center gap-4 rounded-xl border border-transparent bg-transparent p-3 text-white/70 transition-all duration-200",
                    "hover:border-rose-400/25 hover:bg-rose-500/10 hover:text-rose-400 hover:shadow-[0_4px_18px_rgba(244,63,94,0.15)]",
                    "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                )}
                onClick={onLogout}
            >
                <LogOut className="h-[18px] w-[18px]" strokeWidth={1.75} />
                <div className="text-sm font-medium">
                    Çıkış yap
                </div>
            </button>
        </div>
    );
}
