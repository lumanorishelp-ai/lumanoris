<?php
/**
 * Admin — Pazaryeri Başvuruları (madde 3, Faz 5).
 *
 * Veri ve durum değişikliği `ajax/basvurular.php` üzerinden (admin oturumu +
 * CSRF). Tüm değerler DOM'a textContent ile yazılıyor; başvuru alanları
 * kullanıcı girdisi.
 *
 * GK-9: "İncelendi" satıcıyı aktif YAPMAZ (B1).
 * GK-23: "İncelendi" başvurudaki IBAN'ı kullanıcının para çekme IBAN'ı yapar
 * (bekleyen çekim talebi varsa yapmaz) ve değişikliği admin_audit_log'a
 * maskeli yazar. Ekran mevcut ve yeni IBAN'ı yan yana gösteriyor; aktarımı
 * engellenmiş incelenmiş başvuruda "IBAN'ı aktar" ile yeniden denenir.
 * GK-26: tam IBAN ve doğum tarihi yalnızca bu ekranda; dışa aktarma yok.
 */
?>
<main class="bg-gray-50 p-6 min-h-screen">
    <div class="max-w-screen-xl mx-auto">
        <?php pageTitle("Pazaryeri Başvuruları", "Şirket başvurularını inceleyin. \"İncelendi\" satıcıyı otomatik aktif yapmaz; ücretli satış ödeme altyapısına (B1) bağlıdır."); ?>

        <div class="bg-white rounded-xl shadow-lg border border-gray-100 p-6">
            <div class="flex flex-wrap items-center gap-3 mb-4">
                <label for="statusFilter" class="text-sm font-semibold text-gray-700">Durum</label>
                <select id="statusFilter" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <option value="">Tümü</option>
                    <option value="submitted" selected>İnceleme bekleyen</option>
                    <option value="rejected">Reddedilen</option>
                    <option value="reviewed">İncelenen</option>
                </select>
                <span id="msg" class="text-sm"></span>
            </div>
            <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800">
                "İncelendi" başvurudaki IBAN'ı kullanıcının para çekme IBAN'ı olarak kaydeder ve
                değişikliği işlem loguna yazar. Kullanıcının bekleyen para çekme talebi varsa IBAN
                güncellenmez; talep sonuçlandıktan sonra "IBAN'ı aktar" ile yeniden deneyin.
            </div>
            <div id="list" class="space-y-4">
                <p class="text-sm text-gray-400">Yükleniyor…</p>
            </div>
        </div>
    </div>
