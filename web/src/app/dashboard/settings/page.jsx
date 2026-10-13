"use client";

import React, { useState, useEffect, useContext } from "react";
import { UserContext } from "@/shared/contexts/UserContext";
import { useRouter, useSearchParams } from "next/navigation";
import {
  Crown,
  User,
  CreditCard,
  Mail,
  Phone,
  Globe,
  ShieldCheck,
  FileText,
  MessageSquare,
  Sparkles,
  ChevronRight,
  Shield,
  Lock,
  Camera,
  Trash2,
  Check,
  Send,
  HelpCircle,
  ExternalLink,
} from "lucide-react";
import { toast } from "@/shared/hooks/use-toast";
import { FREE_PLAN_NAME } from "@/shared/lib/pricing";
import { Card } from "@/shared/ui/card";
import { Button } from "@/shared/ui/button";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/shared/ui/tabs";

/**
 * Sekme başlığı. Aynı blok sekiz sekmede birebir kopyalanmıştı; tek yerden
 * yönetilince başlık/alt başlık hiyerarşisi de tutarlı kalıyor.
 */
function SectionHeader({ title, description }) {
  return (
    <div className="mb-6 border-b border-white/[0.06] pb-5">
      <h3 className="font-display text-lg font-bold tracking-tight text-white">
        {title}
      </h3>
      {description && (
        <p className="mt-1 text-xs leading-relaxed text-white/45">{description}</p>
      )}
    </div>
  );
}

function PageLayout({ children, className = "" }) {
  return <div className={`min-h-screen ${className}`}>{children}</div>;
}

function PageSection({ children, className = "" }) {
  return <div className={className}>{children}</div>;
}

