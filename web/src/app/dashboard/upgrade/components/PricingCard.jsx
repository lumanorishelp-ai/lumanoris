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
  const isFree = plan.title === "Ücretsiz";
  const canChoose = !isFree && (!plan.is_active_paid || plan.can_renew);

  return (
    <div
      className={cn(
        "group relative flex h-full flex-col rounded-2xl border p-6 transition-all duration-300",
        isFeatured
          ? "border-fuchsia-500/40 bg-gradient-to-b from-fuchsia-500/[0.08] via-luma-panel to-luma-panel shadow-[0_0_40px_rgba(192,38,211,0.12)] hover:border-fuchsia-400/60"
          : "border-fuchsia-400/10 bg-gradient-card shadow-card hover:border-fuchsia-400/20",
        isSelected && "ring-2 ring-fuchsia-500/60",
      )}
    >
      <div className="mb-5 flex h-11 items-center justify-between gap-3">
        <div className="flex h-11 w-11 items-center justify-center rounded-xl border border-white/10 bg-white/[0.04]">
          <Crown className={cn("h-5 w-5", isFeatured ? "text-fuchsia-300" : "text-violet-300")} />
        </div>
        {plan.badge && <PlanBadge variant="default">{plan.badge}</PlanBadge>}
      </div>

      <h3 className="font-display text-lg font-bold tracking-tight text-white">{plan.title}</h3>
      <p className="mt-1.5 line-clamp-3 min-h-[2.5rem] text-sm leading-5 text-white/55">
        {plan.description}
      </p>

      <div className="mt-5 flex min-h-[2.5rem] flex-wrap items-baseline gap-x-1.5 border-b border-white/[0.06] pb-5">
        <span className="font-display text-3xl font-extrabold tracking-tight text-white">
          {plan.monthly_price}
        </span>
        {plan.monthly_price !== "₺0" && (
          <span className="text-xs font-medium text-white/45">/ Aylık</span>
        )}
      </div>

      <div className="mt-5">
        <PricingButton
          disabled={isFree || (plan.is_active_paid && !plan.can_renew) || isUpgrading}
          onClick={onChoose}
          variant={plan.buttonType === "primary" ? "primary" : isFree ? "secondary" : "outline"}
          className="group/btn h-11 w-full rounded-xl text-sm font-semibold"
        >
          <span>{isUpgrading ? "İşleniyor..." : plan.buttonText}</span>
          {canChoose && !isUpgrading && (
            <ArrowRight className="ml-2 h-4 w-4 transition-transform group-hover/btn:translate-x-1" />
          )}
        </PricingButton>
        <p className="mt-2 min-h-[1rem] text-center text-caption text-white/45">
          {plan.is_active_paid
            ? plan.current_expires_at
              ? `Bitiş: ${new Date(plan.current_expires_at.replace(" ", "T")).toLocaleDateString("tr-TR")}${plan.can_renew ? " · kalan günleriniz korunur" : ""}`
              : "Süresiz"
            : ""}
        </p>
      </div>

      <div className="mt-4 flex-1">
        <p className="mb-3 text-caption font-bold uppercase tracking-wider text-white/45">Neler dahil?</p>
        <ul className="flex flex-col gap-2.5">
          {plan.features.map((feature, i) => (
            <li key={i} className="flex items-start gap-2.5 text-sm text-white/75">
              <span className="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full border border-violet-400/25 bg-violet-500/10">
                <Check className="h-2.5 w-2.5 text-violet-300" />
              </span>
              <span className="leading-5">{feature}</span>
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
}
