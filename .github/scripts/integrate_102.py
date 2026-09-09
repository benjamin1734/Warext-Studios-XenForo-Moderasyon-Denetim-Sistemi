from pathlib import Path
import json
import re

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
    path.write_text(text.replace(closing, payload.rstrip() + '\n' + closing, 1), encoding='utf-8')


def update_addon():
    path = ROOT / 'addon.json'
    data = json.loads(path.read_text(encoding='utf-8'))
    data['version_id'] = 1000102
    data['version_string'] = '1.0.2'
    path.write_text(json.dumps(data, ensure_ascii=False, indent=4) + '\n', encoding='utf-8')


def add_permission_helper():
    write(ROOT / 'Support/Permission.php', r'''<?php

namespace Warext\ModerationAudit\Support;

use XF\Entity\User;

final class Permission
{
    public static function isSuperAdmin(?User $user = null): bool
    {
        $user ??= \XF::visitor();
        return (bool)$user->is_super_admin;
    }

    public static function has(User $user, string $permissionId): bool
    {
        return self::isSuperAdmin($user)
            || $user->hasPermission('general', $permissionId);
    }
}
''')


def update_php_permission_checks():
    # Every Warext audit public permission is automatically granted to XenForo super admins.
    pattern = re.compile(
        r"(?P<actor>\\XF::visitor\(\)|\$[A-Za-z_][A-Za-z0-9_]*)->hasPermission\(\s*'general'\s*,\s*'(?P<perm>warextAudit[A-Za-z]+)'\s*\)"
    )
    helper = ROOT / 'Support/Permission.php'
    for path in ROOT.rglob('*.php'):
        if path == helper:
            continue
        text = path.read_text(encoding='utf-8')
        text = pattern.sub(
            lambda m: "\\Warext\\ModerationAudit\\Support\\Permission::has(" + m.group('actor') + ", '" + m.group('perm') + "')",
            text
        )
        path.write_text(text, encoding='utf-8')


def update_template_permission_checks():
    pattern = re.compile(
        r"(?<!OR )\$xf\.visitor\.hasPermission\('general',\s*'(warextAudit[A-Za-z]+)'\)"
    )
    for path in [DATA / 'templates.xml', DATA / 'navigation.xml']:
        text = path.read_text(encoding='utf-8')
        text = pattern.sub(
            lambda m: "($xf.visitor.is_super_admin OR $xf.visitor.hasPermission('general', '" + m.group(1) + "'))",
            text
        )
        path.write_text(text, encoding='utf-8')


def update_admin_guards():
    for rel in ['Admin/Controller/Dashboard.php', 'Admin/Controller/Settings.php']:
        path = ROOT / rel
        text = path.read_text(encoding='utf-8')
        old = "$this->assertAdminPermission('warextAudit');"
        new = "if (!\\XF::visitor()->is_super_admin)\n        {\n            $this->assertAdminPermission('warextAudit');\n        }"
        if old in text and 'if (!\\XF::visitor()->is_super_admin)' not in text:
            text = text.replace(old, new)
        path.write_text(text, encoding='utf-8')


def add_moderator_tools_link():
    write(DATA / 'template_modifications.xml', '''<?xml version="1.0" encoding="utf-8"?>
<template_modifications>
  <modification type="public" template="PAGE_CONTAINER" modification_key="warextAuditModeratorTools" description="Add moderation audit center to Moderator tools" execution_order="50" enabled="1" action="str_replace">
    <find><![CDATA[<!--[XF:mod_tools_menu:top]-->]]></find>
    <replace><![CDATA[$0
<xf:if is="$xf.visitor.is_super_admin OR $xf.visitor.hasPermission('general', 'warextAuditView')">
    <a href="{{ link('denetim') }}" class="menu-linkRow">{{ phrase('warext_audit_moderator_tools') }}</a>
</xf:if>]]></replace>
  </modification>
</template_modifications>
''')


def add_phrase():
    payload = '''
  <phrase title="warext_audit_moderator_tools" version_id="1000102" version_string="1.0.2"><![CDATA[Moderasyon Denetimi]]></phrase>
'''
    insert_before(DATA / 'phrases.xml', '</phrases>', payload, 'title="warext_audit_moderator_tools"')


def bump_owned_template_versions():
    path = DATA / 'templates.xml'
    text = path.read_text(encoding='utf-8')
    # Only metadata; content remains intact apart from permission expressions.
    text = re.sub(r'version_id="1000101" version_string="1\.0\.1"', 'version_id="1000102" version_string="1.0.2"', text)
    path.write_text(text, encoding='utf-8')


def update_readme():
    path = Path('README.md')
    text = path.read_text(encoding='utf-8')
    text = text.replace('**1.0.1**', '**1.0.2**', 1)
    text = text.replace('Warext-ModerationAudit-1.0.1.zip', 'Warext-ModerationAudit-1.0.2.zip')
    section = '''\n## 1.0.2\n\n- XenForo super admin hesapları tüm `warextAudit*` public izinlerini otomatik olarak geçer; ayrıca kullanıcı grubu izni vermeleri gerekmez.\n- ACP `warextAudit` izni super admin için otomatik bypass edilir.\n- `Moderator tools` açılır menüsüne XenForo'nun `mod_tools_menu:top` template hook'u üzerinden **Moderasyon Denetimi** bağlantısı eklendi.\n- Normal moderatör/denetçiler için ayrıntılı kullanıcı grubu izin sistemi aynen korunur.\n'''
    if '## 1.0.2' not in text:
        text += section
    path.write_text(text, encoding='utf-8')


def main():
    update_addon()
    add_permission_helper()
    update_php_permission_checks()
    update_template_permission_checks()
    update_admin_guards()
    add_moderator_tools_link()
    add_phrase()
    bump_owned_template_versions()
    update_readme()
    print('1.0.2 integration applied')


if __name__ == '__main__':
    main()
