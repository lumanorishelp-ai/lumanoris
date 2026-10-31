"use client";
import dynamic from "next/dynamic";
import MessageInput from "@/features/chat/MessageInput";
import ProfileCard from "@/entities/user/ui/ProfileCard";
import React, { useState, useRef, useEffect, useCallback, useContext } from "react";
import { UserContext } from "@/shared/contexts/UserContext";
import { EntityAvatar } from "@/shared/ui/entity-image";
import { chatFailureText } from "@/features/chat/reply-errors.mjs";
import { useSearchParams } from "next/navigation";

// Only loaded when the user actually opens one of these modals, instead of
// shipping their code with every chat page load.
const DialogNotebookModal = dynamic(() => import("@/features/notes/DialogNotebookModal"), { ssr: false });
import ReactMarkdown from "react-markdown";
import ChatMarkdown from "@/features/chat/ChatMarkdown";
import { useRouter } from "next/navigation";
import { cn } from "@/lib/utils";
import { Button } from "@/shared/ui/button";
import { Lumacoin } from "@/shared/ui/lumacoin";
import { Lock, NotebookPen, RotateCcw } from "lucide-react";

/* N-48 — süre zinciri (api/functions/gemini.php): sunucu cURL 30 sn'de,
   PHP 45 sn'de biter; istemci 50 sn bekler, Vercel'in harici rewrite sınırı
   (ilk bayt) 120 sn. Sunucu her zaman istemciden önce ya cevabı kaydeder ya da
   hata karesi + coin iadesi gönderir. gemini_selftest E bu sırayı kilitler. */
const CHAT_REPLY_TIMEOUT_MS = 50_000;
/* İstemci yine de vazgeçerse (ağ, yavaş sunucu) sunucu cevabı kaydetmiş
   olabilir: getchat ile birkaç kez yeniden sorulur. */
const SAVED_REPLY_POLLS = 6;
const SAVED_REPLY_POLL_MS = 4_000;

