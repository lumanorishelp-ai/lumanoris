"use client"
import React, { useState, useEffect, useMemo, useContext } from "react";
import { useRouter } from "next/navigation";
import { Bot, Search } from "lucide-react";
import { toast } from "@/shared/hooks/use-toast";
import { Skeleton } from "@/shared/ui/skeleton";
import { EmptyState } from "@/shared/ui/empty-state";
import { UserContext } from "@/shared/contexts/UserContext";
import ChatbotsPageLayout from "./components/ChatbotsPageLayout";
import ChatbotsCardGrid from "./components/ChatbotsCardGrid";
import ChatbotCard from "./components/ChatbotCard";
import ChatbotsHero from "./components/ChatbotsHero";
import ChatbotsToolbar from "./components/ChatbotsToolbar";

/* N-53 — yayındaki (is_independent=0) FİYATSIZ bot satıcı kaydı istemeden
   herkese açıktır (GK-2); yalnızca fiyatlı bot pazaryeri satıcılığına bağlı.
   Eskiden rozet her herkese açık bot için `seller_status === "active"` şartı
   arıyor ve yayındaki ücretsiz botu "Yayında Değil" gösteriyordu. */
const isFreePublic = (b) =>
  !Number(b.is_independent) && !(Number(b.ucret_haftalik) > 0) && !(Number(b.ucret_aylik) > 0);
const isLivePublic = (b) => isFreePublic(b) || b.seller_status === "active";

