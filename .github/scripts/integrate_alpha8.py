from pathlib import Path
import json
import xml.etree.ElementTree as ET

ROOT = Path('upload/src/addons/Warext/ModerationAudit')


def update_addon():
    path = ROOT / 'addon.json'
    addon = json.loads(path.read_text(encoding='utf-8'))
    addon['description'] = 'Independent moderation audit layer with immutable evidence, blind reviewer assignment, conflict recusal, multi-review scoring, management analytics, appeals, suggestions and auditable management responses for XenForo.'
    addon['version_id'] = 1000080
    addon['version_string'] = '1.0.0 Alpha 8'
    path.write_text(json.dumps(addon, ensure_ascii=False, indent=4) + '\n', encoding='utf-8')


def feedback_table_code(indent='        '):
    return r'''        $sm->createTable('xf_warext_audit_feedback', function (Create $table)
        {
            $table->checkExists(true);
            $table->addColumn('feedback_id', 'int')->autoIncrement();
            $table->addColumn('feedback_type', 'enum')->values(['appeal', 'suggestion'])->setDefault('suggestion');
            $table->addColumn('case_id', 'int')->setDefault(0);
            $table->addColumn('submitted_by_user_id', 'int')->setDefault(0);
            $table->addColumn('subject', 'varchar', 150)->setDefault('');
            $table->addColumn('message', 'mediumblob');
            $table->addColumn('status', 'enum')->values(['open', 'under_review', 'accepted', 'rejected', 'implemented', 'closed'])->setDefault('open');
            $table->addColumn('priority', 'enum')->values(['low', 'normal', 'high', 'critical'])->setDefault('normal');
            $table->addColumn('assigned_to_user_id', 'int')->setDefault(0);
            $table->addColumn('last_response_date', 'int')->setDefault(0);
            $table->addColumn('created_date', 'int')->setDefault(0);
            $table->addColumn('updated_date', 'int')->setDefault(0);
            $table->addPrimaryKey('feedback_id');
            $table->addKey(['feedback_type', 'status', 'created_date'], 'type_status_created');
            $table->addKey(['submitted_by_user_id', 'created_date'], 'submitter_created');
            $table->addKey(['case_id', 'created_date'], 'case_created');
            $table->addKey(['assigned_to_user_id', 'status'], 'assignee_status');
        });

        $sm->createTable('xf_warext_audit_feedback_event', function (Create $table)
        {
            $table->checkExists(true);
            $table->addColumn('event_id', 'int')->autoIncrement();
            $table->addColumn('feedback_id', 'int')->setDefault(0);
            $table->addColumn('event_type', 'varchar', 32)->setDefault('');
            $table->addColumn('actor_user_id', 'int')->setDefault(0);
            $table->addColumn('from_status', 'varchar', 25)->setDefault('');
            $table->addColumn('to_status', 'varchar', 25)->setDefault('');
            $table->addColumn('event_data', 'mediumblob');
            $table->addColumn('data_hash', 'varbinary', 64)->setDefault('');
            $table->addColumn('created_date', 'int')->setDefault(0);
            $table->addPrimaryKey('event_id');
            $table->addKey(['feedback_id', 'created_date'], 'feedback_created');
            $table->addKey(['actor_user_id', 'created_date'], 'actor_created');
        });

'''


def update_setup():
    path = ROOT / 'Setup.php'
    text = path.read_text(encoding='utf-8')

    if "xf_warext_audit_feedback'" not in text:
        anchor = "        $sm->createTable('xf_warext_audit_state', function (Create $table)\n"
        if anchor not in text:
            raise RuntimeError('Setup install anchor not found')
        text = text.replace(anchor, feedback_table_code() + anchor, 1)

    text = text.replace("'schema_version' => '4',", "'schema_version' => '5',", 1)

    if 'upgrade1000080Step1' not in text:
        anchor = '    public function uninstallStep1(): void\n'
        upgrade = r'''    public function upgrade1000080Step1(): void
    {
        $sm = $this->schemaManager();

''' + feedback_table_code() + r'''        $this->db()->insert('xf_warext_audit_state', [
            'state_key' => 'schema_version',
            'state_value' => '5',
            'updated_date' => time()
        ], false, 'state_value = VALUES(state_value), updated_date = VALUES(updated_date)');
    }

'''
        if anchor not in text:
            raise RuntimeError('Setup upgrade anchor not found')
        text = text.replace(anchor, upgrade + anchor, 1)

    uninstall_section = text.split('public function uninstallStep1', 1)[-1]
    if "'xf_warext_audit_feedback_event'" not in uninstall_section:
        anchor = "        $tables = [\n            'xf_warext_audit_review_revision',"
        replacement = "        $tables = [\n            'xf_warext_audit_feedback_event',\n            'xf_warext_audit_feedback',\n            'xf_warext_audit_review_revision',"
        if anchor not in text:
            raise RuntimeError('Setup uninstall anchor not found')
        text = text.replace(anchor, replacement, 1)

    path.write_text(text, encoding='utf-8')