function ProfileImageEdit({ userId }) {
  const [preview, setPreview] = useState(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!userId) return;
    fetch("/api/user/user_getphoto.php", { credentials: "include" })
      .then((res) => res.json())
      .then((result) => {
        if (result.success && result.avatar) setPreview(result.avatar);
      })
      .catch((err) => console.error("Photo fetch error:", err));
  }, [userId]);

  const saveAvatar = async (avatar) => {
    setSaving(true);
    try {
      const formData = new FormData();
      formData.append("data", JSON.stringify({ avatar }));
      const res = await fetch("/api/user/user_profilephoto.php", {
        method: "POST",
        body: formData,
        credentials: "include",
      });
      const result = await res.json();
      if (result.success) {
        setPreview(avatar || null);
        window.dispatchEvent(new Event("profileUpdated"));
        toast({
          variant: "success",
          title: avatar ? "Profil fotoğrafı güncellendi" : "Profil fotoğrafı kaldırıldı",
        });
      } else {
        // Hata yalnizca console'a yaziliyordu: kullanici tikliyor, hicbir sey
        // olmuyor, neden oldugunu goremiyordu.
        toast({
          variant: "destructive",
          title: "Fotoğraf kaydedilemedi",
          description: result.message || "Bilinmeyen bir hata oluştu.",
        });
      }
    } catch (err) {
      console.error("Photo save error:", err);
      toast({
        variant: "destructive",
        title: "Bağlantı hatası",
        description: "Sunucuya ulaşılamadı.",
      });
    } finally {
      setSaving(false);
    }
  };

  /**
   * Sunucu avatari data-URI olarak sakliyor ve 512 KB tavani var
   * (UserController::MAX_AVATAR_DATA_URI_BYTES). Base64 ham dosyayi ~1.37x
   * buyuttugu icin ~370 KB ustundeki her fotograf reddediliyordu — yani
   * pratikte telefondan cekilmis her fotograf. Yuklemeden once tarayicida
   * olcekleyip sikistiriyoruz; boylece dosya sinira takilmadan geciyor.
   */
  const compressToDataUri = (file) =>
    new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onerror = () => reject(new Error("Dosya okunamadı."));
      reader.onload = () => {
        const img = new window.Image();
        img.onerror = () => reject(new Error("Görsel çözümlenemedi."));
        img.onload = () => {
          const MAX_EDGE = 512;
          const scale = Math.min(1, MAX_EDGE / Math.max(img.width, img.height));
          const canvas = document.createElement("canvas");
          canvas.width = Math.max(1, Math.round(img.width * scale));
          canvas.height = Math.max(1, Math.round(img.height * scale));

          const ctx = canvas.getContext("2d");
          ctx.drawImage(img, 0, 0, canvas.width, canvas.height);

          // Sunucudaki 512 KB tavanina rahat sigacak sekilde kaliteyi dusur.
          const LIMIT = 400 * 1024;
          let quality = 0.9;
          let out = canvas.toDataURL("image/jpeg", quality);
          while (out.length > LIMIT && quality > 0.35) {
            quality -= 0.1;
            out = canvas.toDataURL("image/jpeg", quality);
          }
          if (out.length > LIMIT) {
            reject(new Error("Görsel çok büyük, daha küçük bir fotoğraf deneyin."));
            return;
          }
          resolve(out);
        };
        img.src = String(reader.result);
      };
      reader.readAsDataURL(file);
    });

  const handleFileChange = async (e) => {
    const file = e.target.files?.[0];
    e.target.value = ""; // ayni dosya tekrar secilebilsin
    if (!file) return;

    if (!file.type.startsWith("image/")) {
      toast({
        variant: "destructive",
        title: "Geçersiz dosya",
        description: "Yalnızca görsel dosyası yükleyebilirsiniz.",
      });
      return;
    }

    setSaving(true);
    try {
      const dataUri = await compressToDataUri(file);
      await saveAvatar(dataUri);
    } catch (err) {
      setSaving(false);
      toast({
        variant: "destructive",
        title: "Fotoğraf hazırlanamadı",
        description: err.message || "Görsel işlenemedi.",
      });
    }
  };

  return (
    <div className="flex items-center gap-5">
      <div className="relative h-20 w-20 shrink-0 rounded-full overflow-hidden bg-fuchsia-950/40 border-2 border-fuchsia-500/30 flex items-center justify-center">
        {preview ? (
          <img
            src={preview}
            alt="Profil fotoğrafı"
            className="h-full w-full object-cover"
          />
        ) : (
          <User className="h-8 w-8 text-fuchsia-400" />
        )}
      </div>
      <div className="space-y-2">
        <h4 className="text-sm font-semibold text-white">Profil Fotoğrafı</h4>
        <div className="flex items-center gap-2">
          <label className="cursor-pointer inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-gradient-btn text-xs font-medium text-white transition-all duration-200 shadow-glow hover:brightness-110 hover:-translate-y-0.5 active:translate-y-0">
            <Camera className="h-3.5 w-3.5" />
            <span>{saving ? "Yükleniyor..." : "Fotoğraf Yükle"}</span>
            <input
              type="file"
              accept="image/*"
              onChange={handleFileChange}
              disabled={saving}
              className="hidden"
            />
          </label>
          <button
            type="button"
            onClick={() => saveAvatar("")}
            disabled={saving}
            className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-white/5 hover:bg-white/10 text-xs font-medium text-white/80 transition-all border border-white/10"
          >
            <Trash2 className="h-3.5 w-3.5 text-rose-400" />
            <span>Kaldır</span>
          </button>
        </div>
      </div>
    </div>
  );
}

