from pathlib import Path
import json

ROOT = Path('upload/src/addons/Warext/ModerationAudit')
DATA = ROOT / '_data'


def write(path: Path, content: str):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(content, encoding='utf-8')


def insert_before(path: Path, closing: str, payload: str, marker: str):
    text = path.read_text(encoding='utf-8')
    if marker in text:
        return
    if closing not in text:
        raise RuntimeError(f'Closing marker {closing!r} not found in {path}')
    text = text.replace(closing, payload.rstrip() + '\n' + closing, 1)
    path.write_text(text, encoding='utf-8')


def update_addon():
    path = ROOT / 'addon.json'
    data = json.loads(path.read_text(encoding='utf-8'))
    data['version_id'] = 1000101
    data['version_string'] = '1.0.1'
    path.write_text(json.dumps(data, ensure_ascii=False, indent=4) + '\n', encoding='utf-8')


def fix_report_template():
    path = DATA / 'templates.xml'
    text = path.read_text(encoding='utf-8')
    # XenForo template expressions use the concat operator '.', not Twig's '~'.
    # The bad operator caused public:warext_audit_report to fail compilation on install.
    text = text.replace(' ~ ', ' . ')
    path.write_text(text, encoding='utf-8')


def add_admin_routes():
    path = DATA / 'routes.xml'
    payload = '''
  <route route_type="admin" route_prefix="warext-moderation-audit" controller="Warext\\ModerationAudit:Dashboard" context="warextModerationAuditAdmin" />
  <route route_type="admin" route_prefix="warext-moderation-audit-settings" controller="Warext\\ModerationAudit:Settings" context="warextModerationAuditAdmin" />
'''
    insert_before(path, '</routes>', payload, 'route_prefix="warext-moderation-audit"')


def add_admin_data():
    write(DATA / 'admin_permission.xml', '''<?xml version="1.0" encoding="utf-8"?>
<admin_permission>
  <admin_permission admin_permission_id="warextAudit" display_order="650" />
</admin_permission>
''')
    write(DATA / 'admin_navigation.xml', '''<?xml version="1.0" encoding="utf-8"?>
<admin_navigation>
  <admin_navigation_entry navigation_id="warextModerationAudit" display_order="650" link="warext-moderation-audit" icon="fa-shield-alt" admin_permission_id="warextAudit" debug_only="0" development_only="0" hide_no_children="1" />
  <admin_navigation_entry navigation_id="warextModerationAuditOverview" parent_navigation_id="warextModerationAudit" display_order="10" link="warext-moderation-audit" admin_permission_id="warextAudit" debug_only="0" development_only="0" hide_no_children="0" />
  <admin_navigation_entry navigation_id="warextModerationAuditSettings" parent_navigation_id="warextModerationAudit" display_order="20" link="warext-moderation-audit-settings" admin_permission_id="warextAudit" debug_only="0" development_only="0" hide_no_children="0" />
</admin_navigation>
''')


