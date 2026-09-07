from pathlib import Path
import json

ROOT = Path('upload/src/addons/Warext/ModerationAudit')


def update_addon():
    path = ROOT / 'addon.json'
    addon = json.loads(path.read_text(encoding='utf-8'))
    addon['description'] = 'Independent moderation audit system with immutable evidence, blind review, analytics, appeals, suggestions, SLA tracking, escalation and auditable governance for XenForo.'
    addon['version_id'] = 1000100
    addon['version_string'] = '1.0.0'
    path.write_text(json.dumps(addon, ensure_ascii=False, indent=4) + '\n', encoding='utf-8')


def harden_feedback_priority():
    path = ROOT / 'Service/Audit/FeedbackManager.php'
    text = path.read_text(encoding='utf-8')
    old = """        if (!in_array($priority, self::PRIORITIES, true))\n        {\n            $priority = 'normal';\n        }\n\n        $db = $this->app->db();\n"""
    new = """        if (!in_array($priority, self::PRIORITIES, true))\n        {\n            $priority = 'normal';\n        }\n        // Normal users must not be able to self-escalate a submission into the\n        // short high/critical management SLA queues. Management can still use\n        // every priority level when creating a record.\n        if (!$actor->hasPermission('general', 'warextAuditManage') && in_array($priority, ['high', 'critical'], true))\n        {\n            $priority = 'normal';\n        }\n\n        $db = $this->app->db();\n"""
    if new not in text:
        if old not in text:
            raise RuntimeError('Feedback priority hardening anchor not found')
        text = text.replace(old, new, 1)
    path.write_text(text, encoding='utf-8')


def harden_escalation_entity():
    path = ROOT / 'Entity/AuditEscalation.php'
    text = path.read_text(encoding='utf-8')
    anchor = """            foreach (['source_type', 'source_id', 'level', 'reason', 'created_date', 'event_hash'] as $field)\n            {\n                if ($this->isChanged($field))\n                {\n                    $this->error('Eskalasyon kaydının özgün alanları değiştirilemez.', $field);\n                    break;\n                }\n            }\n"""
    hardened = anchor + """\n            if ((int)$this->getExistingValue('resolved_date') > 0)\n            {\n                foreach (['resolved_by_user_id', 'resolved_date', 'resolution_note', 'resolution_hash'] as $field)\n                {\n                    if ($this->isChanged($field))\n                    {\n                        $this->error('Çözümlenmiş eskalasyonun kapanış kaydı değiştirilemez.', $field);\n                        break;\n                    }\n                }\n            }\n"""
    if "Çözümlenmiş eskalasyonun kapanış kaydı değiştirilemez." not in text:
        if anchor not in text:
            raise RuntimeError('Escalation immutability anchor not found')
        text = text.replace(anchor, hardened, 1)
    path.write_text(text, encoding='utf-8')


def harden_notice_entity():
    path = ROOT / 'Entity/AuditNotice.php'
    text = path.read_text(encoding='utf-8')
    anchor = """            foreach (['user_id', 'notice_type', 'source_type', 'source_id', 'message', 'created_date'] as $field)\n            {\n                if ($this->isChanged($field))\n                {\n                    $this->error('Bildirim kaydının özgün alanları değiştirilemez.', $field);\n                    break;\n                }\n            }\n"""
    hardened = anchor + """\n            if ((int)$this->getExistingValue('read_date') > 0 && $this->isChanged('read_date'))\n            {\n                $this->error('Okunmuş denetim bildirimi tekrar okunmamış duruma getirilemez.', 'read_date');\n            }\n"""
    if 'tekrar okunmamış duruma getirilemez' not in text:
        if anchor not in text:
            raise RuntimeError('Notice immutability anchor not found')
        text = text.replace(anchor, hardened, 1)
    path.write_text(text, encoding='utf-8')


def remove_get_side_effect():
    path = ROOT / 'Pub/Controller/Governance.php'
    text = path.read_text(encoding='utf-8')
    block = """        if ($isManager)\n        {\n            try\n            {\n                $this->governance()->scan();\n            }\n            catch (\\Throwable $e)\n            {\n                \\XF::logException($e, false, 'Warext ModerationAudit governance dashboard scan: ');\n            }\n        }\n\n"""
    if block in text:
        text = text.replace(block, '', 1)
    path.write_text(text, encoding='utf-8')


def fix_case_appeal_button():
    path = ROOT / '_data/templates.xml'
    text = path.read_text(encoding='utf-8')
    old = """<xf:if is=\"$canManageAudit\"><a class=\"button\" href=\"{{ link('denetim-geribildirim/olustur', null, {'type':'appeal','case_id':$caseView.case_id}) }}\">Bu vaka için itiraz</a></xf:if>"""
    new = """<xf:if is=\"$canManageAudit OR ($xf.visitor.hasPermission('general', 'warextAuditAppeal') AND $caseView.moderator_user_id == $xf.visitor.user_id)\"><a class=\"button\" href=\"{{ link('denetim-geribildirim/olustur', null, {'type':'appeal','case_id':$caseView.case_id}) }}\">Bu vaka için itiraz</a></xf:if>"""
    if old in text:
        text = text.replace(old, new, 1)
    elif new not in text:
        raise RuntimeError('Case appeal button anchor not found')
    path.write_text(text, encoding='utf-8')