function EditableField({ fields, onSubmit }) {
  const [values, setValues] = useState(
    fields.reduce((acc, f) => ({ ...acc, [f.name]: f.value || "" }), {}),
  );
  const [saved, setSaved] = useState(false);
  const [error, setError] = useState(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    setValues(
      fields.reduce((acc, f) => ({ ...acc, [f.name]: f.value || "" }), {}),
    );
  }, [fields]);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      const result = onSubmit ? await onSubmit(values) : null;
      if (result?.error) {
        setError(result.error);
        return;
      }
      setSaved(true);
      setTimeout(() => setSaved(false), 2000);
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <form
      onSubmit={handleSubmit}
      className="space-y-4"
    >
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        {fields.map((field) => (
          <div key={field.name} className="space-y-1.5">
            <label className="text-caption font-semibold uppercase tracking-wider text-white/50">
              {field.placeholder}
            </label>
            <input
              // B-07: parola alanı için `type` gerekiyor; varsayılan "text".
              type={field.type || "text"}
              autoComplete={field.autoComplete}
              value={values[field.name] || ""}
              onChange={(e) =>
                setValues({ ...values, [field.name]: e.target.value })
              }
              placeholder={field.placeholder}
              className="w-full rounded-xl bg-white/5 border border-white/10 px-4 py-2.5 text-xs text-white placeholder-white/30 focus:outline-none focus:ring-2 focus:ring-fuchsia-500/50"
            />
          </div>
        ))}
      </div>
      <div className="flex items-center justify-end gap-3">
        {error && <span className="text-xs text-rose-400 font-medium">{error}</span>}
        {saved && (
          <span className="text-xs text-emerald-400 flex items-center gap-1 font-medium animate-pulse">
            <Check className="h-3.5 w-3.5" /> Kaydedildi
          </span>
        )}
        <button
          type="submit"
          disabled={submitting}
          className="px-5 py-2 rounded-xl bg-gradient-btn text-xs font-semibold text-white transition-all duration-200 shadow-glow hover:brightness-110 hover:-translate-y-0.5 active:translate-y-0 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {submitting ? "Kaydediliyor..." : "Güncelle"}
        </button>
      </div>
    </form>
  );
}

function EmailEditor({ userId }) {
  const [email, setEmail] = useState("");

  useEffect(() => {
    if (!userId) return;
    fetch("/api/user/getuseremail.php", { credentials: "include" })
      .then((res) => res.json())
      .then((result) => {
        if (result.success) setEmail(result.email || "");
      })
      .catch((err) => console.error("Email fetch error:", err));
  }, [userId]);

  const handleSubmit = async (data) => {
    // B-07: sunucu mevcut parolayı zorunlu tutuyor. Oturumu ele geçiren
    // biri e-postayı kendine çevirip "şifremi unuttum" ile hesabı kalıcı
    // olarak devralabiliyordu; parola kapısı o zinciri kesiyor.
    if (!data.current_password) {
      return { error: "Mevcut parolanızı girin." };
    }
    try {
      const formData = new FormData();
      formData.append("email", data.email);
      formData.append("current_password", data.current_password);
      const res = await fetch("/api/user/updateuseremail.php", {
        method: "POST",
        body: formData,
        credentials: "include",
      });
      const result = await res.json();
      if (!result.success) return { error: result.message || "Güncellenemedi." };
      setEmail(data.email);
    } catch (err) {
      console.error("Email save error:", err);
      return { error: "Sunucuya bağlanılamadı." };
    }
  };

  return (
    <div className="space-y-4">
      <EditableField
        fields={[
          { name: "email", value: email, placeholder: "E-posta Adresi" },
          {
            name: "current_password",
            value: "",
            placeholder: "Mevcut Parolanız",
            type: "password",
            autoComplete: "current-password",
          },
        ]}
        onSubmit={handleSubmit}
      />
    </div>
  );
}

function PhoneEditor({ userId }) {
  const [phone, setPhone] = useState("");

  useEffect(() => {
    if (!userId) return;
    fetch("/api/user/getuserphone.php", { credentials: "include" })
      .then((res) => res.json())
      .then((result) => {
        if (result.success) setPhone(result.telefon || "");
      })
      .catch((err) => console.error("Phone fetch error:", err));
  }, [userId]);

  const handleSubmit = async (data) => {
    try {
      const formData = new FormData();
      formData.append("telefon", data.phone);
      const res = await fetch("/api/user/updateuserphone.php", {
        method: "POST",
        body: formData,
        credentials: "include",
      });
      const result = await res.json();
      if (!result.success) return { error: result.message || "Güncellenemedi." };
      setPhone(data.phone);
    } catch (err) {
      console.error("Phone save error:", err);
      return { error: "Sunucuya bağlanılamadı." };
    }
  };

  return (
    <div className="space-y-4">
      <EditableField
        fields={[
          { name: "phone", value: phone, placeholder: "Telefon Numarası" },
        ]}
        onSubmit={handleSubmit}
      />
    </div>
  );
}