def add_admin_controllers():
    write(ROOT / 'Admin/Controller/Dashboard.php', r'''<?php

namespace Warext\ModerationAudit\Admin\Controller;

use XF\Admin\Controller\AbstractController;
use XF\Mvc\ParameterBag;

class Dashboard extends AbstractController
{
    public function actionIndex()
    {
        $state = $this->repository('Warext\ModerationAudit:AuditState');

        $viewParams = [
            'version' => '1.0.1',
            'schemaVersion' => (int)$state->get('schema_version', '0'),
            'caseCount' => $this->finder('Warext\ModerationAudit:AuditCase')->total(),
            'openCaseCount' => $this->finder('Warext\ModerationAudit:AuditCase')
                ->where('status', ['pending', 'assigned', 'in_review', 'reviewed'])
                ->total(),
            'feedbackCount' => $this->finder('Warext\ModerationAudit:AuditFeedback')->total(),
            'openFeedbackCount' => $this->finder('Warext\ModerationAudit:AuditFeedback')
                ->where('status', ['open', 'under_review'])
                ->total(),
            'activeEscalationCount' => $this->finder('Warext\ModerationAudit:AuditEscalation')
                ->where('resolved_date', 0)
                ->total(),
            'settings' => [
                'sla_case_normal_hours' => (int)$state->get('sla_case_normal_hours', '72'),
                'sla_case_elevated_hours' => (int)$state->get('sla_case_elevated_hours', '36'),
                'sla_case_critical_hours' => (int)$state->get('sla_case_critical_hours', '12'),
                'sla_feedback_low_hours' => (int)$state->get('sla_feedback_low_hours', '96'),
                'sla_feedback_normal_hours' => (int)$state->get('sla_feedback_normal_hours', '48'),
                'sla_feedback_high_hours' => (int)$state->get('sla_feedback_high_hours', '24'),
                'sla_feedback_critical_hours' => (int)$state->get('sla_feedback_critical_hours', '6')
            ]
        ];

        return $this->view(
            'Warext\ModerationAudit:Dashboard',
            'warext_audit_admin_dashboard',
            $viewParams
        );
    }

    protected function preDispatchController($action, ParameterBag $params): void
    {
        $this->assertAdminPermission('warextAudit');
    }
}
''')

    write(ROOT / 'Admin/Controller/Settings.php', r'''<?php

namespace Warext\ModerationAudit\Admin\Controller;

use XF\Admin\Controller\AbstractController;
use XF\Mvc\ParameterBag;

class Settings extends AbstractController
{
    protected function getSettings(): array
    {
        $state = $this->repository('Warext\ModerationAudit:AuditState');
        return [
            'sla_case_normal_hours' => (int)$state->get('sla_case_normal_hours', '72'),
            'sla_case_elevated_hours' => (int)$state->get('sla_case_elevated_hours', '36'),
            'sla_case_critical_hours' => (int)$state->get('sla_case_critical_hours', '12'),
            'sla_feedback_low_hours' => (int)$state->get('sla_feedback_low_hours', '96'),
            'sla_feedback_normal_hours' => (int)$state->get('sla_feedback_normal_hours', '48'),
            'sla_feedback_high_hours' => (int)$state->get('sla_feedback_high_hours', '24'),
            'sla_feedback_critical_hours' => (int)$state->get('sla_feedback_critical_hours', '6')
        ];
    }

    public function actionIndex()
    {
        return $this->view(
            'Warext\ModerationAudit:Settings',
            'warext_audit_admin_settings',
            ['settings' => $this->getSettings()]
        );
    }

    public function actionSave()
    {
        $this->assertPostOnly();

        $input = $this->filter([
            'sla_case_normal_hours' => 'uint',
            'sla_case_elevated_hours' => 'uint',
            'sla_case_critical_hours' => 'uint',
            'sla_feedback_low_hours' => 'uint',
            'sla_feedback_normal_hours' => 'uint',
            'sla_feedback_high_hours' => 'uint',
            'sla_feedback_critical_hours' => 'uint'
        ]);

        foreach ($input as $key => $value)
        {
            if ($value < 1 || $value > 720)
            {
                return $this->error('SLA süreleri 1 ile 720 saat arasında olmalıdır.');
            }
        }

        if (!($input['sla_case_critical_hours'] <= $input['sla_case_elevated_hours']
            && $input['sla_case_elevated_hours'] <= $input['sla_case_normal_hours']))
        {
            return $this->error('Vaka SLA sırası kritik ≤ yüksek risk ≤ normal şeklinde olmalıdır.');
        }

        if (!($input['sla_feedback_critical_hours'] <= $input['sla_feedback_high_hours']
            && $input['sla_feedback_high_hours'] <= $input['sla_feedback_normal_hours']
            && $input['sla_feedback_normal_hours'] <= $input['sla_feedback_low_hours']))
        {
            return $this->error('Başvuru SLA sırası kritik ≤ yüksek ≤ normal ≤ düşük öncelik şeklinde olmalıdır.');
        }

        $state = $this->repository('Warext\ModerationAudit:AuditState');
        foreach ($input as $key => $value)
        {
            $state->set($key, (string)$value);
        }

        return $this->redirect(
            $this->buildLink('warext-moderation-audit-settings'),
            'Moderasyon denetim sistemi ayarları kaydedildi.'
        );
    }

    protected function preDispatchController($action, ParameterBag $params): void
    {
        $this->assertAdminPermission('warextAudit');
    }
}
''')


