"use client";

/**
 * Pazaryeri Başvurusu — madde 3 (Faz 2 iskeleti → Faz 5 backend'e bağlı).
 *
 * Pazaryeri kaydı yalnızca şirketlere açık (GK-8): şahıs şirketleri ve
 * kurumsal şirketler başvurabilir, vergi kaydı olmayan bireyler başvuramaz.
 * Alanlar GK-7; belge yükleme bu turda yok.
 *
 * Uçlar: POST /api/seller/application_submit.php,
 *        GET  /api/seller/application_status.php (useMarketplaceRegistration).
 * Durumlar: yok → form | submitted → "alındı, inceleniyor" | reviewed →
 * "incelendi" (değiştirilemez, GK-21) | rejected → gerekçe + yeniden gönderim.
 *
 * Başvuru ve inceleme satış yetkisini KENDİLİĞİNDEN AÇMAZ: satıcı aktifliği
 * ödeme altyapısına (B1) bağlı. Sayfa bunu açıkça söylüyor.
 */
import { useContext, useState } from "react";
import { Building2, CheckCircle2, Clock, Info, Lock, User, XCircle } from "lucide-react";
import { PageLayout, PageHeader, PageSection } from "@/shared/ui/page-layout";
import { Card, CardContent } from "@/shared/ui/card";
import { Input } from "@/shared/ui/input";
import { Button } from "@/shared/ui/button";
import { cn } from "@/lib/utils";
import { UserContext } from "@/shared/contexts/UserContext";
import useMarketplaceRegistration from "@/shared/hooks/useMarketplaceRegistration";

const ACCOUNT_TYPES = [
  { id: "sahis", label: "Şahıs Şirketi", hint: "Vergi levhası olan şahıs işletmeleri", icon: User },
  { id: "kurumsal", label: "Kurumsal Şirket", hint: "Limited, anonim ve diğer sermaye şirketleri", icon: Building2 },
];

const SECTIONS = [
  {
    title: "Şirket Bilgileri",
    fields: [
      { name: "company_title", label: "Ticari unvan", placeholder: "Örn. Örnek Yazılım Ltd. Şti.", maxLength: 255 },
      { name: "tax_number", label: "Vergi numarası", placeholder: "Kurumsal: 10 haneli VKN · Şahıs: 11 haneli TCKN ya da VKN", inputMode: "numeric", maxLength: 11 },
      { name: "tax_office", label: "Vergi dairesi", placeholder: "Örn. Kadıköy", maxLength: 100 },
      { name: "mersis_no", label: "MERSİS numarası", placeholder: "16 haneli MERSİS no (şahısta isteğe bağlı)", inputMode: "numeric", maxLength: 16 },
    ],
  },
  {
    title: "Yetkili Kişi",
    fields: [
      { name: "authorized_first_name", label: "Yetkili adı", maxLength: 100 },
      { name: "authorized_last_name", label: "Yetkili soyadı", maxLength: 100 },
      { name: "authorized_birth_date", label: "Yetkili doğum tarihi", type: "date" },
    ],
  },
  {
    title: "Ödeme ve Adres",
    fields: [
      { name: "iban", label: "IBAN", placeholder: "TR00 0000 0000 0000 0000 0000 00", maxLength: 32 },
      { name: "il", label: "İl", maxLength: 100 },
      { name: "ilce", label: "İlçe", maxLength: 100 },
      { name: "address", label: "Açık adres", wide: true, maxLength: 500 },
    ],
  },
];

