# Warext Studios | XenForo Moderasyon Denetim Sistemi

XenForo 2.3 için bağımsız moderasyon denetim eklentisi. Moderasyon işlemlerini değiştirmeden kayıt altına alır; kanıt anlık görüntüleri, kör denetçi ataması, çıkar çatışması yönetimi, çoklu değerlendirme ve yönetim analitiği sunar.

## Sürüm

**1.0.0 Alpha 7**

## Gereksinimler

- XenForo 2.3.0+
- PHP 8.1+

## Kurulum

Repo kökündeki `Warext-ModerationAudit-1.0.0-Alpha7.zip` dosyasını XenForo yönetim panelinde **Add-ons → Install/upgrade from archive** alanına yükleyin.

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

Alpha 7 geliştirme sürümüdür. PHP 8.4 sözdizimi, XML/JSON, ZIP ve SHA-256 manifest kontrollerinden geçirilmiştir. Canlı XenForo 2.3 kurulumu üzerinde runtime testi henüz tamamlanmamıştır.
