"use client";

import { useContext, useEffect, useState } from "react";
import { CheckCircle2, Clock, Mail, Phone, Send, Store, User, XCircle } from "lucide-react";
import { PageLayout, PageHeader, PageSection } from "@/shared/ui/page-layout";
import { Card, CardContent } from "@/shared/ui/card";
import { Input } from "@/shared/ui/input";
import { Textarea } from "@/shared/ui/textarea";
import { Button } from "@/shared/ui/button";
import { cn } from "@/lib/utils";
import { UserContext } from "@/shared/contexts/UserContext";
import useMarketplaceRegistration from "@/shared/hooks/useMarketplaceRegistration";

const DESCRIPTION_MIN = 20;
const DESCRIPTION_MAX = 2000;

const FIELDS = [
  { name: "full_name", label: "İsim Soyisim", placeholder: "Adınız Soyadınız", icon: User, autoComplete: "name", maxLength: 150 },
  { name: "phone", label: "Telefon", placeholder: "0532 123 45 67", icon: Phone, type: "tel", inputMode: "tel", autoComplete: "tel", maxLength: 20 },
  { name: "email", label: "E-posta", placeholder: "ornek@eposta.com", icon: Mail, type: "email", autoComplete: "email", maxLength: 190, wide: true },
];

const STATUS_VIEW = {
  submitted: {
    icon: Clock,
    tone: "border-amber-400/20 bg-amber-500/[0.06]",
    iconTone: "text-amber-300",
    title: "Başvurunuz alındı",
    text: "Ekibimiz başvurunuzu inceledikten sonra sizinle iletişime geçecek. Sonucu bu sayfada ve bildirimlerinizde de görebilirsiniz.",
  },
  reviewed: {
    icon: CheckCircle2,
    tone: "border-emerald-400/20 bg-emerald-500/[0.06]",
    iconTone: "text-emerald-300",
    title: "Başvurunuz onaylandı",
    text: "Bilgilerinizde değişiklik için destek ekibimizle iletişime geçebilirsiniz.",
  },
  rejected: {
    icon: XCircle,
    tone: "border-rose-400/20 bg-rose-500/[0.06]",
    iconTone: "text-rose-300",
    title: "Başvurunuz onaylanmadı",
    text: "Aşağıdaki açıklamaya göre bilgilerinizi düzenleyip yeniden başvurabilirsiniz.",
  },
};

const EMPTY = { full_name: "", phone: "", email: "", description: "" };