export default function App() {
  const router = useRouter();
  const { userId } = useContext(UserContext);
  const [chatbots, setChatbots] = useState([]);
  const [categories, setCategories] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const [searchQuery, setSearchQuery] = useState("");
  const [selectedCategory, setSelectedCategory] = useState("all");
  const [sortBy, setSortBy] = useState("newest");

  useEffect(() => {
    fetch("/api/content/getcategories.php")
      .then((res) => res.json())
      .then((data) => {
        // ERR-003: getcategories artık zarflı ({success, categories}).
        if (Array.isArray(data?.categories)) setCategories(data.categories);
      })
      .catch((err) => console.error("Categories fetch error:", err));
  }, []);

  const fetchChatbots = () => {
    if (!userId) return;
    setLoading(true);
    setError(null);
    fetch("/api/chatbot/getchatbotsmenu.php", { credentials: "include" })
      .then((res) => res.json())
      .then((result) => {
        if (result.success && Array.isArray(result.bots)) {
          setChatbots(result.bots);
        } else {
          setError(result.message || "Chatbotlar yüklenemedi.");
        }
      })
      .catch((err) => {
        console.error("Chatbots fetch error:", err);
        setError("Sunucuya bağlanılamadı.");
      })
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    if (!userId) {
      setLoading(false);
      return;
    }
    fetchChatbots();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [userId]);

  const handleDelete = async (id) => {
    try {
      const formData = new FormData();
      formData.append("data", JSON.stringify({ id }));
      const res = await fetch("/api/chatbot/deletechatbot.php", {
        method: "POST",
        body: formData,
        credentials: "include",
      });
      const result = await res.json();
      if (!result.success) {
        toast.error(result.message || "Chatbot silinemedi.");
        return;
      }
      setChatbots((prev) => prev.filter((bot) => bot.id !== id));
      toast.success("Chatbot başarıyla silindi.");
    } catch (err) {
      console.error("Delete error:", err);
      toast.error("Sunucuya bağlanılamadı.");
    }
  };

  const filteredChatbots = useMemo(() => {
    return chatbots
      .filter((bot) => {
        const matchesSearch = bot.isim
          ?.toLowerCase()
          .includes(searchQuery.toLowerCase());
        const matchesCategory =
          selectedCategory === "all" ||
          String(bot.kategori_id) === String(selectedCategory);
        return matchesSearch && matchesCategory;
      })
      .sort((a, b) => {
        if (sortBy === "newest") return Number(b.id) - Number(a.id);
        if (sortBy === "name")
          return (a.isim || "").localeCompare(b.isim || "");
        if (sortBy === "likes")
          return Number(b.likes || 0) - Number(a.likes || 0);
        return 0;
      });
  }, [chatbots, searchQuery, selectedCategory, sortBy]);

  const metrics = useMemo(() => {
    const total = chatbots.length;
    const active = chatbots.filter(
      (b) => isLivePublic(b) || Number(b.is_independent),
    ).length;
    const totalDialogs = chatbots.reduce(
      (acc, b) => acc + Number(b.toplam_chats || 0),
      0,
    );
    const totalLikes = chatbots.reduce(
      (acc, b) => acc + Number(b.likes || 0),
      0,
    );
    return { total, active, totalDialogs, totalLikes };
  }, [chatbots]);

  if (loading) {
    return (
      <ChatbotsPageLayout className="min-h-screen bg-luma-base text-white">
        <div className="mb-12 rounded-3xl border border-white/[0.08] bg-white/[0.04] p-8">
          <Skeleton className="h-9 w-64" />
        </div>
        <ChatbotsCardGrid>
          {Array.from({ length: 4 }).map((_, i) => (
            <div
              key={i}
              className="rounded-3xl border border-white/[0.06] bg-[#0c0c10] p-5 flex flex-col gap-4"
            >
              <Skeleton className="aspect-[16/10] w-full rounded-2xl" />
              <Skeleton className="h-4 w-3/4" />
            </div>
          ))}
        </ChatbotsCardGrid>
      </ChatbotsPageLayout>
    );
  }

  return (
    <ChatbotsPageLayout className="min-h-screen bg-luma-base text-white pb-20">
      <ChatbotsHero metrics={metrics} />

      <ChatbotsToolbar
        searchQuery={searchQuery}
        onSearchChange={setSearchQuery}
        categories={categories}
        selectedCategory={selectedCategory}
        onSelectCategory={setSelectedCategory}
        sortBy={sortBy}
        onSortChange={setSortBy}
      />

      {error && (
        <div className="mb-6 rounded-2xl border border-rose-500/20 bg-rose-500/10 px-5 py-4 text-sm text-rose-300">
          {error}
        </div>
      )}

      {filteredChatbots.length > 0 && (
        <ChatbotsCardGrid>
          {filteredChatbots.map((bot) => {
            const targetCategory = categories.find(
              (cat) => String(cat.id) === String(bot.kategori_id),
            );
            const categoryLabel = targetCategory
              ? targetCategory.kategori_adi_tr
              : "Genel";
            const isOwn = String(bot.author_user_id) === String(userId);
            const isPurchased =
              !isOwn && String(bot.owner_user_id) === String(userId);
            const statusLabel = isPurchased
              ? "Satın Alındı"
              : Number(bot.is_independent)
                ? "Bağımsız"
                : isFreePublic(bot)
                  ? "Yayında"
                  : bot.seller_status === "active"
                    ? "Aktif"
                    : "Yayında Değil";

            return (
              <ChatbotCard
                key={bot.id}
                id={bot.id}
                userId={userId}
                title={bot.isim}
                image={bot.kapak_fotografi}
                profileImage={bot.profil_fotografi}
                category={categoryLabel}
                status={statusLabel}
                likes={bot.likes}
                dialogs={bot.toplam_chats}
                weeklyPrice={bot.ucret_haftalik}
                monthlyPrice={bot.ucret_aylik}
                isIndependent={!!bot.is_independent}
                isOwn={isOwn}
                onDelete={() => handleDelete(bot.id)}
                onChanged={fetchChatbots}
              />
            );
          })}
        </ChatbotsCardGrid>
      )}

      {!loading && !error && chatbots.length === 0 && (
        <EmptyState
          icon={Bot}
          title="Henüz Chatbot Oluşturmadınız"
          description="İlk yapay zeka asistanınızı oluşturarak başlayın."
          actionLabel="Yeni Chatbot Oluştur"
          onAction={() => router.push("/dashboard/chatbots/create")}
        />
      )}

      {filteredChatbots.length === 0 && chatbots.length > 0 && (
        <EmptyState
          icon={Search}
          title="Sonuç Bulunamadı"
          description="Arama kriterlerinize uygun chatbot bulunamadı."
          actionLabel="Filtreleri Temizle"
          onAction={() => {
            setSearchQuery("");
            setSelectedCategory("all");
          }}
        />
      )}
    </ChatbotsPageLayout>
  );
}
