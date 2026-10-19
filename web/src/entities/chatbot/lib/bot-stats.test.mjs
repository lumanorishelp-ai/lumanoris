// N-66 — node --test web/src/entities/chatbot/lib/bot-stats.test.mjs
import { test } from "node:test";
import assert from "node:assert/strict";
import { statsFromDetail, priceLabel } from "./bot-stats.mjs";

test("sayılar getchatbot.php (getDetail) alanlarından — sohbet sayfasıyla aynı kaynak", () => {
  assert.deepEqual(statsFromDetail({ toplam_chats: "8", likes: "1", follows: "3" }), { dialogues: 8, likes: 1, followers: 3 });
});

test("eksik/bozuk alan 0, null detay null", () => {
  assert.deepEqual(statsFromDetail({}), { dialogues: 0, likes: 0, followers: 0 });
  assert.equal(statsFromDetail(null), null);
});

test("fiyat kartla aynı biçimde: ücretsiz ya da ₺X /hafta", () => {
  assert.equal(priceLabel(0), "Ücretsiz");
  assert.equal(priceLabel(null), "Ücretsiz");
  assert.equal(priceLabel(150), "₺150 /hafta");
  assert.equal(priceLabel("99.5"), "₺99,5 /hafta");
});