export default function Chat() {
  const { userId, account: sharedAccount, setDailyCoinsRemaining } = useContext(UserContext) || {};
  const [bot, setBot] = useState(null);
  const [botId, setBotId] = useState(0);
  const [comments, setComments] = useState(null);
  const [conversationId, setConversationId] = useState(0);
  const searchParams = useSearchParams();
  const urlBotId = searchParams.get("botId") || "";
  const urlConvId = Number(searchParams.get("convId") || 0);
  const urlPrompt = searchParams.get("prompt") || "";
  const chatSessionRef = useRef(0);
  const convPendingRef = useRef(null);
  const promptSentRef = useRef(false);
  const [chatAdFrequency, setChatAdFrequency] = useState(10);
  const messagesEndRef = useRef(null);
  // Kullanıcı yukarı kaydırdıysa akış sırasında zorla aşağı çekme.
  const stickToBottomRef = useRef(true);
  const [messages, setMessages] = useState([]);
  const [logoClicked, setLogoClicked] = useState(false);
  const [isDialogModalOpen, setIsDialogModalOpen] = useState(false);
  const [activeDialog, setActiveDialog] = useState({ input: "", output: "" });
  const [botLoadError, setBotLoadError] = useState(null);
  const [hasSubscription, setHasSubscription] = useState(false);
  // N-52 — sunucunun sohbet erişimi kararı (getchatbot `can_chat`). false ise
  // mesaj kutusu yerine kilitli uyarı; karşılama kaydı denenmez.
  const [canChat, setCanChat] = useState(null);
  const [checkingSub, setCheckingSub] = useState(true);
  // Sohbet Luma Coini: mesaj hakkı tükendiğinde mesaj kutusunun yerini alan
  // "Sınır Aşıldı" bandı.
  const [limitReached, setLimitReached] = useState(false);
  const [coinsRemaining, setLocalCoinsRemaining] = useState(null);
  const [retryAt, setRetryAt] = useState(null);
  // N-50 — sunucudan gelen her coin değeri hem bu sayfanın mantığına hem de
  // layout'taki paylaşılan hesaba yazılır; kenar çubuğu ve çip aynı değeri
  // gösterir (tek istemci kaynağı: useAccountData).
  const setCoinsRemaining = useCallback((n) => {
    if (typeof n !== "number") return;
    setLocalCoinsRemaining(n);
    setDailyCoinsRemaining?.(n);
  }, [setDailyCoinsRemaining]);
  // Çip paylaşılan değeri okur; hesap henüz yüklenmediyse yerel değer.
  const sharedCoins = sharedAccount?.id != null ? sharedAccount.dailyCoinsRemaining : coinsRemaining;

  const checkMessageAllowance = useCallback(async (uId, bId) => {
      if (!uId || !bId) return;
      try {
          const res = await fetch(`/api/message/checkmessageallowance.php?chatbot_id=${bId}`);
          const data = await res.json();
          if (data.success) {
              setCoinsRemaining(data.daily_coins_remaining);
              setRetryAt(data.retry_at || null);
          }
      } catch (err) {
          console.error("Mesaj hakkı sorgulama hatası:", err);
      }
  }, [setCoinsRemaining]);

  useEffect(() => {
      if (userId && botId) {
          checkMessageAllowance(userId, botId);
      }
  }, [userId, botId, checkMessageAllowance]);

  // "Sınır Aşıldı" bandındaki 24 saatlik geri sayım — sadece limit doluyken tik atar.
  const [retryCountdownLabel, setRetryCountdownLabel] = useState("");
  useEffect(() => {
      if (!limitReached || !retryAt) {
          setRetryCountdownLabel("");
          return;
      }
      const target = new Date(retryAt.replace(" ", "T")).getTime();
      const tick = () => {
          const diff = target - Date.now();
          if (diff <= 0) {
              setRetryCountdownLabel("");
              checkMessageAllowance(userId, botId);
              return;
          }
          const h = Math.floor(diff / 3600000);
          const m = Math.floor((diff % 3600000) / 60000);
          const s = Math.floor((diff % 60000) / 1000);
          setRetryCountdownLabel(`${h}s ${m}dk ${s}sn`);
      };
      tick();
      const interval = setInterval(tick, 1000);
      return () => clearInterval(interval);
  }, [limitReached, retryAt, userId, botId, checkMessageAllowance]);

  const checkSubscription = useCallback(async (uId, bId) => {
      if (!uId || !bId) return;
      try {
          const res = await fetch(`/api/wallet/getsubscription.php?user_id=${uId}&chatbot_id=${bId}`);
          const data = await res.json();
          setHasSubscription(data.has_active_sub);
      } catch (err) {
          console.error("Abonelik kontrol hatası:", err);
      } finally {
          setCheckingSub(false);
      }
  }, []);

  useEffect(() => {
      if (userId && botId) {
          checkSubscription(userId, botId);
      }
  }, [userId, botId, checkSubscription]);

  const conversationIdRef = useRef(conversationId);
  const botIdRef = useRef(botId);

  const router = useRouter();

  function timeAgo(dateString) {
    const diff = Date.now() - new Date(dateString).getTime();
    const seconds = Math.floor(diff / 1000);
    if (seconds < 60) return `${seconds} saniye önce`;
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes} dakika önce`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours} saat önce`;
    const days = Math.floor(hours / 24);
    return `${days} gün önce`;
  }

  /* Sunucudaki karsiligi: ChatController::MAX_FILE_BYTES. Ikisi elle senkron
     tutuluyor; buradaki kontrol yalnizca bosuna bir yukleme yapilmasini
     onlemek icin, gercek kapi sunucuda. */
  const MAX_CHAT_FILE_BYTES = 4 * 1024 * 1024;
  const MAX_CHAT_TOTAL_FILE_BYTES = 5 * 1024 * 1024;

  const fileToBase64 = (file) => {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => resolve(reader.result.split(",")[1]); // sadece Base64 kısmı
      reader.onerror = reject;
      reader.readAsDataURL(file);
    });
  };

  const openDialogModal = (index) => {
    const currentBotMsg = messages[index]?.text || "";
    // Bot mesajından geriye doğru giderek ilk 'sent' (kullanıcı) mesajını buluyoruz
    const lastUserMsg = messages
      .slice(0, index)
      .reverse()
      .find((m) => m.type === "sent")?.text || "";

    setActiveDialog({
      input: lastUserMsg,
      output: currentBotMsg,
      // N-30: sunucu soru/cevabı bu kimlikten DB'den kopyalıyor.
      messageId: messages[index]?.messageId,
    });
    setIsDialogModalOpen(true);
  };

  // AI-001 / SEC-015 / COIN-001: bu fonksiyon botun TÜM eğitim metnini
  // (LONGTEXT, sınırsız) 10 KB'lık parçalar hâlinde tarayıcıya indiriyor ve
  // her mesajda Gemini'ye geri gönderiyordu. Artık sistem talimatı sunucuda
  // kuruluyor (ChatController::generateReply), yani bu indirmenin hiçbir
  // gerekçesi kalmadı: ücretli içeriğin istemciye hiç ulaşmaması ödeme
  // duvarının kendisi.

  useEffect(() => {
    fetch("/api/content/getadcounts.php")
      .then((response) => {
        if (!response.ok) {
          throw new Error("Network response was not ok");
        }
        return response.json();
      })
      .then((data) => {
        // ERR-003: yanıt artık zarflı ({success, content}).
        setChatAdFrequency(Number(data?.content?.chat_reklam_sikligi ?? 0));
      })
      .catch((error) => {
        console.error("Fetch error:", error);
      });

    const handleLogoClick = (event) => {
      setLogoClicked(event.detail.clicked);
    };

    window.addEventListener("logoClicked", handleLogoClick);

    return () => {
      window.removeEventListener("logoClicked", handleLogoClick);
    };
  }, []);

  useEffect(() => {
    if (!userId) return;

    const botIdd = urlBotId || 0;
    setBotId(botIdd);
    setBot(null);
    setBotLoadError(null);

    // No botId in the URL means no bot is selected yet — the page used to
    // request getchatbot.php?id=0 anyway and take a 400, filling the console
    // with an error that looked like a real failure.
    if (!botIdd || Number(botIdd) <= 0) return;

    // API-001: getchatbot.php artık başarıda da zarflı yanıt veriyor, yani
    // "başarılı mı?" sorusu ilk kez cevaplanabilir. Kontrolsüz hâlde 404
    // (silinmiş bot) ve 403 (aboneliği olmayan kullanıcı) sessizce boş bir
    // sayfaya dönüşüyordu; kullanıcı neden hiçbir şey olmadığını göremiyordu.
    fetch(`/api/chatbot/getchatbot.php?id=${botIdd}&user_id=${userId}`)
      .then(async (res) => {
        const data = await res.json().catch(() => null);
        if (!res.ok || !data || data.success === false) {
          setBotLoadError(
            data?.message ||
              "Chatbot yüklenemedi. Bağlantınızı kontrol edip tekrar deneyin.",
          );
          return;
        }

        const botData = data.chatbot;
        const commentsData = data.comments;

        if (commentsData && commentsData.list) {
          const mapped = commentsData.list.map((item) => ({
            text: item.comment,
            author: item.kullanici_adi,
            date: timeAgo(item.commented_at), // helper fonksiyon
          }));
          setComments(mapped);
        }
        if (typeof data.can_chat === "boolean") {
          setCanChat(data.can_chat);
        }
        if (botData) {
          setBot(botData);
        }
      })
      .catch(() => {
        setBotLoadError("Sunucuya ulaşılamadı. Bağlantınızı kontrol edin.");
      });
  }, [userId, urlBotId]);

  const greetingMessages = useCallback((b) => {
    const text = (b?.sohbet_basi_mesaj || "").trim();
    return text ? [{ type: "received", text: b.sohbet_basi_mesaj }] : [];
  }, []);

  useEffect(() => {
    if (!userId || !bot) return;
    chatSessionRef.current += 1;
    convPendingRef.current = null;
    const greeting = greetingMessages(bot);
    const greetText = (bot.sohbet_basi_mesaj || "").trim();

    if (urlConvId < 1) {
      conversationIdRef.current = 0;
      setConversationId(0);
      setMessages(greeting);
      return;
    }

    let cancelled = false;
    conversationIdRef.current = urlConvId;
    setConversationId(urlConvId);
    setMessages(greeting);

    fetch(`/api/chat/getchat.php?chatbot_id=${bot.id}&conversation_id=${urlConvId}`)
      .then(async (res) => ({ ok: res.ok, data: await res.json().catch(() => null) }))
      .then(({ ok, data }) => {
        if (cancelled) return;
        if (!ok || !data?.success || !Array.isArray(data.messages)) {
          conversationIdRef.current = 0;
          setConversationId(0);
          return;
        }
        const restored = data.messages
          .filter((m) => !(m.sent_by !== "user" && greetText && String(m.message).trim() === greetText))
          .map((m) => ({
            type: m.sent_by === "user" ? "sent" : "received",
            text: m.message,
            messageId: m.sent_by === "user" ? undefined : Number(m.id),
          }));
        setMessages([...greeting, ...restored]);
      })
      .catch((err) => console.error("Sohbet yüklenemedi:", err));

    return () => {
      cancelled = true;
    };
  }, [bot, userId, urlConvId, greetingMessages]);

  useEffect(() => {
    if (!userId || !bot || canChat === false || promptSentRef.current) return;
    if (urlConvId > 0 || !urlPrompt.trim()) return;
    promptSentRef.current = true;
    router.replace(`/dashboard/chat/?botId=${bot.id}`);
    handleSendMessage({ text: urlPrompt });
  }, [bot, userId, canChat, urlConvId, urlPrompt]);

  useEffect(() => {
    if (messagesEndRef.current && stickToBottomRef.current) {
      messagesEndRef.current.scrollIntoView({ behavior: "smooth", block: "end" });
    }
  }, [messages]);

  // Alta ~120 px'ten yakınsa "takip et"; daha yukarıdaysa kullanıcı okuyor.
  const handleMessagesScroll = (e) => {
    const el = e.currentTarget;
    stickToBottomRef.current = el.scrollHeight - el.scrollTop - el.clientHeight < 120;
  };

  useEffect(() => {
    conversationIdRef.current = conversationId;
  }, [conversationId]);

  useEffect(() => {
    botIdRef.current = botId;
  }, [botId]);

  /*const handleResetChat = () => {
    setMessages([]);
    setTimeout(() => {
      if (messagesEndRef.current) {
        messagesEndRef.current.scrollIntoView({ behavior: "smooth" });
      }
    }, 50);
  };*/

  const handleResetChat = () => {
    chatSessionRef.current += 1;
    convPendingRef.current = null;
    conversationIdRef.current = 0;
    setConversationId(0);
    setMessages(greetingMessages(bot));
    if (urlConvId > 0 || urlPrompt) {
      router.replace(`/dashboard/chat/?botId=${botId}`);
    }
    setTimeout(() => {
      if (messagesEndRef.current) {
        messagesEndRef.current.scrollIntoView({ behavior: "smooth" });
      }
    }, 50);
  };

  const handleSendMessage = async (data) => {
  /*if (!hasSubscription) {
        alert("Bu chatbot ile konuşmak için aktif bir aboneliğiniz bulunmuyor.");
        return;
    }*/
  if (!data.text.trim() && !data.fileName && !data.audioUrl) return;

  // COIN-001 / AI-005: mesaj hakkı artık SUNUCUDA, generatereply.php içinde
  // tüketiliyor. Buradaki ayrı `consumemessage.php` çağrısı iki soruna yol
  // açıyordu:
  //   • Tek gerçek limit buydu — istemci bu isteği atlayınca sunucu tarafında
  //     hiçbir şey mesajı saymıyordu (etkin limit 10/gün yerine 28.800/gün).
  //   • Coin ÖNCE yakılıyor, Gemini SONRA çağrılıyordu; upstream hata verirse
  //     iade yoktu, kullanıcı cevap almadan hakkını kaybediyordu.
  // Sunucu tarafında tüketim + upstream hatasında iade var; 429 durumunu
  // generateReply() içindeki res.ok kontrolü ele alıyor.

  setMessages((prev) => [
    ...prev,
    {
      type: "sent",
      text: data.text || "",
      fileName: data.fileName || "",
      audioUrl: data.audioUrl || "",
    },
  ]);

  /* Dosya burada base64'e çevrilip `parts` dizisine konuyordu ve o dizi
     HİÇBİR YERDE OKUNMUYORDU: istek gövdesi yalnızca {chatbot_id, message}
     taşıyor, dosya çöpe gidiyordu. Kullanıcı mesaj balonunda dosya adını
     gördüğü için yüklenmiş sanıyor, bot ise dosyadan habersiz cevap
     veriyordu (AUDIT M-01). Dosya artık isteğe gerçekten biniyor.

     Okuma başarısız olursa mesajı dosyasız GÖNDERMİYORUZ: kullanıcı dosyayı
     bilerek ekledi, sessizce düşürmek onu yine yanıltırdı. */
  const pickedFiles = Array.isArray(data.files)
    ? data.files
    : data.file
      ? [data.file]
      : [];

  let filePayloads = [];
  if (pickedFiles.length > 0) {
    const tooBig = pickedFiles.find((f) => f.size > MAX_CHAT_FILE_BYTES);
    if (tooBig) {
      setMessages((prev) => [
        ...prev,
        {
          type: "received",
          text: `"${tooBig.name}" çok büyük (dosya başına maks. ${MAX_CHAT_FILE_BYTES / 1024 / 1024} MB).`,
        },
      ]);
      return;
    }

    /* Toplam sınır ayrıca kontrol ediliyor: tek tek sınırı geçen beş dosya
       birlikte istek gövdesini PHP'nin post_max_size'ının üstüne çıkarır ve
       gövde sessizce atılır (bkz. AUDIT L-01). */
    const totalBytes = pickedFiles.reduce((sum, f) => sum + f.size, 0);
    if (totalBytes > MAX_CHAT_TOTAL_FILE_BYTES) {
      setMessages((prev) => [
        ...prev,
        {
          type: "received",
          text: `Belgelerin toplam boyutu çok büyük (maks. ${MAX_CHAT_TOTAL_FILE_BYTES / 1024 / 1024} MB).`,
        },
      ]);
      return;
    }

    try {
      filePayloads = await Promise.all(
        pickedFiles.map(async (f) => ({
          mime_type: f.type,
          data: await fileToBase64(f),
        })),
      );
    } catch (err) {
      console.error("Dosya okunamadı:", err);
      setMessages((prev) => [
        ...prev,
        { type: "received", text: "Belgeler okunamadı, mesaj gönderilmedi." },
      ]);
      return;
    }
  }

  await generateReply(data.text, filePayloads);
};

