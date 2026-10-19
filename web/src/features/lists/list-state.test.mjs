// N-65 — node --test web/src/features/lists/list-state.test.mjs
import { test } from "node:test";
import assert from "node:assert/strict";
import { withoutBot, LISTS_CHANGED_EVENT } from "./list-state.mjs";

const list = {
  id: 7,
  title: "Felsefe",
  summary: "3 Bot İçeriyor",
  dialog: "40 Diyalog",
  bots: [
    { id: 1, isim: "Aristoteles", toplam_chats: 28 },
    { id: 2, isim: "Platon", toplam_chats: 10 },
    { id: 3, isim: "Sokrates", toplam_chats: 2 },
  ],
};

test("yalnızca o bot listeden çıkar, diğerleri ve liste kalır", () => {
  const r = withoutBot(list, 1);
  assert.deepEqual(r.bots.map((b) => b.id), [2, 3]);
  assert.equal(r.id, 7);
  assert.equal(r.title, "Felsefe");
});

test("sayaçlar yeniden hesaplanır", () => {
  const r = withoutBot(list, 1);
  assert.equal(r.summary, "2 Bot İçeriyor");
  assert.equal(r.dialog, "12 Diyalog");
});

test("özgün nesne değişmez (React durumu)", () => {
  withoutBot(list, 2);
  assert.equal(list.bots.length, 3);
});

test("id metin/sayı farkı tolere edilir; olmayan bot → değişiklik yok", () => {
  assert.equal(withoutBot(list, "3").bots.length, 2);
  assert.equal(withoutBot(list, 99).bots.length, 3);
});

test("sayfalar arası olay adı sabit", () => {
  assert.equal(LISTS_CHANGED_EVENT, "lumanoris:lists-changed");
});
