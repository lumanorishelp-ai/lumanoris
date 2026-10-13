import { Crown, Check, ArrowRight } from "lucide-react";
import { cn } from "@/lib/utils";
import PlanBadge from "./PlanBadge";
import PricingButton from "./PricingButton";

export default function PricingCard({
  plan,
  isSelected,
  isUpgrading,
  onChoose,
}) {
  const isFeatured = !!plan.badge;

  return (
    <div
      className={cn(
        "group relative flex flex-col justify-between rounded-3xl p-8 transition-all duration-300 ease-out backdrop-blur-2xl",
        isFeatured
          ? "bg-gradient-to-b from-zinc-900/90 via-zinc-900/60 to-zinc-950/90 border-2 border-fuchsia-500/40 shadow-[0_0_50px_rgba(192,38,211,0.15)] hover:border-fuchsia-400/70 hover:shadow-[0_0_70px_rgba(192,38,211,0.25)] -translate-y-2"
          : "bg-zinc-900/40 border border-zinc-800/80 hover:border-zinc-700/80 hover:bg-zinc-900/70 hover:-translate-y-0.5 shadow-xl",
        isSelected && "ring-2 ring-violet-500 border-transparent",
      )}
    >
      {/* Ambient Glow for Featured Card */}
      {isFeatured && (
        <div className="absolute -top-20 -right-20 w-40 h-40 bg-fuchsia-600/20 rounded-full blur-[70px] pointer-events-none transition-opacity duration-500 group-hover:opacity-100" />
      )}

      <div>
        {/* Header & Badge */}
        <div className="flex items-center justify-between mb-6">
          <div className="w-12 h-12 rounded-2xl bg-zinc-800/80 border border-zinc-700/60 flex items-center justify-center shadow-inner group-hover:scale-105 transition-transform duration-300">
            <Crown
              className={cn(
                "w-6 h-6",
                isFeatured ? "text-fuchsia-400" : "text-violet-400",
              )}
            />
          </div>
          {plan.badge && <PlanBadge variant="default">{plan.badge}</PlanBadge>}
        </div>

        <h3 className="text-xl font-bold text-white mb-2 tracking-tight">
          {plan.title}
        </h3>

        <p className="text-zinc-400 text-xs sm:text-sm leading-relaxed mb-6 min-h-[40px]">
          {plan.description}
        </p>

        {/* Pricing */}
        <div className="mb-6 pb-6 border-b border-zinc-800/80 flex items-baseline gap-1">
          <span className="text-4xl font-extrabold text-white tracking-tight">
            {plan.monthly_price}
          </span>
          {plan.monthly_price !== "₺0" && (
            <span className="text-xs text-zinc-400 font-medium">
              /Aylık
            </span>
          )}
        </div>
      </div>

      <div>
        {/* Action Button */}
        <PricingButton
          disabled={plan.title === "Ücretsiz" || isUpgrading}
          onClick={onChoose}
          variant={
            plan.buttonType === "primary"
              ? "primary"
              : plan.title === "Ücretsiz"
                ? "secondary"
                : "outline"
          }
          className="w-full rounded-xl py-3.5 text-xs font-semibold tracking-wide mb-8 group/btn"
        >
          <span>{isUpgrading ? "İşleniyor..." : plan.buttonText}</span>
          {plan.title !== "Ücretsiz" && !isUpgrading && (
            <ArrowRight className="w-4 h-4 ml-2 group-hover/btn:translate-x-1 transition-transform" />
          )}
        </PricingButton>

        {/* Features List */}
        <div className="space-y-4">
          <div className="text-xs font-bold uppercase tracking-wider text-zinc-400">
            Neler Dahil?
          </div>
          <ul className="flex flex-col gap-3">
            {plan.features.map((feature, i) => (
              <li
                key={i}
                className="flex items-start gap-3 text-xs sm:text-sm text-zinc-300"
              >
                <div className="w-5 h-5 rounded-full bg-violet-500/10 border border-violet-500/20 flex items-center justify-center shrink-0 mt-0.5">
                  <Check className="w-3 h-3 text-violet-400" />
                </div>
                <span className="leading-tight">{feature}</span>
              </li>
            ))}
          </ul>
        </div>
      </div>
    </div>
  );
}