def add_admin_templates():
    dashboard = r'''
  <template type="admin" title="warext_audit_admin_dashboard" version_id="1000101" version_string="1.0.1"><![CDATA[<xf:title>Moderasyon Denetim Sistemi</xf:title>

<div class="block">
    <div class="block-container">
        <div class="block-header">Sistem Bilgisi</div>
        <div class="block-body">
            <div class="block-row"><b>Sürüm:</b> {$version}</div>
            <div class="block-row"><b>Şema sürümü:</b> {$schemaVersion}</div>
            <div class="block-row"><b>Gereksinimler:</b> XenForo 2.3.0+ / PHP 8.1+</div>
            <div class="block-row"><b>Denetim vakaları:</b> {$caseCount} toplam / {$openCaseCount} açık</div>
            <div class="block-row"><b>İtiraz ve öneriler:</b> {$feedbackCount} toplam / {$openFeedbackCount} açık</div>
            <div class="block-row"><b>Aktif eskalasyon:</b> {$activeEscalationCount}</div>
        </div>
        <div class="block-footer">
            <a class="button button--primary" href="{{ link('warext-moderation-audit-settings') }}">Ayarları Düzenle</a>
        </div>
    </div>
</div>

<div class="block">
    <div class="block-container">
        <div class="block-header">Aktif SLA Ayarları</div>
        <div class="block-body">
            <div class="block-row">Vaka: Normal {$settings.sla_case_normal_hours} saat · Yüksek risk {$settings.sla_case_elevated_hours} saat · Kritik {$settings.sla_case_critical_hours} saat</div>
            <div class="block-row">Başvuru: Düşük {$settings.sla_feedback_low_hours} saat · Normal {$settings.sla_feedback_normal_hours} saat · Yüksek {$settings.sla_feedback_high_hours} saat · Kritik {$settings.sla_feedback_critical_hours} saat</div>
        </div>
    </div>
</div>]]></template>
'''
    settings = r'''
  <template type="admin" title="warext_audit_admin_settings" version_id="1000101" version_string="1.0.1"><![CDATA[<xf:title>Moderasyon Denetim Sistemi - Ayarlar</xf:title>

<xf:form action="{{ link('warext-moderation-audit-settings/save') }}" class="block">
    <div class="block-container">
        <div class="block-header">Vaka SLA Süreleri</div>
        <div class="block-body">
            <div class="block-row"><label><b>Normal risk (saat)</b><input class="input" type="number" min="1" max="720" name="sla_case_normal_hours" value="{$settings.sla_case_normal_hours}" required="required" /></label></div>
            <div class="block-row"><label><b>Yüksek risk (saat)</b><input class="input" type="number" min="1" max="720" name="sla_case_elevated_hours" value="{$settings.sla_case_elevated_hours}" required="required" /></label></div>
            <div class="block-row"><label><b>Kritik risk (saat)</b><input class="input" type="number" min="1" max="720" name="sla_case_critical_hours" value="{$settings.sla_case_critical_hours}" required="required" /></label></div>
        </div>
    </div>

    <div class="block-container">
        <div class="block-header">İtiraz / Öneri SLA Süreleri</div>
        <div class="block-body">
            <div class="block-row"><label><b>Düşük öncelik (saat)</b><input class="input" type="number" min="1" max="720" name="sla_feedback_low_hours" value="{$settings.sla_feedback_low_hours}" required="required" /></label></div>
            <div class="block-row"><label><b>Normal öncelik (saat)</b><input class="input" type="number" min="1" max="720" name="sla_feedback_normal_hours" value="{$settings.sla_feedback_normal_hours}" required="required" /></label></div>
            <div class="block-row"><label><b>Yüksek öncelik (saat)</b><input class="input" type="number" min="1" max="720" name="sla_feedback_high_hours" value="{$settings.sla_feedback_high_hours}" required="required" /></label></div>
            <div class="block-row"><label><b>Kritik öncelik (saat)</b><input class="input" type="number" min="1" max="720" name="sla_feedback_critical_hours" value="{$settings.sla_feedback_critical_hours}" required="required" /></label></div>
        </div>
        <div class="block-footer">
            <button type="submit" class="button button--primary">Ayarları Kaydet</button>
            <a class="button" href="{{ link('warext-moderation-audit') }}">Sistem Bilgisine Dön</a>
        </div>
    </div>
</xf:form>]]></template>
'''
    path = DATA / 'templates.xml'
    insert_before(path, '</templates>', dashboard + settings, 'title="warext_audit_admin_dashboard"')


