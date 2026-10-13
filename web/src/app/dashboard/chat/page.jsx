"use client";
import dynamic from "next/dynamic";
import MessageInput from "@/features/chat/MessageInput";
import ProfileCard from "@/entities/user/ui/ProfileCard";
import React, { useState, useRef, useEffect, useCallback, useContext } from "react";
import { UserContext } from "@/shared/contexts/UserContext";
import { resolveAvatarSrc } from "@/shared/lib/image";
import { useSearchParams } from "next/navigation";

// Only loaded when the user actually opens one of these modals, instead of
// shipping their code with every chat page load.
const DialogNotebookModal = dynamic(() => import("@/features/notes/DialogNotebookModal"), { ssr: false });
import ReactMarkdown from "react-markdown";
import { useRouter } from "next/navigation";
import { cn } from "@/lib/utils";
import { Button } from "@/shared/ui/button";
import { Lumacoin } from "@/shared/ui/lumacoin";
import { Lock, NotebookPen, RotateCcw } from "lucide-react";

export default function Chat() {
  const { userId } = useContext(UserContext);
  const [bot, setBot] = useState(null);
  const [botId, setBotId] = useState(0);
  const [comments, setComments] = useState(null);
  const [prompt, setPrompt] = useState("");
  const [conversation, setConversation] = useState();
  const [conversationId, setConversationId] = useState(-1);
  const [chatAdFrequency, setChatAdFrequency] = useState(10);
  const messagesEndRef = useRef(null);
  const [messages, setMessages] = useState([]);
  const [logoClicked, setLogoClicked] = useState(false);
  const [isDialogModalOpen, setIsDialogModalOpen] = useState(false);
  const [activeDialog, setActiveDialog] = useState({ input: "", output: "" });
  const [botLoadError, setBotLoadError] = useState(null);
  const [hasSubscription, setHasSubscription] = useState(false);
  const [checkingSub, setCheckingSub] = useState(true);
  // Sohbet Luma Coini: mesaj hakkı tükendiğinde mesaj kutusunun yerini alan
  // "Sınır Aşıldı" bandı.
  const [limitReached, setLimitReached] = useState(false);
  const [coinsRemaining, setCoinsRemaining] = useState(null);
  const [retryAt, setRetryAt] = useState(null);

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
  }, []);

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

  const formatImage = (img) => {
    if (!img) return resolveAvatarSrc(null);
    return img.startsWith("data:") ? img : `data:image/jpeg;base64,${img}`;
  };

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

    let botIdd = 0;
    const params = new URLSearchParams(window.location.search);
    botIdd = params.get("botId") || 0;
    setBotId(botIdd);
    const initialPrompt = params.get("prompt") || "";
    const conversationIdd = params.get("convId") || 0;
    setConversationId(conversationIdd);
    setPrompt(initialPrompt);

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
        if (botData) {
          setBot(botData);
        }
      })
      .catch(() => {
        setBotLoadError("Sunucuya ulaşılamadı. Bağlantınızı kontrol edin.");
      });
  }, [userId]);

  useEffect(() => {
    if (!userId) return;
    if (!bot) return;

    const handleConversationData = async (data) => {
        setConversation(data);

        if (data?.id == 0 && prompt.trim() !== "") {
          const words = prompt.trim().split(/\s+/);
          let convName = words.slice(0, 5).join(" ");
          if (words.length > 5) {
            convName += " ...";
          }
          const payload = {
            chatbot_id: botId,
            user_id: userId,
            conversation_name: convName,
          };
          try {
            const res = await fetch("/api/chat/addconversation.php", {
              method: "POST",
              headers: {
                "Content-Type": "application/x-www-form-urlencoded",
              },
              body: new URLSearchParams({
                data: JSON.stringify(payload),
              }),
            });
            const result = await res.json();
            const newConversation = {
              id: Number(result.id), // backend’den dönen id
              conversation_name: convName, // frontend’de oluşturduğun isim
            };

            setConversation(newConversation);
            setConversationId(result.id);
            const firstPrompt = {
              text: prompt,
              convId: newConversation.id,
            };
            handleSendMessage(firstPrompt);
          } catch (err) {
            console.error("Yeni sohbet eklenirken hata:", err);
          }
        }
    };

    // URL'de convId yoksa (kullanıcı geçmişten belirli bir sohbete
    // girmediyse), sayfa her açıldığında/yenilendiğinde son sohbeti
    // otomatik devam ettirmek yerine her zaman yeni ve temiz bir sohbet
    // başlatılır.
    if (conversationId < 1) {
      handleConversationData({ id: 0, conversation_name: "Yeni Sohbet" });
      return;
    }

    const convFetchUrl = `/api/chat/getconversation.php?chatbot_id=${botId}&user_id=${userId}&convId=${conversationId}`;

    fetch(convFetchUrl)
      .then((response) => {
        if (!response.ok) {
          throw new Error("HTTP error " + response.status);
        }
        return response.json();
      })
      .then(handleConversationData)
      .catch((error) => console.error("Hata:", error));
  }, [bot]);

  useEffect(() => {
    // bot bilgisinin de geldiğinden emin oluyoruz (başlangıç mesajı için)
    if (!conversation || !userId || !bot) return;
    

    // Eğer zaten mesaj varsa ve bu var olan bir sohbetse tekrar yükleme.
    //
    // N-23 — bu kontrol `conversationIdRef.current` ile yapılıyordu. Ref, AŞAĞIDAKİ
    // bir effect'te güncellendiği için yeni sohbet oluşturan ilk mesajdan sonra
    // (handleSendMessage sonunda setConversationId + setConversation) bu effect
    // çalışırken ref hâlâ -1'di: kontrol kaçıyor, geçmiş DB'den yeniden
    // yükleniyor ve DB'ye yazılmayan hata balonu ("…ulaşılamıyor", "Tekrar Dene")
    // siliniyordu. Aynı render'ın `conversationId` state'i zaten güncel.
    if (messages.length > 0 && conversationId > 0) {
        return;
    }

    // N-32 — bu yükleme bitmeden sohbet değişirse (ana sayfadan ?prompt= ile
    // gelindiğinde: önce id=0 sohbeti, hemen ardından yeni sohbet + ilk mesaj)
    // geç dönen eski sonuç, o arada eklenen mesajı ve cevabı ekrandan
    // siliyordu — kullanıcının bu botla geçmişi varsa yalnızca karşılama
    // mesajı kalıyordu. Effect yeniden çalışınca eski yükleme yok sayılır.
    let cancelled = false;

    const loadHistory = async () => {
      try {
        // Gerçek geçmişi artık HER ZAMAN çekiyoruz — sadece "kullanıcı
        // belirli bir sohbeti mi açtı" değil, "botun karşılama mesajı bu
        // kullanıcı için daha önce hiç kaydedilmiş mi" sorusuna da cevap
        // vermemiz gerekiyor. Eskiden convId yoksa bu kontrol hiç
        // yapılmıyordu, bu yüzden convId'siz her sayfa yüklemesinde botun
        // karşılama mesajı DB'ye yeni, tekrar eden bir satır olarak
        // ekleniyordu.
        const historyResult = await (
          await fetch(`/api/chat/getchat.php?chatbot_id=${bot.id}&user_id=${userId}`)
        ).json();
        if (cancelled) return; // N-32: sohbet bu arada değişti
        const historyData = Array.isArray(historyResult?.messages) ? historyResult.messages : [];
        const hasHistory = historyData.length > 0;
        const shouldRestoreHistory = conversationId >= 1;

        if (hasHistory && shouldRestoreHistory) {
          // Kullanıcı Geçmişim üzerinden belirli bir sohbeti açtı — tüm
          // geçmişi göster.
          setMessages(
            historyData.map((m) => ({
              type: m.sent_by === "user" ? "sent" : "received",
              text: m.message,
              // N-30: defter kaydı bot mesajının kimliğiyle yapılıyor.
              messageId: m.sent_by === "user" ? undefined : Number(m.id),
            })),
          );
        } else if (hasHistory) {
          // convId yok (bot'a yeni tıklandı) ama bu bot+kullanıcı için
          // geçmiş zaten var — "temiz" görünüm için yalnızca botun daha
          // önce kaydedilmiş karşılama mesajını ekranda göster, DB'ye
          // tekrar YAZMA.
          const firstBotMsg = historyData.find((m) => m.sent_by !== "user");
          setMessages(firstBotMsg ? [{ type: "received", text: firstBotMsg.message }] : []);
        } else if (bot.sohbet_basi_mesaj && bot.sohbet_basi_mesaj.trim() !== "") {
          // Bu bot+kullanıcı için hiç geçmiş yok — karşılama mesajını ilk
          // ve tek kez ekle.
          const initialMsg = {
            type: "received",
            text: bot.sohbet_basi_mesaj,
          };
          setMessages([initialMsg]);

          try {
            const botPayload = {
              chatbot_id: bot.id,
              user_id: userId,
              sent_by: "bot",
              message: bot.sohbet_basi_mesaj,
            };

            fetch("/api/chat/addchat.php", {
              method: "POST",
              headers: { "Content-Type": "application/x-www-form-urlencoded" },
              body: new URLSearchParams({ data: JSON.stringify(botPayload) }),
            });
          } catch (dbErr) {
            console.error("Bot başlangıç mesajı kaydedilemedi:", dbErr);
          }
        }
      } catch (err) {
        console.error("Geçmiş yüklenirken hata:", err);
      }
    };

    loadHistory();
    return () => {
      cancelled = true;
    };
  }, [conversation, bot]); // bot'u da bağımlılığa ekledik

  useEffect(() => {
    if (messagesEndRef.current) {
      messagesEndRef.current.scrollIntoView({ behavior: "smooth" });
    }
  }, [messages]);

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

  const handleResetChat = async () => {
    setMessages([]);
    const newConvId = conversation.id + 1;
    const bot_id = conversation.chatbot_id;
    const payload = {
      chatbot_id: bot_id,
      user_id: userId,
      conversation_name: "Yeni Sohbet",
    };
    try {
      const res = await fetch("/api/chat/addconversation.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: new URLSearchParams({
          data: JSON.stringify(payload),
        }),
      });
      const result = await res.json();
      const newConversation = {
        id: Number(result.id), // backend’den dönen id
        conversation_name: "Yeni Sohbet", // frontend’de oluşturduğun isim
      };

      setConversation(newConversation);
      setConversationId(result.id);
    } catch (err) {
      console.error("Yeni sohbet eklenirken hata:", err);
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

  // Fonksiyon içinde kullanacağımız yerel değişkenler
  let currentConvId = (data.convId && data.convId > 0) ? data.convId : conversationId;
  let isNewConversation = false;
  let newConvData = null;

  // 1. EĞER YENİ SOHBETSE ÖNCE LOCAL OLARAK OLUŞTUR
  if (currentConvId <= 0) {
    const words = data.text.trim().split(/\s+/);
    let convName = words.slice(0, 5).join(" ");
    if (words.length > 5) convName += " ...";
    if (!convName) convName = "Yeni Sohbet";

    const payload = {
      chatbot_id: botId,
      user_id: userId,
      conversation_name: convName,
    };

    try {
      const res = await fetch("/api/chat/addconversation.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({ data: JSON.stringify(payload) }),
      });
      const result = await res.json();

      if (result.id) {
        currentConvId = Number(result.id);
        isNewConversation = true; // Yeni olduğunu işaretle
        newConvData = { id: currentConvId, conversation_name: convName };
        // DİKKAT: setConversation'ı burada çağırmıyoruz, sona saklıyoruz!
      } else {
        throw new Error("Conversation oluşturulamadı.");
      }
    } catch (err) {
      console.error("Conversation oluşturma hatası:", err);
      return;
    }
  }

  // 2. Kullanıcı mesajını arayüze ekle
  setMessages((prev) => [
    ...prev,
    {
      type: "sent",
      text: data.text || "",
      fileName: data.fileName || "",
      audioUrl: data.audioUrl || "",
    },
  ]);

  // 3. Kullanıcı mesajını DB'ye kaydet
  const userPayload = {
    chatbot_id: botId,
    user_id: userId,
    sent_by: "user",
    message: data.text,
  };

  try {
    fetch("/api/chat/addchat.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({ data: JSON.stringify(userPayload) }),
    });
  } catch (error) {
    console.error("Kullanıcı mesajı DB hatası:", error);
  }

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

  if (isNewConversation) {
    setConversationId(currentConvId);
    setConversation(newConvData);
  }
};