const STATUS_VIEW = {
  submitted: {
    icon: Clock,
    tone: "border-amber-500/20 bg-amber-500/[0.06] text-amber-100/85",
    title: "Başvurunuz alındı, inceleniyor",
    text: "Ekibimiz başvurunuzu inceledikten sonra sonucu bu sayfada ve bildirimlerinizde göreceksiniz. İnceleme beklenirken bilgilerinizi güncelleyip yeniden gönderebilirsiniz (güvenlik nedeniyle form boş açılır; tüm alanları yeniden girmeniz gerekir).",
  },
  reviewed: {
    icon: CheckCircle2,
    tone: "border-emerald-500/20 bg-emerald-500/[0.06] text-emerald-100/85",
    title: "Başvurunuz incelendi",
    text: "Bilgilerinizde değişiklik için destek ekibiyle iletişime geçin. Pazaryerinde ücretli satışın açılması ödeme altyapısının tamamlanmasına bağlıdır; hazır olduğunda size bildirilecek.",
  },
  rejected: {
    icon: XCircle,
    tone: "border-rose-500/20 bg-rose-500/[0.06] text-rose-100/85",
    title: "Başvurunuz onaylanmadı",
    text: "Aşağıdaki açıklamaya göre bilgilerinizi düzeltip yeniden gönderebilirsiniz (güvenlik nedeniyle form boş açılır).",
  },
};