// Bot cevabını üretip akışını ekrana basan kısım, orijinal kullanıcı
// mesajını tekrar göndermeden/mesaj hakkını tekrar tüketmeden "Tekrar
// Dene" ile yeniden çağrılabilsin diye handleSendMessage'dan ayrıldı.
// placeholderId/timeoutId artık try'ın DIŞINDA tanımlı — önceki halinde
// try içinde const olarak tanımlanıp catch bloğunda kullanılıyordu, bu da
// bir hata oluştuğunda (AbortError dahil) "placeholderId is not defined"
// ReferenceError'ı ile catch'in kendisinin patlamasına ve sahte
// "yazıyor..." animasyonunun sonsuza kadar ekranda kalmasına yol açıyordu.
// N-48 — sunucunun bu istek için kaydettiği cevabı ara: meta karesindeki
// after_id'den büyük kimlikli son bot mesajı. Bulunamazsa null.
const pollSavedReply = async (afterId, convId) => {
  if (!(convId > 0)) return null;
  for (let i = 0; i < SAVED_REPLY_POLLS; i++) {
    await new Promise((r) => setTimeout(r, SAVED_REPLY_POLL_MS));
    try {
      const res = await fetch(`/api/chat/getchat.php?chatbot_id=${botId}&conversation_id=${convId}`);
      const json = await res.json();
      const found = (Array.isArray(json?.messages) ? json.messages : [])
        .filter((m) => m.sent_by !== "user" && Number(m.id) > afterId)
        .pop();
      if (found) return found;
    } catch (e) {
      // geçici ağ hatası — bir sonraki denemede tekrar sor
    }
  }
  return null;
};

