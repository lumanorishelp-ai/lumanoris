// N-59 — node --test web/src/features/chat/reply-errors.test.mjs
import { test } from "node:test";
import assert from "node:assert/strict";
import { chatFailureText } from "./reply-errors.mjs";

test("yoğunluk (503 / 429 dakikalık) 'ulaşılamıyor' DEĞİL, 'yoğun' der", () => {
  assert.match(chatFailureText({ kind: "busy", code: 503 }), /yoğun/);
  assert.doesNotMatch(chatFailureText({ kind: "busy", code: 503 }), /ulaşılamıyor|yöneticinize/);
});

test("eski sunucu (kind yok): 503 ve 429 → yoğun, diğerleri → ulaşılamıyor", () => {
  assert.match(chatFailureText({ code: 503 }), /yoğun/);
  assert.match(chatFailureText({ code: 429 }), /yoğun/);
  assert.match(chatFailureText({ code: 502 }), /ulaşılamıyor/);
});

test("her tür için ayrı metin", () => {
  assert.match(chatFailureText({ kind: "quota" }), /kullanım sınırına/);
  assert.match(chatFailureText({ kind: "timeout" }), /zamanında gelmedi/);
  assert.match(chatFailureText({ kind: "config" }), /yöneticinize/);
  assert.match(chatFailureText({ kind: "empty" }), /farklı biçimde/);
});

test("iade edildiyse kullanıcıya söylenir", () => {
  assert.match(chatFailureText({ kind: "busy", refunded: true }), /LumaCoin düşülmedi/);
  assert.doesNotMatch(chatFailureText({ kind: "busy", refunded: false }), /LumaCoin/);
});

test("bilinmeyen kind → koda göre", () => {
  assert.match(chatFailureText({ kind: "<script>", code: 503 }), /yoğun/);
  assert.equal(chatFailureText(null), "Şu anda cevap veremiyorum.");
});
