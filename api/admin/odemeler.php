<?php
/**
 * Admin — Paket Ödemeleri (Hoppa) ve iade (Faz 7a-3).
 *
 * Veri ve iade `ajax/odemeler.php` üzerinden (admin oturumu + CSRF,
 * `basvurular.php` ile aynı desen). Değerler DOM'a textContent ile yazılıyor.
 *
 * Yalnızca Hoppa ile satılmış üyelik paketi ödemeleri; pazaryeri ve iyzico
 * satırları bu ekranda yok. İade her zaman TAM tutar (kısmi iade yok); tutar
 * Hoppa'nın kayıtlı çekim tutarıdır. Paket geri alınamadıysa (kullanıcının
 * sonradan ödenmiş başka paketi var / şu anki paketi farklı) neden sonuç
 * kutusunda açıkça yazılır.
 */
?>
<main class="bg-gray-50 p-6 min-h-screen">
    <div class="max-w-screen-xl mx-auto">
        <?php pageTitle("Paket Ödemeleri", "Hoppa ile satılan üyelik paketi ödemeleri. Ödenmiş bir paketi buradan tam tutarla iade edebilirsiniz; pazaryeri satışları bu listede yer almaz."); ?>

        <div class="bg-white rounded-xl shadow-lg border border-gray-100 p-6">
            <form id="filters" class="flex flex-wrap items-end gap-3 mb-4">
                <div>
                    <label for="statusFilter" class="block text-xs font-semibold text-gray-700 mb-1">Durum</label>
                    <select id="statusFilter" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                        <option value="">Tümü</option>
                        <option value="paid">Ödendi</option>
                        <option value="refunded">İade edildi</option>
                        <option value="hoppa_pending">Beklemede</option>
                        <option value="hoppa_expired">Süresi doldu</option>
                        <option value="hoppa_failed">Başarısız</option>
                    </select>
                </div>
                <div class="flex-1 min-w-[220px]">
                    <label for="emailFilter" class="block text-xs font-semibold text-gray-700 mb-1">Kullanıcı e-postası</label>
                    <input id="emailFilter" type="search" maxlength="190" placeholder="ör. ali@ornek.com" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <button type="submit" class="bg-indigo-600 text-white text-sm font-semibold px-4 py-2 rounded-lg">Ara</button>
            </form>

            <div id="result" class="hidden mb-4 rounded-lg border px-4 py-3 text-sm"></div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-gray-500 border-b">
                            <th class="py-2 pr-4">Kullanıcı</th>
                            <th class="py-2 pr-4">Paket</th>
                            <th class="py-2 pr-4 text-right">Tutar</th>
                            <th class="py-2 pr-4">Tarih</th>
                            <th class="py-2 pr-4">Durum</th>
                            <th class="py-2 pr-4">Sipariş</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody id="rows">
                        <tr><td colspan="7" class="py-4 text-gray-400">Yükleniyor…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- İade onay penceresi -->
    <div id="confirmBox" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
        <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
            <h3 id="confirmTitle" class="text-lg font-bold text-gray-900 mb-3">Paketi iade et</h3>
            <dl id="confirmDetails" class="grid grid-cols-3 gap-x-3 gap-y-2 text-sm mb-4"></dl>
            <p class="text-xs text-gray-600 mb-3">
                Ödemenin tamamı Hoppa üzerinden karta iade edilir (kısmi iade yok; komisyon alıcıya
                yansıdıysa o da iade edilir). İade doğrulanınca kullanıcının paketi geri alınır ve
                işlem kaydı tutulur. Bu işlem geri alınamaz.
            </p>
            <label for="refundReason" class="block text-xs font-semibold text-gray-700 mb-1">İade nedeni (kayda geçer)</label>
            <textarea id="refundReason" maxlength="500" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm mb-4"></textarea>
            <div class="flex justify-end gap-2">
                <button id="confirmCancel" type="button" class="px-4 py-2 text-sm font-semibold rounded-lg border border-gray-300 text-gray-700">Vazgeç</button>
                <button id="confirmOk" type="button" class="px-4 py-2 text-sm font-semibold rounded-lg bg-red-600 text-white">İadeyi onayla</button>
            </div>
        </div>
    </div>