const generateReply = async (userText, filePayloads = []) => {
  const session = chatSessionRef.current;
  let convId = conversationIdRef.current;
  let resolveConv = null;
  if (!(convId > 0)) {
    if (convPendingRef.current) {
      convId = await convPendingRef.current;
      if (session !== chatSessionRef.current) return;
    } else {
      convPendingRef.current = new Promise((r) => {
        resolveConv = r;
      });
    }
  }
  const settleConv = (id) => {
    if (!resolveConv) return;
    resolveConv(id);
    resolveConv = null;
    if (session === chatSessionRef.current) convPendingRef.current = null;
  };
  const placeholderId = Date.now();
  let timeoutId;
  let afterId = null; // N-48: sunucunun ilk karesinden
  // Yeni cevap: kullanıcı yukarıdaysa bile kendi mesajını ve cevabı görsün.
  stickToBottomRef.current = true;
  // `streaming`: yazım sırasında imleç; akış nasıl biterse bitsin finally'de kapanır.
  setMessages((prev) => [...prev, { id: placeholderId, type: "received", text: "", streaming: true }]);

  // N-48 — istemci beklemekten vazgeçti ya da akış yarıda koptu; sunucu
  // (ignore_user_abort) cevabı kaydetmiş olabilir. Bulursa göster, yoksa
  // açıklayıcı hata + Tekrar Dene. Coin sayacı sunucudan yeniden okunur
  // (kayıt → düşmüş, iade → geri gelmiş).
  const recoverSavedReply = async (fallbackText) => {
    if (afterId === null) return false;
    setMessages((prev) => prev.map((msg) =>
      msg.id === placeholderId ? { ...msg, text: "Cevap bekleniyor…", error: false } : msg
    ));
    const saved = await pollSavedReply(afterId, conversationIdRef.current);
    checkMessageAllowance(userId, botId);
    setMessages((prev) => prev.map((msg) =>
      msg.id === placeholderId
        ? saved
          ? { ...msg, text: saved.message, messageId: Number(saved.id), error: false, retryText: undefined }
          : { ...msg, text: fallbackText, error: true, retryText: userText }
        : msg
    ));
    return true;
  };

  try {
    const controller = new AbortController();
    timeoutId = setTimeout(() => controller.abort(), CHAT_REPLY_TIMEOUT_MS);

    // SEC-015 / COIN-001 / PAY-002: sistem talimatı artık burada KURULMUYOR.
    // İstemci yalnızca hangi botla konuştuğunu ve ne dediğini söylüyor;
    // persona, eğitim metni, boyut sınırı ve mesaj hakkı tüketimi sunucuda.
    // Bu, istemcinin botun personasını değiştirmesini, ücretli içeriği
    // indirmesini ve mesaj limitini atlamasını aynı anda kapatıyor.
    const geminiRes = await fetch("/api/chat/generatereply.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      signal: controller.signal,
      body: new URLSearchParams({
        data: JSON.stringify({
          chatbot_id: botId,
          message: userText,
          // N-50: sunucu geçmişi bu sohbetin başından kurar (doğrular).
          ...(Number(convId) > 0 ? { conversation_id: Number(convId) } : {}),
          ...(filePayloads.length ? { files: filePayloads } : {}),
        }),
      }),
    });

    // Sunucu akışa başlamadan önce reddedebiliyor (403 yetki, 429 mesaj hakkı,
    // 500 yapılandırma) — o durumda gövde SSE değil JSON. res.ok kontrolü
    // olmadan bunlar sessizce boş bir cevaba dönüşüyordu.
    if (!geminiRes.ok) {
      clearTimeout(timeoutId);
      let payload = null;
      try {
        payload = await geminiRes.json();
      } catch (e) {}

      if (geminiRes.status === 429) {
        setLimitReached(true);
        if (typeof payload?.remaining === "number") setCoinsRemaining(payload.remaining);
        checkMessageAllowance(userId, botId);
        setMessages((prev) => prev.filter((m) => m.id !== placeholderId));
        return;
      }

      setMessages((prev) =>
        prev.map((msg) =>
          msg.id === placeholderId
            ? {
                ...msg,
                text:
                  payload?.message ||
                  "Yapay zeka servisine şu anda ulaşılamıyor. Sorun sürerse yöneticinize bildirin.",
                error: true,
                retryText: userText,
              }
            : msg,
        ),
      );
      return;
    }

    const reader = geminiRes.body.getReader();
    const decoder = new TextDecoder("utf-8");
    let fullText = "";
    let upstreamError = null;

    // AI-002: her chunk kendi başına `split("\n")` ile ayrıştırılıyordu.
    // Ağ paketleri SSE kare sınırlarına saygı duymaz — bir `data: {...}`
    // satırı iki okuma arasında bölünebilir. Bölündüğünde iki yarım parça da
    // geçersiz JSON olur, `catch {}` ikisini de sessizce yutar ve o metin
    // parçası cevaptan DÜŞER. Kullanıcı ortasından kelime eksik bir cevap
    // görür; hiçbir yerde iz kalmaz.
    //
    // Tampon: tamamlanmamış son satır bir sonraki okumaya devrediliyor.
    let buffer = "";

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;

      buffer += decoder.decode(value, { stream: true });

      // Son parça tamamlanmamış olabilir — onu tamponda bırak.
      const lines = buffer.split("\n");
      buffer = lines.pop() ?? "";

      for (const line of lines) {
        if (line.startsWith("data: ")) {
          try {
            const jsonStr = line.replace("data: ", "").trim();
            const gData = JSON.parse(jsonStr);
            // generatereply.php emits an `error` frame when the upstream call
            // fails; without this the stream just ended empty and the reason
            // was lost on both sides.
            if (gData.error) {
              upstreamError = gData.error;
              // N-33 — ilk `meta` karesi çipi tüketim SONRASI değere (ör. 9)
              // çekmişti; sunucu hatada hakkı iade ediyor. Tahmin etmek yerine
              // gerçek değeri sunucudan yeniden oku.
              if (gData.error.refunded) checkMessageAllowance(userId, botId);
              continue;
            }
            // N-30 — sunucu cevabı kendisi kaydedip kimliğini son karede
            // gönderiyor; "Diyalog Defterine Ekle" bu kimliği kullanır.
            // Bilgi bankasından yararlanıldıysa (event: sources) cevabın altında kaynak notu.
            if (Array.isArray(gData.sources)) {
              const sources = gData.sources.filter((s) => typeof s === "string" && s);
              setMessages((prev) =>
                prev.map((msg) => (msg.id === placeholderId ? { ...msg, sources } : msg))
              );
              continue;
            }
            if (gData.saved) {
              const savedId = Number(gData.saved.message_id);
              setMessages((prev) =>
                prev.map((msg) => (msg.id === placeholderId ? { ...msg, messageId: savedId } : msg))
              );
              continue;
            }
            // Sunucunun ilk SSE karesi (event: meta) kalan mesaj hakkını
            // taşıyor — coin sayacı için ayrı bir istek atmaya gerek yok.
            if (typeof gData.remaining === "number" || gData.source) {
              if (typeof gData.remaining === "number") setCoinsRemaining(gData.remaining);
              if (typeof gData.after_id === "number") afterId = gData.after_id;
              const newConvId = Number(gData.conversation_id);
              if (newConvId > 0 && session === chatSessionRef.current) {
                conversationIdRef.current = newConvId;
                setConversationId(newConvId);
              }
              settleConv(newConvId > 0 ? newConvId : 0);
              continue;
            }
            const textChunk = gData.candidates?.[0]?.content?.parts?.[0]?.text || "";
            if (textChunk) {
              fullText += textChunk;
              setMessages((prev) =>
                prev.map((msg) => (msg.id === placeholderId ? { ...msg, text: fullText } : msg))
              );
            }
          } catch (e) {}
        }
      }
    }

    clearTimeout(timeoutId);

    // N-48 — akış hata karesi olmadan ve metinsiz bittiyse bağlantı yolda
    // kopmuş olabilir; sunucunun kaydettiği cevabı ara.
    if (!fullText && !upstreamError &&
        (await recoverSavedReply("Cevap alınamadı. Lütfen tekrar deneyin."))) {
      return;
    }

    // Akış yarıda koptu: sunucu yarım cevabı kaydetmedi ve hakkı iade etti
    // (event: error). Yarım metin yerine açıklama + Tekrar Dene.
    if (fullText && upstreamError) {
      setMessages((prev) => prev.map((msg) =>
        msg.id === placeholderId
          ? { ...msg, text: chatFailureText(upstreamError), error: true, retryText: userText, sources: undefined }
          : msg
      ));
      return;
    }

    if (fullText) {
      // N-30 — bot cevabını artık SUNUCU kaydediyor (generateReply sonunda
      // `event: saved`). Buradaki addchat(sent_by:"bot") istemcinin yazdığı
      // metni bot cevabı gibi kaydediyordu; sunucu bunu artık reddediyor.
      return;
    }

    // Stream finished without throwing but produced no content. If the server
    // told us why (upstream 4xx/5xx), say so instead of the generic wording —
    // a suspended key and a transient outage need different user action.
    // N-59 — tür (yoğun / kota / zaman aşımı / …) → metin tek yerde.
    const failureText = chatFailureText(upstreamError);

    setMessages((prev) => prev.map((msg) =>
      msg.id === placeholderId
        ? { ...msg, text: failureText, error: true, retryText: userText }
        : msg
    ));
  } catch (err) {
    clearTimeout(timeoutId);
    console.error("Hata:", err);
    const errorText = err.name === "AbortError"
      ? "Cevap çok gecikti. Lütfen tekrar deneyin."
      : "Bir hata oluştu. Lütfen tekrar deneyin.";
    // N-48 — zaman aşımı/kopma: sunucu cevabı yine de kaydetmiş olabilir.
    if (await recoverSavedReply(errorText)) return;
    setMessages((prev) => prev.map((msg) =>
      msg.id === placeholderId ? { ...msg, text: errorText, error: true, retryText: userText } : msg
    ));
  } finally {
    settleConv(conversationIdRef.current);
    setMessages((prev) => prev.map((msg) => (msg.id === placeholderId ? { ...msg, streaming: false } : msg)));
  }
};

