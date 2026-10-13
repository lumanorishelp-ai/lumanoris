"use client";
import BotCard from "@/entities/chatbot/ui/BotCard.bento";

/**
 * Keşfet'in kart ızgarası. Anasayfa ile AYNI kartı kullanır — daha önce
 * burada ayrı bir bileşen (MarketplaceListCard) vardı ve iki sayfadaki
 * kartlar birbirine benzemiyordu.
 */
export default function BotList({ bots, onOpenDetails }) {
  return (
    <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
      {bots.map((bot) => (
        <BotCard
          key={bot.id}
          bot={bot}
          onOpenDetails={onOpenDetails}
        />
      ))}
    </div>
  );
}