def add_phrases():
    payload = '''
  <phrase title="admin_navigation.warextModerationAudit" version_id="1000101" version_string="1.0.1"><![CDATA[Moderasyon Denetim Sistemi]]></phrase>
  <phrase title="admin_navigation.warextModerationAuditOverview" version_id="1000101" version_string="1.0.1"><![CDATA[Sistem Bilgisi]]></phrase>
  <phrase title="admin_navigation.warextModerationAuditSettings" version_id="1000101" version_string="1.0.1"><![CDATA[Ayarlar]]></phrase>
  <phrase title="admin_permission.warextAudit" version_id="1000101" version_string="1.0.1"><![CDATA[Moderasyon denetim sistemini yönet]]></phrase>
'''
    insert_before(DATA / 'phrases.xml', '</phrases>', payload, 'admin_navigation.warextModerationAudit')


def update_readme():
    path = Path('README.md')
    text = path.read_text(encoding='utf-8')
    text = text.replace('**1.0.0**', '**1.0.1**', 1)
    text = text.replace('Warext-ModerationAudit-1.0.0.zip', 'Warext-ModerationAudit-1.0.1.zip')
    if '## 1.0.1 — Kurulum düzeltmesi ve bağımsız ACP bölümü' not in text:
        text += '''\n\n## 1.0.1 — Kurulum düzeltmesi ve bağımsız ACP bölümü\n\n- `public:warext_audit_report` şablonundaki XenForo ile uyumsuz `~` string birleştirme operatörü `.` ile düzeltildi; kurulum sırasında oluşan template syntax hatası giderildi.\n- ACP sol menüsüne diğer XenForo kategorilerinden bağımsız **Moderasyon Denetim Sistemi** ana bölümü eklendi.\n- Bu bölüm altında **Sistem Bilgisi** ve **Ayarlar** alt sayfaları eklendi.\n- Sistem Bilgisi ekranında sürüm, şema, açık/toplam vaka ve başvuru sayıları ile aktif eskalasyon sayısı gösterilir.\n- Ayarlar ekranından vaka ve itiraz/öneri SLA süreleri doğrudan düzenlenebilir.\n- Ayar değerleri mevcut `xf_warext_audit_state` altyapısına yazılır; governance/cron sistemi bu değerleri doğrudan kullanır.\n'''
    path.write_text(text, encoding='utf-8')


def main():
    update_addon()
    fix_report_template()
    add_admin_routes()
    add_admin_data()
    add_admin_controllers()
    add_admin_templates()
    add_phrases()
    update_readme()
    print('1.0.1 installer fix and ACP section applied')


if __name__ == '__main__':
    main()