const handleRetryReply = (retryText) => {
  generateReply(retryText);
};

  // API-001: yükleme başarısızsa boş bir sohbet ekranı yerine nedenini göster.
  if (botLoadError) {
    return (
      <div className="flex h-[calc(100vh-84.5px)] w-full flex-col items-center justify-center gap-4 px-6 text-center text-white">
        <div className="rounded-2xl border border-red-400/25 bg-red-500/10 px-6 py-5">
          <h2 className="font-display text-xl font-semibold">Chatbot açılamadı</h2>
          <p className="mt-2 max-w-md text-sm leading-relaxed text-white/60">
            {botLoadError}
          </p>
        </div>
        <button
          type="button"
          onClick={() => window.location.reload()}
          className="rounded-full border border-white/15 px-5 py-2 text-sm text-white/80 transition hover:bg-white/5"
        >
          Tekrar dene
        </button>
      </div>
    );
  }

  return (
    <div className="relative flex h-[calc(100vh-84.5px)] w-full flex-col text-white">
      {bot && <ProfileCard bot={bot} comments={comments} />}

      {/* Sohbetin kendisi artık ekranın tamamını dolduruyor — üstteki
          başlık ile ilk mesaj arasında eskiden `justify-between`in
          zorladığı boş alan kalmıyor. */}
      <div onScroll={handleMessagesScroll} className="flex flex-1 flex-col overflow-y-auto px-4 pb-[150px] pt-5 md:pl-16 md:pr-10">
        {messages.length === 0 ? (
          <div className="flex flex-1 flex-col items-center justify-center gap-3 text-center">
            <EntityAvatar
              src={bot?.profil_fotografi}
              name={bot?.isim}
              className="h-16 w-16 rounded-full text-xl ring-1 ring-fuchsia-400/20"
            />
            <h2 className="font-display text-2xl font-bold text-white md:text-3xl">
              {bot?.isim ? `${bot.isim} ile sohbete başla` : "Sohbete başla"}
            </h2>
            <p className="max-w-sm text-sm leading-relaxed text-white/45">
              Aşağıya bir mesaj yazarak sohbete başlayabilirsin.
            </p>
          </div>
        ) : (
          <div className="flex flex-col gap-3">
            <div className="mx-auto mb-3 w-fit rounded-full border border-white/[0.06] bg-white/[0.03] px-3.5 py-1 text-center text-[11px] font-medium tracking-wide text-white/45">
              Bugün
            </div>
            {messages.map((msg, index) => (
              <React.Fragment key={index}>
                <div className={cn(
                  "max-w-[85%] break-words text-sm leading-relaxed sm:max-w-[75%] lg:max-w-[65%]",
                  msg.type === "received"
                    ? "flex animate-[fadeInUp_0.4s_ease-out] items-start gap-3 py-3"
                    : "flex flex-col items-end self-end",
                )}>
                  {msg.type === "received" ? (
                    <>
                      <div className="shrink-0">
                        <EntityAvatar
                          src={bot?.profil_fotografi}
                          name={bot?.isim}
                          className="h-[38px] w-[38px] rounded-full text-sm"
                        />
                      </div>
                      <div className="flex flex-col">
                        <p className="font-display text-body-sm font-semibold capitalize text-white/55">{bot?.isim}</p>
                        <div className={cn("font-sans text-[15px] font-normal leading-relaxed", msg.error ? "text-rose-300" : "text-white/90")}>
                          {msg.text ? (
                            <ChatMarkdown streaming={Boolean(msg.streaming) && !msg.error}>{msg.text}</ChatMarkdown>
                          ) : (
                            <div className="flex gap-1 py-2">
                              <span className="h-2 w-2 animate-bounce rounded-full bg-white/40 [animation-delay:-0.32s]" />
                              <span className="h-2 w-2 animate-bounce rounded-full bg-white/40 [animation-delay:-0.16s]" />
                              <span className="h-2 w-2 animate-bounce rounded-full bg-white/40" />
                            </div>
                          )}
                        </div>
                        {!msg.error && msg.sources?.length > 0 && (
                          <p className="mt-1 text-caption text-white/40">
                            Kaynak: {msg.sources.join(", ")}
                          </p>
                        )}
                        {msg.error ? (
                          <button
                            className="mt-1.5 flex w-max items-center justify-center gap-1.5 rounded-md bg-rose-500/10 px-2.5 py-1 text-rose-300 transition-all duration-200 hover:-translate-y-px hover:bg-rose-500/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            onClick={() => handleRetryReply(msg.retryText)}
                          >
                            <RotateCcw className="h-3 w-3" strokeWidth={2} />
                            <span className="text-caption font-medium">Tekrar Dene</span>
                          </button>
                        ) : msg.messageId ? (
                          /* N-30: yalnızca sunucunun kaydettiği cevapta (kimliği
                             olan); karşılama mesajı ve kaydedilmemiş cevap deftere
                             eklenemez. */
                          <button
                            className="mt-1.5 flex w-max items-center justify-center gap-1.5 rounded-md bg-luma-input px-2.5 py-1 text-white/55 transition-all duration-200 hover:-translate-y-px hover:bg-white/10 hover:text-white/85 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            onClick={() => openDialogModal(index)}
                          >
                            <NotebookPen className="h-3 w-3" strokeWidth={2} />
                            <span className="text-caption font-medium">Diyalog Defterine Ekle</span>
                          </button>
                        ) : null}
                      </div>
                    </>
                  ) : (
                    <>
                      {msg.fileName && (
                        <div className="mb-1.5 inline-block w-max rounded-xl bg-luma-card px-5 py-2.5 text-xs font-medium text-white/70">
                          {msg.fileName}
                        </div>
                      )}
                      {msg.text && (
                        <div className="rounded-2xl rounded-tr-md bg-gradient-to-br from-fuchsia-600 to-violet-600 px-4 py-2.5 font-sans text-[15px] font-normal leading-relaxed text-white shadow-lg shadow-fuchsia-950/30">
                          <ReactMarkdown>{msg.text}</ReactMarkdown>
                        </div>
                      )}
                    </>
                  )}
                </div>

                {/* Reklam ekleme */}
                {(index + 1) % chatAdFrequency === 0 && (
                  <div className="flex justify-center py-2">
                    <div className="flex h-[60px] w-full max-w-[468px] items-center justify-center rounded-lg border border-white/[0.06] bg-luma-card text-caption text-white/25">
                      Reklam Alanı
                    </div>
                  </div>
                )}
              </React.Fragment>
            ))}

            {/* Scroll target */}
            <div ref={messagesEndRef} />
          </div>
        )}
      </div>

      {/* Fixed input at bottom — günlük mesaj hakkı tükendiğinde, mesaj
          kutusunun yerini "Sınır Aşıldı" bandı alır (artık ayrı bir modal
          değil, sayfa akışının içinde kalıcı bir uyarı). */}
      <div
        className={cn(
          "fixed inset-x-0 bottom-0 z-[8] px-4 py-4 transition-all duration-300 md:px-16",
          logoClicked ? "md:ml-[90px]" : "md:ml-[280px]",
        )}
      >
        {limitReached ? (
          <div className="flex items-center justify-between gap-4 rounded-xl border border-fuchsia-400/15 bg-luma-elevated px-5 py-4">
            <div className="flex items-center gap-3">
              <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-fuchsia-500/25 to-violet-500/15">
                <Lock className="h-4 w-4 text-fuchsia-300" />
              </div>
              <p className="text-body-sm leading-snug text-white/80">
                Günlük mesajlaşma limitine ulaştınız.
                <br className="hidden sm:block" />
                Paketinizi yükselterek daha fazla günlük mesaj hakkı alabilirsiniz.
                {retryCountdownLabel && (
                  <>
                    <br className="hidden sm:block" />
                    <span className="inline-flex items-center gap-1.5 text-fuchsia-300/80">
                      <Lumacoin size={13} />
                      Lumacoin&apos;leriniz {retryCountdownLabel} sonra yenilenecek.
                    </span>
                  </>
                )}
              </p>
            </div>
            {/* GK-14 — sohbet sayfasındaki dört "Satın Al" kaldırıldı;
                limit bandındaki eylem artık paket yükseltme. */}
            <Button
              onClick={() => router.push("/dashboard/upgrade")}
              className="h-auto shrink-0 px-5 py-2.5 text-body-sm"
            >
              Paketini yükselt
            </Button>
          </div>
        ) : canChat === false ? (
          /* N-52 — erişimi olmayan ücretli bot: kullanıcı mesaj yazmadan önce
             durumu görür (eskiden ancak gönderince 403 ile anlaşılıyordu). */
          <div className="flex items-center justify-between gap-4 rounded-xl border border-amber-400/20 bg-luma-elevated px-5 py-4">
            <div className="flex items-center gap-3">
              <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-amber-500/15">
                <Lock className="h-4 w-4 text-amber-300" />
              </div>
              <p className="text-body-sm leading-snug text-white/80">
                Bu bot ücretli. Sohbet edebilmek için bu bota erişim satın almanız gerekiyor.
                <br className="hidden sm:block" />
                <span className="text-white/55">Ücretsiz herkese açık botları Keşfet&apos;te bulabilirsiniz.</span>
              </p>
            </div>
            <Button
              onClick={() => router.push("/dashboard/explore")}
              className="h-auto shrink-0 px-5 py-2.5 text-body-sm"
            >
              Keşfet
            </Button>
          </div>
        ) : (
          <div className="space-y-2">
            {typeof sharedCoins === "number" && (
              <div className="flex justify-center">
                <span className="inline-flex items-center gap-1.5 rounded-full border border-white/[0.06] bg-white/[0.03] px-3 py-1 text-[11px] text-white/40">
                  <Lumacoin size={13} />
                  Bugün kalan Lumacoin: <span className="font-semibold tabular-nums text-fuchsia-300/90">{sharedCoins}</span>
                </span>
              </div>
            )}
            <MessageInput
              onSend={handleSendMessage}
              onResetChat={handleResetChat}
            />
          </div>
        )}
      </div>

      <DialogNotebookModal
        userId={userId}
        // N-06 (M0-4) — burada `conversationId` geçiliyordu; modal onu
        // `user_dialog_books.chatbot_id` olarak yazıyor, sunucu da
        // (NoteController::getDialogues) o sütunu gerçek bot id'si sayıyor.
        botId={botId}
        inputMessage={activeDialog.input}
        outputMessage={activeDialog.output}
        messageId={activeDialog.messageId}
        isOpen={isDialogModalOpen}
        onClose={() => setIsDialogModalOpen(false)}
        onPublish={(title) => {
        }}
      />

    </div>
  );
}
