# Warext Studios | XenForo Moderasyon Denetim Sistemi

XenForo 2.3 için bağımsız moderasyon denetim eklentisi. Moderasyon işlemlerini değiştirmeden kayıt altına alır; kanıt anlık görüntüleri, kör denetçi ataması, çıkar çatışması yönetimi, çoklu değerlendirme ve değiştirilemez değerlendirme geçmişi sunar.

## Sürüm

**1.0.0 Alpha 6**

## Gereksinimler

- XenForo 2.3.0+
- PHP 8.1+

## Kurulum

Repo kökündeki `Warext-ModerationAudit-1.0.0-Alpha6.zip` dosyasını XenForo yönetim panelinde **Add-ons → Install/upgrade from archive** alanına yükleyin.

## Özellikler

- Moderasyon işlemlerinin kurulumdan itibaren denetim kaydına alınması
- Post, konu, uyarı, kullanıcı yasağı, rapor ve moderatör logu olaylarının yakalanması
- İşlem anındaki içerik ve bağlamın kanıt olarak saklanması
- Hassas verilerin maskelenmesi ve ayrı izinle görüntülenmesi
- SHA-256 kanıt bütünlüğü doğrulaması
- Salt-okunur `/denetim/` merkezi
- Doğru / kısmen doğru / yanlış / kanıt yetersiz değerlendirmesi
- Kural uygulaması, ceza orantısı ve yetkili iletişiminin ayrı değerlendirilmesi
- Değiştirilemez ve hash'li değerlendirme revizyon geçmişi
- Normal, yükseltilmiş ve kritik vakalar için çoklu denetçi desteği
- Kör ve tam kör denetim
- Çıkar çatışması bildirimi ve vakadan çekilme
- Aktif iş yüküne göre dengeli otomatik denetçi ataması
- Denetçilerin moderasyon yetkilerinden tamamen ayrılması

## Durum

Alpha 6 geliştirme sürümüdür. PHP 8.4 üzerinde sözdizimi, XML/JSON ve ZIP bütünlük kontrolleri yapılmıştır. Canlı XenForo 2.3 kurulumu üzerinde runtime testi henüz tamamlanmadığı için üretim öncesinde test ortamında doğrulanması önerilir.
