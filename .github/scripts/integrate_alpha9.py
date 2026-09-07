from pathlib import Path
import json
import xml.etree.ElementTree as ET

ROOT = Path('upload/src/addons/Warext/ModerationAudit')


def update_addon():
    path = ROOT / 'addon.json'
    addon = json.loads(path.read_text(encoding='utf-8'))
    addon['description'] = 'Independent moderation audit layer with immutable evidence, blind reviewer assignment, analytics, appeals, suggestions, SLA tracking, escalation and auditable governance for XenForo.'
    addon['version_id'] = 1000090
    addon['version_string'] = '1.0.0 Alpha 9'
    path.write_text(json.dumps(addon, ensure_ascii=False, indent=4) + '\n', encoding='utf-8')


def governance_tables():
    return r'''        $sm->createTable('xf_warext_audit_escalation', function (Create $table)
        {
            $table->checkExists(true);
            $table->addColumn('escalation_id', 'int')->autoIncrement();
            $table->addColumn('source_type', 'enum')->values(['case', 'feedback'])->setDefault('case');
            $table->addColumn('source_id', 'int')->setDefault(0);
            $table->addColumn('level', 'tinyint')->setDefault(1);
            $table->addColumn('reason', 'varchar', 255)->setDefault('');
            $table->addColumn('created_date', 'int')->setDefault(0);
            $table->addColumn('event_hash', 'varbinary', 64)->setDefault('');
            $table->addColumn('resolved_by_user_id', 'int')->setDefault(0);
            $table->addColumn('resolved_date', 'int')->setDefault(0);
            $table->addColumn('resolution_note', 'mediumblob');
            $table->addColumn('resolution_hash', 'varbinary', 64)->setDefault('');
            $table->addPrimaryKey('escalation_id');
            $table->addUniqueKey(['source_type', 'source_id', 'level'], 'source_level');
            $table->addKey(['resolved_date', 'created_date'], 'resolved_created');
            $table->addKey(['source_type', 'created_date'], 'source_created');
        });

        $sm->createTable('xf_warext_audit_notice', function (Create $table)
        {
            $table->checkExists(true);
            $table->addColumn('notice_id', 'int')->autoIncrement();
            $table->addColumn('user_id', 'int')->setDefault(0);
            $table->addColumn('notice_type', 'varchar', 32)->setDefault('');
            $table->addColumn('source_type', 'varchar', 20)->setDefault('');
            $table->addColumn('source_id', 'int')->setDefault(0);
            $table->addColumn('message', 'varchar', 255)->setDefault('');
            $table->addColumn('created_date', 'int')->setDefault(0);
            $table->addColumn('read_date', 'int')->setDefault(0);
            $table->addPrimaryKey('notice_id');
            $table->addUniqueKey(['user_id', 'notice_type', 'source_type', 'source_id'], 'user_notice_source');
            $table->addKey(['user_id', 'read_date', 'created_date'], 'user_read_created');
        });

'''


def update_setup():
    path = ROOT / 'Setup.php'
    text = path.read_text(encoding='utf-8')

    if "xf_warext_audit_escalation'" not in text:
        anchor = "        $sm->createTable('xf_warext_audit_state', function (Create $table)\n"
        if anchor not in text:
            raise RuntimeError('install anchor missing')
        text = text.replace(anchor, governance_tables() + anchor, 1)

    text = text.replace("'schema_version' => '5',", "'schema_version' => '6',", 1)

    state_anchor = "            'critical_blind_mode' => 'full'\n"
    if "'sla_case_normal_hours'" not in text.split('public function installStep2', 1)[-1].split('public function upgrade', 1)[0]:
        replacement = "            'critical_blind_mode' => 'full',\n            'sla_case_normal_hours' => '72',\n            'sla_case_elevated_hours' => '36',\n            'sla_case_critical_hours' => '12',\n            'sla_feedback_low_hours' => '96',\n            'sla_feedback_normal_hours' => '48',\n            'sla_feedback_high_hours' => '24',\n            'sla_feedback_critical_hours' => '6'\n"
        if state_anchor not in text:
            raise RuntimeError('install state anchor missing')
        text = text.replace(state_anchor, replacement, 1)

    if 'upgrade1000090Step1' not in text:
        anchor = '    public function uninstallStep1(): void\n'
        upgrade = r'''    public function upgrade1000090Step1(): void
    {
        $sm = $this->schemaManager();

''' + governance_tables() + r'''        $now = time();
        foreach ([
            'schema_version' => '6',
            'sla_case_normal_hours' => '72',
            'sla_case_elevated_hours' => '36',
            'sla_case_critical_hours' => '12',
            'sla_feedback_low_hours' => '96',
            'sla_feedback_normal_hours' => '48',
            'sla_feedback_high_hours' => '24',
            'sla_feedback_critical_hours' => '6'
        ] as $key => $value)
        {
            $this->db()->insert('xf_warext_audit_state', [
                'state_key' => $key,
                'state_value' => $value,
                'updated_date' => $now
            ], false, 'state_value = VALUES(state_value), updated_date = VALUES(updated_date)');
        }
    }

'''
        if anchor not in text:
            raise RuntimeError('upgrade anchor missing')
        text = text.replace(anchor, upgrade + anchor, 1)

    uninstall = text.split('public function uninstallStep1', 1)[-1]
    if "'xf_warext_audit_escalation'" not in uninstall:
        anchor = "        $tables = [\n"
        replacement = "        $tables = [\n            'xf_warext_audit_notice',\n            'xf_warext_audit_escalation',\n"
        text = text.replace(anchor, replacement, 1)

    path.write_text(text, encoding='utf-8')