def update_routes():
    path = ROOT / '_data/routes.xml'
    tree = ET.parse(path)
    root = tree.getroot()
    if not any(x.get('route_prefix') == 'denetim-geribildirim' for x in root.findall('route')):
        ET.SubElement(root, 'route', {
            'route_type': 'public',
            'route_prefix': 'denetim-geribildirim',
            'controller': r'Warext\ModerationAudit:Feedback',
            'context': 'warextAudit'
        })
    ET.indent(tree, space='  ')
    tree.write(path, encoding='utf-8', xml_declaration=True)


def update_permissions():
    path = ROOT / '_data/permissions.xml'
    tree = ET.parse(path)
    root = tree.getroot()
    existing = {x.get('permission_id') for x in root.findall('permission')}
    specs = [
        ('warextAuditAppeal', '60'),
        ('warextAuditSuggest', '70')
    ]
    for permission_id, order in specs:
        if permission_id not in existing:
            ET.SubElement(root, 'permission', {
                'permission_group_id': 'general',
                'permission_id': permission_id,
                'permission_type': 'flag',
                'interface_group_id': 'warextAudit',
                'display_order': order
            })
    ET.indent(tree, space='  ')
    tree.write(path, encoding='utf-8', xml_declaration=True)


def update_phrases():
    path = ROOT / '_data/phrases.xml'
    tree = ET.parse(path)
    root = tree.getroot()
    existing = {x.get('title') for x in root.findall('phrase')}
    specs = {
        'permission_general_warextAuditAppeal': 'Kendi denetim vakaları için itiraz oluşturabilir',
        'permission_general_warextAuditSuggest': 'Denetim sistemi için öneri oluşturabilir'
    }
    for title, value in specs.items():
        if title not in existing:
            el = ET.SubElement(root, 'phrase', {
                'title': title,
                'version_id': '1000080',
                'version_string': '1.0.0 Alpha 8'
            })
            el.text = value
    ET.indent(tree, space='  ')
    tree.write(path, encoding='utf-8', xml_declaration=True)


