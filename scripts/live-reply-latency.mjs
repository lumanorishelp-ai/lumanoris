// N-60 — canlı sohbet yanıt süresi: node scripts/live-reply-latency.mjs .env.e2e-prod
// Yalnızca test hesabı A; 2 Gemini mesajı (prompt 14: ≤5); ölçüm sonunda sohbet silinir.
import fs from "node:fs";
const env = Object.fromEntries(fs.readFileSync(process.argv[2], "utf8").split(/\r?\n/).map((l) => l.trim()).filter(Boolean).map((l) => [l.slice(0, l.indexOf("=")), l.slice(l.indexOf("=") + 1)]));
const BASE = "https://www.lumanoris.net";
let cookie = "";
const keep = (res) => { const sc = res.headers.getSetCookie?.() ?? []; for (const c of sc) { const kv = c.split(";")[0]; if (kv.startsWith("PHPSESSID=")) cookie = kv; } };
const form = (obj) => { const f = new FormData(); f.append("data", JSON.stringify(obj)); return f; };
const call = async (path, opt = {}) => { const r = await fetch(BASE + path, { ...opt, headers: { ...(opt.headers || {}), cookie }, redirect: "manual" }); keep(r); return r; };

const login = await call("/api/auth/login.php", { method: "POST", body: form({ eposta: env.PROD_E2E_A_EMAIL, sifre: env.PROD_E2E_A_PASSWORD }) });
const lj = await login.json(); if (!lj.success) { console.log("giriş başarısız:", lj.message); process.exit(1); }
const def = await (await call("/api/chatbot/getdefaultbot.php")).json();
const botId = def.id; console.log("bot:", botId);
const conv = await (await call("/api/chat/addconversation.php", { method: "POST", body: form({ chatbot_id: botId, conversation_name: "hiz-olcumu-N60" }) })).json();
const convId = conv.id; console.log("sohbet:", convId);

const questions = [
  ["kısa", "İki artı iki kaç eder? Yalnızca sayıyla cevap ver."],
  ["uzun", "Aristoteles'in mantık anlayışını 6 maddede, her maddeyi 2-3 cümleyle açıkla."],
];
for (const [label, message] of questions) {
  const t0 = performance.now();
  const res = await call("/api/chat/generatereply.php", { method: "POST", body: form({ message, chatbot_id: botId, conversation_id: convId }) });
  const tHead = performance.now() - t0;
  const reader = res.body.getReader(); const dec = new TextDecoder();
  let buf = "", tMeta = null, tFirst = null, chunks = 0, text = "", err = null;
  for (;;) {
    const { done, value } = await reader.read(); if (done) break;
    buf += dec.decode(value, { stream: true });
    const lines = buf.split("\n"); buf = lines.pop();
    for (const l of lines) {
      if (!l.startsWith("data: ")) continue;
      let j; try { j = JSON.parse(l.slice(6)); } catch { continue; }
      if (j.error) err = j.error;
      else if ("remaining" in j && tMeta === null) tMeta = performance.now() - t0;
      const t = j.candidates?.[0]?.content?.parts?.[0]?.text;
      if (t) { chunks++; text += t; if (tFirst === null) tFirst = performance.now() - t0; }
    }
  }
  const tEnd = performance.now() - t0;
  const s = (x) => (x === null ? "—" : (x / 1000).toFixed(2) + " sn");
  console.log(`[${label}] HTTP ${res.status} · başlık ${s(tHead)} · meta ${s(tMeta)} · ilk kelime ${s(tFirst)} · toplam ${s(tEnd)} · ${chunks} parça · ${text.length} karakter${err ? " · HATA " + JSON.stringify(err) : ""}`);
}
const del = await (await call("/api/chat/deleteconversation.php", { method: "POST", body: (() => { const f = new FormData(); f.append("id", String(convId)); return f; })() })).json();
console.log("temizlik:", del.success ? "sohbet silindi" : "SİLİNEMEDİ " + del.message);