function LanguageSelector() {
  const [lang, setLang] = useState("tr");
  return (
    <div className="space-y-4">
      <h4 className="text-sm font-semibold text-white">Arayüz Dili</h4>
      <div className="flex gap-3">
        {[
          { id: "tr", label: "Türkçe" },
          { id: "en", label: "English" },
        ].map((item) => (
          <button
            key={item.id}
            type="button"
            onClick={() => setLang(item.id)}
            className={`px-5 py-2.5 rounded-xl text-xs font-semibold transition-all border ${
              lang === item.id
                ? "bg-fuchsia-600 border-fuchsia-400/30 text-white shadow-lg shadow-fuchsia-950/40"
                : "bg-white/5 border-white/10 text-white/70 hover:bg-white/10"
            }`}
          >
            {item.label}
          </button>
        ))}
      </div>
    </div>
  );
}

/**
 * FE-001 🟠 — bu iki bileşen sabit kodlu, iki cümlelik yer tutuculardı.
 *
 * Gerçek KVKK ve kullanım koşulları metni admin panelinden yönetiliyor
 * (`/admin/gizlilikpolitikasi`, `/admin/kullanimkosullari` → `global_vars` →
 * `/api/content/getprivacy.php`, `getusage.php`). O zincirin tamamı çalışıyordu;
 * yalnızca son halka bağlı değildi, yani yönetilen metin hiçbir kullanıcıya
 * ulaşmıyordu. (Tur 1'de "ölü" sayılan `widgets/info/*` bileşenleri aslında
 * çalışan koddu; ölü olan, sayfaya gömülü yer tutucuydu.)
 *
 * Metin admin tarafından yazılan HTML — CSP (next.config.mjs headers()) ve
 * admin oturumu bunun tek güven sınırı; kullanıcı girdisi buraya hiç girmiyor.
 */
function LegalDocument({ title, endpoint, contentKey }) {
  const [html, setHtml] = useState(null);
  const [state, setState] = useState("loading");

  useEffect(() => {
    let cancelled = false;
    const controller = new AbortController();

    (async () => {
      try {
        const res = await fetch(endpoint, { signal: controller.signal });
        const result = await res.json().catch(() => null);
        if (cancelled) return;

        if (!res.ok || !result?.success || !result?.content?.[contentKey]) {
          setState("empty");
          return;
        }
        setHtml(result.content[contentKey]);
        setState("ready");
      } catch (err) {
        if (!cancelled && err.name !== "AbortError") setState("error");
      }
    })();

    return () => {
      cancelled = true;
      controller.abort();
    };
  }, [endpoint, contentKey]);

  return (
    <div className="space-y-4 text-xs leading-relaxed text-white/70">
      {state === "loading" && <p className="text-white/40">Yükleniyor…</p>}

      {state === "error" && (
        <p className="text-white/50">
          Metin şu anda yüklenemedi. Bağlantınızı kontrol edip sayfayı
          yenileyin.
        </p>
      )}

      {state === "empty" && (
        <p className="text-white/50">
          Bu metin henüz yayınlanmamış. Ayrıntılı bilgi için destek ekibimizle
          iletişime geçebilirsiniz.
        </p>
      )}

      {state === "ready" && (
        <div
          className="[&_h2]:mb-3 [&_h2]:text-base [&_h2]:text-white [&_h4]:mb-2 [&_h4]:mt-4 [&_h4]:text-sm [&_h4]:text-fuchsia-400 [&_li]:mb-1 [&_p]:mb-3 [&_ul]:pl-5"
          dangerouslySetInnerHTML={{ __html: html }}
        />
      )}
    </div>
  );
}

