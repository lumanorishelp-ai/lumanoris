<?php
$abonelikler = $database->selectMulti("* FROM plans");
// Madde 11 — `privacy_right_limit` migration 012 ile geliyor. Kolon yoksa alan
// hiç çizilmiyor: aksi hâlde form onu gönderir ve genel update.php "Unknown
// column" ile kaydı tamamen reddederdi.
$hasPrivacyColumn = (int) ($database->selectSingle(
    "COUNT(*) AS cnt FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plans' AND COLUMN_NAME = 'privacy_right_limit'"
)['cnt'] ?? 0) === 1;
// plan_icerikler'i okumak için şimdilik bir şey çekmiyoruz, JS'de okuyacağız.
?>
<main class="bg-gray-50 p-6 min-h-screen">
    <div class="max-w-screen-xl mx-auto">
        <?php pageTitle("Abonelik Planları", "Bu sayfada sitenizde sunacağınız abonelik planlarını yönetebilirsiniz."); ?>

        <div class="grid grid-cols-1 lg:grid-cols-[300px_auto] gap-6">
            <aside class="bg-white rounded-xl shadow-lg border border-gray-100 p-4 h-[400px] overflow-y-auto">
                <h3 class="text-lg font-bold text-gray-800 border-b pb-3 mb-3">Abonelik Planları</h3>
                <ul id="dataUl" class="space-y-2 overflow-auto">
                    <?php foreach ($abonelikler as $abonelik): ?>
                        <li class="flex items-center bg-gray-100 hover:bg-indigo-50/70 text-gray-800 px-3 py-2 rounded-lg cursor-pointer transition duration-150" data-id="<?= $abonelik['id'] ?>">
                            <span class="font-medium"><?= htmlspecialchars($abonelik['name_tr'] ?? '') ?></span>
                        </li>   
                    <?php endforeach; ?>
                </ul>
            </aside>
            <section class="bg-white rounded-xl shadow-lg border border-gray-100 p-6">
                <h3 class="text-lg font-bold text-gray-800 border-b pb-3 mb-4">Plan Bilgileri</h3>
                <form id="dataForm" class="space-y-6" enctype="multipart/form-data">
                    <input type="hidden" name="id" id="id" value="0">
                    
                    <!-- Plan Alanları -->
                    <div>
                        <label for="name_tr" class="block font-semibold text-sm text-gray-700 mb-2">Plan Adı (TR)</label>
                        <input type="text" id="name_tr" name="name_tr" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition shadow-sm" required>
                    </div>
                    <div>
                        <label for="name_en" class="block font-semibold text-sm text-gray-700 mb-2">Plan Adı (EN)</label>
                        <input type="text" id="name_en" name="name_en" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition shadow-sm" required>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                         <div>
                            <label for="monthly_price" class="block font-semibold text-sm text-gray-700 mb-2">Aylık Fiyat</label>
                            <input type="number" step="0.01" id="monthly_price" name="monthly_price" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition shadow-sm" min="0" value="0.00" required>
                        </div>
                         <div>
                            <label for="yearly_price" class="block font-semibold text-sm text-gray-700 mb-2">Yıllık Fiyat</label>
                            <input type="number" step="0.01" id="yearly_price" name="yearly_price" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition shadow-sm" min="0" value="0.00" required>
                        </div>
                    </div>
                    <div>
                        <label for="currency" class="block font-semibold text-sm text-gray-700 mb-2">Para Birimi (Örnek: 1=₺)</label>
                        <input type="number" id="currency" name="currency" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition shadow-sm" min="0" value="1" required>
                    </div>
                    <div>
                      <label for="description_tr" class="block font-semibold text-sm text-gray-700 mb-2">Açıklama (TR)</label>
                      <textarea id="description_tr" name="description_tr" rows="2" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition shadow-sm"></textarea>
                    </div>
                    <div>
                      <label for="description_en" class="block font-semibold text-sm text-gray-700 mb-2">Açıklama (EN)</label>
                      <textarea id="description_en" name="description_en" rows="2" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition shadow-sm"></textarea>
                    </div>
                    
                    <!--
                      H-04 — `plans` tablosunun üç KOTA sütunu bu formda hiç
                      yoktu. Panelden açılan her yeni plan bu sütunları DB
                      varsayılanlarıyla, yani ÜCRETSİZ plan kotalarıyla
                      (1/2/10) alıyordu: "Elmas" planı satılıyor, kullanıcı
                      ücretsiz limitleri görüyordu. `ajax/create.php` ve
                      `update.php` `plans` tablosunu zaten beyaz listede
                      tuttuğu için sunucu tarafında değişiklik gerekmedi.
                    -->
                    <div class="border-t pt-4 mt-4">
                        <h4 class="text-md font-bold text-indigo-700 mb-2">Plan Kotaları</h4>
                        <p class="text-xs text-gray-500 mb-3">
                            Bu değerler doğrudan kullanıcının bot, mesaj ve özel yapma hakkına
                            dönüşür (<code>functions/plans.php</code>, <code>chatbot_limits.php</code>).
                            Bot kotalarında <strong>boş bırakmak = sınırsız</strong> (migration 012
                            gerektirir). Özel yapma hakkı toplamdır, aylık yenilenmez.
                        </p>
                        <div class="grid grid-cols-1 md:grid-cols-<?= $hasPrivacyColumn ? '4' : '3' ?> gap-4">
                            <div>
                                <label for="independent_bot_limit" class="block font-semibold text-sm text-gray-700 mb-2">Bağımsız bot</label>
                                <input type="number" id="independent_bot_limit" name="independent_bot_limit" min="0" step="1" value="1" placeholder="Sınırsız"
                                       class="w-full border border-gray-300 rounded-lg px-4 py-2.5 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition shadow-sm">
                            </div>
                            <div>
                                <label for="public_bot_limit" class="block font-semibold text-sm text-gray-700 mb-2">Herkese açık bot</label>
                                <input type="number" id="public_bot_limit" name="public_bot_limit" min="0" step="1" value="2" placeholder="Sınırsız"
                                       class="w-full border border-gray-300 rounded-lg px-4 py-2.5 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition shadow-sm">
                            </div>
                            <?php if ($hasPrivacyColumn): ?>
                            <div>
                                <label for="privacy_right_limit" class="block font-semibold text-sm text-gray-700 mb-2">Özel yapma hakkı (toplam)</label>
                                <input type="number" id="privacy_right_limit" name="privacy_right_limit" min="0" step="1" value="1" required
                                       class="w-full border border-gray-300 rounded-lg px-4 py-2.5 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition shadow-sm">
                            </div>
                            <?php endif; ?>
                            <div>
                                <label for="daily_message_limit" class="block font-semibold text-sm text-gray-700 mb-2">Günlük mesaj</label>
                                <input type="number" id="daily_message_limit" name="daily_message_limit" min="0" step="1" value="10"
                                       class="w-full border border-gray-300 rounded-lg px-4 py-2.5 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition shadow-sm">
                            </div>
                        </div>
                    </div>

                    <!-- Plan İçerikleri Yönetimi -->
                    <!--
                      G-02 — burada `<form id="featureForm">` DIŞ `<form
                      id="dataForm">`in İÇİNE yuvalanmıştı. HTML ayrıştırıcı
                      iç form başlangıç etiketini yok sayar, kapanış etiketi
                      ise DIŞ formu kapatır. Sonuç:
                      `getElementById("featureForm")` null dönüyor, ona
                      bağlanan submit dinleyicisi hiç kurulamıyordu — yani
                      "Plan İçerikleri" ekleme/güncelleme HİÇ ÇALIŞMIYORDU;
                      üstelik dış formun geri kalanı (kotalar, kaydet/sil
                      düğmeleri) da form dışında kalıyordu.
                      Çözüm: iç form bir `<div>`; submit yerine butonun
                      `click`ine bağlanıyor (JS tarafı da güncellendi).
                    -->
                    <div class="border-t pt-4 mt-4">
                        <h4 class="text-md font-bold text-indigo-700 mb-2">Plan İçerikleri</h4>
                        <ul id="featureList" class="space-y-2 mb-4">
                            <!-- İçerikler buraya JS ile eklenecek -->
                            <li class="text-sm text-gray-600 italic">Plan seçilince içerikler yüklenecektir.</li>
                        </ul>
                        <div id="featureForm" class="flex gap-2">
                            <input type="hidden" id="feature_id" value="0">
                            <input type="text" id="feature_tr" placeholder="Özellik (TR)" class="flex-grow border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-indigo-500">
                            <input type="text" id="feature_en" placeholder="Özellik (EN)" class="flex-grow border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-indigo-500">
                            <button type="button" id="saveFeature" class="bg-green-500 text-white text-sm px-3 py-2 rounded-lg hover:bg-green-600 active:scale-95 transition">Ekle/Güncelle</button>
                            <button type="button" id="cancelFeature" class="hidden bg-yellow-500 text-white text-sm px-3 py-2 rounded-lg hover:bg-yellow-600 active:scale-95 transition">İptal</button>
                        </div>
                    </div>

                    <div class="flex justify-between gap-2 pt-4">
                        <button type="button" id="new" class="flex-1 bg-gray-200 text-indigo-600 font-semibold px-4 py-2 rounded-lg hover:bg-gray-300 active:bg-gray-400 active:scale-95 transition duration-150">Yeni Plan</button>
                        <button type="submit" id="saveOrUpdate" class="flex-1 bg-indigo-600 text-white font-semibold px-4 py-2 rounded-lg hover:bg-indigo-700 active:bg-indigo-800 active:scale-95 transition duration-150 shadow-md shadow-indigo-500/30">Planı Kaydet</button>
                        <button type="button" id="delete" class="flex-1 hidden bg-red-600 text-white font-semibold px-4 py-2 rounded-lg hover:bg-red-700 active:bg-red-800 active:scale-95 transition duration-150 shadow-md shadow-red-500/30">Planı Sil</button>
                    </div>
                </form>
            </section>
        </div>
    </div>