// Bot cevabını üretip akışını ekrana basan kısım, orijinal kullanıcı
// mesajını tekrar göndermeden/mesaj hakkını tekrar tüketmeden "Tekrar
// Dene" ile yeniden çağrılabilsin diye handleSendMessage'dan ayrıldı.
// placeholderId/timeoutId artık try'ın DIŞINDA tanımlı — önceki halinde
// try içinde const olarak tanımlanıp catch bloğunda kullanılıyordu, bu da
// bir hata oluştuğunda (AbortError dahil) "placeholderId is not defined"
// ReferenceError'ı ile catch'in kendisinin patlamasına ve sahte
// "yazıyor..." animasyonunun sonsuza kadar ekranda kalmasına yol açıyordu.
const generateReply = async (userText, filePayloads = []) => {
  const placeholderId = Date.now();
  let timeoutId;
  setMessages((prev) => [...prev, { id: placeholderId, type: "received", text: "" }]);

  try {
    const controller = new AbortController();
    // AI-004: sunucu tarafındaki cURL zaman aşımıyla aynı (20 sn). Eskiden
    // istemci 15 sn'de kesiyor, sunucu 30 sn beklemeye devam ediyordu:
    // kullanıcı hata görüyor, upstream isteği (ve faturası) sürüyordu.
    timeoutId = setTimeout(() => controller.abort(), 20000);

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

    if (fullText) {
      // N-30 — bot cevabını artık SUNUCU kaydediyor (generateReply sonunda
      // `event: saved`). Buradaki addchat(sent_by:"bot") istemcinin yazdığı
      // metni bot cevabı gibi kaydediyordu; sunucu bunu artık reddediyor.
      return;
    }

    // Stream finished without throwing but produced no content. If the server
    // told us why (upstream 4xx/5xx), say so instead of the generic wording —
    // a suspended key and a transient outage need different user action.
    const failureText = upstreamError
      ? (upstreamError.code === 429
          ? "Yapay zeka servisi şu anda yoğun. Lütfen biraz sonra tekrar deneyin."
          : "Yapay zeka servisine şu anda ulaşılamıyor. Sorun sürerse yöneticinize bildirin.")
      : "Şu anda cevap veremiyorum.";

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
    setMessages((prev) => prev.map((msg) =>
      msg.id === placeholderId ? { ...msg, text: errorText, error: true, retryText: userText } : msg
    ));
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
      <div className="flex flex-1 flex-col overflow-y-auto px-4 pb-[150px] pt-5 md:pl-16 md:pr-10">
        {messages.length === 0 ? (
          <div className="flex flex-1 flex-col items-center justify-center gap-3 text-center">
            <img
              src={formatImage(bot?.profil_fotografi)}
              alt=""
              className="h-16 w-16 rounded-full object-cover ring-1 ring-fuchsia-400/20"
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
                        <img
                          src={formatImage(bot?.profil_fotografi)}
                          alt="avatar"
                          className="h-[38px] w-[38px] rounded-full object-cover"
                        />
                      </div>
                      <div className="flex flex-col">
                        <p className="font-display text-body-sm font-semibold capitalize text-white/55">{bot?.isim}</p>
                        <div className={cn("font-sans text-[15px] font-normal leading-relaxed", msg.error ? "text-rose-300" : "text-white/90")}>
                          {msg.text ? (
                            <ReactMarkdown>{msg.text}</ReactMarkdown>
                          ) : (
                            <div className="flex gap-1 py-2">
                              <span className="h-2 w-2 animate-bounce rounded-full bg-white/40 [animation-delay:-0.32s]" />
                              <span className="h-2 w-2 animate-bounce rounded-full bg-white/40 [animation-delay:-0.16s]" />
                              <span className="h-2 w-2 animate-bounce rounded-full bg-white/40" />
                            </div>
                          )}
                        </div>
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
        ) : (
          <div className="space-y-2">
            {typeof coinsRemaining === "number" && (
              <div className="flex justify-center">
                <span className="inline-flex items-center gap-1.5 rounded-full border border-white/[0.06] bg-white/[0.03] px-3 py-1 text-[11px] text-white/40">
                  <Lumacoin size={13} />
                  Bugün kalan Lumacoin: <span className="font-semibold tabular-nums text-fuchsia-300/90">{coinsRemaining}</span>
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