def improve_governance_query():
    path = ROOT / 'Pub/Controller/Governance.php'
    text = path.read_text(encoding='utf-8')
    old = """        else\n        {\n            $all = $finder->limit(200)->fetch();\n            foreach ($all as $escalation)\n            {\n                if ($this->canViewEscalation($escalation, (int)$visitor->user_id))\n                {\n                    $escalations[] = $escalation;\n                    if (!(int)$escalation->resolved_date)\n                    {\n                        $activeCount++;\n                    }\n                }\n            }\n        }\n"""
    new = """        else\n        {\n            $userId = (int)$visitor->user_id;\n            $escalationIds = \\XF::db()->fetchAllColumn(\n                'SELECT e.escalation_id\n                 FROM xf_warext_audit_escalation AS e\n                 LEFT JOIN xf_warext_audit_case AS c\n                    ON (e.source_type = ? AND c.case_id = e.source_id)\n                 LEFT JOIN xf_warext_audit_feedback AS f\n                    ON (e.source_type = ? AND f.feedback_id = e.source_id)\n                 WHERE c.moderator_user_id = ?\n                    OR f.submitted_by_user_id = ?\n                    OR f.assigned_to_user_id = ?\n                 ORDER BY e.created_date DESC\n                 LIMIT 100',\n                ['case', 'feedback', $userId, $userId, $userId]\n            );\n\n            if ($escalationIds)\n            {\n                $escalations = $this->finder('Warext\\ModerationAudit:AuditEscalation')\n                    ->where('escalation_id', $escalationIds)\n                    ->order('created_date', 'DESC')\n                    ->fetch();\n                foreach ($escalations as $escalation)\n                {\n                    if (!(int)$escalation->resolved_date)\n                    {\n                        $activeCount++;\n                    }\n                }\n            }\n        }\n"""
    if new not in text:
        if old not in text:
            raise RuntimeError('Governance user query anchor not found')
        text = text.replace(old, new, 1)
    path.write_text(text, encoding='utf-8')


def update_readme():
    path = Path('README.md')
    text = path.read_text(encoding='utf-8')
    text = text.replace('**1.0.0 Alpha 9**', '**1.0.0**')
    text = text.replace('Warext-ModerationAudit-1.0.0-Alpha9.zip', 'Warext-ModerationAudit-1.0.0.zip')
    old_status = 'Alpha 9 geliştirme sürümüdür. PHP 8.4 sözdizimi, XML/JSON, ZIP ve SHA-256 manifest kontrollerinden geçirilmiştir. Canlı XenForo 2.3 kurulumu üzerinde runtime testi henüz tamamlanmamıştır.'
    new_status = '1.0.0 stable kaynak/paket sürümüdür. Minimum PHP 8.1 sözdizimi, PHP/XML/JSON, route-controller, cron-callback, controller-template, izin tanımı, entity-veritabanı şeması, sınıf aliasları, ZIP ve SHA-256 manifest kontrollerinden geçirilmiştir. Lisanslı canlı XenForo 2.3 kurulumu üzerinde runtime testi CI ortamında yapılamadığından bu kontrol ayrıca gerçek kurulumda yapılmalıdır.'
    if old_status in text:
        text = text.replace(old_status, new_status)
    if '## 1.0.0 — Adım 10/10' not in text:
        text += '''\n\n## 1.0.0 — Adım 10/10\n\n- Final kaynak ve paket sağlamlaştırması tamamlandı.\n- Normal kullanıcıların yüksek/kritik öncelikle kendi SLA sürelerini yapay biçimde kısaltması engellendi.\n- Çözümlenmiş eskalasyon kapanış kayıtları değiştirilemez hale getirildi.\n- Okunmuş denetim bildirimlerinin tekrar okunmamış duruma çevrilmesi engellendi.\n- Takip sayfasının GET isteğinde SLA taraması/veri yazması kaldırıldı; tarama yalnızca cron veya açık yönetici POST işlemiyle çalışır.\n- Normal kullanıcı eskalasyon sorgusu yalnızca ilişkili vaka/başvurular üzerinden veritabanında filtrelenecek şekilde optimize edildi.\n- Vaka ekranındaki itiraz düğmesi kendi işlemi olan ve itiraz yetkisine sahip kullanıcılar için düzeltildi.\n- Route/controller, cron callback, template, permission, entity/DB kolon ve sınıf alias çapraz kontrolleri final doğrulamaya eklendi.\n- Kurulum ZIP'i yalnızca `upload/` ağacını içerir; geliştirme ve CI dosyaları pakete girmez.\n'''
    path.write_text(text, encoding='utf-8')


def main():
    update_addon()
    harden_feedback_priority()
    harden_escalation_entity()
    harden_notice_entity()
    remove_get_side_effect()
    improve_governance_query()
    fix_case_appeal_button()
    update_readme()
    print('Stable 1.0.0 hardening applied')


if __name__ == '__main__':
    main()
