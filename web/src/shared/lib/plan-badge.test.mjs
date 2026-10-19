// N-64 — node --test web/src/shared/lib/plan-badge.test.mjs
import { test } from "node:test";
import assert from "node:assert/strict";
import { showProBadge, planExpiryMs } from "./plan-badge.mjs";

const NOW = Date.parse("2026-10-18T12:00:00Z");

test("ücretsiz hesapta asla görünmez (plan adı ne olursa olsun)", () => {
  assert.equal(showProBadge({ planIsPaid: false, planName: "Altın" }, NOW), false);
  assert.equal(showProBadge({ planIsPaid: false, planName: "Ücretsiz Plan" }, NOW), false);
  assert.equal(showProBadge({ planName: "Altın" }, NOW), false, "planIsPaid bilinmiyorsa gösterme (fail-closed)");
  assert.equal(showProBadge(null, NOW), false);
});

test("aktif ücretli pakette görünür", () => {
  assert.equal(showProBadge({ planIsPaid: true, planExpiresAtMs: NOW + 86400000 }, NOW), true);
});

test("süresiz ücretli pakette görünür", () => {
  assert.equal(showProBadge({ planIsPaid: true, planExpiresAtMs: null }, NOW), true);
});

test("süre bittiği an kalkar (sayfa yenilenmeden)", () => {
  const acc = { planIsPaid: true, planExpiresAtMs: NOW + 1000 };
  assert.equal(showProBadge(acc, NOW), true);
  assert.equal(showProBadge(acc, NOW + 1000), false);
  assert.equal(showProBadge(acc, NOW + 5000), false);
});

test("kalan saniyeden bitiş anı (saat dilimi bağımsız)", () => {
  assert.equal(planExpiryMs(3600, NOW), NOW + 3600000);
  assert.equal(planExpiryMs(null, NOW), null);
  assert.equal(planExpiryMs(undefined, NOW), null);
});