</main>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const dataForm = document.getElementById("dataForm");
        const saveOrUpdateBtn = document.getElementById("saveOrUpdate");
        const newBtn = document.getElementById("new");
        const deleteBtn = document.getElementById("delete");
        const dataUl = document.getElementById("dataUl");
        
        // Plan İçerikleri Yönetimi Elementleri
        const featureForm = document.getElementById("featureForm");
        const featureList = document.getElementById("featureList");
        const featureIdInput = document.getElementById("feature_id");
        const featureTrInput = document.getElementById("feature_tr");
        const featureEnInput = document.getElementById("feature_en");
        const saveFeatureBtn = document.getElementById("saveFeature");
        const cancelFeatureBtn = document.getElementById("cancelFeature");

        // G-02: `featureForm` artık bir <form> değil <div> (iç içe form
        // ayrıştırıcı tarafından atılıyordu), yani `.reset()` yok.
        const resetFeatureForm = () => {
            featureIdInput.value = "0";
            featureTrInput.value = "";
            featureEnInput.value = "";
        };


        // --- Plan Okuma ve Seçme ---
        dataUl.querySelectorAll("li").forEach(li => {
            li.addEventListener("click", async () => {
                const id = li.dataset.id;
                dataForm.id.value = id; // Form ID'sini ayarla

                // Aktif stili yönet
                dataUl.querySelectorAll("li").forEach(item => {
                    item.classList.remove("bg-white", "text-black", "pointer-events-none", "shadow-inner");
                    item.classList.add("bg-gray-100", "hover:bg-indigo-50/70");
                });
                li.classList.remove("bg-gray-100", "hover:bg-indigo-50/70");
                li.classList.add("bg-white", "text-black", "pointer-events-none", "shadow-inner");

                // Butonları ayarla
                saveOrUpdateBtn.textContent = "Güncelle";
                newBtn.classList.remove("hidden");
                deleteBtn.classList.remove("hidden");

                // Veriyi oku
                const formData = new FormData();
                formData.append("table", "plans");
                formData.append("where", "id = " + id);

                try {
                    const res = await fetch("/admin/ajax/read.php", {
                        method: "POST",
                        body: formData
                    });
                    const result = JSON.parse(await res.text());

                    if (result.success === true) {
                        const row = result.data[0];
                        // Plan Bilgilerini Forma Doldur
                        Object.keys(row).forEach(key => {
                            if (dataForm[key]) {
                                dataForm[key].value = row[key];
                            }
                        });
                        
                        // İçerikleri yükle
                        await loadFeatures(id);
                    } else {
                        new Notification({
                            text: result.message,
                            type: "error",
                            position: "top-right"
                        });
                    }
                } catch (err) {
                    new Notification({
                        text: "Plan bilgisi alınamadı: " + err.message,
                        type: "error",
                        position: "top-right"
                    });
                }
            });
        });

        // Yeni Plan Ekle
        newBtn.addEventListener("click", () => {
            dataForm.reset();
            dataForm.id.value = "0";
            saveOrUpdateBtn.textContent = "Kaydet";
            newBtn.classList.add("hidden");
            deleteBtn.classList.add("hidden");
            featureList.innerHTML = '<li class="text-sm text-gray-600 italic">Plan seçilince içerikler yüklenecektir.</li>';
            resetFeatureForm();
            featureIdInput.value = "0";
            saveFeatureBtn.textContent = "Ekle";
            cancelFeatureBtn.classList.add("hidden");


            dataUl.querySelectorAll("li").forEach(item => {
                item.classList.remove("bg-white", "text-black", "pointer-events-none", "shadow-inner");
                item.classList.add("bg-gray-100", "hover:bg-indigo-50/70");
            });
        });

        // --- Plan Kaydet/Güncelle ---
        saveOrUpdateBtn.addEventListener("click", async (event) => {
            event.preventDefault();
            const id = dataForm.id.value;

            const formData = new FormData();
            formData.append("table", "plans");

            // Madde 11 / migration 012 — bot kotası boşsa NULL (= sınırsız).
            const UNLIMITABLE = ["independent_bot_limit", "public_bot_limit"];
            const data = {};
            Array.from(dataForm.elements).forEach(el => {
                if (!el.name || el.type === "button" || el.type === "submit" || el.name === "id") return;
                data[el.name] = (UNLIMITABLE.includes(el.name) && el.value.trim() === "") ? null : el.value;
            });

            formData.append("data", JSON.stringify(data));

            let url = "/admin/ajax/create.php";

            if (id !== "0") {
                url = "/admin/ajax/update.php";
                formData.append("where", "id = " + id);
            }

            try {
                const res = await fetch(url, {
                    method: "POST",
                    body: formData
                });
                const result = JSON.parse(await res.text());

                new Notification({
                    text: result.message,
                    type: result.success === true ? "success" : "error",
                    position: "top-right",
                    autoClose: 3000,
                    showProgress: true
                });
                
                if (result.success) {
                    if (id === "0") {
                        // Yeni kayıt sonrası listeye ekle
                        const newId = result.id;
                        const newLi = document.createElement("li");
                        newLi.className = "flex items-center bg-gray-100 hover:bg-indigo-50/70 text-gray-800 px-3 py-2 rounded-lg cursor-pointer transition duration-150";
                        newLi.dataset.id = newId;
                        // G-16 ailesi: innerHTML + şablon interpolasyonu yerine
                        // textContent — plan adı bir metin, işaretleme değil.
                        const newLabel = document.createElement("span");
                        newLabel.className = "font-medium";
                        newLabel.textContent = dataForm.name_tr.value;
                        newLi.appendChild(newLabel);
                        dataUl.appendChild(newLi);
                        
                        // Yeni eklenen öğeyi seçili yap
                        dataUl.querySelectorAll("li").forEach(item => item.classList.remove("bg-white", "text-black", "pointer-events-none", "shadow-inner"));
                        newLi.classList.add("bg-white", "text-black", "pointer-events-none", "shadow-inner");
                        dataForm.id.value = newId; // Form ID'sini güncellenmiş ID yap
                        saveOrUpdateBtn.textContent = "Güncelle";
                        deleteBtn.classList.remove("hidden");

                    } else {
                        // Güncelleme sonrası listedeki ismi güncelle
                        const existingLi = dataUl.querySelector(`li[data-id="${id}"]`);
                        if (existingLi) {
                            existingLi.querySelector('span').textContent = dataForm.name_tr.value;
                        }
                    }
                }
            } catch (err) {
                console.error(err);
                new Notification({text: "Plan kaydedilirken hata: " + err.message, type: "error", position: "top-right"});
            }
        });

        // --- Plan Silme ---
        deleteBtn.addEventListener("click", async () => {
            const id = dataForm.id.value;
            const dataName = dataForm.name_tr.value || "Bu plan"; 

            if (id === "0") {
                new Notification({text: "Silmek için bir plan seçili olmalı.", type: "error", position: "top-right"});
                return;
            }

            if (confirm(`Emin misiniz? "${dataName}" planını kalıcı olarak silmek istiyor musunuz?`)) {
                const formData = new FormData();
                formData.append("table", "plans");
                formData.append("where", "id = " + id);

                try {
                    const res = await fetch("/admin/ajax/delete.php", {
                        method: "POST",
                        body: formData
                    });
                    const result = JSON.parse(await res.text());

                    if (result.success === true) {
                        dataUl.querySelector(`li[data-id="${id}"]`)?.remove(); // Listeden kaldır

                        // Formu sıfırla
                        dataForm.reset();
                        dataForm.id.value = "0";
                        saveOrUpdateBtn.textContent = "Kaydet";
                        newBtn.classList.remove("hidden");
                        deleteBtn.classList.add("hidden");
                        featureList.innerHTML = '<li class="text-sm text-gray-600 italic">Plan seçilince içerikler yüklenecektir.</li>';
                        resetFeatureForm();
                        featureIdInput.value = "0";
                        saveFeatureBtn.textContent = "Ekle";
                        cancelFeatureBtn.classList.add("hidden");

                        new Notification({text: result.message, type: "success", position: "top-right", autoClose: 3000});
                    } else {
                        new Notification({text: result.message, type: "error", position: "top-right"});
                    }
                } catch (err) {
                    new Notification({text: "Silme işlemi sırasında bir hata oluştu: " + err.message, type: "error", position: "top-right"});
                }
            }
        });
        
        // --- Plan İçerikleri (Features) Yönetimi ---
        
        // İçerikleri Yükle
        async function loadFeatures(planId) {
            const formData = new FormData();
            formData.append("table", "plan_icerikler");
            formData.append("where", "plan_id = " + planId + " ORDER BY id ASC");
            
            featureList.innerHTML = ''; // Eski listeyi temizle

            try {
                const res = await fetch("/admin/ajax/read.php", {
                    method: "POST",
                    body: formData
                });
                const result = JSON.parse(await res.text());

                if (result.success === true && result.data.length > 0) {
                    result.data.forEach(feature => {
                        const li = document.createElement('li');
                        li.className = 'flex justify-between items-center border-b border-gray-200 py-1';
                        li.dataset.featureId = feature.id;
                        li.innerHTML = `
                            <span>${feature.feature_tr} / ${feature.feature_en}</span>
                            <div>
                                <button type="button" class="editFeature text-xs text-blue-600 hover:text-blue-800 mr-2" data-id="${feature.id}" data-tr="${feature.feature_tr}" data-en="${feature.feature_en}">Düzenle</button>
                                <button type="button" class="deleteFeature text-xs text-red-600 hover:text-red-800" data-id="${feature.id}">Sil</button>
                            </div>
                        `;
                        featureList.appendChild(li);
                    });
                    // Düzenle/Sil butonlarına yeni dinleyiciler ekle
                    attachFeatureListeners();
                } else {
                     featureList.innerHTML = '<li class="text-sm text-gray-600 italic">Bu plana ait içerik bulunamadı.</li>';
                }
            } catch (err) {
                featureList.innerHTML = '<li class="text-sm text-red-500">İçerikler yüklenemedi.</li>';
                console.error("Feature load error:", err);
            }
        }
        
        function attachFeatureListeners() {
            // Düzenle butonları
            featureList.querySelectorAll('.editFeature').forEach(btn => {
                btn.onclick = function() {
                    featureIdInput.value = btn.dataset.id;
                    featureTrInput.value = btn.dataset.tr;
                    featureEnInput.value = btn.dataset.en;
                    saveFeatureBtn.textContent = "Güncelle";
                    cancelFeatureBtn.classList.remove("hidden");
                };
            });
            
            // Sil butonları
            featureList.querySelectorAll('.deleteFeature').forEach(btn => {
                btn.onclick = async function() {
                    const id = btn.dataset.id;
                    const planId = dataForm.id.value;
                    if (confirm("Bu özelliği silmek istediğinizden emin misiniz?")) {
                        const formData = new FormData();
                        formData.append("table", "plan_icerikler");
                        formData.append("where", "id = " + id);
                        
                        try {
                            const res = await fetch("/admin/ajax/delete.php", { method: "POST", body: formData });
                            const result = JSON.parse(await res.text());
                            
                            if (result.success) {
                                btn.closest('li').remove();
                                new Notification({text: "Özellik silindi.", type: "success", position: "top-right"});
                                // Plan ID değişmediği için loadFeatures'a gerek yok, ama çağırmak en güvenlisi
                                // loadFeatures(planId); 
                            } else {
                                new Notification({text: result.message, type: "error", position: "top-right"});
                            }
                        } catch (e) {
                            new Notification({text: "Silme hatası.", type: "error", position: "top-right"});
                        }
                    }
                };
            });
        }

        // İçerik Ekle/Güncelle
        saveFeatureBtn.addEventListener("click", async (e) => {
            e.preventDefault();
            const planId = dataForm.id.value;
            if (planId === "0") {
                new Notification({text: "Önce bir plan seçmeli/kaydetmelisiniz.", type: "warning", position: "top-right"});
                return;
            }
            
            const featureId = featureIdInput.value;
            const trVal = featureTrInput.value.trim();
            const enVal = featureEnInput.value.trim();
            
            if (!trVal || !enVal) {
                 new Notification({text: "Her iki dildeki özelliği de giriniz.", type: "warning", position: "top-right"});
                 return;
            }

            const formData = new FormData();
            formData.append("table", "plan_icerikler");

            const data = {
                plan_id: planId,
                feature_tr: trVal,
                feature_en: enVal
            };
            
            formData.append("data", JSON.stringify(data));

            let url = "/admin/ajax/create.php";
            if (featureId !== "0") {
                url = "/admin/ajax/update.php";
                formData.append("where", "id = " + featureId);
            }
            
            try {
                const res = await fetch(url, { method: "POST", body: formData });
                const result = JSON.parse(await res.text());
                
                if (result.success) {
                    new Notification({text: result.message, type: "success", position: "top-right"});
                    resetFeatureForm();
                    featureIdInput.value = "0";
                    saveFeatureBtn.textContent = "Ekle";
                    cancelFeatureBtn.classList.add("hidden");
                    await loadFeatures(planId); // Listeyi yenile
                } else {
                    new Notification({text: result.message, type: "error", position: "top-right"});
                }
            } catch (err) {
                new Notification({text: "Özellik işlemi sırasında hata: " + err.message, type: "error", position: "top-right"});
            }
        });
        
        // İçerik Ekle/Güncelle İptal
        cancelFeatureBtn.addEventListener('click', () => {
            resetFeatureForm();
            featureIdInput.value = "0";
            saveFeatureBtn.textContent = "Ekle";
            cancelFeatureBtn.classList.add("hidden");
        });
        
        // Sayfa yüklendiğinde, eğer veri varsa ilkini seçili yap (Opsiyonel)
        // if (dataUl.querySelector('li')) {
        //     dataUl.querySelector('li').click();
        // }
        
    });
</script>