"use client";

import React, { useState, useEffect, useRef, useContext } from "react";
import { UserContext } from "@/shared/contexts/UserContext";
import { useRouter } from "next/navigation";
import {
  Select,
  SelectTrigger,
  SelectValue,
  SelectContent,
  SelectItem,
} from "@/shared/ui/select";
import Link from "next/link";
import {
  Lock,
  Globe2,
  Sparkles,
  ArrowRight,
  FileText,
  Rocket,
  Bot,
  Zap,
  Check,
  ChevronRight,
  ChevronLeft,
  ShieldCheck,
  HelpCircle,
  Info,
  AlertCircle,
  Terminal,
  SlidersHorizontal,
  Database,
  Layers,
  Cpu,
  Star,
  RefreshCw,
  User,
  Plus,
  Send,
  MessageSquare,
  Copy,
  ExternalLink,
  Code,
  ImagePlus,
  Trash2,
  Link2,
  Loader2,
  CheckCircle2,
} from "lucide-react";

// Deny-by-default limits used whenever the server's real limits cannot be
// read. Creation stays blocked rather than being optimistically allowed.
const LIMITS_UNAVAILABLE = {
  can_create_independent: false,
  can_create_public: false,
  independent_limit: "—",
  public_limit: "—",
  unavailable: true,
};

// Utility for class merging
function cn(...classes) {
  return classes.filter(Boolean).join(" ");
}

function GlassCard({
  children,
  className = "",
  interactive = false,
  onClick,
  disabled = false,
}) {
  return (
    <div
      onClick={!disabled ? onClick : undefined}
      className={cn(
        "relative rounded-2xl border transition-all duration-300 overflow-hidden",
        "bg-zinc-900/60 backdrop-blur-xl border-white/[0.08]",
        interactive &&
          !disabled &&
          "hover:border-violet-500/40 hover:bg-zinc-900/90 hover:shadow-[0_0_30px_-5px_rgba(139,92,246,0.15)] hover:-translate-y-0.5 cursor-pointer",
        disabled &&
          "opacity-50 cursor-not-allowed border-zinc-800 bg-zinc-950/40",
        className,
      )}
    >
      {children}
    </div>
  );
}

function PageHeader({ eyebrow, eyebrowClassName, title, description }) {
  return (
    <div className="space-y-2 mb-8">
      {eyebrow && (
        <div className="inline-flex items-center gap-2 rounded-full bg-violet-500/10 border border-violet-500/20 px-3 py-1 text-xs font-mono font-semibold tracking-wide text-violet-300">
          <Sparkles className="w-3.5 h-3.5" />
          <span className={eyebrowClassName}>{eyebrow}</span>
        </div>
      )}
      <h1 className="text-3xl sm:text-4xl font-extrabold tracking-tight text-white font-sans">
        {title}
      </h1>
      {description && (
        <p className="text-sm sm:text-base text-zinc-400 max-w-2xl font-normal leading-relaxed">
          {description}
        </p>
      )}
    </div>
  );
}

function Badge({ children, variant = "default", className = "" }) {
  const variants = {
    default: "bg-zinc-800 text-zinc-300 border-zinc-700",
    destructive: "bg-rose-500/10 text-rose-400 border-rose-500/20",
    success: "bg-emerald-500/10 text-emerald-400 border-emerald-500/20",
    violet: "bg-violet-500/10 text-violet-300 border-violet-500/20",
    fuchsia: "bg-fuchsia-500/10 text-fuchsia-300 border-fuchsia-500/20",
  };
  return (
    <span
      className={cn(
        "inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border",
        variants[variant] || variants.default,
        className,
      )}
    >
      {children}
    </span>
  );
}

function Skeleton({ className = "" }) {
  return (
    <div
      className={cn("animate-pulse rounded-xl bg-white/[0.06]", className)}
    />
  );
}

/** Sunucu `assets/kapak_fotografi/x.png` gibi goreli yol donuyor. */
function assetUrl(path) {
  if (!path) return "";
  if (path.startsWith("http") || path.startsWith("data:")) return path;
  return "/" + String(path).replace(/^\/+/, "");
}

function ImagePicker({
  label,
  hint,
  preview,
  inputRef,
  aspect,
  onPick,
  onClear,
}) {
  return (
    <div>
      <label className="mb-1.5 block font-mono text-xs text-zinc-300">
        {label}
      </label>
      <div
        className={cn(
          "group relative w-full overflow-hidden rounded-xl border border-dashed border-white/10 bg-zinc-900/60 transition-colors hover:border-violet-500/40",
          aspect,
        )}
      >
        {preview ? (
          <>
            <img src={preview} alt="" className="h-full w-full object-cover" />
            <div className="absolute inset-0 flex items-center justify-center gap-2 bg-black/60 opacity-0 transition-opacity group-hover:opacity-100">
              <button
                type="button"
                onClick={() => inputRef.current?.click()}
                className="rounded-lg bg-white/15 px-3 py-1.5 text-xs font-medium text-white hover:bg-white/25"
              >
                Değiştir
              </button>
              <button
                type="button"
                onClick={onClear}
                aria-label="Görseli kaldır"
                className="flex h-8 w-8 items-center justify-center rounded-lg bg-rose-500/20 text-rose-300 hover:bg-rose-500/30"
              >
                <Trash2 className="h-3.5 w-3.5" />
              </button>
            </div>
          </>
        ) : (
          <button
            type="button"
            onClick={() => inputRef.current?.click()}
            className="flex h-full w-full flex-col items-center justify-center gap-1.5 px-3 text-center"
          >
            <span className="flex h-9 w-9 items-center justify-center rounded-full bg-violet-500/10 text-violet-400">
              <ImagePlus className="h-4 w-4" />
            </span>
            <span className="text-xs font-medium text-white">Görsel Seç</span>
            <span className="text-caption text-zinc-500">{hint}</span>
          </button>
        )}
      </div>
      <input
        ref={inputRef}
        type="file"
        accept="image/*"
        className="hidden"
        onChange={(e) => onPick(e.target.files?.[0])}
      />
    </div>
  );
}

/* Bilgi bankası PDF tavanı — TrainingController::MAX_PDF_BYTES ile ELLE
   senkron (S21 kararı, 2026-10-05: 15 MB → 5 MB). */
/* Kota kartlarındaki "Kapasite" satırı. Eskiden limit yoksa sabit
   "1/2 Kullanıldı" / "0/5 Kullanıldı" yazıyordu — kullanıcının gerçek
   sayısıyla ilgisi yoktu. Sınırsız kota (madde 2: onaylı satıcının herkese
   açık hakkı) sunucudan `*_unlimited: true` olarak geliyor. */
function capacityLabel(used, limit, unlimited) {
  if (unlimited) return `${Number(used) || 0} / Sınırsız`;
  if (limit === null || limit === undefined || limit === "—") return "—";
  return `${Number(used) || 0} / ${limit}`;
}

const KB_MAX_PDF_MB = 5;
/* Eğitim metni tavanı — AppConfig::MAX_TRAINING_CHARS ile ELLE senkron.
   Sunucu bu sınırı aşan kısmı keser, dolunca 413 döner; sohbette metnin
   tamamı (en fazla bu kadar) olduğu gibi modele verilir — arama/vektör yok. */
const KB_MAX_CHARS = 60000;
const KB_MAX_PDF_BYTES = KB_MAX_PDF_MB * 1024 * 1024;

/* Sekmeler tek kaynakta: hem üstteki sekme çubuğu hem alttaki "Devam Et"
   gezinmesi bu diziden besleniyor. Liste JSX'in içinde satır içi duruyordu;
   ileri/geri hesabı sıranın tek yerden okunabilmesini gerektiriyor. */