TEMPLATE_BLOCK = r'''

  <template type="public" title="warext_audit_feedback_index" version_id="1000080" version_string="1.0.0 Alpha 8"><![CDATA[<xf:title>İtiraz ve Öneri Merkezi</xf:title>
<xf:css src="warext_audit.less" />
<div class="buttonGroup">
    <a class="button" href="{{ link('denetim') }}">← Denetim</a>
    <xf:if is="$canAppeal OR $isManager"><a class="button button--primary" href="{{ link('denetim-geribildirim/olustur', null, {'type':'appeal'}) }}">Yeni itiraz</a></xf:if>
    <xf:if is="$canSuggest OR $isManager"><a class="button" href="{{ link('denetim-geribildirim/olustur', null, {'type':'suggestion'}) }}">Yeni öneri</a></xf:if>
</div>
<div class="blockMessage blockMessage--important">İtiraz ve önerilerin özgün metni sonradan değiştirilemez. Yönetim cevapları ve durum değişiklikleri ayrı, bütünlüğü doğrulanabilir olay kayıtları olarak saklanır.</div>
<div class="block"><div class="block-container"><h2 class="block-header">Filtreler</h2><div class="block-body">
<form action="{{ link('denetim-geribildirim') }}" method="get">
    <select name="type" class="input"><option value="">Tüm türler</option><option value="appeal" {{ $type == 'appeal' ? 'selected' : '' }}>İtiraz</option><option value="suggestion" {{ $type == 'suggestion' ? 'selected' : '' }}>Öneri</option></select>
    <select name="status" class="input"><option value="">Tüm durumlar</option><xf:foreach loop="$statusLabels" key="$value" value="$label"><option value="{$value}" {{ $status == $value ? 'selected' : '' }}>{$label}</option></xf:foreach></select>
    <button class="button" type="submit">Filtrele</button>
</form></div></div></div>
<div class="block"><div class="block-container"><h2 class="block-header">{{ $isManager ? 'Tüm başvurular' : 'Başvurularım' }} <span class="block-desc">{$total} kayıt</span></h2><div class="block-body">
<xf:if is="$feedback"><div class="structItemContainer"><xf:foreach loop="$feedback" value="$item"><div class="structItem structItem--thread"><div class="structItem-cell structItem-cell--main"><div class="structItem-title"><a href="{{ link('denetim-geribildirim/goruntule', null, {'feedback_id':$item.feedback_id}) }}">#{$item.feedback_id} {$item.subject}</a></div><div class="structItem-minor"><span>{{ $item.feedback_type == 'appeal' ? 'İtiraz' : 'Öneri' }}</span> · <span>Durum: {$item.status}</span> · <span>Öncelik: {$item.priority}</span><xf:if is="$item.case_id"> · <span>Vaka #{$item.case_id}</span></xf:if><xf:if is="$isManager"> · <span>Gönderen: {$item.Submitter.username}</span></xf:if> · <xf:date time="$item.created_date" /></div></div></div></xf:foreach></div><xf:else /><div class="blockMessage">Başvuru bulunamadı.</div></xf:if>
</div></div></div>
<xf:pagenav page="$page" perpage="$perPage" total="$total" link="denetim-geribildirim" />]]></template>

  <template type="public" title="warext_audit_feedback_create" version_id="1000080" version_string="1.0.0 Alpha 8"><![CDATA[<xf:title>{{ $type == 'appeal' ? 'Yeni itiraz' : 'Yeni öneri' }}</xf:title>
<xf:css src="warext_audit.less" />
<div class="buttonGroup"><a class="button" href="{{ link('denetim-geribildirim') }}">← İtiraz ve Öneri Merkezi</a><xf:if is="$canAppeal"><a class="button" href="{{ link('denetim-geribildirim/olustur', null, {'type':'appeal'}) }}">İtiraz</a></xf:if><xf:if is="$canSuggest"><a class="button" href="{{ link('denetim-geribildirim/olustur', null, {'type':'suggestion'}) }}">Öneri</a></xf:if></div>
<xf:if is="$type == 'appeal'"><div class="blockMessage blockMessage--important">İtiraz yalnızca kendi moderasyon işleminle ilişkili denetim vakası için açılabilir. İtiraz açılması mevcut denetim kararını otomatik olarak değiştirmez.</div><xf:else /><div class="blockMessage">Öneriler genel sistem iyileştirmeleri içindir ve bir denetim vakasına bağlı olmak zorunda değildir.</div></xf:if>
<div class="block"><div class="block-container"><xf:form action="{{ link('denetim-geribildirim/olustur') }}" class="block-body">
    <input type="hidden" name="type" value="{$type}" />
    <xf:if is="$type == 'appeal'"><xf:formrow label="Denetim vaka ID"><input class="input" type="number" name="case_id" min="1" value="{$caseId}" required="required" /></xf:formrow></xf:if>
    <xf:formrow label="Konu"><input class="input" type="text" name="subject" maxlength="150" required="required" /></xf:formrow>
    <xf:formrow label="Öncelik"><select class="input" name="priority"><xf:foreach loop="$priorityLabels" key="$value" value="$label"><option value="{$value}" {{ $value == 'normal' ? 'selected' : '' }}>{$label}</option></xf:foreach></select></xf:formrow>
    <xf:formrow label="Açıklama"><textarea class="input" name="message" rows="10" maxlength="10000" required="required"></textarea></xf:formrow>
    <xf:submitrow submit="Başvuruyu oluştur" />
</xf:form></div></div>]]></template>

  <template type="public" title="warext_audit_feedback_view" version_id="1000080" version_string="1.0.0 Alpha 8"><![CDATA[<xf:title>#{$feedback.feedback_id} {$feedback.subject}</xf:title>
<xf:css src="warext_audit.less" />
<div class="buttonGroup"><a class="button" href="{{ link('denetim-geribildirim') }}">← İtiraz ve Öneri Merkezi</a><xf:if is="$feedback.case_id AND $xf.visitor.hasPermission('general', 'warextAuditView')"><a class="button" href="{{ link('denetim/vaka', null, {'case_id':$feedback.case_id}) }}">Denetim vakası #{$feedback.case_id}</a></xf:if></div>
<div class="block"><div class="block-container"><h2 class="block-header">Başvuru</h2><div class="block-body"><div class="pairs pairs--columns pairs--fixedSmall"><dl><dt>Tür</dt><dd>{{ $feedback.feedback_type == 'appeal' ? 'İtiraz' : 'Öneri' }}</dd></dl><dl><dt>Durum</dt><dd>{$feedback.status}</dd></dl><dl><dt>Öncelik</dt><dd>{$feedback.priority}</dd></dl><dl><dt>Gönderen</dt><dd>{$feedback.Submitter.username}</dd></dl><xf:if is="$feedback.case_id"><dl><dt>Vaka</dt><dd>#{$feedback.case_id}</dd></dl></xf:if><dl><dt>Oluşturuldu</dt><dd><xf:date time="$feedback.created_date" /></dd></dl></div><hr class="formRowSep" /><div class="message-userContent">{$feedback.message}</div></div></div></div>
<div class="block"><div class="block-container"><h2 class="block-header">Değiştirilemez olay geçmişi</h2><div class="block-body"><xf:foreach loop="$eventRows" value="$row"><div class="message message--simple"><div class="message-inner"><div class="message-cell message-cell--main"><div class="message-attribution"><b>{$row.event.event_type}</b> · {$row.event.Actor.username} · <xf:date time="$row.event.created_date" /> · <xf:if is="$row.integrity_valid"><span class="label label--green">SHA-256 doğrulandı</span><xf:else /><span class="label label--red">Bütünlük hatası</span></xf:if></div><xf:if is="$row.data.response"><div class="message-userContent">{$row.data.response}</div></xf:if><xf:if is="$row.event.from_status OR $row.event.to_status"><div class="contentRow-minor">{$row.event.from_status} → {$row.event.to_status}</div></xf:if><div class="contentRow-minor"><code>{$row.event.data_hash}</code></div></div></div></div></xf:foreach></div></div></div>
<xf:if is="$isManager"><div class="block"><div class="block-container"><h2 class="block-header">Yönetim dönüşü</h2><xf:form action="{{ link('denetim-geribildirim/yonetim', null, {'feedback_id':$feedback.feedback_id}) }}" class="block-body"><xf:formrow label="Yeni durum"><select class="input" name="status"><xf:foreach loop="$statusLabels" key="$value" value="$label"><option value="{$value}" {{ $feedback.status == $value ? 'selected' : '' }}>{$label}</option></xf:foreach></select></xf:formrow><xf:formrow label="Atanan kullanıcı ID"><input class="input" type="number" name="assigned_to_user_id" min="0" value="{$feedback.assigned_to_user_id}" /></xf:formrow><xf:formrow label="Yönetim cevabı"><textarea class="input" name="response" rows="8" maxlength="10000" required="required"></textarea></xf:formrow><xf:submitrow submit="Dönüşü kaydet" /></xf:form></div></div></xf:if>]]></template>
'''