function PrivacyPolicy2() {
  return (
    <LegalDocument
      title="Gizlilik Politikası"
      endpoint="/api/content/getprivacy.php"
      contentKey="gizlilik_politikasi"
    />
  );
}

function TermsOfUse() {
  return (
    <LegalDocument
      title="Kullanım Koşulları"
      endpoint="/api/content/getusage.php"
      contentKey="kullanim_kosullari"
    />
  );
}

function ContactForm() {
  const [form, setForm] = useState({ fullName: "", email: "", subject: "", message: "" });
  const [sent, setSent] = useState(false);
  const [error, setError] = useState(null);
  const [sending, setSending] = useState(false);

  const update = (field) => (e) => setForm((prev) => ({ ...prev, [field]: e.target.value }));

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError(null);
    setSending(true);
    try {
      const formData = new FormData();
      formData.append("fullName", form.fullName);
      formData.append("email", form.email);
      formData.append("subject", form.subject);
      formData.append("message", form.message);
      const res = await fetch("/api/contact/contact.php", {
        method: "POST",
        body: formData,
        credentials: "include",
      });
      const result = await res.json();
      if (!result.success) {
        setError(result.message || "Mesaj gönderilemedi.");
        return;
      }
      setSent(true);
      setForm({ fullName: "", email: "", subject: "", message: "" });
      setTimeout(() => setSent(false), 3000);
    } catch (err) {
      console.error("Contact form error:", err);
      setError("Sunucuya bağlanılamadı.");
    } finally {
      setSending(false);
    }
  };

  return (
    <form
      onSubmit={handleSubmit}
      className="space-y-4"
    >
      <h4 className="text-sm font-semibold text-white">
        Destek ve Geri Bildirim Talebi
      </h4>
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div className="space-y-1.5">
          <label className="text-caption font-semibold uppercase tracking-wider text-white/50">
            Ad Soyad
          </label>
          <input
            type="text"
            name="fullName"
            required
            value={form.fullName}
            onChange={update("fullName")}
            placeholder="Adınız Soyadınız"
            className="w-full rounded-xl bg-white/5 border border-white/10 px-4 py-2.5 text-xs text-white placeholder-white/30 focus:outline-none focus:ring-2 focus:ring-fuchsia-500/50"
          />
        </div>
        <div className="space-y-1.5">
          <label className="text-caption font-semibold uppercase tracking-wider text-white/50">
            E-posta
          </label>
          <input
            type="email"
            name="email"
            required
            value={form.email}
            onChange={update("email")}
            placeholder="ornek@eposta.com"
            className="w-full rounded-xl bg-white/5 border border-white/10 px-4 py-2.5 text-xs text-white placeholder-white/30 focus:outline-none focus:ring-2 focus:ring-fuchsia-500/50"
          />
        </div>
      </div>
      <div className="space-y-1.5">
        <label className="text-caption font-semibold uppercase tracking-wider text-white/50">
          Konu
        </label>
        <input
          type="text"
          name="subject"
          required
          value={form.subject}
          onChange={update("subject")}
          placeholder="Talebinizin konusu"
          className="w-full rounded-xl bg-white/5 border border-white/10 px-4 py-2.5 text-xs text-white placeholder-white/30 focus:outline-none focus:ring-2 focus:ring-fuchsia-500/50"
        />
      </div>
      <div className="space-y-1.5">
        <label className="text-caption font-semibold uppercase tracking-wider text-white/50">
          Mesajınız
        </label>
        <textarea
          name="message"
          rows={4}
          required
          value={form.message}
          onChange={update("message")}
          placeholder="Sorununuzu veya talebinizi detaylıca yazın..."
          className="w-full rounded-xl bg-white/5 border border-white/10 p-3 text-xs text-white placeholder-white/30 focus:outline-none focus:ring-2 focus:ring-fuchsia-500/50"
        />
      </div>
      <div className="flex justify-end gap-3 items-center">
        {error && <span className="text-xs text-rose-400 font-medium">{error}</span>}
        {sent && (
          <span className="text-xs text-emerald-400 font-medium animate-pulse">
            Mesajınız iletildi!
          </span>
        )}
        <button
          type="submit"
          disabled={sending}
          className="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-gradient-btn text-xs font-semibold text-white transition-all duration-200 shadow-glow hover:brightness-110 hover:-translate-y-0.5 active:translate-y-0 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          <Send className="h-3.5 w-3.5" />
          <span>{sending ? "Gönderiliyor..." : "Gönder"}</span>
        </button>
      </div>
    </form>
  );
}

