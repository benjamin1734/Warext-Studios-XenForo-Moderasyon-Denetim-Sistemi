# Warext Studios | XenForo Moderasyon Denetim Sistemi

XenForo 2.3 için bağımsız moderasyon denetim eklentisi. Moderasyon işlemlerini değiştirmeden kayıt altına alır; kanıt anlık görüntüleri, kör denetçi ataması, çıkar çatışması yönetimi, çoklu değerlendirme ve yönetim analitiği sunar.

## Sürüm

**1.0.0 Alpha 9**

## Gereksinimler

- XenForo 2.3.0+
- PHP 8.1+

## Kurulum

Repo kökündeki `Warext-ModerationAudit-1.0.0-Alpha9.zip` dosyasını XenForo yönetim panelinde **Add-ons → Install/upgrade from archive** alanına yükleyin.

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

Alpha 9 geliştirme sürümüdür. PHP 8.4 sözdizimi, XML/JSON, ZIP ve SHA-256 manifest kontrollerinden geçirilmiştir. Canlı XenForo 2.3 kurulumu üzerinde runtime testi henüz tamamlanmamıştır.


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