def update_templates():
    path = ROOT / '_data/templates.xml'
    text = path.read_text(encoding='utf-8')
    if 'title="warext_audit_feedback_index"' not in text:
        text = text.replace('</templates>', TEMPLATE_BLOCK + '\n</templates>', 1)

    marker = '<div class="warextAudit-toolbar">\n'
    feedback_link = '    <a class="button" href="{{ link(\'denetim-geribildirim\') }}">İtiraz / Öneri</a>\n'
    if feedback_link not in text and marker in text:
        text = text.replace(marker, marker + feedback_link, 1)

    case_marker = '<div class="warextAudit-toolbar"><a class="button" href="{{ link(\'denetim\') }}">← Denetim merkezine dön</a>'
    case_segment = text.split('title="warext_audit_case"', 1)[-1].split('</template>', 1)[0]
    if case_marker in text and 'Bu vaka için itiraz' not in case_segment:
        repl = case_marker + '<xf:if is="$canManageAudit"><a class="button" href="{{ link(\'denetim-geribildirim/olustur\', null, {\'type\':\'appeal\',\'case_id\':$caseView.case_id}) }}">Bu vaka için itiraz</a></xf:if>'
        text = text.replace(case_marker, repl, 1)

    path.write_text(text, encoding='utf-8')


def update_readme():
    path = Path('README.md')
    text = path.read_text(encoding='utf-8') if path.exists() else '# Warext Moderation Audit\n'
    if '1.0.0 Alpha 8' not in text:
        text += '''\n\n## 1.0.0 Alpha 8 — Adım 8/10\n\n- İtiraz ve öneri merkezi eklendi.\n- İtirazlar denetim vakasına bağlanıyor; standart kullanıcı yalnızca kendi moderasyon işlemi için itiraz açabiliyor.\n- Özgün başvuru içeriği değiştirilemiyor veya silinemiyor.\n- Yönetim cevapları, atama ve durum geçişleri ayrı olay geçmişinde tutuluyor.\n- Her olay SHA-256 bütünlük doğrulamasına sahip.\n- Yeni izinler: `warextAuditAppeal`, `warextAuditSuggest`.\n- Alpha 8 şema sürümü: 5.\n'''
        path.write_text(text, encoding='utf-8')


def main():
    update_addon()
    update_setup()
    update_routes()
    update_permissions()
    update_phrases()
    update_templates()
    update_readme()
    print('Alpha 8 integration applied')


if __name__ == '__main__':
    main()