export default function MarketplaceApplicationPage() {
  const { userId } = useContext(UserContext);
  const registration = useMarketplaceRegistration(userId);
  const application = registration.application;

  const [accountType, setAccountType] = useState("kurumsal");
  const [values, setValues] = useState({});
  const [fieldErrors, setFieldErrors] = useState({});
  const [formError, setFormError] = useState(null);
  const [submitting, setSubmitting] = useState(false);

  const locked = application?.status === "reviewed"; // GK-21
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
      const payload = { account_type: accountType };
      for (const section of SECTIONS) {
        for (const f of section.fields) payload[f.name] = (values[f.name] || "").trim();
      }
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
        setFormError(result?.message || `Başvuru gönderilemedi (HTTP ${res.status}).`);
        return;
      }
      // Başarı mesajı yalnızca sunucu kaydı doğruladıktan sonra: durum
      // kutusu yeni durumu (submitted) sunucudan okuyarak gösterir.
      registration.refetch();
    } catch {
      setFormError("Sunucuya bağlanılamadı.");
    } finally {
      setSubmitting(false);
    }
  };

  const view = application ? STATUS_VIEW[application.status] : null;

  return (
    <PageLayout>
      <PageHeader
        eyebrow="Pazaryeri"
        title="Pazaryeri Başvurusu"
        description="Pazaryerinde ücretli satış yapmak için şirket bilgilerinizle başvurun. Pazaryeri kaydı yalnızca şirketlere açıktır."
      />

      {registration.loading ? (
        <PageSection>
          <p className="text-sm text-white/50">Başvuru durumunuz yükleniyor…</p>
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
                <view.icon className="mt-0.5 h-5 w-5 shrink-0" />
                <div className="text-sm leading-relaxed">
                  <p className="font-semibold text-white">{view.title}</p>
                  <p className="mt-1">{view.text}</p>
                  {application.status === "rejected" && application.review_note && (
                    <p className="mt-2 rounded-xl bg-black/20 px-3 py-2 text-white/80">
                      <span className="font-semibold">Açıklama:</span> {application.review_note}
                    </p>
                  )}
                  <p className="mt-2 text-xs text-white/50">
                    {application.company_title} · {application.account_type === "sahis" ? "Şahıs şirketi" : "Kurumsal"}
                    {application.iban_masked ? ` · ${application.iban_masked}` : ""}
                  </p>
                </div>
              </div>
            </PageSection>
          )}

          {!locked && (
            <>
              {!application && (
                <PageSection>
                  <div className="flex items-start gap-3 rounded-2xl border border-white/10 bg-white/[0.02] p-4">
                    <Info className="mt-0.5 h-4 w-4 shrink-0 text-fuchsia-300" />
                    <p className="text-sm leading-relaxed text-white/70">
                      Başvurunuz ekibimiz tarafından incelenir. İnceleme, pazaryerinde ücretli
                      satışı tek başına açmaz; satış ödeme altyapısı tamamlandığında açılacaktır.
                    </p>
                  </div>
                </PageSection>
              )}

              <PageSection>
                <h2 className="mb-3 font-display text-base font-semibold text-white">Hesap türü</h2>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                  {ACCOUNT_TYPES.map(({ id, label, hint, icon: Icon }) => {
                    const active = accountType === id;
                    return (
                      <button
                        key={id}
                        type="button"
                        onClick={() => setAccountType(id)}
                        aria-pressed={active}
                        className={cn(
                          "flex items-start gap-3 rounded-2xl border p-4 text-left transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-fuchsia-400",
                          active ? "border-fuchsia-400/50 bg-fuchsia-500/10" : "border-white/10 bg-white/[0.02] hover:border-white/20",
                        )}
                      >
                        <Icon className="mt-0.5 h-4 w-4 shrink-0 text-fuchsia-300" />
                        <span>
                          <span className="block text-sm font-semibold text-white">{label}</span>
                          <span className="block text-xs text-white/50">{hint}</span>
                        </span>
                      </button>
                    );
                  })}
                  {/* Madde 1 / GK-8 / GK-10 — bireysel kayıt kullanım dışı. */}
                  <div aria-disabled="true" className="flex items-start gap-3 rounded-2xl border border-dashed border-white/10 p-4 opacity-60">
                    <Lock className="mt-0.5 h-4 w-4 shrink-0 text-white/40" />
                    <span>
                      <span className="flex items-center gap-2 text-sm font-semibold text-white/70">
                        Bireysel
                        <span className="rounded-full border border-white/15 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wider text-white/50">Yakında</span>
                      </span>
                      <span className="block text-xs text-white/40">Vergi kaydı olmayan bireysel satıcılar şu an başvuramaz.</span>
                    </span>
                  </div>
                </div>
                {fieldErrors.account_type && <p className="mt-2 text-xs text-rose-300">{fieldErrors.account_type}</p>}
              </PageSection>

              <form onSubmit={handleSubmit} noValidate className="contents">
                {SECTIONS.map((section) => (
                  <PageSection key={section.title}>
                    <Card>
                      <CardContent className="p-5">
                        <h2 className="mb-4 font-display text-base font-semibold text-white">{section.title}</h2>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                          {section.fields.map((f) => (
                            <label key={f.name} className={cn("block", f.wide && "sm:col-span-2")}>
                              <span className="mb-1.5 block text-xs font-medium text-white/60">{f.label}</span>
                              <Input
                                name={f.name}
                                type={f.type || "text"}
                                inputMode={f.inputMode}
                                maxLength={f.maxLength}
                                placeholder={f.placeholder}
                                value={values[f.name] || ""}
                                onChange={(e) => setField(f.name, e.target.value)}
                                aria-invalid={Boolean(fieldErrors[f.name])}
                                autoComplete="off"
                                className={fieldErrors[f.name] ? "border-rose-400/60" : undefined}
                              />
                              {fieldErrors[f.name] && (
                                <span className="mt-1 block text-xs text-rose-300">{fieldErrors[f.name]}</span>
                              )}
                            </label>
                          ))}
                        </div>
                      </CardContent>
                    </Card>
                  </PageSection>
                ))}

                <PageSection>
                  {formError && (
                    <div className="mb-3 rounded-2xl border border-rose-500/20 bg-rose-500/10 px-4 py-3 text-sm text-rose-300">
                      {formError}
                    </div>
                  )}
                  <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <p className="text-xs text-white/45">
                      Bilgileriniz yalnızca başvurunuzun incelenmesi için kullanılır.
                    </p>
                    <Button type="submit" disabled={submitting}>
                      {submitting
                        ? "Gönderiliyor…"
                        : application
                          ? "Başvuruyu Güncelle ve Gönder"
                          : "Başvuruyu Gönder"}
                    </Button>
                  </div>
                </PageSection>
              </form>
            </>
          )}
        </>
      )}
    </PageLayout>
  );
}