def update_routes():
    path = ROOT / '_data/routes.xml'
    tree = ET.parse(path)
    root = tree.getroot()
    if not any(x.get('route_prefix') == 'denetim-takip' for x in root.findall('route')):
        ET.SubElement(root, 'route', {
            'route_type': 'public',
            'route_prefix': 'denetim-takip',
            'controller': r'Warext\ModerationAudit:Governance',
            'context': 'warextAudit'
        })
    ET.indent(tree, space='  ')
    tree.write(path, encoding='utf-8', xml_declaration=True)


def update_cron():
    path = ROOT / '_data/cron_entries.xml'
    tree = ET.parse(path)
    root = tree.getroot()
    if not any(x.get('cron_entry_id') == 'warextAuditGovernance' for x in root.findall('cron_entry')):
        entry = ET.SubElement(root, 'cron_entry', {
            'cron_entry_id': 'warextAuditGovernance',
            'addon_id': 'Warext/ModerationAudit',
            'run_rules': '{"minutes":[5],"hours":[],"days_type":"dom","days":[]}'
        })
        title = ET.SubElement(entry, 'title')
        title.text = 'Warext ModerationAudit: SLA ve eskalasyon taraması'
        ET.SubElement(entry, 'callback', {
            'class': r'Warext\ModerationAudit\Cron\Governance',
            'method': 'run'
        })
        active = ET.SubElement(entry, 'active')
        active.text = '1'
    ET.indent(tree, space='  ')
    tree.write(path, encoding='utf-8', xml_declaration=True)


GOVERNANCE_TEMPLATE = r'''

  <template type="public" title="warext_audit_governance" version_id="1000090" version_string="1.0.0 Alpha 9"><![CDATA[<xf:title>Denetim Takip ve Eskalasyon</xf:title>
<xf:css src="warext_audit.less" />
<div class="warextAudit-toolbar">
    <a class="button" href="{{ link('denetim') }}">← Denetim merkezi</a>
    <a class="button" href="{{ link('denetim-geribildirim') }}">İtiraz / Öneri</a>
    <xf:if is="$isManager"><xf:form action="{{ link('denetim-takip/tara') }}" class="u-inlineBlock"><button class="button button--primary" type="submit">SLA taramasını çalıştır</button></xf:form></xf:if>
</div>

<div class="warextAudit-stats">
    <div class="warextAudit-stat"><span class="warextAudit-statValue">{$activeCount}</span><span class="warextAudit-statLabel">Aktif eskalasyon</span></div>
    <div class="warextAudit-stat"><span class="warextAudit-statValue">{$unreadCount}</span><span class="warextAudit-statLabel">Okunmamış bildirim</span></div>
</div>

<div class="blockMessage blockMessage--important">SLA ihlalleri silinmez. Eskalasyonun özgün kaydı SHA-256 ile doğrulanır; çözüm işlemi ayrıca hash'li bir kapanış izi bırakır.</div>

<div class="block"><div class="block-container"><h2 class="block-header">Bildirimlerim</h2><div class="block-body">
<xf:if is="$notices"><xf:foreach loop="$notices" value="$notice"><div class="contentRow {{ !$notice.read_date ? 'is-unread' : '' }}"><div class="contentRow-main"><b>{$notice.message}</b><div class="contentRow-minor">{$notice.source_type} #{$notice.source_id} · <xf:date time="$notice.created_date" /></div></div><xf:if is="!$notice.read_date"><div class="contentRow-extra"><xf:form action="{{ link('denetim-takip/oku', null, {'notice_id':$notice.notice_id}) }}"><button type="submit" class="button button--small">Okundu</button></xf:form></div></xf:if></div></xf:foreach><xf:else /><div class="blockMessage">Bildirim bulunmuyor.</div></xf:if>
</div></div></div>

<div class="block"><div class="block-container"><h2 class="block-header">Eskalasyon geçmişi</h2><div class="block-body">
<xf:if is="$escalationRows"><xf:foreach loop="$escalationRows" value="$row"><div class="message message--simple"><div class="message-inner"><div class="message-cell message-cell--main"><div class="message-attribution"><b>Seviye {$row.entity.level}</b> · {$row.entity.source_type} #{$row.entity.source_id} · <xf:date time="$row.entity.created_date" /> · <xf:if is="$row.integrity_valid"><span class="label label--green">Kayıt doğrulandı</span><xf:else /><span class="label label--red">Kayıt bütünlüğü hatalı</span></xf:if></div><div class="message-userContent">{$row.entity.reason}</div><xf:if is="$row.entity.resolved_date"><div class="blockMessage">Çözüldü: {$row.entity.resolution_note} · <xf:date time="$row.entity.resolved_date" /> · <xf:if is="$row.resolution_valid"><span class="label label--green">Çözüm doğrulandı</span><xf:else /><span class="label label--red">Çözüm bütünlüğü hatalı</span></xf:if></div><xf:elseif is="$isManager" /><xf:form action="{{ link('denetim-takip/coz', null, {'escalation_id':$row.entity.escalation_id}) }}"><textarea class="input" name="resolution_note" rows="2" maxlength="10000" placeholder="Çözüm notu" required="required"></textarea><button class="button button--small button--primary" type="submit">Eskalasyonu çöz</button></xf:form></xf:if><div class="contentRow-minor"><code>{$row.entity.event_hash}</code></div></div></div></div></xf:foreach><xf:else /><div class="blockMessage">Görüntülenebilir eskalasyon kaydı yok.</div></xf:if>
</div></div></div>]]></template>
'''