const TABS = [
  { key: "user", label: "Kullanıcı Profili", icon: User },
  // Madde 7 — banka/ödeme bilgileri artık Pazaryeri Başvurusu'nun parçası
  // (GK-7: IBAN başvuru alanı). Sekme içerik göstermiyor, oraya gidiyor.
  // Anahtar korunuyor: `?tab=security` derin bağlantıları da yönlendirilir.
  {
    key: "security",
    label: "Ödeme Bilgileri",
    icon: CreditCard,
    href: "/dashboard/pazaryeri-basvurusu",
  },
  { key: "email", label: "E-posta Adresi", icon: Mail },
  { key: "phone", label: "Telefon Numarası", icon: Phone },
  { key: "privacy", label: "Gizlilik Politikası", icon: ShieldCheck },
  { key: "terms", label: "Kullanım Koşulları", icon: FileText },
  { key: "contact", label: "Destek ve İletişim", icon: MessageSquare },
];
const TAB_KEYS = TABS.map((t) => t.key);

export default function App() {
  const { userId, account } = useContext(UserContext);
  const router = useRouter();
  const searchParams = useSearchParams();
  const requestedTab = searchParams.get("tab");
  const [activeTab, setActiveTab] = useState(
    TAB_KEYS.includes(requestedTab) ? requestedTab : "user",
  );

  // URL değişirse (ör. menüden başka bir sekmeye derin bağlantı) sekmeyi izle.
  // Bağlantı sekmesi (href) içerik göstermez; o sayfaya yönlendirilir.
  useEffect(() => {
    if (requestedTab && TAB_KEYS.includes(requestedTab)) {
      const linked = TABS.find((t) => t.key === requestedTab)?.href;
      if (linked) {
        router.replace(linked);
        return;
      }
      setActiveTab(requestedTab);
    }
  }, [requestedTab, router]);

  const handleTabChange = (key) => {
    const linked = TABS.find((t) => t.key === key)?.href;
    if (linked) {
      router.push(linked);
      return;
    }
    setActiveTab(key);
  };
  const [userInfo, setUserInfo] = useState({
    ad: "",
    soyad: "",
    kullaniciAdi: "",
  });

  useEffect(() => {
    if (!userId) return;
    fetch("/api/user/getusernames.php", { credentials: "include" })
      .then((res) => res.json())
      .then((result) => {
        if (!result.success) return;
        const [firstName = "", ...rest] = (result.fullname || "").split(" ");
        setUserInfo({
          ad: firstName,
          soyad: rest.join(" "),
          kullaniciAdi: result.username || "",
        });
      })
      .catch((err) => console.error("User names fetch error:", err));
  }, [userId]);

  const saveUsername = async (username) => {
    try {
      const formData = new FormData();
      formData.append("kullanici_adi", username);
      const res = await fetch("/api/user/updateusernames.php", {
        method: "POST",
        body: formData,
        credentials: "include",
      });
      const result = await res.json();
      if (!result.success) return { error: result.message || "Güncellenemedi." };
      setUserInfo((prev) => ({ ...prev, kullaniciAdi: username }));
    } catch (err) {
      console.error("Username save error:", err);
      return { error: "Sunucuya bağlanılamadı." };
    }
  };

  const saveFullName = async (firstName, lastName) => {
    try {
      const formData = new FormData();
      formData.append("ad_soyad", `${firstName} ${lastName}`.trim());
      const res = await fetch("/api/user/updateusernames.php", {
        method: "POST",
        body: formData,
        credentials: "include",
      });
      const result = await res.json();
      if (!result.success) return { error: result.message || "Güncellenemedi." };
      setUserInfo((prev) => ({ ...prev, ad: firstName, soyad: lastName }));
    } catch (err) {
      console.error("Name save error:", err);
      return { error: "Sunucuya bağlanılamadı." };
    }
  };

  return (
    <PageLayout className="min-h-screen bg-luma-base text-white selection:bg-fuchsia-500/30 selection:text-fuchsia-200">
      {/* Background ambiance glow */}
      <div className="absolute top-0 left-1/2 -translate-x-1/2 w-full max-w-7xl h-96 bg-gradient-to-b from-fuchsia-600/10 via-purple-900/5 to-transparent blur-3xl pointer-events-none" />

      <div className="relative px-4 sm:px-6 lg:px-8 py-10 space-y-8">
        {/* Header */}
        <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4 border-b border-white/[0.08] pb-6">
          <div className="space-y-1">
            <div className="flex items-center gap-2">
              <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-fuchsia-500/10 border border-fuchsia-500/20 text-caption font-semibold text-fuchsia-300 tracking-wider uppercase">
                <Sparkles className="h-3 w-3" /> Kontrol Paneli
              </span>
            </div>
            <h1 className="font-display text-3xl font-extrabold tracking-tight text-white sm:text-4xl">
              Hesap Ayarları
            </h1>
            <p className="text-sm text-white/50">
              Profilinizi, aboneliğinizi ve güvenlik tercihlerinizi tek bir
              yerden yönetin.
            </p>
          </div>

          {/* Quick user badge */}
          <div className="flex items-center gap-3 bg-white/[0.03] border border-white/[0.08] px-4 py-2.5 rounded-2xl backdrop-blur-xl">
            <div className="h-2 w-2 rounded-full bg-emerald-500 animate-pulse" />
            <span className="text-xs font-medium text-white/80">
              {userInfo.kullaniciAdi
                ? `@${userInfo.kullaniciAdi}`
                : "Oturum Açık"}
            </span>
          </div>
        </div>

        {/* Subscription Banner */}
        <PageSection className="relative flex flex-col md:flex-row md:items-center md:justify-between gap-6 overflow-hidden rounded-3xl border border-fuchsia-500/20 bg-gradient-to-br from-[#180e29] via-[#120a20] to-[#0a0614] p-6 sm:p-8 shadow-2xl shadow-fuchsia-950/40">
          <div className="absolute -right-16 -top-16 h-56 w-56 rounded-full bg-fuchsia-600/10 blur-[100px] pointer-events-none" />

          <div className="relative flex items-center gap-4">
            <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-fuchsia-500/20 to-purple-500/10 border border-fuchsia-500/30 text-fuchsia-300 shadow-inner">
              <Crown className="h-7 w-7" strokeWidth={1.75} />
            </div>
            <div className="space-y-1">
              <div className="flex items-center gap-2">
                <span className="text-caption font-bold uppercase tracking-widest text-fuchsia-400">
                  Mevcut Plan
                </span>
                <span className="px-2 py-0.5 rounded-full bg-white/10 text-caption font-medium text-white/80">
                  Aktif
                </span>
              </div>
              <h2 className="text-xl font-bold text-white tracking-tight">
                {account?.planName || FREE_PLAN_NAME}
              </h2>
              <p className="text-xs text-white/50 max-w-md">
                Gelişmiş özellikler, yüksek limitler ve öncelikli destek için
                Pro plana geçiş yapın.
              </p>
            </div>
          </div>

          <Button
            onClick={() => router.push("/dashboard/upgrade")}
            className="group shrink-0"
          >
            <span>Abonelik Seçeneklerini İncele</span>
            <ChevronRight className="h-4 w-4 transition-transform group-hover:translate-x-1" />
          </Button>
        </PageSection>

        {/* Main Settings Layout (Sidebar Navigation + Content Canvas) */}
        <PageSection>
          <Tabs
            value={activeTab}
            onValueChange={handleTabChange}
            className="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start"
          >
            {/* Sidebar Navigation */}
            <div className="lg:col-span-3 space-y-0.5 lg:sticky lg:top-6">
              <div className="px-1 pb-2.5 text-[10px] font-semibold uppercase tracking-[0.12em] text-white/30">
                Navigasyon
              </div>
              <TabsList className="h-auto w-full flex-col gap-1 rounded-2xl border-white/[0.06] bg-white/[0.02] p-2">
                {TABS.map((tab) => {
                  const IconComponent = tab.icon;
                  return (
                    <TabsTrigger
                      key={tab.key}
                      value={tab.key}
                      className="w-full justify-start gap-3 rounded-xl px-3.5 py-2.5 data-[state=inactive]:text-white data-[state=inactive]:hover:text-white [&_svg]:text-white/70 [&[data-state=active]_svg]:text-white"
                    >
                      <IconComponent className="h-4 w-4 shrink-0" />
                      <span className="truncate">{tab.label}</span>
                    </TabsTrigger>
                  );
                })}
              </TabsList>
            </div>

            {/* Content Canvas */}
            <div className="lg:col-span-9">
              <Card className="rounded-3xl border-white/[0.07] bg-white/[0.02] p-6 sm:p-8">
                <TabsContent
                  value="user"
                  className="mt-0 space-y-8"
                >
                  <SectionHeader
                    title="Kullanıcı Bilgileri"
                    description="Profil fotoğrafınızı ve temel hesap detaylarınızı düzenleyin."
                  />

                  <ProfileImageEdit userId={userId} />

                  <div className="space-y-6 pt-2">
                    <EditableField
                      fields={[
                        {
                          name: "username",
                          value: userInfo.kullaniciAdi,
                          placeholder: "Kullanıcı Adı",
                        },
                      ]}
                      onSubmit={(data) => saveUsername(data.username)}
                    />

                    <EditableField
                      fields={[
                        {
                          name: "firstName",
                          value: userInfo.ad,
                          placeholder: "Ad",
                        },
                        {
                          name: "lastName",
                          value: userInfo.soyad,
                          placeholder: "Soyad",
                        },
                      ]}
                      onSubmit={(data) => saveFullName(data.firstName, data.lastName)}
                    />
                  </div>
                </TabsContent>

                <TabsContent
                  value="email"
                  className="mt-0 space-y-6"
                >
                  <SectionHeader
                    title="E-posta Adresi"
                    description="Hesabınıza bağlı e-posta adresini güncelleyin."
                  />
                  <EmailEditor userId={userId} />
                </TabsContent>

                <TabsContent
                  value="phone"
                  className="mt-0 space-y-6"
                >
                  <SectionHeader
                    title="Telefon Numarası"
                    description="SMS doğrulaması ve iletişim için telefon numaranızı yönetin."
                  />
                  <PhoneEditor userId={userId} />
                </TabsContent>

             

                <TabsContent
                  value="privacy"
                  className="mt-0 space-y-6"
                >
                  <SectionHeader
                    title="Gizlilik Politikası"
                    description="Verilerinizin nasıl işlendiği ve korunduğu hakkında bilgi edinin."
                  />
                  <PrivacyPolicy2 />
                </TabsContent>

                <TabsContent
                  value="terms"
                  className="mt-0 space-y-6"
                >
                  <SectionHeader
                    title="Kullanım Koşulları"
                    description="Hizmet şartlarımızı ve yasal sözleşmeleri inceleyin."
                  />
                  <TermsOfUse />
                </TabsContent>

                <TabsContent
                  value="contact"
                  className="mt-0 space-y-6"
                >
                  <SectionHeader
                    title="Destek ve İletişim"
                    description="Ekibimizle iletişime geçin ve geri bildirimde bulunun."
                  />
                  <ContactForm />
                </TabsContent>
              </Card>
            </div>
          </Tabs>
        </PageSection>
      </div>
    </PageLayout>
  );
}
