import { Check } from "lucide-react";
import { cn } from "@/lib/utils";

/** Small centered inline banner for the page's API-error / upgrade-success notices. */
export default function StatusBanner({ variant = "error", children }) {
  return (
    <div
      className={cn(
        "mb-6 flex items-center gap-2 rounded-xl p-4 text-sm",
        variant === "success"
          ? "bg-emerald-500/10 border border-emerald-500/20 text-emerald-300"
          : "bg-rose-500/10 border border-rose-500/20 text-rose-300",
      )}
    >
      {variant === "success" && <Check className="h-4 w-4 shrink-0 text-emerald-400" />}
      <span>{children}</span>
    </div>
  );
}