def update_templates():
    path = ROOT / '_data/templates.xml'
    text = path.read_text(encoding='utf-8')
    if 'title="warext_audit_governance"' not in text:
        text = text.replace('</templates>', GOVERNANCE_TEMPLATE + '\n</templates>', 1)

    link = '    <a class="button" href="{{ link(\'denetim-takip\') }}">Takip / Eskalasyon</a>\n'
    first_toolbar = '<div class="warextAudit-toolbar">\n'
    if link not in text and first_toolbar in text:
        text = text.replace(first_toolbar, first_toolbar + link, 1)

    feedback_title = 'title="warext_audit_feedback_index"'
    if feedback_title in text:
        segment_start = text.index(feedback_title)
        segment_end = text.find('</template>', segment_start)
        segment = text[segment_start:segment_end]
        if "denetim-takip" not in segment:
            marker = '<div class="buttonGroup">\n'
            pos = text.find(marker, segment_start, segment_end)
            if pos != -1:
                insert_at = pos + len(marker)
                text = text[:insert_at] + '    <a class="button" href="{{ link(\'denetim-takip\') }}">Takip / Eskalasyon</a>\n' + text[insert_at:]

    path.write_text(text, encoding='utf-8')


def update_controller_safety():
    path = ROOT / 'Pub/Controller/Governance.php'
    text = path.read_text(encoding='utf-8')
    text = text.replace("$this->app()->em()->find", "\\XF::em()->find")
    path.write_text(text, encoding='utf-8')


def update_readme():
    path = Path('README.md')
    text = path.read_text(encoding='utf-8')
    text = text.replace('**1.0.0 Alpha 7**', '**1.0.0 Alpha 9**')
    text = text.replace('Warext-ModerationAudit-1.0.0-Alpha7.zip', 'Warext-ModerationAudit-1.0.0-Alpha9.zip')
    text = text.replace('Alpha 7 geliştirme sürümüdür.', 'Alpha 9 geliştirme sürümüdür.')
    if '## 1.0.0 Alpha 9 — Adım 9/10' not in text:
        text += '''\n\n## 1.0.0 Alpha 9 — Adım 9/10\n\n- Risk ve öncelik bazlı SLA süreleri eklendi.\n- Denetim vakaları için normal 72s, yükseltilmiş 36s, kritik 12s varsayılan SLA.\n- İtiraz/öneriler için düşük 96s, normal 48s, yüksek 24s, kritik 6s varsayılan SLA.\n- SLA ihlalleri 3 seviyeli eskalasyon kaydı üretir ve aynı seviye tekrarlanmaz.\n- Eskalasyon başlangıcı ve çözümü ayrı SHA-256 bütünlük kontrolüne sahiptir.\n- Kullanıcıya bağlı dahili denetim bildirim merkezi eklendi.\n- Sonuçlanan vaka/başvuruların açık eskalasyonları sistem tarafından iz bırakarak kapatılır.\n- Saatlik XenForo cron taraması ve yönetici manuel tarama düğmesi eklendi.\n- Yeni `/denetim-takip/` takip ve eskalasyon merkezi eklendi.\n- Alpha 9 şema sürümü: 6.\n'''
    path.write_text(text, encoding='utf-8')


def main():
    update_addon()
    update_setup()
    update_routes()
    update_cron()
    update_templates()
    update_controller_safety()
    update_readme()
    print('Alpha 9 integration applied')


if __name__ == '__main__':
    main()