export default function MarketplaceApplicationPage() {
  const { userId } = useContext(UserContext);
  const registration = useMarketplaceRegistration(userId);
  const application = registration.application;

  const [values, setValues] = useState(EMPTY);
  const [prefilled, setPrefilled] = useState(false);
  const [fieldErrors, setFieldErrors] = useState({});
  const [formError, setFormError] = useState(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (prefilled || registration.loading) return;
    const source = application?.full_name ? application : registration.defaults;
    if (source) {
      setValues({
        full_name: source.full_name || "",
        phone: source.phone || "",
        email: source.email || "",
        description: application?.description || "",
      });
    }
    setPrefilled(true);
  }, [prefilled, registration.loading, registration.defaults, application]);

  const locked = application?.status === "reviewed";
  const pending = application?.status === "submitted";
  const view = application ? STATUS_VIEW[application.status] : null;
  const descriptionLength = values.description.trim().length;

  const setField = (name, value) => {
    setValues((prev) => ({ ...prev, [name]: value }));
    setFieldErrors((prev) => ({ ...prev, [name]: undefined }));
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (locked || submitting) return;
    setSubmitting(true);
    setFormError(null);
    setFieldErrors({});
    try {
      const payload = Object.fromEntries(Object.entries(values).map(([k, v]) => [k, v.trim()]));
      const body = new FormData();
      body.append("data", JSON.stringify(payload));
      const res = await fetch("/api/seller/application_submit.php", {
        method: "POST",
        body,
        credentials: "include",
      });
      const result = await res.json().catch(() => null);
      if (!result?.success) {
        if (result?.errors && typeof result.errors === "object") setFieldErrors(result.errors);
        setFormError(result?.message || "Başvurunuz gönderilemedi. Lütfen tekrar deneyin.");
        return;
      }
      registration.refetch();
    } catch {
      setFormError("Sunucuya bağlanılamadı. İnternet bağlantınızı kontrol edip tekrar deneyin.");
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <PageLayout>
      <PageHeader
        eyebrow="Pazaryeri"
        title="Pazaryeri Başvurusu"
        description="Chatbotlarınızı pazaryerinde satmak için başvurun. Ekibimiz başvurunuzu inceleyip sizinle iletişime geçecek."
      />

      <div className="w-full max-w-2xl">
        {registration.loading ? (
          <PageSection>
            <Card>
              <CardContent className="space-y-4 p-6">
                {[0, 1, 2].map((i) => (
                  <div key={i} className="h-11 animate-pulse rounded-xl bg-white/[0.04]" />
                ))}
                <div className="h-32 animate-pulse rounded-xl bg-white/[0.04]" />
              </CardContent>
            </Card>
          </PageSection>
        ) : registration.error ? (
          <PageSection>
            <div className="flex flex-col items-start gap-3 rounded-2xl border border-rose-500/20 bg-rose-500/10 px-5 py-4 text-sm text-rose-300">
              <span>{registration.error}</span>
              <Button size="sm" variant="secondary" onClick={registration.refetch}>Tekrar dene</Button>
            </div>
          </PageSection>
        ) : (
          <>
            {view && (
              <PageSection>
                <div className={cn("flex items-start gap-3 rounded-2xl border p-4", view.tone)}>
                  <view.icon className={cn("mt-0.5 h-5 w-5 shrink-0", view.iconTone)} />
                  <div className="min-w-0 text-sm leading-relaxed text-white/70">
                    <p className="font-semibold text-white">{view.title}</p>
                    <p className="mt-1">{view.text}</p>
                    {application.status === "rejected" && application.review_note && (
                      <p className="mt-2 rounded-xl bg-black/20 px-3 py-2 text-white/80">
                        <span className="font-semibold">Açıklama:</span> {application.review_note}
                      </p>
                    )}
                  </div>
                </div>
              </PageSection>
            )}

            {!locked && (
              <PageSection>
                <Card>
                  <CardContent className="p-5 sm:p-6">
                    <div className="mb-6 flex items-center gap-3">
                      <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-fuchsia-500/10 text-fuchsia-300">
                        <Store className="h-5 w-5" />
                      </span>
                      <div className="min-w-0">
                        <h2 className="font-display text-base font-semibold text-white">
                          {pending ? "Başvurunuzu güncelleyin" : "Başvuru formu"}
                        </h2>
                        <p className="text-xs text-white/45">Tüm alanlar zorunludur.</p>
                      </div>
                    </div>

                    <form onSubmit={handleSubmit} noValidate className="space-y-4">
                      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        {FIELDS.map(({ name, label, icon: Icon, wide, ...rest }) => (
                          <label key={name} className={cn("block", wide && "sm:col-span-2")}>
                            <span className="mb-1.5 block text-caption font-semibold uppercase tracking-wider text-white/50">
                              {label}
                            </span>
                            <span className="relative block">
                              <Icon className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-fuchsia-300/50" />
                              <Input
                                name={name}
                                type={rest.type || "text"}
                                inputMode={rest.inputMode}
                                autoComplete={rest.autoComplete}
                                maxLength={rest.maxLength}
                                placeholder={rest.placeholder}
                                value={values[name]}
                                onChange={(e) => setField(name, e.target.value)}
                                aria-invalid={Boolean(fieldErrors[name])}
                                className={cn("pl-10", fieldErrors[name] && "border-rose-400/60")}
                              />
                            </span>
                            {fieldErrors[name] && (
                              <span className="mt-1 block text-xs text-rose-300">{fieldErrors[name]}</span>
                            )}
                          </label>
                        ))}
                      </div>

                      <label className="block">
                        <span className="mb-1.5 block text-caption font-semibold uppercase tracking-wider text-white/50">
                          Açıklama
                        </span>
                        <Textarea
                          name="description"
                          rows={6}
                          maxLength={DESCRIPTION_MAX}
                          placeholder="Kendinizden ve pazaryerinde sunmak istediğiniz chatbotlardan kısaca bahsedin."
                          value={values.description}
                          onChange={(e) => setField("description", e.target.value)}
                          aria-invalid={Boolean(fieldErrors.description)}
                          className={cn("min-h-[150px] resize-y", fieldErrors.description && "border-rose-400/60")}
                        />
                        <span className="mt-1 flex items-start justify-between gap-3 text-xs">
                          <span className="text-rose-300">{fieldErrors.description}</span>
                          <span
                            className={cn(
                              "shrink-0 tabular-nums",
                              descriptionLength > 0 && descriptionLength < DESCRIPTION_MIN ? "text-amber-300/80" : "text-white/35",
                            )}
                          >
                            {descriptionLength}/{DESCRIPTION_MAX}
                          </span>
                        </span>
                      </label>

                      {formError && (
                        <div className="rounded-2xl border border-rose-500/20 bg-rose-500/10 px-4 py-3 text-sm text-rose-300">
                          {formError}
                        </div>
                      )}

                      <div className="flex flex-col-reverse gap-3 pt-1 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-xs text-white/40">
                          Bilgileriniz yalnızca başvurunuzun değerlendirilmesi için kullanılır.
                        </p>
                        <Button type="submit" disabled={submitting} className="w-full sm:w-auto">
                          <Send className="h-4 w-4" />
                          {submitting ? "Gönderiliyor…" : pending ? "Güncelle" : "Başvur"}
                        </Button>
                      </div>
                    </form>
                  </CardContent>
                </Card>
              </PageSection>
            )}
          </>
        )}
      </div>
    </PageLayout>
  );
}
