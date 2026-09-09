# Warext Studios | XenForo Moderasyon Denetim Sistemi

XenForo 2.3 için bağımsız moderasyon denetim eklentisi. Moderasyon işlemlerini değiştirmeden kayıt altına alır; kanıt anlık görüntüleri, kör denetçi ataması, çıkar çatışması yönetimi, çoklu değerlendirme ve yönetim analitiği sunar.

## Sürüm

**1.0.3**

## Gereksinimler

- XenForo 2.3.0+
- PHP 8.1+

## Kurulum

Repo kökündeki `Warext-ModerationAudit-1.0.3.zip` dosyasını XenForo yönetim panelinde **Add-ons → Install/upgrade from archive** alanına yükleyin.

## Özellikler

- Kurulumdan sonraki moderasyon işlemlerinin denetim kaydına alınması
- Post, konu, uyarı, kullanıcı yasağı, rapor ve moderatör logu olaylarının yakalanması
- İşlem anındaki içerik ve bağlamın kanıt olarak saklanması
- Hassas verilerin maskelenmesi ve ayrı izinle görüntülenmesi
- SHA-256 kanıt ve rapor bütünlüğü doğrulaması
- Salt-okunur `/denetim/` merkezi
- Doğru / kısmen doğru / yanlış / kanıt yetersiz değerlendirmesi
- Kural uygulaması, ceza orantısı ve yetkili iletişiminin ayrı değerlendirilmesi
- Değiştirilemez ve hash'li değerlendirme revizyon geçmişi
- Normal, yükseltilmiş ve kritik vakalarda çoklu denetçi desteği
- Kör ve tam kör denetim
- Çıkar çatışması bildirimi ve vakadan çekilme
- Dengeli otomatik denetçi ataması
- Haftalık, aylık ve özel dönem yönetim raporları
- Yetkili bazlı denetim kapsamı, doğruluk skoru ve sorunlu işlem oranı
- Kural, ceza ve iletişim sorunlarının ayrı analizi
- Önceki eş dönemle değişim karşılaştırması
- Anonimleştirilmiş rapor görünümü
- Haftalık ve aylık raporların cron ile otomatik oluşturulması

## Durum

1.0.0 stable kaynak/paket sürümüdür. Minimum PHP 8.1 sözdizimi, PHP/XML/JSON, route-controller, cron-callback, controller-template, izin tanımı, entity-veritabanı şeması, sınıf aliasları, ZIP ve SHA-256 manifest kontrollerinden geçirilmiştir. Lisanslı canlı XenForo 2.3 kurulumu üzerinde runtime testi CI ortamında yapılamadığından bu kontrol ayrıca gerçek kurulumda yapılmalıdır.


## 1.0.0 Alpha 8 — Adım 8/10

- İtiraz ve öneri merkezi eklendi.
- İtirazlar denetim vakasına bağlanıyor; standart kullanıcı yalnızca kendi moderasyon işlemi için itiraz açabiliyor.
- Özgün başvuru içeriği değiştirilemiyor veya silinemiyor.
- Yönetim cevapları, atama ve durum geçişleri ayrı olay geçmişinde tutuluyor.
- Her olay SHA-256 bütünlük doğrulamasına sahip.
- Yeni izinler: `warextAuditAppeal`, `warextAuditSuggest`.
- Alpha 8 şema sürümü: 5.


## 1.0.0 Alpha 9 — Adım 9/10

- Risk ve öncelik bazlı SLA süreleri eklendi.
- Denetim vakaları için normal 72s, yükseltilmiş 36s, kritik 12s varsayılan SLA.
- İtiraz/öneriler için düşük 96s, normal 48s, yüksek 24s, kritik 6s varsayılan SLA.
- SLA ihlalleri 3 seviyeli eskalasyon kaydı üretir ve aynı seviye tekrarlanmaz.
- Eskalasyon başlangıcı ve çözümü ayrı SHA-256 bütünlük kontrolüne sahiptir.
- Kullanıcıya bağlı dahili denetim bildirim merkezi eklendi.
- Sonuçlanan vaka/başvuruların açık eskalasyonları sistem tarafından iz bırakarak kapatılır.
- Saatlik XenForo cron taraması ve yönetici manuel tarama düğmesi eklendi.
- Yeni `/denetim-takip/` takip ve eskalasyon merkezi eklendi.
- Alpha 9 şema sürümü: 6.


