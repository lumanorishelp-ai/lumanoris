<div class="startbar d-print-none lg:relative">
    <nav id="admin-nav" class="
        d-flex flex-column w-56 <?= $current_theme['main_color'] ?> flex-shrink-0 overflow-auto 
        fixed lg:static top-0 left-0 z-30 transform h-full transition-transform duration-300 ease-in-out 
        -translate-x-full lg:translate-x-0 
        " 
        style="height: calc(100vh); scrollbar-width: thin; scrollbar-color: oklch(0.373 0.034 259.733) oklch(0.446 0.03 256.802);">
        
        <div class="startbar-menu flex flex-col h-full">
            <div class="startbar-collapse flex-grow-1 overflow-y-auto mt-6 lg:mt-0" id="startbarCollapse" data-simplebar>
                <ul class="flex-grow-1 px-3 py-2 space-y-2">
                    <li class="flex justify-center items-center py-4">
                        <a href="/admin/" class="logo block">
                            <img src="/admin/logo.png" alt="Lumanoris" class="h-[150px] w-auto">
                        </a>
                    </li>
                    <li>
                        <button class="d-block p-2 rounded <?= $currentPath === '/admin/adminler' ? $current_theme['active_color'].' hover:'.$current_theme['active_color'] : 'hover:'.$current_theme['hover_color'].' active:'.$current_theme['active_color'] ?> <?= $current_theme['text_color'] ?> sidebar-button transition duration-250 ease-in-out"
                        style="width: 100%; text-align: left; border: none; color: white; cursor: pointer;"
                        onclick="window.location.href='/admin/adminler'">
                        <i class="bi bi-people-fill me-1"></i> Adminler
                        </button>
                    </li>
                    <li>
                      <button class="toggleButton mt-0 d-block p-2 rounded hover:<?= $current_theme['hover_color'] ?> active:<?= $current_theme['active_color'] ?> <?= $current_theme['text_color'] ?> sidebar-button transition duration-250 ease-in-out"
                        style="width: 100%; text-align: left; border: none; cursor: pointer;">
                        <div class="flex items-center justify-between w-full">
                          <span class="flex items-center gap-2">
                            <i class="bi bi-bar-chart-fill me-1"></i> Site İçerikleri
                          </span>
                          <i class="bi bi-chevron-down"></i>
                        </div>
                      </button>
                      <ul class="contentList px-2 space-y-1 <?= $current_theme['sub_color'] ?>" style="max-height: 0; overflow: hidden; transition: max-height 0.5s ease;">
                        <li><a href="/admin/iletisim" class="d-block p-2 mt-1 rounded <?= $currentPath === '/admin/iletisim' ? $current_theme['active_color'] . ' hover:' . $current_theme['active_color'] : 'hover:' . $current_theme['hover_color'] . ' active:' . $current_theme['active_color'] ?> <?= $current_theme['text_color'] ?> sidebar-button transition duration-250 ease-in-out">İletişim Bilgileri</a></li>
                        <li><a href="/admin/anasayfa" class="d-block p-2 mt-1 rounded <?= $currentPath === '/admin/anasayfa' ? $current_theme['active_color'] . ' hover:' . $current_theme['active_color'] : 'hover:' . $current_theme['hover_color'] . ' active:' . $current_theme['active_color'] ?> <?= $current_theme['text_color'] ?> sidebar-button transition duration-250 ease-in-out">Ana Sayfa Resimleri</a></li>
                        <li><a href="/admin/hakkinda" class="d-block p-2 mt-1 rounded <?= $currentPath === '/admin/hakkinda' ? $current_theme['active_color'] . ' hover:' . $current_theme['active_color'] : 'hover:' . $current_theme['hover_color'] . ' active:' . $current_theme['active_color'] ?> <?= $current_theme['text_color'] ?> sidebar-button transition duration-250 ease-in-out">Hakkımızda Sayfası</a></li>
                        <li><a href="/admin/kullanimkosullari" class="d-block p-2 mt-1 rounded <?= $currentPath === '/admin/kullanimkosullari' ? $current_theme['active_color'] . ' hover:' . $current_theme['active_color'] : 'hover:' . $current_theme['hover_color'] . ' active:' . $current_theme['active_color'] ?> <?= $current_theme['text_color'] ?> sidebar-button transition duration-250 ease-in-out">Kullanım Koşulları</a></li>
                        <li><a href="/admin/gizlilikpolitikasi" class="d-block p-2 mt-1 rounded <?= $currentPath === '/admin/gizlilikpolitikasi' ? $current_theme['active_color'] . ' hover:' . $current_theme['active_color'] : 'hover:' . $current_theme['hover_color'] . ' active:' . $current_theme['active_color'] ?> <?= $current_theme['text_color'] ?> sidebar-button transition duration-250 ease-in-out">Gizlilik Politikası</a></li>
                        <li><a href="/admin/teslimatiade" class="d-block p-2 mt-1 rounded <?= $currentPath === '/admin/teslimatiade' ? $current_theme['active_color'] . ' hover:' . $current_theme['active_color'] : 'hover:' . $current_theme['hover_color'] . ' active:' . $current_theme['active_color'] ?> <?= $current_theme['text_color'] ?> sidebar-button transition duration-250 ease-in-out">Teslimat ve İade Şartları</a></li>
                        <li><a href="/admin/satiskosullari" class="d-block p-2 mb-1 rounded <?= $currentPath === '/admin/satiskosullari' ? $current_theme['active_color'] . ' hover:' . $current_theme['active_color'] : 'hover:' . $current_theme['hover_color'] . ' active:' . $current_theme['active_color'] ?> <?= $current_theme['text_color'] ?> sidebar-button transition duration-250 ease-in-out">Mesafeli Satış Sözleşmesi</a></li>
                    </ul>
                    </li>
                    <li>
                        <button class="d-block p-2 rounded <?= $currentPath === '/admin/seo' ? $current_theme['active_color'].' hover:'.$current_theme['active_color'] : 'hover:'.$current_theme['hover_color'].' active:'.$current_theme['active_color'] ?> <?= $current_theme['text_color'] ?> sidebar-button transition duration-250 ease-in-out"
                        style="width: 100%; text-align: left; border: none; color: white; cursor: pointer;"
                        onclick="window.location.href='/admin/seo'">
                        <i class="bi bi-body-text me-1"></i> SEO Ayarları
                        </button>
                    </li>
                    <li>
                        <button class="d-block p-2 rounded <?= $currentPath === '/admin/sosyalmedya' ? $current_theme['active_color']. ' hover:'. $current_theme['active_color'] : 'hover:'.$current_theme['hover_color'].' active:'.$current_theme['active_color'] ?> <?= $current_theme['text_color'] ?> sidebar-button transition duration-250 ease-in-out" 
                                style="width: 100%; text-align: left; border: none; cursor: pointer;" onclick="window.location.href='/admin/sosyalmedya'">
                            <i class="bi bi-phone-fill me-1"></i> Sosyal Medya Linkleri
                        </button>
                    </li>
                    <li>
                    <button class="d-block p-2 rounded <?= $currentPath === '/admin/kullanicilar' ? $current_theme['active_color']. ' hover:'. $current_theme['active_color'] : 'hover:'.$current_theme['hover_color'].' active:'.$current_theme['active_color'] ?> <?= $current_theme['text_color'] ?> sidebar-button transition duration-250 ease-in-out"
                            style="width: 100%; text-align: left; border: none; cursor: pointer;" onclick="window.location.href='/admin/kullanicilar'">
                        <i class="bi bi-person-fill me-1"></i> Kullanıcılar
                    </button>
                    <li>
                      <button class="toggleButton mt-0 d-block p-2 rounded hover:<?= $current_theme['hover_color'] ?> active:<?= $current_theme['active_color'] ?> <?= $current_theme['text_color'] ?> sidebar-button transition duration-250 ease-in-out"
                        style="width: 100%; text-align: left; border: none; cursor: pointer;">
                        <div class="flex items-center justify-between w-full">
                          <span class="flex items-center gap-2">
                            <i class="bi bi-robot"></i> Chatbotlar
                          </span>
                          <i class="bi bi-chevron-down"></i>
                        </div>
                      </button>
                      <ul class="contentList px-2 space-y-1 <?= $current_theme['sub_color'] ?>" style="max-height: 0; overflow: hidden; transition: max-height 0.5s ease;">
                        <li><a href="/admin/chatbotkategoriler" class="d-block p-2 mt-1 rounded <?= $currentPath === '/admin/chatbotkategoriler' ? $current_theme['active_color'] . ' hover:' . $current_theme['active_color'] : 'hover:' . $current_theme['hover_color'] . ' active:' . $current_theme['active_color'] ?> <?= $current_theme['text_color'] ?> sidebar-button transition duration-250 ease-in-out">Chatbot Kategorileri</a></li>
                        <li><a href="/admin/chatbotlar" class="d-block p-2 mt-1 rounded <?= $currentPath === '/admin/chatbotlar' ? $current_theme['active_color'] . ' hover:' . $current_theme['active_color'] : 'hover:' . $current_theme['hover_color'] . ' active:' . $current_theme['active_color'] ?> <?= $current_theme['text_color'] ?> sidebar-button transition duration-250 ease-in-out">Chatbotlar</a></li>
                        <li><a href="/admin/chatbotistatistik" class="d-block p-2 mb-1 rounded <?= $currentPath === '/admin/chatbotistatistik' ? $current_theme['active_color'] . ' hover:' . $current_theme['active_color'] : 'hover:' . $current_theme['hover_color'] . ' active:' . $current_theme['active_color'] ?> <?= $current_theme['text_color'] ?> sidebar-button transition duration-250 ease-in-out">Chatbot İstatistikleri</a></li>
                        <li><a href="/admin/chatayar" class="d-block p-2 mb-1 rounded <?= $currentPath === '/admin/chatayar' ? $current_theme['active_color'] . ' hover:' . $current_theme['active_color'] : 'hover:' . $current_theme['hover_color'] . ' active:' . $current_theme['active_color'] ?> <?= $current_theme['text_color'] ?> sidebar-button transition duration-250 ease-in-out">Chat Ayarları</a></li>
                      </ul>
                    </li>
                    </li>
                    <li>
                        <button class="d-block p-2 rounded <?= $currentPath === '/admin/api' ? $current_theme['active_color']. ' hover:'. $current_theme['active_color'] : 'hover:'.$current_theme['hover_color'].' active:'.$current_theme['active_color'] ?> <?= $current_theme['text_color'] ?> sidebar-button transition duration-250 ease-in-out"
                                style="width: 100%; text-align: left; border: none; cursor: pointer;" onclick="window.location.href='/admin/api'">
                            <i class="bi bi-gear-wide-connected me-1"></i> API Entegrasyonları
                        </button>
                    </li>
                    <li>
                        <button class="toggleButton mt-0 d-block p-2 rounded hover:<?= $current_theme['hover_color'] ?> active:<?= $current_theme['active_color'] ?> <?= $current_theme['text_color'] ?> sidebar-button transition duration-250 ease-in-out"
                        style="width: 100%; text-align: left; border: none; cursor: pointer;">
                            <div class="flex items-center justify-between w-full">
                                <span class="flex items-center gap-2">
                                    <i class="bi bi-bar-chart-fill me-1"></i> İstatistikler
                                </span>
                                <i class="bi bi-chevron-down"></i> 
                            </div>
                        </button>
                        <ul class="contentList px-2 space-y-1 <?= $current_theme['sub_color'] ?>" style="max-height: 0; overflow: hidden; transition: max-height 0.5s ease;">
                            <li><a href="/admin/hit" class="d-block p-2 mt-1 mb-1 rounded <?= $currentPath === '/admin/hit' ? $current_theme['active_color'].' hover:'.$current_theme['active_color'] : 'hover:'.$current_theme['hover_color'].' active:'.$current_theme['active_color'] ?> <?= $current_theme['text_color'] ?> sidebar-button transition duration-250 ease-in-out">Ziyaretçi İstatistikleri</a></li>
                        </ul>
                    </li>
                    <li>
                      <button class="toggleButton mt-0 d-block p-2 rounded hover:<?= $current_theme['hover_color'] ?> active:<?= $current_theme['active_color'] ?> <?= $current_theme['text_color'] ?> sidebar-button transition duration-250 ease-in-out"
                        style="width: 100%; text-align: left; border: none; cursor: pointer;">
                        <div class="flex items-center justify-between w-full">
                          <span class="flex items-center gap-2">
                            <i class="bi bi- me-1"></i> Ödeme
                          </span>
                          <i class="bi bi-chevron-down"></i>
                        </div>
                      </button>
                      <ul class="contentList px-2 space-y-1 <?= $current_theme['sub_color'] ?>" style="max-height: 0; overflow: hidden; transition: max-height 0.5s ease;">
                        <li><a href="/admin/odemeentegrasyon" class="d-block p-2 mt-1 rounded <?= $currentPath === '/admin/odemeentegrasyon' ? $current_theme['active_color'] . ' hover:' . $current_theme['active_color'] : 'hover:' . $current_theme['hover_color'] . ' active:' . $current_theme['active_color'] ?> <?= $current_theme['text_color'] ?> sidebar-button transition duration-250 ease-in-out">Ödeme Entegrasyonları</a></li>
                        <li><a href="/admin/abonelik" class="d-block p-2 rounded <?= $currentPath === '/admin/abonelik' ? $current_theme['active_color'] . ' hover:' . $current_theme['active_color'] : 'hover:' . $current_theme['hover_color'] . ' active:' . $current_theme['active_color'] ?> <?= $current_theme['text_color'] ?> sidebar-button transition duration-250 ease-in-out">Abonelik Sistemi</a></li>
                        <!-- COMP-007 / AUDIT D-01: satıcı çekim taleplerinin
                             tek yönetim noktası. Bu link olmadan sayfaya
                             ulaşmanın yolu adresi elle yazmaktı. -->
                        <li><a href="/admin/parcekme" class="d-block p-2 mb-1 rounded <?= $currentPath === '/admin/parcekme' ? $current_theme['active_color'] . ' hover:' . $current_theme['active_color'] : 'hover:' . $current_theme['hover_color'] . ' active:' . $current_theme['active_color'] ?> <?= $current_theme['text_color'] ?> sidebar-button transition duration-250 ease-in-out">Para Çekme Talepleri</a></li>
                        <li><a href="/admin/basvurular" class="d-block p-2 mb-1 rounded <?= $currentPath === '/admin/basvurular' ? $current_theme['active_color'] . ' hover:' . $current_theme['active_color'] : 'hover:' . $current_theme['hover_color'] . ' active:' . $current_theme['active_color'] ?> <?= $current_theme['text_color'] ?> sidebar-button transition duration-250 ease-in-out">Pazaryeri Başvuruları</a></li>
                        <li><a href="/admin/odemeler" class="d-block p-2 mb-1 rounded <?= $currentPath === '/admin/odemeler' ? $current_theme['active_color'] . ' hover:' . $current_theme['active_color'] : 'hover:' . $current_theme['hover_color'] . ' active:' . $current_theme['active_color'] ?> <?= $current_theme['text_color'] ?> sidebar-button transition duration-250 ease-in-out">Paket Ödemeleri</a></li>
                      </ul>
                    </li>
                    <li>
                        <button class="toggleButton mt-0 d-block p-2 rounded hover:<?= $current_theme['hover_color'] ?> active:<?= $current_theme['active_color'] ?> <?= $current_theme['text_color'] ?> sidebar-button transition duration-250 ease-in-out"
                        style="width: 100%; text-align: left; border: none; cursor: pointer;">
                            <div class="flex items-center justify-between w-full">
                                <span class="flex items-center gap-2">
                                    <i class="bi bi-gear-wide me-1"></i> Ayarlar
                                </span>
                                <i class="bi bi-chevron-down"></i>
                            </div>
                        </button>
                        <ul class="contentList px-2 space-y-1 <?= $current_theme['sub_color'] ?>" style="max-height: 0; overflow: hidden; transition: max-height 0.5s ease;">
                            <li><a href="/admin/genelayar" class="d-block p-2 mt-1 rounded <?= $currentPath === '/admin/genelayar' ? $current_theme['active_color'].' hover:'.$current_theme['active_color'] : 'hover:'.$current_theme['hover_color'].' active:'.$current_theme['active_color'] ?> <?= $current_theme['text_color'] ?> sidebar-button transition duration-250 ease-in-out">Genel Ayarlar</a></li>
                            <li><a href="/admin/smtp" class="d-block p-2 mb-1 rounded <?= $currentPath === '/admin/smtp' ? $current_theme['active_color'].' hover:'.$current_theme['active_color'] : 'hover:'.$current_theme['hover_color'].' active:'.$current_theme['active_color'] ?> <?= $current_theme['text_color'] ?> sidebar-button transition duration-250 ease-in-out">SMTP Ayarları</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
</div>