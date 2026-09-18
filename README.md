# Warext Studios | XenForo Independent Moderation Audit System

## Türkçe

XenForo 2.3 için bağımsız moderasyon denetim eklentisi. Moderasyon işlemlerini değiştirmeden kayıt altına alır; kanıt anlık görüntüleri, kör denetçi ataması, çıkar çatışması yönetimi, çoklu değerlendirme ve yönetim analitiği sunar.

## Sürüm

**1.0.4**

## Gereksinimler

- XenForo 2.3.0+
- PHP 8.1+

## Kurulum

Repo kökündeki `Warext-ModerationAudit-1.0.4.zip` dosyasını XenForo yönetim panelinde **Add-ons → Install/upgrade from archive** alanına yükleyin.

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

## 1.0.4

- Public ana navbar üzerindeki `Denetim` sekmesi tamamen kaldırıldı.
- Moderasyon denetimi artık yalnızca `Moderator tools` menüsünden, doğrudan yetkili URL'lerinden ve ACP'deki bağımsız yönetim bölümünden erişilir.
- Normal kullanıcıların ana navigasyonunda moderasyon denetim sistemiyle ilgili herhangi bir menü öğesi bulunmaz.
- Super admin otomatik erişimi ve normal moderatör/denetçi izin kontrolleri korunur.

## Destek

Sorularınız, hata bildirimleriniz, kurulum desteği ve Warext Studios XenForo eklentileriyle ilgili yardım için destek Discord sunucumuza katılabilirsiniz:

**Discord:** https://discord.gg/tgsV5XMcFS

---

## English

Warext Studios XenForo Moderation Audit System is an independent moderation-governance add-on for XenForo 2.3. It records moderation actions without altering XenForo's moderation behavior and provides evidence snapshots, blind reviewer assignment, conflict-of-interest controls, multi-review workflows, and management analytics.

## Version

**1.0.4**

## Requirements

- XenForo 2.3.0+
- PHP 8.1+

## Installation

Upload `Warext-ModerationAudit-1.0.4.zip` from the repository root through XenForo Admin CP → **Add-ons → Install/upgrade from archive**.

## Features

- Audit logging for moderation actions performed after installation
- Capture of post, thread, warning, user-ban, report, and moderator-log events
- Preservation of content and context at action time as evidence
- Sensitive-data masking with separate permission-based access
- SHA-256 evidence and report-integrity verification
- Read-only `/denetim/` audit center
- Correct / partially correct / incorrect / insufficient evidence review outcomes
- Separate scoring of rule application, penalty proportionality, and staff communication
- Immutable, hashed review-revision history
- Multiple reviewer support for normal, elevated, and critical cases
- Blind and fully blind audits
- Conflict-of-interest declaration and reviewer recusal
- Balanced automatic reviewer assignment
- Weekly, monthly, and custom-period management reports
- Staff-specific review coverage, accuracy score, and problematic-action rate
- Separate analysis of rule, penalty, and communication problems
- Comparison against the previous equivalent period
- Anonymized report view
- Automatic weekly and monthly report generation through cron

## Status

1.0.0 was the stable source/package milestone. It was validated for minimum PHP 8.1 syntax, PHP/XML/JSON integrity, route-controller mappings, cron callbacks, controller-template connections, permissions, entity/database schema, class aliases, ZIP packaging, and SHA-256 manifests. CI cannot perform a runtime test on a licensed live XenForo 2.3 installation, so that validation must additionally be performed on a real installation.

## 1.0.0 Alpha 8 — Step 8/10

- Added the appeals and suggestions center.
- Appeals are linked to audit cases; standard users can appeal only moderation actions concerning themselves.
- Original submission content cannot be modified or deleted.
- Management replies, assignments, and status transitions are preserved in a separate event history.
- Every event has SHA-256 integrity verification.
- New permissions: `warextAuditAppeal`, `warextAuditSuggest`.
- Alpha 8 schema version: 5.

## 1.0.0 Alpha 9 — Step 9/10

- Added risk- and priority-based SLA periods.
- Default audit-case SLA: normal 72h, elevated 36h, critical 12h.
- Default appeal/suggestion SLA: low 96h, normal 48h, high 24h, critical 6h.
- SLA violations generate three levels of escalation records without repeating the same level.
- Escalation opening and resolution have separate SHA-256 integrity checks.
- Added an internal user-linked audit notification center.
- Open escalations belonging to resolved cases/submissions are closed by the system with an audit trail.
- Added hourly XenForo cron scanning and an administrator manual-scan action.
- Added the `/denetim-takip/` tracking and escalation center.
- Alpha 9 schema version: 6.

## 1.0.0 — Step 10/10

- Completed final source and package hardening.
- Prevented normal users from artificially shortening their own SLA by selecting high/critical priority.
- Resolved escalation-closing records became immutable.
- Read audit notifications can no longer be changed back to unread.
- GET requests on the tracking page no longer perform SLA scans or data writes; scans run only through cron or explicit administrator POST actions.
- Normal-user escalation queries were optimized to filter only related cases/submissions at database level.
- Fixed the appeal button so it appears for users whose own moderation action is being reviewed and who have appeal permission.
- Added final cross-checks for route/controller, cron callback, template, permission, entity/DB columns, and class aliases.
- Installation ZIP contains only the `upload/` tree; development and CI files are excluded.

## 1.0.1 — Installation fix and dedicated ACP section

- Replaced the XenForo-incompatible `~` string concatenation operator in `public:warext_audit_report` with `.`, fixing the template syntax error during installation.
- Added a dedicated **Moderation Audit System** root section in the ACP sidebar, independent of other XenForo categories.
- Added **System Information** and **Settings** pages under this section.
- System Information shows version, schema, open/total case and submission counts, and active escalation count.
- Case and appeal/suggestion SLA periods can be edited directly from Settings.
- Settings are stored in the existing `xf_warext_audit_state` infrastructure and used directly by governance/cron logic.

## 1.0.2

- XenForo super admins automatically pass all `warextAudit*` public permissions without requiring extra user-group permissions.
- ACP `warextAudit` permission is automatically bypassed for super admins.
- Added **Moderation Audit** to the `Moderator tools` dropdown via XenForo's `mod_tools_menu:top` template hook.
- Detailed user-group permissions remain in place for normal moderators/reviewers.

## 1.0.3

- Removed raw **Event metadata** and large JSON **Evidence snapshots** from the main case view.
- Reviewers now see the action, action time, state transition, real content, thread title, report reasons/notes, and nearby content context in readable cards.
- SHA-256 verification remains in the background; the main view shows only the evidence-integrity result.
- Raw metadata, hashes, and snapshot JSON are available only to audit managers inside a collapsed **Technical details** area.
- For report cases, report comments/reasons are included in subsequent event snapshots as immutable evidence.
- User-friendly action/source names are shown instead of raw technical codes.

## 1.0.4

- Removed the public-main-navbar `Audit` tab entirely.
- Moderation audit is now accessible only through `Moderator tools`, authorized direct URLs, and the dedicated ACP management section.
- Normal users do not see any moderation-audit navigation item in the main navigation.
- Automatic super-admin access and normal moderator/reviewer permission checks are preserved.

## Support

For questions, bug reports, installation support, and help with Warext Studios XenForo add-ons, you can join our support Discord server:

**Discord:** https://discord.gg/tgsV5XMcFS