</main>
<script>
(() => {
    const listEl   = document.getElementById('list');
    const filterEl = document.getElementById('statusFilter');
    const msgEl    = document.getElementById('msg');

    const STATUS_TR = { submitted: 'İnceleme bekliyor', reviewed: 'İncelendi', rejected: 'Reddedildi' };
    const TYPE_TR   = { sahis: 'Şahıs şirketi', kurumsal: 'Kurumsal' };

    function say(text, ok) {
        msgEl.textContent = text || '';
        msgEl.className = 'text-sm ' + (ok ? 'text-emerald-600' : 'text-red-600');
    }
    function el(tag, cls, text) {
        const n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = String(text);
        return n;
    }
    function field(label, value) {
        const wrap = el('div', '');
        wrap.appendChild(el('dt', 'text-xs text-gray-500', label));
        wrap.appendChild(el('dd', 'text-sm text-gray-900 break-all', value || '—'));
        return wrap;
    }

    function card(a) {
        const c = el('div', 'rounded-lg border border-gray-200 p-4');
        const head = el('div', 'flex flex-wrap items-center justify-between gap-2 mb-3');
        head.appendChild(el('h3', 'font-semibold text-gray-900', a.company_title + ' · ' + (TYPE_TR[a.account_type] || a.account_type)));
        head.appendChild(el('span', 'text-xs font-semibold rounded-full px-2 py-1 bg-gray-100 text-gray-700', STATUS_TR[a.status] || a.status));
        c.appendChild(head);

        const dl = el('dl', 'grid grid-cols-1 md:grid-cols-3 gap-3');
        [
            ['Kullanıcı', (a.kullanici_adi || '—') + ' (#' + a.user_id + ') · ' + (a.eposta || '')],
            ['Vergi no / dairesi', a.tax_number + ' · ' + a.tax_office],
            ['MERSİS', a.mersis_no],
            ['Yetkili', a.authorized_first_name + ' ' + a.authorized_last_name],
            ['Yetkili doğum tarihi', a.authorized_birth_date],
            ['Adres', a.address + ' · ' + a.ilce + ' / ' + a.il],
            ['Başvurudaki IBAN (yeni)', a.iban],
            ['Kullanıcının mevcut IBAN\'ı', a.current_iban || 'kayıtlı IBAN yok'],
            ['Son güncelleme', a.updated_at + (a.reviewed_at ? ' · incelendi: ' + a.reviewed_at : '')],
        ].forEach(([l, v]) => dl.appendChild(field(l, v)));
        c.appendChild(dl);

        if (a.iban_differs) {
            c.appendChild(el('p', 'mt-2 text-xs text-amber-700', 'Başvurudaki IBAN mevcut IBAN\'dan farklı.'));
        }
        if (a.pending_withdrawals > 0) {
            c.appendChild(el('p', 'mt-1 text-xs text-red-700',
                'Kullanıcının ' + a.pending_withdrawals + ' bekleyen para çekme talebi var — bu durumda IBAN güncellenmez.'));
        }
        if (a.review_note) {
            c.appendChild(el('p', 'mt-2 text-xs text-gray-600', 'Not: ' + a.review_note));
        }

        // İncelenmiş ama IBAN'ı aktarılamamış (bekleyen çekim vardı) başvuru:
        // yalnızca aktarımı yeniden deneme düğmesi.
        const needsTransfer = a.status === 'reviewed' && (a.iban_differs || !a.current_iban);
        if (needsTransfer) {
            const retry = el('button', 'mt-3 bg-indigo-600 text-white text-sm font-semibold px-3 py-2 rounded-lg', 'IBAN\x27ı aktar');
            retry.type = 'button';
            retry.addEventListener('click', () => save(a.id, 'reviewed', '', retry));
            c.appendChild(retry);
        }

        if (a.status !== 'reviewed') {
            const actions = el('div', 'mt-3 flex flex-wrap items-center gap-2');
            const note = el('input', 'flex-1 min-w-[200px] border border-gray-300 rounded-lg px-3 py-2 text-sm');
            note.placeholder = 'Ret açıklaması (kullanıcı görür)';
            note.maxLength = 1000;
            const ok  = el('button', 'bg-emerald-600 text-white text-sm font-semibold px-3 py-2 rounded-lg', 'İncelendi');
            const bad = el('button', 'bg-red-600 text-white text-sm font-semibold px-3 py-2 rounded-lg', 'Reddet');
            ok.type = bad.type = 'button';
            ok.addEventListener('click', () => save(a.id, 'reviewed', '', ok));
            bad.addEventListener('click', () => save(a.id, 'rejected', note.value, bad));
            actions.append(note, ok, bad);
            c.appendChild(actions);
        }
        return c;
    }

    async function load() {
        listEl.replaceChildren(el('p', 'text-sm text-gray-400', 'Yükleniyor…'));
        const status = filterEl.value;
        try {
            const res  = await fetch('/admin/ajax/basvurular.php' + (status ? '?status=' + encodeURIComponent(status) : ''), { credentials: 'same-origin' });
            const json = await res.json();
            if (!json.success) {
                listEl.replaceChildren(el('p', 'text-sm text-red-600', json.message || 'Başvurular alınamadı.'));
                return;
            }
            const apps = json.applications || [];
            if (!apps.length) {
                listEl.replaceChildren(el('p', 'text-sm text-gray-500', 'Bu durumda başvuru yok.'));
                return;
            }
            listEl.replaceChildren(...apps.map(card));
        } catch (e) {
            listEl.replaceChildren(el('p', 'text-sm text-red-600', 'Sunucuya bağlanılamadı.'));
        }
    }

    async function save(id, status, note, button) {
        if (status === 'reviewed' && !confirm(id + ' numaralı başvuruyu "incelendi" olarak işaretlemek istiyor musunuz?\n\nBaşvurudaki IBAN kullanıcının para çekme IBAN\'ı olarak kaydedilecek (bekleyen çekim talebi yoksa).\nBu, satıcıyı aktif yapmaz.')) return;
        if (status === 'rejected' && !note.trim()) { say('Reddetmek için açıklama yazın.', false); return; }
        button.disabled = true;
        try {
            const body = new FormData();
            body.append('id', id);
            body.append('status', status);
            body.append('review_note', note);
            const res  = await fetch('/admin/ajax/basvurular.php', { method: 'POST', credentials: 'same-origin', body });
            const json = await res.json();
            say(json.message || (json.success ? 'Güncellendi.' : 'Güncellenemedi.'), !!json.success);
            if (json.success) await load();
        } catch (e) {
            say('Sunucuya bağlanılamadı.', false);
        } finally {
            button.disabled = false;
        }
    }

    filterEl.addEventListener('change', load);
    load();
})();
</script>