const FORM_TABS = [
  { id: "general", label: "1. Kimlik & Genel", icon: User },
  { id: "brain", label: "2. Prompt & Zeka", icon: Cpu },
  { id: "knowledge", label: "3. Bilgi Bankası", icon: Database },
  { id: "publish", label: "4. Yayın & Fiyat", icon: Rocket },
];

/* Hazır rol şablonları. Seçili olanı işaretleyebilmek için liste ile sistem
   talimatının aynı yerden karşılaştırılabilmesi gerekiyor; JSX'in içinde
   satır içi durduğu sürece her render'da yeniden üretiliyordu. */
const ROLE_PRESETS = [
  {
    role: "Müşteri Temsilcisi",
    prompt: "Sen nazik ve çözüm odaklı bir müşteri hizmetleri uzmanısın.",
  },
  {
    role: "Satış Danışmanı",
    prompt:
      "Sen ürünlerin avantajlarını öne çıkaran ikna edici bir satış asistanısın.",
  },
  {
    role: "Teknik Destek",
    prompt: "Sen yazılım ve sistem sorunlarını adım adım çözen bir mühendissin.",
  },
  {
    role: "Eğitmen & Koç",
    prompt: "Sen karmaşık konuları basitleştirerek anlatan bir eğitmensin.",
  },
];