## 1.0.0 — Adım 10/10

- Final kaynak ve paket sağlamlaştırması tamamlandı.
- Normal kullanıcıların yüksek/kritik öncelikle kendi SLA sürelerini yapay biçimde kısaltması engellendi.
- Çözümlenmiş eskalasyon kapanış kayıtları değiştirilemez hale getirildi.
- Okunmuş denetim bildirimlerinin tekrar okunmamış duruma çevrilmesi engellendi.
- Takip sayfasının GET isteğinde SLA taraması/veri yazması kaldırıldı; tarama yalnızca cron veya açık yönetici POST işlemiyle çalışır.
- Normal kullanıcı eskalasyon sorgusu yalnızca ilişkili vaka/başvurular üzerinden veritabanında filtrelenecek şekilde optimize edildi.
- Vaka ekranındaki itiraz düğmesi kendi işlemi olan ve itiraz yetkisine sahip kullanıcılar için düzeltildi.
- Route/controller, cron callback, template, permission, entity/DB kolon ve sınıf alias çapraz kontrolleri final doğrulamaya eklendi.
- Kurulum ZIP'i yalnızca `upload/` ağacını içerir; geliştirme ve CI dosyaları pakete girmez.


## 1.0.1 — Kurulum düzeltmesi ve bağımsız ACP bölümü

- `public:warext_audit_report` şablonundaki XenForo ile uyumsuz `~` string birleştirme operatörü `.` ile düzeltildi; kurulum sırasında oluşan template syntax hatası giderildi.
- ACP sol menüsüne diğer XenForo kategorilerinden bağımsız **Moderasyon Denetim Sistemi** ana bölümü eklendi.
- Bu bölüm altında **Sistem Bilgisi** ve **Ayarlar** alt sayfaları eklendi.
- Sistem Bilgisi ekranında sürüm, şema, açık/toplam vaka ve başvuru sayıları ile aktif eskalasyon sayısı gösterilir.
- Ayarlar ekranından vaka ve itiraz/öneri SLA süreleri doğrudan düzenlenebilir.
- Ayar değerleri mevcut `xf_warext_audit_state` altyapısına yazılır; governance/cron sistemi bu değerleri doğrudan kullanır.

## 1.0.2

- XenForo super admin hesapları tüm `warextAudit*` public izinlerini otomatik olarak geçer; ayrıca kullanıcı grubu izni vermeleri gerekmez.
- ACP `warextAudit` izni super admin için otomatik bypass edilir.
- `Moderator tools` açılır menüsüne XenForo'nun `mod_tools_menu:top` template hook'u üzerinden **Moderasyon Denetimi** bağlantısı eklendi.
- Normal moderatör/denetçiler için ayrıntılı kullanıcı grubu izin sistemi aynen korunur.

## 1.0.3

- Vaka ekranındaki ham **Olay metadatası** ve büyük JSON **Kanıt anlık görüntüleri** ana görünümden kaldırıldı.
- Denetçiye artık yapılan işlem, işlem zamanı, durum geçişi, gerçek içerik, konu başlığı, rapor nedeni/notları ve yakın içerik bağlamı okunabilir kartlar halinde gösterilir.
- SHA-256 doğrulaması arka planda korunur; ana ekranda yalnızca kanıt bütünlüğü sonucu gösterilir.
- Ham metadata, hash ve snapshot JSON verileri sadece denetim yöneticileri için kapalı **Teknik detaylar** alanında tutulur.
- Rapor vakalarında rapor yorumları/nedenleri bundan sonraki olay snapshot'larına değişmez kanıt olarak dahil edilir.
- Teknik action/source kodları yerine kullanıcı dostu Türkçe işlem ve kaynak adları gösterilir.