</main>
<script>
(() => {
    const rowsEl    = document.getElementById('rows');
    const statusEl  = document.getElementById('statusFilter');
    const emailEl   = document.getElementById('emailFilter');
    const resultEl  = document.getElementById('result');
    const boxEl     = document.getElementById('confirmBox');
    const detailsEl = document.getElementById('confirmDetails');
    const reasonEl  = document.getElementById('refundReason');
    const okBtn     = document.getElementById('confirmOk');
    const cancelBtn = document.getElementById('confirmCancel');

    const STATUS = {
        paid:          ['Ödendi', 'bg-emerald-100 text-emerald-800'],
        refunded:      ['İade edildi', 'bg-gray-200 text-gray-700'],
        hoppa_pending: ['Beklemede', 'bg-amber-100 text-amber-800'],
        hoppa_expired: ['Süresi doldu', 'bg-gray-100 text-gray-500'],
        hoppa_failed:  ['Başarısız', 'bg-red-100 text-red-700'],
    };
    const money = (n) => Number(n).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ₺';
    let pending = null;

    function el(tag, cls, text) {
        const n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = String(text);
        return n;
    }

    function showResult(ok, lines) {
        resultEl.className = 'mb-4 rounded-lg border px-4 py-3 text-sm ' +
            (ok ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-red-200 bg-red-50 text-red-800');
        resultEl.replaceChildren(...lines.map(([text, strong]) => el('p', strong ? 'font-semibold' : 'mt-1', text)));
    }

    function row(p) {
        const tr = el('tr', 'border-b last:border-0 align-top');
        const user = el('td', 'py-3 pr-4');
        user.appendChild(el('div', 'font-medium text-gray-900', p.eposta || '—'));
        user.appendChild(el('div', 'text-xs text-gray-500', (p.kullanici_adi || '—') + ' · #' + p.user_id));
        tr.appendChild(user);
        tr.appendChild(el('td', 'py-3 pr-4', p.plan_name));
        tr.appendChild(el('td', 'py-3 pr-4 text-right tabular-nums', money(p.amount)));
        tr.appendChild(el('td', 'py-3 pr-4 whitespace-nowrap', p.created_at));
        const st = el('td', 'py-3 pr-4');
        const [label, cls] = STATUS[p.status] || [p.status, 'bg-gray-100 text-gray-700'];
        st.appendChild(el('span', 'text-xs font-semibold rounded-full px-2 py-1 ' + cls, label));
        if (p.test) st.appendChild(el('span', 'ml-1 text-[10px] font-semibold rounded px-1 py-0.5 bg-sky-100 text-sky-800', 'TEST'));
        tr.appendChild(st);
        tr.appendChild(el('td', 'py-3 pr-4 font-mono text-xs text-gray-500', p.order_id));
        const act = el('td', 'py-3 text-right');
        if (p.status === 'paid') {
            const b = el('button', 'bg-red-600 text-white text-xs font-semibold px-3 py-1.5 rounded-lg', 'İade et');
            b.type = 'button';
            b.addEventListener('click', () => openConfirm(p));
            act.appendChild(b);
        }
        tr.appendChild(act);
        return tr;
    }

    async function load() {
        rowsEl.replaceChildren(el('tr', '', null));
        rowsEl.firstChild.appendChild(Object.assign(el('td', 'py-4 text-gray-400', 'Yükleniyor…'), { colSpan: 7 }));
        const q = new URLSearchParams();
        if (statusEl.value) q.set('status', statusEl.value);
        if (emailEl.value.trim()) q.set('email', emailEl.value.trim());
        try {
            const res  = await fetch('/admin/ajax/odemeler.php' + (q.toString() ? '?' + q : ''), { credentials: 'same-origin' });
            const json = await res.json();
            const tr = el('tr');
            if (!json.success) {
                tr.appendChild(Object.assign(el('td', 'py-4 text-red-600', json.message || 'Ödemeler alınamadı.'), { colSpan: 7 }));
                rowsEl.replaceChildren(tr);
                return;
            }
            const list = json.payments || [];
            if (!list.length) {
                tr.appendChild(Object.assign(el('td', 'py-4 text-gray-500', 'Bu filtreyle paket ödemesi yok.'), { colSpan: 7 }));
                rowsEl.replaceChildren(tr);
                return;
            }
            rowsEl.replaceChildren(...list.map(row));
        } catch (e) {
            const tr = el('tr');
            tr.appendChild(Object.assign(el('td', 'py-4 text-red-600', 'Sunucuya bağlanılamadı.'), { colSpan: 7 }));
            rowsEl.replaceChildren(tr);
        }
    }

    function openConfirm(p) {
        pending = p;
        reasonEl.value = '';
        detailsEl.replaceChildren();
        [
            ['Kullanıcı', (p.eposta || '—') + ' (' + (p.kullanici_adi || '—') + ', #' + p.user_id + ')'],
            ['Paket', p.plan_name],
            ['Tutar', money(p.amount) + ' (Hoppa\'daki çekim tutarı iade edilir)'],
            ['Sipariş', p.order_id],
            ['Ödeme tarihi', p.created_at],
        ].forEach(([k, v]) => {
            detailsEl.appendChild(el('dt', 'text-gray-500', k));
            detailsEl.appendChild(el('dd', 'col-span-2 text-gray-900 break-all', v));
        });
        okBtn.disabled = false;
        okBtn.textContent = 'İadeyi onayla';
        boxEl.classList.remove('hidden');
        cancelBtn.focus();
    }
    function closeConfirm() {
        boxEl.classList.add('hidden');
        pending = null;
    }

    async function refund() {
        if (!pending) return;
        const p = pending;
        okBtn.disabled = true;
        okBtn.textContent = 'İade ediliyor…';
        try {
            const body = new FormData();
            body.append('payment_id', p.id);
            body.append('reason', reasonEl.value);
            const res  = await fetch('/admin/ajax/odemeler.php', { method: 'POST', credentials: 'same-origin', body });
            const json = await res.json();
            closeConfirm();
            if (json.success) {
                const r = json.refund || {};
                const lines = [
                    [p.order_id + ' · ' + (p.eposta || '#' + p.user_id) + ': ' + (json.message || 'İade tamamlandı.'), true],
                    ['İade edilen tutar: ' + (r.refunded_amount != null ? money(r.refunded_amount) : '—') + ' · Hoppa durumu: ' + (r.provider_state === 'cancelled' ? 'iptal edildi (aynı gün)' : r.provider_state === 'refunded' ? 'iade edildi' : (r.provider_state || '—')), false],
                ];
                if (r.plan && r.plan.revoked === false) {
                    lines.push(['DİKKAT — "' + r.plan.plan + '" paketi geri ALINMADI: ' + r.plan.reason + '. Kullanıcının paketini gerekirse elle kontrol edin.', true]);
                } else if (r.plan && r.plan.revoked) {
                    lines.push(['"' + r.plan.plan + '" paketi geri alındı; kullanıcı varsayılan pakete döndü.', false]);
                }
                showResult(true, lines);
            } else {
                showResult(false, [[p.order_id + ': ' + (json.message || 'İade yapılamadı.'), true]]);
            }
            await load();
        } catch (e) {
            closeConfirm();
            showResult(false, [['Sunucuya bağlanılamadı. İadenin yapılıp yapılmadığını listeyi yenileyerek kontrol edin.', true]]);
        }
    }

    document.getElementById('filters').addEventListener('submit', (e) => { e.preventDefault(); load(); });
    statusEl.addEventListener('change', load);
    okBtn.addEventListener('click', refund);
    cancelBtn.addEventListener('click', closeConfirm);
    boxEl.addEventListener('click', (e) => { if (e.target === boxEl && !okBtn.disabled) closeConfirm(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !boxEl.classList.contains('hidden') && !okBtn.disabled) closeConfirm(); });
    load();
})();
</script>