function ChatbotForm({
  selectedCard,
  bot,
  botId: initialBotId,
  userId,
  independentMode,
}) {
  /* N-02 devamı — ilk kayıttan sonra kullanıcı /dashboard/chatbots'a
     atılıyordu; Bilgi Bankası'na ulaşmak için listeden "Yönet" ile geri
     dönmesi gerekiyordu. Artık sunucunun döndürdüğü id burada tutuluyor ve
     form aynı bileşende "kayıtlı bot" olarak devam ediyor. Bileşen yeniden
     mount EDİLMİYOR (dış sayfanın id'yi yeniden okuması iskelet gösterip
     form durumunu sıfırlardı); URL yalnızca history ile ?id=N yapılıyor,
     yani yenilemede normal düzenleme yolu açılıyor. */
  const [createdBotId, setCreatedBotId] = useState(null);
  const botId = createdBotId ?? initialBotId;
  const isPersisted = Boolean(bot) || createdBotId !== null;
  const router = useRouter();
  const { refetchAccount } = useContext(UserContext);
  const [botName, setBotName] = useState(bot?.chatbot?.isim || "");
  const [description, setDescription] = useState(bot?.chatbot?.aciklama || "");
  const [systemPrompt, setSystemPrompt] = useState(
    bot?.chatbot?.style_prompt ||
      "Sen yardımsever, profesyonel ve sorulara hızlı cevap veren bir yapay zeka asistanısın.",
  );
  const [pricingType, setPricingType] = useState("free");
  const [activeTab, setActiveTab] = useState("general");
  const tabsRef = useRef(null);

  const activeTabIndex = FORM_TABS.findIndex((t) => t.id === activeTab);

  /* Sekme değişince sekme çubuğuna kaydırıyoruz: "Devam Et" formun EN
     ALTINDA duruyor, kaydırmadan basıldığında kullanıcı yeni sekmenin
     ortasına bakıyor ve içeriğin başını kaçırıyordu. */
  const goToTab = (id) => {
    setActiveTab(id);
    tabsRef.current?.scrollIntoView({ behavior: "smooth", block: "start" });
  };
  const [simulatedChat, setSimulatedChat] = useState([
    {
      sender: "bot",
      text: `Merhaba! Ben ${botName || "Yeni Asistanınız"}. Size nasıl yardımcı olabilirim?`,
    },
  ]);
  const [inputMsg, setInputMsg] = useState("");
  const [isBuilding, setIsBuilding] = useState(false);
  const [isPreviewLoading, setIsPreviewLoading] = useState(false);
  const [submitError, setSubmitError] = useState(null);

  // Kimlik & Genel: kategori + gorseller. Backend (savechatbot/updatechatbot)
  // kategori_id'yi zaten beyaz listede tutuyor ve gorselleri coverImage_file /
  // profileImage_file multipart alanlarindan aliyordu; form bunlari hic
  // gondermiyordu.
  const [categories, setCategories] = useState([]);
  const [categoryId, setCategoryId] = useState(
    bot?.chatbot?.kategori_id ? String(bot.chatbot.kategori_id) : "",
  );
  const [coverFile, setCoverFile] = useState(null);
  const [profileFile, setProfileFile] = useState(null);
  const [coverPreview, setCoverPreview] = useState(
    bot?.chatbot?.kapak_fotografi ? assetUrl(bot.chatbot.kapak_fotografi) : "",
  );
  const [profilePreview, setProfilePreview] = useState(
    bot?.chatbot?.profil_fotografi
      ? assetUrl(bot.chatbot.profil_fotografi)
      : "",
  );
  const coverInputRef = useRef(null);
  const profileInputRef = useRef(null);

  useEffect(() => {
    fetch("/api/content/getcategories.php")
      .then((res) => res.json())
      .then((data) => {
        if (Array.isArray(data?.categories)) setCategories(data.categories);
      })
      .catch(() => setCategories([]));
  }, []);

  // Secilen dosyayi dogrula ve onizlemesini uret. Sunucu tarafi da ayni
  // kontrolleri yapiyor (MIME + 5MB); burasi kullaniciya aninda geri bildirim.
  // --- Bilgi Bankasi (RAG degil: metin training_prompt'a eklenir, sohbette
  // tamami modele verilir; bkz. KB_MAX_CHARS) ---------------------------
  // Backend zaten hazirdi ve hic cagrilmiyordu:
  //   POST /api/training/readpdf.php            -> PDF metni cikarir
  //   POST /api/training/readurl.php            -> sayfa metni cikarir (yeni)
  //   POST /api/training/update_training_chunk.php -> training_prompt'a ekler
  const [kbSources, setKbSources] = useState([]);
  const [kbUrl, setKbUrl] = useState("");
  const [kbBusy, setKbBusy] = useState("");
  const [kbError, setKbError] = useState(null);
  const [corpusLength, setCorpusLength] = useState(null);
  const kbFileRef = useRef(null);

  const refreshCorpus = async () => {
    // N-02: yeni botun id'si -1 (truthy) — `!botId` onu yakalamıyordu.
    if (!botId || botId === -1) return;
    try {
      const res = await fetch(
        `/api/training/get_training_chunks.php?botId=${botId}&offset=0`,
        {
          credentials: "include",
        },
      );
      const data = await res.json();
      if (data?.success) setCorpusLength(Number(data.totalLength) || 0);
    } catch {
      /* sayac kritik degil */
    }
  };

  useEffect(() => {
    if (activeTab === "knowledge") refreshCorpus();
  }, [activeTab, botId]);

  // Metni parcalara bolup training_prompt'un sonuna ekler. Tek bir dev POST
  // yerine parcali: LONGTEXT sinirsiz ama istek boyutu degil.
  const appendToCorpus = async (text, label, kind) => {
    const clean = String(text || "").trim();
    if (!clean) {
      setKbError("Bu kaynaktan okunabilir metin çıkmadı.");
      return;
    }

    const header = `\n\n### Kaynak: ${label}\n`;
    const payload = header + clean;
    const CHUNK = 8000;

    for (let i = 0; i < payload.length; i += CHUNK) {
      const body = new FormData();
      body.append(
        "data",
        JSON.stringify({
          id: botId,
          textChunk: payload.slice(i, i + CHUNK),
          isFirst: false,
        }),
      );
      const res = await fetch("/api/training/update_training_chunk.php", {
        method: "POST",
        body,
        credentials: "include",
      });
      const result = await res.json();
      if (!result?.success) {
        throw new Error(result?.message || "Bilgi bankasına yazılamadı.");
      }
    }

    setKbSources((prev) => [...prev, { kind, label, chars: clean.length }]);
    await refreshCorpus();
  };

  const handleKbPdf = async (file) => {
    if (!file) return;
    setKbError(null);
    if (file.type !== "application/pdf") {
      setKbError("Yalnızca PDF dosyası yükleyebilirsiniz.");
      return;
    }
    if (file.size > KB_MAX_PDF_BYTES) {
      setKbError(`PDF çok büyük (maks. ${KB_MAX_PDF_MB} MB).`);
      return;
    }

    setKbBusy("PDF okunuyor...");
    try {
      const base64Data = await new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result).split(",")[1]);
        reader.onerror = reject;
        reader.readAsDataURL(file);
      });

      const res = await fetch("/api/training/readpdf.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ base64Data }),
        credentials: "include",
      });

      /* Burada dogrudan res.json() cagriliyordu ve yanit JSON degilse
         atilan "Unexpected token" hatasi kullaniciya PDF hatasi gibi
         gosteriliyordu. Bu uc icin onemli: istek govdesi buyuk oldugu icin
         araya giren bir vekil sunucu (nginx client_max_body_size, Apache
         LimitRequestBody) HTML bir 413 dondurebiliyor ya da PHP olumcul
         hatada bos govde birakabiliyor. O hallerde gercek neden HTTP
         durumunda saklidir; artik onu soyluyoruz. */
      const rawBody = await res.text();
      let data = null;
      try {
        data = JSON.parse(rawBody);
      } catch {
        throw new Error(
          res.status === 413
            ? "PDF, sunucunun kabul ettiği istek boyutunu aşıyor. Daha küçük bir dosya deneyin."
            : `Sunucu beklenmeyen bir yanıt döndürdü (HTTP ${res.status}). Dosya çok büyük olabilir.`,
        );
      }
      if (!data?.success)
        throw new Error(data?.message || "PDF ayrıştırılamadı.");

      setKbBusy("Bilgi bankasına ekleniyor...");
      await appendToCorpus(data.text, file.name, "pdf");
    } catch (err) {
      setKbError(err.message || "PDF eklenemedi.");
    } finally {
      setKbBusy("");
      if (kbFileRef.current) kbFileRef.current.value = "";
    }
  };

  const handleKbUrl = async () => {
    const url = kbUrl.trim();
    if (!url) return;
    setKbError(null);
    setKbBusy("Sayfa taranıyor...");
    try {
      const res = await fetch("/api/training/readurl.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ url }),
        credentials: "include",
      });
      const data = await res.json();
      if (!data?.success) throw new Error(data?.message || "Sayfa okunamadı.");

      setKbBusy("Bilgi bankasına ekleniyor...");
      await appendToCorpus(data.text, url, "url");
      setKbUrl("");
    } catch (err) {
      setKbError(err.message || "URL eklenemedi.");
    } finally {
      setKbBusy("");
    }
  };

  const pickImage = (file, setFile, setPreview) => {
    if (!file) return;
    if (!file.type.startsWith("image/")) {
      setSubmitError("Yalnızca görsel dosyası yükleyebilirsiniz.");
      return;
    }
    if (file.size > 5 * 1024 * 1024) {
      setSubmitError("Görsel boyutu 5 MB'ı aşamaz.");
      return;
    }
    setSubmitError(null);
    setFile(file);
    const reader = new FileReader();
    reader.onload = () => setPreview(String(reader.result || ""));
    reader.readAsDataURL(file);
  };

  const handleSubmit = async () => {
    if (!botName.trim()) {
      setSubmitError("Sohbet botu ismi zorunludur.");
      return;
    }
    setSubmitError(null);
    setIsBuilding(true);
    try {
      const chatbotData = {
        id: isPersisted ? botId : -1,
        isim: botName.trim(),
        aciklama: description,
        style_prompt: systemPrompt,
        sohbet_basi_mesaj: "",
      };
      if (categoryId) chatbotData.kategori_id = Number(categoryId);
      if (!isPersisted) {
        chatbotData.is_independent = independentMode ? 1 : 0;
      }
      const formData = new FormData();
      formData.append("data", JSON.stringify(chatbotData));
      // Sunucunun handleImageUploads() fonksiyonunun bekledigi alan adlari.
      if (coverFile) formData.append("coverImage_file", coverFile);
      if (profileFile) formData.append("profileImage_file", profileFile);
      const res = await fetch(
        isPersisted
          ? "/api/chatbot/updatechatbot.php"
          : "/api/chatbot/savechatbot.php",
        { method: "POST", body: formData, credentials: "include" },
      );
      const result = await res.json();
      if (result.success && !isPersisted && result.id) {
        // İlk kayıt: listeye atma, aynı sihirbazda Bilgi Bankası'nı aç.
        refetchAccount();
        setCreatedBotId(Number(result.id));
        // Görseller yüklendi; sonraki "Kaydet" onları yeniden göndermesin.
        setCoverFile(null);
        setProfileFile(null);
        window.history.replaceState(
          null,
          "",
          `${window.location.pathname}?id=${result.id}`,
        );
        goToTab("knowledge");
      } else if (result.success) {
        refetchAccount();
        router.push("/dashboard/chatbots");
      } else {
        setSubmitError(result.message || "Chatbot kaydedilemedi.");
      }
    } catch (err) {
      console.error("Chatbot save error:", err);
      setSubmitError("Sunucuya bağlanılamadı.");
    } finally {
      setIsBuilding(false);
    }
  };

  /**
   * UX-001 🟠 — bu önizleme SAHTEYDİ.
   *
   * Eski hâli `setTimeout(800)` sonrası sabit bir şablon yazıyordu:
   * `"${userText}" sorunuzu sistem talimatıma [${systemPrompt.slice(0,30)}...]
   * göre yanıtlıyorum!`. Sayfa `generatereply.php`'yi hiç çağırmıyordu; yani
   * kullanıcı prompt'unu test ettiğini sanıyor, aldığı cevap ise prompt'un
   * içeriğinden tamamen bağımsız oluyordu. "Canlı Test Modu" ve "Sandbox v2.4"
   * etiketleri bu yanılgıyı pekiştiriyordu.
   *
   * Artık iki hâl var ve ikisi de dürüst:
   *   • Kayıtlı bir botu düzenlerken (botId > 0) gerçek `generatereply.php`
   *     çağrılıyor — sunucu sistem talimatını kendi kuruyor (SEC-015), yani
   *     görülen cevap gerçekten kaydedilmiş prompt'un cevabı.
   *   • Henüz kaydedilmemiş yeni bir botta gerçek bir çağrı yapılamaz (sunucu
   *     erişim kontrolü ve talimat için chatbot_id ister). Sahte cevap
   *     üretmek yerine ne yapılması gerektiği söyleniyor.
   */
  const isSavedBot = Boolean(botId) && botId !== -1;

  const handleSendMessage = async (e) => {
    e.preventDefault();
    if (!inputMsg.trim() || isPreviewLoading) return;

    const userText = inputMsg;
    setInputMsg("");
    setSimulatedChat((prev) => [...prev, { sender: "user", text: userText }]);

    if (!isSavedBot) {
      setSimulatedChat((prev) => [
        ...prev,
        {
          sender: "system",
          text: "Gerçek önizleme için botu önce kaydedin. Kaydettikten sonra bu panelde botunuzun gerçek cevaplarını test edebilirsiniz.",
        },
      ]);
      return;
    }

    setIsPreviewLoading(true);
    try {
      const res = await fetch("/api/chat/generatereply.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({
          data: JSON.stringify({ chatbot_id: botId, message: userText }),
        }),
      });

      if (!res.ok) {
        const payload = await res.json().catch(() => null);
        setSimulatedChat((prev) => [
          ...prev,
          {
            sender: "system",
            text:
              payload?.message ||
              "Önizleme cevabı alınamadı. Daha sonra tekrar deneyin.",
          },
        ]);
        return;
      }

      const reader = res.body.getReader();
      const decoder = new TextDecoder("utf-8");
      let fullText = "";
      let placed = false;

      // AI-002: SSE kareleri okuma sınırlarında bölünebilir; tamamlanmamış
      // son satır tamponda bekletiliyor (sohbet sayfasıyla aynı desen).
      let buffer = "";

      while (true) {
        const { done, value } = await reader.read();
        if (done) break;

        buffer += decoder.decode(value, { stream: true });
        const lines = buffer.split("\n");
        buffer = lines.pop() ?? "";

        for (const line of lines) {
          if (!line.startsWith("data: ")) continue;
          try {
            const frame = JSON.parse(line.slice(6).trim());
            if (frame.error) continue;
            const chunk =
              frame.candidates?.[0]?.content?.parts?.[0]?.text || "";
            if (!chunk) continue;
            fullText += chunk;
            setSimulatedChat((prev) => {
              if (!placed) {
                placed = true;
                return [...prev, { sender: "bot", text: fullText }];
              }
              const next = [...prev];
              next[next.length - 1] = { sender: "bot", text: fullText };
              return next;
            });
          } catch (err) {
            // yarım kalmış SSE satırı — bir sonraki chunk'ta tamamlanır
          }
        }
      }

      if (!fullText) {
        setSimulatedChat((prev) => [
          ...prev,
          {
            sender: "system",
            text: "Yapay zeka servisinden cevap alınamadı. Mesaj hakkınız iade edildi.",
          },
        ]);
      }
    } catch (err) {
      setSimulatedChat((prev) => [
        ...prev,
        { sender: "system", text: "Sunucuya ulaşılamadı." },
      ]);
    } finally {
      setIsPreviewLoading(false);
    }
  };

  return (
    <div className="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
      {/* Left Column: Multi-tab Form Controls */}
      <div className="lg:col-span-7 space-y-6">
        {/* Navigation Tabs */}
        <div ref={tabsRef} className="flex border-b border-white/10 space-x-6">
          {FORM_TABS.map((tab) => {
            const Icon = tab.icon;
            const isActive = activeTab === tab.id;
            return (
              <button
                key={tab.id}
                onClick={() => setActiveTab(tab.id)}
                className={cn(
                  "flex items-center gap-2 pb-3 text-xs font-medium border-b-2 transition-all cursor-pointer",
                  isActive
                    ? "border-violet-500 text-white font-semibold"
                    : "border-transparent text-zinc-500 hover:text-zinc-300",
                )}
              >
                <Icon
                  className={cn(
                    "w-4 h-4",
                    isActive ? "text-violet-400" : "text-zinc-500",
                  )}
                />
                <span>{tab.label}</span>
              </button>
            );
          })}
        </div>

        {/* Tab 1: General Info */}
        {activeTab === "general" && (
          <div className="space-y-4 animate-in fade-in duration-200">
            {/* Sutunlar 8fr/5fr, iki kutu ESIT YUKSEKLIKTE dursun diye.
                Ikisi de kendi en-boy oranini koruyor (kapak 16/10, profil
                kare); yukseklik de o orandan cikiyor. Esit genislikte
                (sm:grid-cols-2) kapak w*10/16 = 0.625w yuksekligindeyken
                kare 1.0w oluyordu — profil gorunur bicimde daha uzundu.
                8:5 orani bunu esitliyor: kapak 8k genis, 8k*10/16 = 5k
                yuksek; profil 5k genis, 5k yuksek. */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-[8fr_5fr]">
              <ImagePicker
                label="Kapak Görseli"
                hint="16:10 önerilir · maks. 5 MB"
                preview={coverPreview}
                inputRef={coverInputRef}
                aspect="aspect-[16/10]"
                onPick={(f) => pickImage(f, setCoverFile, setCoverPreview)}
                onClear={() => {
                  setCoverFile(null);
                  setCoverPreview("");
                  if (coverInputRef.current) coverInputRef.current.value = "";
                }}
              />
              <ImagePicker
                label="Profil Görseli"
                hint="Kare · maks. 5 MB"
                preview={profilePreview}
                inputRef={profileInputRef}
                aspect="aspect-square"
                onPick={(f) => pickImage(f, setProfileFile, setProfilePreview)}
                onClear={() => {
                  setProfileFile(null);
                  setProfilePreview("");
                  if (profileInputRef.current)
                    profileInputRef.current.value = "";
                }}
              />
            </div>

            <div>
              <label className="block text-xs font-mono text-zinc-300 mb-1.5">
                Sohbet Botu İsmi
              </label>
              <input
                type="text"
                value={botName}
                onChange={(e) => setBotName(e.target.value)}
                placeholder="Örn: E-Ticaret Destek Asistanı"
                className="w-full rounded-xl bg-zinc-900 border border-white/10 px-4 py-3 text-xs text-white placeholder:text-zinc-600 focus:border-fuchsia-500/60 focus:ring-2 focus:ring-fuchsia-500/20 focus:outline-none"
              />
            </div>

            <div>
              <label className="block text-xs font-mono text-zinc-300 mb-1.5">
                Açıklama (Ne İş Yapar?)
              </label>
              <textarea
                rows={3}
                value={description}
                onChange={(e) => setDescription(e.target.value)}
                placeholder="Müşterilere sipariş durumunu aktarır, ürün tavsiye eder..."
                className="w-full rounded-xl bg-zinc-900 border border-white/10 px-4 py-3 text-xs text-white placeholder:text-zinc-600 focus:border-fuchsia-500/60 focus:ring-2 focus:ring-fuchsia-500/20 focus:outline-none resize-none"
              />
            </div>

            <div>
              <label className="mb-1.5 block font-mono text-xs text-zinc-300">
                Kategori
              </label>
              <Select
                value={categoryId}
                onValueChange={(v) => setCategoryId(v)}
              >
                <SelectTrigger>
                  <SelectValue placeholder="Kategori seçin" />
                </SelectTrigger>
                <SelectContent>
                  {categories.map((cat) => (
                    <SelectItem key={cat.id} value={String(cat.id)}>
                      {cat.kategori_adi_tr}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <p className="mt-1.5 text-caption text-zinc-500">
                Pazaryerinde hangi kategoride listeleneceğini belirler.
              </p>
            </div>

            <div className="pt-2">
              <label className="block text-xs font-mono text-zinc-300 mb-2">
                Hazır Rol Şablonu Seçin
              </label>
              {/* Seçili şablon AYRI bir state'te tutulmuyor, sistem
                  talimatından türetiliyor. İki yararı var: (a) düzenlenen bir
                  botun talimatı bir şablonla birebir aynıysa açılışta da
                  işaretli gelir; (b) kullanıcı talimatı elle değiştirdiği anda
                  işaret kendiliğinden kalkar — artık o şablon değildir. Ayrı
                  bir state bu iki durumda da gerçekle çelişirdi. */}
              <div className="grid grid-cols-2 gap-2.5">
                {ROLE_PRESETS.map((preset) => {
                  const isSelected = systemPrompt.trim() === preset.prompt;
                  return (
                    <button
                      key={preset.role}
                      type="button"
                      aria-pressed={isSelected}
                      onClick={() => setSystemPrompt(preset.prompt)}
                      className={cn(
                        "p-3 rounded-xl border text-left transition cursor-pointer group",
                        isSelected
                          ? "border-violet-500 bg-violet-500/10 ring-1 ring-violet-500/40"
                          : "border-white/5 bg-zinc-900/80 hover:border-violet-500/30",
                      )}
                    >
                      <div className="flex items-start justify-between gap-2">
                        <p
                          className={cn(
                            "text-xs font-semibold",
                            isSelected
                              ? "text-violet-200"
                              : "text-white group-hover:text-violet-300",
                          )}
                        >
                          {preset.role}
                        </p>
                        {/* Renk tek başına yeterli bir işaret değil; onay
                            simgesi renk körlüğünde de okunuyor. */}
                        {isSelected && (
                          <span className="flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-violet-500 text-white">
                            <Check className="h-2.5 w-2.5" />
                          </span>
                        )}
                      </div>
                      <p
                        className={cn(
                          "text-caption truncate mt-0.5",
                          isSelected ? "text-violet-300/70" : "text-zinc-500",
                        )}
                      >
                        {preset.prompt}
                      </p>
                    </button>
                  );
                })}
              </div>
            </div>
          </div>
        )}

        {/* Tab 2: Prompt Engine */}
        {activeTab === "brain" && (
          <div className="space-y-4 animate-in fade-in duration-200">
            <div>
              <label className="mb-1.5 block text-xs font-mono text-zinc-300">
                Sistem Talimatı
              </label>
              <textarea
                rows={6}
                value={systemPrompt}
                onChange={(e) => setSystemPrompt(e.target.value)}
                className="w-full rounded-xl bg-zinc-900 border border-white/10 p-4 text-xs font-mono text-zinc-200 focus:border-fuchsia-500/60 focus:ring-2 focus:ring-fuchsia-500/20 focus:outline-none leading-relaxed"
              />
            </div>

            <div className="p-4 rounded-xl bg-violet-500/5 border border-violet-500/15 flex items-start gap-3">
              <Sparkles className="w-4 h-4 text-violet-400 shrink-0 mt-0.5" />
              <p className="text-xs text-zinc-400 leading-relaxed">
                Asistanınızın sınırlarını, konuşma tonunu ve hangi durumlarda
                insan temsilciye yönlendirme yapacağını detaylıca
                belirtebilirsiniz.
              </p>
            </div>
          </div>
        )}

        {/* Tab 3: Bilgi Bankası (eğitim metni) */}
        {activeTab === "knowledge" && (
          <div className="space-y-4 animate-in fade-in duration-200">
            {!isSavedBot ? (
              /* training_prompt bir bot satirina yazilir; kaydedilmemis botun
                 id'si yok. Eskiden bu alan her durumda tiklanabilir gorunuyordu
                 ama hicbir sey yapmiyordu.

                 N-02 (M0-1) — bu kapı `!botId` ile kontrol ediliyordu, ama
                 yeni botta botId -1 (truthy) olduğu için kapı HİÇ kapanmıyordu:
                 yükleme `update_training_chunk.php`'ye id:-1 gönderiyor,
                 sunucu `positiveInt(-1)=0` ile sahiplik kontrolünü düşürüp
                 "Bu chatbot üzerinde yetkiniz yok." diyordu. URL yolu da
                 aynı çağrıyı yaptığı için kaydedilmemiş botta o da bozuktu. */
              <div className="flex items-start gap-3 rounded-xl border border-amber-500/20 bg-amber-500/[0.06] p-4">
                <AlertCircle className="mt-0.5 h-4 w-4 shrink-0 text-amber-400" />
                <p className="text-xs leading-relaxed text-amber-200/80">
                  Bilgi bankasına kaynak eklemek için önce botu kaydedin.
                  <span className="text-amber-200/50">
                    {" "}
                    “4. Yayın &amp; Fiyat” sekmesinden oluşturduktan sonra bu
                    alan aktifleşir.
                  </span>
                </p>
              </div>
            ) : (
              <>
                <div className="flex items-center justify-between rounded-xl border border-white/10 bg-zinc-900/60 px-4 py-3">
                  <div className="flex items-center gap-2.5">
                    <Database className="h-4 w-4 text-violet-400" />
                    <span className="text-xs text-zinc-400">
                      Bilgi bankası boyutu
                    </span>
                  </div>
                  <span className="font-mono text-xs font-semibold text-white">
                    {corpusLength === null
                      ? "—"
                      : `${corpusLength.toLocaleString("tr-TR")} / ${KB_MAX_CHARS.toLocaleString("tr-TR")} karakter`}
                  </span>
                </div>
                <p className="px-1 text-caption leading-relaxed text-zinc-500">
                  Eklediğiniz belge ve sayfaların metni botun eğitim metnine
                  eklenir ve her mesajda olduğu gibi modele verilir; ayrı bir
                  arama ya da vektör dizini yoktur. En fazla{" "}
                  {KB_MAX_CHARS.toLocaleString("tr-TR")} karakter saklanır,
                  fazlası eklenmez.
                </p>

                {/* PDF */}
                <button
                  type="button"
                  onClick={() => kbFileRef.current?.click()}
                  disabled={Boolean(kbBusy)}
                  className="w-full space-y-3 rounded-2xl border-2 border-dashed border-white/10 bg-zinc-900/40 p-8 text-center transition hover:border-violet-500/40 disabled:cursor-not-allowed disabled:opacity-60"
                >
                  <span className="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-violet-500/10 text-violet-400">
                    <FileText className="h-5 w-5" />
                  </span>
                  <span className="block text-xs font-semibold text-white">
                    Doküman veya PDF Yükleyin
                  </span>
                  <span className="block text-caption text-zinc-500">
                    Sıkça sorulan sorular, kataloglar veya şirket içi kılavuzlar
                    (maks. {KB_MAX_PDF_MB} MB)
                  </span>
                </button>
                <input
                  ref={kbFileRef}
                  type="file"
                  accept="application/pdf"
                  className="hidden"
                  onChange={(e) => handleKbPdf(e.target.files?.[0])}
                />

                {/* URL */}
                <div className="rounded-xl border border-white/10 bg-zinc-900 p-4">
                  <div className="mb-3 flex items-center gap-3">
                    <Globe2 className="h-4 w-4 shrink-0 text-cyan-400" />
                    <div>
                      <p className="text-xs font-semibold text-white">
                        Web Sitesi URL Tarama
                      </p>
                      <p className="text-caption text-zinc-500">
                        Sayfanın metni çıkarılıp bilgi bankasına eklenir
                      </p>
                    </div>
                  </div>
                  <div className="flex items-center gap-2 rounded-xl border border-white/10 bg-zinc-950 p-1.5 pl-3.5 transition-colors focus-within:border-fuchsia-500/50">
                    <Link2 className="h-3.5 w-3.5 shrink-0 text-zinc-500" />
                    <input
                      type="url"
                      value={kbUrl}
                      data-focus-managed
                      onChange={(e) => setKbUrl(e.target.value)}
                      onKeyDown={(e) => {
                        if (e.key === "Enter") {
                          e.preventDefault();
                          handleKbUrl();
                        }
                      }}
                      placeholder="https://ornek.com/sss"
                      className="min-w-0 flex-1 border-none bg-transparent text-xs text-white outline-none placeholder:text-zinc-600"
                    />
                    <button
                      type="button"
                      onClick={handleKbUrl}
                      disabled={!kbUrl.trim() || Boolean(kbBusy)}
                      className={cn(
                        "h-8 shrink-0 rounded-lg px-3 text-xs font-semibold transition-all",
                        kbUrl.trim() && !kbBusy
                          ? "bg-gradient-btn text-white hover:brightness-110"
                          : "cursor-not-allowed bg-white/[0.06] text-zinc-600",
                      )}
                    >
                      URL Ekle
                    </button>
                  </div>
                </div>

                {kbBusy && (
                  <p className="flex items-center gap-2 text-xs text-violet-300">
                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                    {kbBusy}
                  </p>
                )}
                {kbError && (
                  <p className="rounded-xl border border-rose-500/20 bg-rose-500/10 px-4 py-3 text-xs text-rose-400">
                    {kbError}
                  </p>
                )}

                {kbSources.length > 0 && (
                  <div className="space-y-1.5">
                    <p className="text-caption font-semibold uppercase tracking-wider text-zinc-500">
                      Bu oturumda eklenenler
                    </p>
                    {kbSources.map((src, i) => (
                      <div
                        key={i}
                        className="flex items-center gap-2.5 rounded-xl border border-emerald-500/15 bg-emerald-500/[0.05] px-3.5 py-2.5"
                      >
                        <CheckCircle2 className="h-3.5 w-3.5 shrink-0 text-emerald-400" />
                        {src.kind === "url" ? (
                          <Globe2 className="h-3.5 w-3.5 shrink-0 text-cyan-400" />
                        ) : (
                          <FileText className="h-3.5 w-3.5 shrink-0 text-violet-400" />
                        )}
                        <span className="min-w-0 flex-1 truncate text-xs text-white/80">
                          {src.label}
                        </span>
                        <span className="shrink-0 font-mono text-caption text-zinc-500">
                          {src.chars.toLocaleString("tr-TR")} krk
                        </span>
                      </div>
                    ))}
                  </div>
                )}
              </>
            )}
          </div>
        )}

        {/* Tab 4: Pricing */}
        {activeTab === "publish" && (
          <div className="space-y-4 animate-in fade-in duration-200">
            <div className="p-4 rounded-xl bg-zinc-900 border border-white/10 space-y-3">
              <h4 className="text-xs font-bold text-white flex items-center gap-2">
                <ShieldCheck className="w-4 h-4 text-emerald-400" />
                Erişim Türü:{" "}
                {independentMode
                  ? "Bağımsız (Özel)"
                  : "Herkese Açık (Ücretsiz)"}
              </h4>
              <p className="text-xs text-zinc-400 leading-relaxed">
                {independentMode
                  ? "Bu bot sadece sizin hesabınızda kalacaktır. İstediniz an sitenize widget olarak ekleyebilirsiniz."
                  : "Bu bot Keşfet'te listelenecek; herkes ücretsiz sohbet edebilir, mesajlar kullanıcıların günlük Luma Coin'inden düşer."}
              </p>
            </div>

            {submitError && (
              <p className="text-xs text-rose-400 bg-rose-500/10 border border-rose-500/20 rounded-xl px-4 py-3">
                {submitError}
              </p>
            )}

            <button
              onClick={handleSubmit}
              disabled={isBuilding}
              className="w-full py-4 rounded-xl bg-gradient-btn text-xs font-bold text-white shadow-glow hover:brightness-110 hover:-translate-y-0.5 active:translate-y-0 transition-all duration-200 cursor-pointer flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {isBuilding ? (
                <RefreshCw className="w-4 h-4 animate-spin" />
              ) : (
                <>
                  <Rocket className="w-4 h-4" />
                  <span>
                    {isPersisted
                      ? "Değişiklikleri Kaydet & Güncelle"
                      : "Sohbet Botunu Oluştur & Yayına Al"}
                  </span>
                </>
              )}
            </button>
          </div>
        )}

        {/* Sekmeler arası gezinme.
            Üstteki sekme çubuğu zaten tıklanabilirdi ama form uzun: bir
            sonraki adıma geçmek için her seferinde sayfanın başına dönmek
            gerekiyordu.

            Son sekmede "Devam Et" YOK — orada gerçek eylem "Sohbet Botunu
            Oluştur"; yanına ikinci bir ileri düğmesi koymak hangisinin
            kaydettiğini belirsizleştirirdi. Aynı şekilde ilk sekmede "Geri"
            çizilmiyor. İki yan da eşit genişlikte (flex-1) tutulduğu için
            adım sayacı düğmeler görünüp kayboldukça yerinden oynamıyor. */}
        <div className="flex items-center justify-between gap-3 border-t border-white/10 pt-5">
          <div className="flex-1">
            {activeTabIndex > 0 && (
              <button
                type="button"
                onClick={() => goToTab(FORM_TABS[activeTabIndex - 1].id)}
                className="inline-flex items-center gap-1.5 rounded-xl border border-white/10 bg-white/5 px-4 py-2.5 text-xs font-medium text-white/80 transition-colors hover:bg-white/10 hover:text-white cursor-pointer"
              >
                <ChevronLeft className="h-4 w-4" />
                <span>Geri</span>
              </button>
            )}
          </div>

          <span className="shrink-0 font-mono text-caption text-zinc-500">
            Adım {activeTabIndex + 1} / {FORM_TABS.length}
          </span>

          <div className="flex flex-1 justify-end">
            {activeTabIndex < FORM_TABS.length - 1 && (
              <button
                type="button"
                onClick={() => goToTab(FORM_TABS[activeTabIndex + 1].id)}
                className="inline-flex items-center gap-1.5 rounded-xl bg-violet-600 px-4 py-2.5 text-xs font-semibold text-white transition-colors hover:bg-violet-500 cursor-pointer"
              >
                <span>Devam Et</span>
                <ChevronRight className="h-4 w-4" />
              </button>
            )}
          </div>
        </div>
      </div>

      {/* Right Column: Live AI Sandbox Simulator */}
      <div className="lg:col-span-5 sticky top-6">
        <div className="rounded-2xl bg-zinc-950 border border-white/10 shadow-2xl overflow-hidden flex flex-col h-[520px]">
          {/* Header toolbar */}
          <div className="p-3.5 bg-zinc-900/90 border-b border-white/10 flex items-center justify-between">
            <div className="flex items-center gap-2.5">
              <div className="w-7 h-7 rounded-full bg-gradient-to-tr from-violet-500 to-fuchsia-500 flex items-center justify-center text-white font-bold text-xs">
                <Bot className="w-4 h-4" />
              </div>
              <div>
                <h4 className="text-xs font-bold text-white leading-tight">
                  {botName || "Önizleme Asistanı"}
                </h4>
                <p className="text-caption flex items-center gap-1">
                  {/* UX-001: etiket artık gerçeği söylüyor. "Canlı Test Modu"
                      + "Sandbox v2.4", hiçbir model çağrısı yapılmayan sabit
                      bir şablonun üstünde duruyordu. */}
                  {isSavedBot ? (
                    <>
                      <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse" />
                      <span className="text-emerald-400">
                        Gerçek yanıt · mesaj hakkınızdan düşer
                      </span>
                    </>
                  ) : (
                    <>
                      <span className="w-1.5 h-1.5 rounded-full bg-amber-400" />
                      <span className="text-amber-400">
                        Önizleme için botu kaydedin
                      </span>
                    </>
                  )}
                </p>
              </div>
            </div>
            <span className="text-caption font-mono text-zinc-500 bg-zinc-900 px-2 py-1 rounded border border-white/5">
              {isSavedBot ? "Canlı" : "Taslak"}
            </span>
          </div>

          {/* Chat message body */}
          <div className="flex-1 p-4 space-y-3 overflow-y-auto bg-black/40">
            {simulatedChat.map((msg, i) => (
              <div
                key={i}
                className={cn(
                  "flex",
                  msg.sender === "user" ? "justify-end" : "justify-start",
                )}
              >
                <div
                  className={cn(
                    "max-w-[85%] rounded-2xl px-3.5 py-2.5 text-xs leading-relaxed",
                    msg.sender === "user" &&
                      "bg-violet-600 text-white rounded-br-xs shadow-md shadow-violet-950/50",
                    msg.sender === "bot" &&
                      "bg-zinc-800/90 text-zinc-200 border border-white/10 rounded-bl-xs",
                    msg.sender === "system" &&
                      "bg-amber-500/10 text-amber-200/90 border border-amber-400/25 rounded-bl-xs",
                  )}
                >
                  {msg.text}
                </div>
              </div>
            ))}
            {isPreviewLoading && (
              <div className="flex justify-start">
                <div className="max-w-[85%] rounded-2xl rounded-bl-xs border border-white/10 bg-zinc-800/90 px-3.5 py-2.5 text-xs text-zinc-400">
                  Yanıt üretiliyor…
                </div>
              </div>
            )}
          </div>

          {/* Input field */}
          <form
            onSubmit={handleSendMessage}
            className="p-3 bg-zinc-900/80 border-t border-white/10 flex gap-2"
          >
            <input
              type="text"
              value={inputMsg}
              onChange={(e) => setInputMsg(e.target.value)}
              placeholder={
                isSavedBot ? "Test mesajı yazın..." : "Önce botu kaydedin..."
              }
              className="flex-1 bg-black/50 border border-white/10 rounded-xl px-3 py-2 text-xs text-white placeholder:text-zinc-600 focus:outline-none focus:border-fuchsia-500/60 focus:ring-2 focus:ring-fuchsia-500/20"
            />
            <button
              type="submit"
              disabled={isPreviewLoading}
              className="p-2 rounded-xl bg-violet-600 text-white hover:bg-violet-500 transition cursor-pointer disabled:cursor-not-allowed disabled:opacity-50"
            >
              <Send className="w-4 h-4" />
            </button>
          </form>
        </div>
      </div>
    </div>
  );
}

function CreateChatbotInner({ userId, bot, botId, selectedCard }) {
  const isEditing = !!bot;
  const [choice, setChoice] = useState(
    isEditing ? (bot.chatbot?.is_independent ? "independent" : "public") : null,
  );
  const [limits, setLimits] = useState(null);

  const fetchLimits = () => {
    if (!userId) return;
    fetch(`/api/chatbot/getchatbotlimits.php?user_id=${userId}`)
      .then((res) => res.json())
      .then((data) => {
        if (data.success) {
          setLimits(data);
          return;
        }
        // A `success: false` response used to be ignored entirely, leaving
        // `limits` null forever — and the guard below only renders once it is
        // set, so the page sat on its loading skeleton indefinitely.
        setLimits(LIMITS_UNAVAILABLE);
      })
      .catch(() => {
        // Never invent permissive limits. The old fallback reported
        // can_create_* = true with made-up "1/2" and "0/5" counters that
        // contradicted the real server-side limits (AppConfig's
        // FREE_INDEPENDENT_BOT_LIMIT = 1 / FREE_PUBLIC_BOT_LIMIT = 2), so a
        // backend outage advertised capacity the user did not have and an
        // action the server would then refuse. Deny until we actually know.
        setLimits(LIMITS_UNAVAILABLE);
      });
  };

  useEffect(() => {
    if (isEditing || !userId) return;
    fetchLimits();
  }, [isEditing, userId]);

  if (!userId || (!isEditing && !limits)) {
    return (
      <div className="space-y-6">
        <Skeleton className="h-10 w-64" />
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          <Skeleton className="h-64 rounded-2xl" />
          <Skeleton className="h-64 rounded-2xl" />
        </div>
      </div>
    );
  }

  // State 1: New Chatbot and no selection made yet
  if (!isEditing && choice === null) {
    return (
      <div className="space-y-10">
        <PageHeader
          title="Yeni Bir Chatbot Yaratın"
          description="Yapay zeka asistanınızı birkaç adımda yayına alın. İlk olarak chatbot'un erişim modelini belirleyin."
        />

        {limits.unavailable && (
          /* Without this the deny-by-default state looks like the account
             simply has no quota left, with no way to tell it apart from a
             backend that is merely unreachable. */
          <div className="rounded-2xl border border-amber-400/25 bg-amber-400/[0.07] px-5 py-4">
            <p className="text-body-sm leading-relaxed text-amber-200/90">
              <span className="font-semibold">
                Chatbot limitleriniz okunamadı.
              </span>{" "}
              Sunucuya ulaşılamadığı için oluşturma geçici olarak kapalı.
              <button
                type="button"
                onClick={fetchLimits}
                className="ml-2 underline underline-offset-2 hover:text-amber-100"
              >
                Tekrar dene
              </button>
            </p>
          </div>
        )}

        {/* Twin Choice Cards */}
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          {/* Card 1: Independent / Private */}
          <GlassCard
            interactive={limits.can_create_independent}
            disabled={!limits.can_create_independent}
            onClick={() => setChoice("independent")}
            className="p-8 group relative flex flex-col justify-between"
          >
            <div className="pointer-events-none absolute -right-12 -top-12 h-36 w-36 rounded-full bg-violet-500/10 blur-3xl group-hover:bg-violet-500/20 transition-all" />

            <div className="space-y-4 relative z-10">
              <div className="flex items-center justify-between">
                <div className="p-3.5 rounded-2xl bg-violet-500/10 text-violet-300 border border-violet-500/20">
                  <Lock className="w-6 h-6" />
                </div>
                <Badge
                  variant={
                    limits.can_create_independent ? "violet" : "destructive"
                  }
                >
                  {limits.can_create_independent
                    ? "Özel Erişim"
                    : `Hakkınız Doldu (${limits.independent_limit ?? "—"})`}
                </Badge>
              </div>

              <div>
                <h3 className="text-xl font-bold text-white group-hover:text-violet-200 transition-colors">
                  Bağımsız (Özel) Chatbot
                </h3>
                <p className="text-xs sm:text-sm text-zinc-400 mt-2 leading-relaxed">
                  Oluşturduğunuz chatbot yalnızca size özel kalır. Kendi web
                  sitenize widget olarak ekleyebilir veya özel API token ile
                  bağlayabilirsiniz.
                </p>
              </div>
            </div>

            <div className="pt-6 mt-6 border-t border-white/5 flex items-center justify-between relative z-10">
              <span className="text-xs font-mono text-zinc-400">
                Kapasite: {capacityLabel(limits.independent_used, limits.independent_limit, limits.independent_unlimited)}
              </span>
              <span className="text-xs font-semibold text-violet-300 group-hover:translate-x-1 transition-transform flex items-center gap-1">
                Seç ve Devam Et <ArrowRight className="w-4 h-4" />
              </span>
            </div>
          </GlassCard>

          {/* Card 2: Herkese Açık (madde 2 / GK-2) — ücretsiz, satıcı kaydı gerekmez.
              Fiyatlı pazaryeri satışı ayrı: şirket başvurusu (madde 3), aşağıdaki bağlantı. */}
          <GlassCard
            interactive={limits.can_create_public}
            disabled={!limits.can_create_public}
            onClick={() => setChoice("public")}
            className="p-8 group relative flex flex-col justify-between"
          >
            <div className="pointer-events-none absolute -right-12 -top-12 h-36 w-36 rounded-full bg-fuchsia-500/10 blur-3xl group-hover:bg-fuchsia-500/20 transition-all" />

            <div className="space-y-4 relative z-10">
              <div className="flex items-center justify-between">
                <div className="p-3.5 rounded-2xl bg-fuchsia-500/10 text-fuchsia-300 border border-fuchsia-500/20">
                  <Globe2 className="w-6 h-6" />
                </div>
                <Badge
                  variant={limits.can_create_public ? "fuchsia" : "destructive"}
                >
                  {limits.can_create_public
                    ? "Ücretsiz"
                    : `Hakkınız Doldu (${limits.public_limit ?? "—"})`}
                </Badge>
              </div>

              <div>
                <h3 className="text-xl font-bold text-white group-hover:text-fuchsia-200 transition-colors">
                  Herkese Açık Chatbot Oluştur
                </h3>
                <p className="text-xs sm:text-sm text-zinc-400 mt-2 leading-relaxed">
                  Chatbotunuz Keşfet'te herkese açık yayınlanır. Herkes ücretsiz
                  sohbet edebilir; mesajlar kullanıcıların günlük Luma
                  Coin'inden düşer.
                </p>
              </div>
            </div>

            <div className="pt-6 mt-6 border-t border-white/5 flex items-center justify-between relative z-10">
              <span className="text-xs font-mono text-zinc-400">
                Kapasite: {capacityLabel(limits.public_used, limits.public_limit, limits.public_unlimited)}
              </span>
              <span className="text-xs font-semibold text-fuchsia-300 group-hover:translate-x-1 transition-transform flex items-center gap-1">
                Seç ve Devam Et <ArrowRight className="w-4 h-4" />
              </span>
            </div>
          </GlassCard>
        </div>

        {/* Madde 10 — pazaryerinde ücretli satış şirket başvurusu ister. */}
        <p className="text-xs text-zinc-400">
          Botunuzu pazaryerinde ücretli satmak mı istiyorsunuz?{" "}
          <Link
            href="/dashboard/pazaryeri-basvurusu"
            className="font-semibold text-fuchsia-300 hover:text-fuchsia-200"
          >
            Pazaryeri Başvurusu
          </Link>
        </p>

        {/* Visual Workflow Steps Preview */}
        <div className="pt-4 border-t border-white/10 space-y-4">
          <p className="text-xs font-mono font-semibold uppercase tracking-widest text-zinc-400">
            Süreç Nasıl İlerler?
          </p>

          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            {[
              {
                title: "1. Kişiliğini Tanımla",
                desc: "Botun ismini, konuşma üslubunu ve sistem yönergelerini belirle.",
              },
              {
                title: "2. Veri Kaynağını Bağla",
                desc: "PDF, Notion dokümanı veya web URL'si ekleyerek botu eğit.",
              },
              {
                title: "3. Yayına Al",
                desc: "Bağımsız özel bot olarak kullan ya da herkese açık yayınla.",
              },
            ].map((st, i) => (
              <div
                key={i}
                className="p-4 rounded-xl bg-zinc-900/50 border border-white/5 space-y-2"
              >
                <h5 className="text-xs font-bold text-white">{st.title}</h5>
                <p className="text-caption text-zinc-400 leading-relaxed">
                  {st.desc}
                </p>
              </div>
            ))}
          </div>
        </div>

      </div>
    );
  }

  const independentMode = choice === "independent";

  // Faz 4 — eski "State 2" (herkese açık için satıcı sihirbazı) kaldırıldı:
  // herkese açık bot artık satıcı kaydı istemiyor (M10).

  // State 3: Ready to create or edit chatbot form
  return (
    <div className="space-y-8">
      <PageHeader
        eyebrow="AI Creation Studio"
        eyebrowClassName="text-violet-300"
        title={bot ? "Sohbet Botunu Düzenle" : "Yeni Chatbot Oluştur"}
        description={
          bot
            ? "Var olan asistanınızın parametrelerini, prompt mimarisini ve bilgi bankasını güncelleyin."
            : "Kimliğini, davranışını ve bilgi kaynaklarını belirleyerek yapay zeka asistanınızı canlıya alın."
        }
      />

      <ChatbotForm
        selectedCard={selectedCard}
        bot={bot}
        botId={botId}
        userId={userId}
        independentMode={independentMode}
      />
    </div>
  );
}

export default function CreateChatbot() {
  const { userId } = useContext(UserContext);
  const [bot, setBot] = useState(null);
  const [botId, setBotId] = useState(null);

  const selectedCard = {
    title: "YÖNLENDİRME BOTU",
    desc: "Talimat Vererek Bir Bot Oluştur.",
    icon: <Bot className="w-5 h-5 text-violet-400" />,
    bgColor: "#9BC8FF",
  };

  // 2. Query param reader
  useEffect(() => {
    if (typeof window !== "undefined") {
      const params = new URLSearchParams(window.location.search);
      const id = params.get("id") || -1;
      setBotId(id);
    } else {
      setBotId(-1);
    }
  }, []);

  // 3. Chatbot fetch if editing
  useEffect(() => {
    if (!userId) return;
    if (botId && botId !== -1) {
      fetch(`/api/chatbot/getchatbot.php?id=${botId}&user_id=${userId}`)
        .then((res) => res.json())
        .then((data) => {
          const botData = Array.isArray(data) ? data[0] : data;
          if (botData) setBot(botData);
        })
        .catch((err) => console.error("Bot fetch error:", err));
    }
  }, [userId, botId]);

  // Loading Skeleton State
  if (botId === null || (botId !== -1 && !bot)) {
    return (
      <div className="min-h-screen bg-[#09090b] text-zinc-100 p-6 sm:p-12">
        <div className="space-y-8">
          <Skeleton className="h-10 w-64" />
          <Skeleton className="h-80 w-full rounded-2xl" />
        </div>
      </div>
    );
  }

  // Unauthenticated fallback prompt
  if (!userId) {
    return (
      <div className="min-h-screen bg-[#09090b] text-zinc-100 flex flex-col items-center justify-center p-4 text-center">
        <GlassCard className="p-8  space-y-4">
          <div className="w-12 h-12 rounded-2xl bg-violet-500/10 text-violet-400 mx-auto flex items-center justify-center border border-violet-500/20">
            <Lock className="w-6 h-6" />
          </div>
          <h2 className="text-2xl font-bold text-white">
            Giriş Yapmanız Gerekiyor
          </h2>
          <p className="text-xs text-zinc-400 leading-relaxed">
            Yeni bir sohbet botu oluşturmak veya düzenlemek için önce hesabınıza
            giriş yapmalısınız.
          </p>
          <a
            href="/login"
            className="block w-full py-3 rounded-xl bg-violet-600 text-xs font-semibold text-white hover:bg-violet-500 transition cursor-pointer"
          >
            Giriş Yap
          </a>
        </GlassCard>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-[#09090b] text-zinc-100 antialiased selection:bg-violet-500/30 selection:text-violet-200 p-4 sm:p-8 font-sans">
      <CreateChatbotInner
        userId={userId}
        bot={bot}
        botId={botId}
        selectedCard={selectedCard}
      />
    </div>
  );
}
