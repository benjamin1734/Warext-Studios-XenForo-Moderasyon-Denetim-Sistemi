from pathlib import Path
import hashlib
import json
import re
import sys
import xml.etree.ElementTree as ET

ROOT = Path('upload/src/addons/Warext/ModerationAudit')
DATA = ROOT / '_data'
errors = []


def require(ok, message):
    if not ok:
        errors.append(message)


def read(path):
    return path.read_text(encoding='utf-8')

addon = json.loads(read(ROOT / 'addon.json'))
require(addon.get('version_id') == 1000101, 'addon version_id must be 1000101')
require(addon.get('version_string') == '1.0.1', 'addon version_string must be 1.0.1')

xml_roots = {}
for path in sorted(DATA.glob('*.xml')):
    try:
        xml_roots[path.name] = ET.parse(path).getroot()
    except Exception as exc:
        errors.append(f'XML parse failed: {path}: {exc}')

# Installer regression: Twig-style ~ must never remain in XenForo expressions.
templates = read(DATA / 'templates.xml')
require(' ~ ' not in templates, 'templates.xml still contains unsupported XenForo concat operator ~')
require('title="warext_audit_report"' in templates, 'report template missing')
require('title="warext_audit_admin_dashboard"' in templates, 'ACP dashboard template missing')
require('title="warext_audit_admin_settings"' in templates, 'ACP settings template missing')

# ACP standalone category, permissions and routes.
for path in [
    DATA / 'admin_navigation.xml',
    DATA / 'admin_permission.xml',
    ROOT / 'Admin/Controller/Dashboard.php',
    ROOT / 'Admin/Controller/Settings.php'
]:
    require(path.exists(), f'missing ACP file: {path}')

nav = read(DATA / 'admin_navigation.xml') if (DATA / 'admin_navigation.xml').exists() else ''
for needle in [
    'navigation_id="warextModerationAudit"',
    'parent_navigation_id="warextModerationAudit"',
    'navigation_id="warextModerationAuditOverview"',
    'navigation_id="warextModerationAuditSettings"',
    'link="warext-moderation-audit"',
    'link="warext-moderation-audit-settings"'
]:
    require(needle in nav, f'ACP navigation missing: {needle}')

admin_perm = read(DATA / 'admin_permission.xml') if (DATA / 'admin_permission.xml').exists() else ''
require('admin_permission_id="warextAudit"' in admin_perm, 'warextAudit admin permission missing')

routes = read(DATA / 'routes.xml')
require('route_type="admin" route_prefix="warext-moderation-audit"' in routes, 'ACP dashboard route missing')
require('route_type="admin" route_prefix="warext-moderation-audit-settings"' in routes, 'ACP settings route missing')

phrases = read(DATA / 'phrases.xml')
for phrase in [
    'admin_navigation.warextModerationAudit',
    'admin_navigation.warextModerationAuditOverview',
    'admin_navigation.warextModerationAuditSettings',
    'admin_permission.warextAudit'
]:
    require(f'title="{phrase}"' in phrases, f'ACP phrase missing: {phrase}')

settings_php = read(ROOT / 'Admin/Controller/Settings.php') if (ROOT / 'Admin/Controller/Settings.php').exists() else ''
dashboard_php = read(ROOT / 'Admin/Controller/Dashboard.php') if (ROOT / 'Admin/Controller/Dashboard.php').exists() else ''
require("assertAdminPermission('warextAudit')" in settings_php, 'ACP settings permission guard missing')
require("assertAdminPermission('warextAudit')" in dashboard_php, 'ACP dashboard permission guard missing')
require('actionSave' in settings_php and 'assertPostOnly' in settings_php, 'ACP settings POST save missing')

state_keys = [
    'sla_case_normal_hours', 'sla_case_elevated_hours', 'sla_case_critical_hours',
    'sla_feedback_low_hours', 'sla_feedback_normal_hours', 'sla_feedback_high_hours',
    'sla_feedback_critical_hours'
]
for key in state_keys:
    require(key in settings_php, f'ACP settings missing state key: {key}')

# Existing governance must consume the same state keys.
governance = read(ROOT / 'Service/Audit/GovernanceManager.php')
for key in state_keys:
    require(key in governance, f'GovernanceManager does not consume state key: {key}')

# Route -> controller existence (public + admin aware).
routes_root = xml_roots.get('routes.xml')
if routes_root is not None:
    for route in routes_root.findall('route'):
        controller = route.attrib.get('controller', '')
        route_type = route.attrib.get('route_type', '')
        prefix = 'Warext\\ModerationAudit:'
        if not controller.startswith(prefix):
            continue
        alias = controller[len(prefix):].replace('\\', '/')
        if route_type == 'admin':
            path = ROOT / 'Admin/Controller' / f'{alias}.php'
        else:
            path = ROOT / 'Pub/Controller' / f'{alias}.php'
        require(path.exists(), f'route {route.attrib.get("route_prefix")} controller missing: {path}')

# Controller -> template existence.
template_titles = set(re.findall(r'<template[^>]+title="([^"]+)"', templates))
for base in [ROOT / 'Pub/Controller', ROOT / 'Admin/Controller']:
    if not base.exists():
        continue
    for path in sorted(base.glob('*.php')):
        text = read(path)
        for match in re.finditer(r'->view\(\s*[^,]+,\s*[\'\"]([^\'\"]+)[\'\"]', text, flags=re.S):
            title = match.group(1)
            require(title in template_titles, f'{path.name} references missing template: {title}')

# Install ZIP source tree must not contain development junk.
for path in ROOT.rglob('*'):
    if not path.is_file():
        continue
    require(path.suffix not in {'.py', '.zip'}, f'development artifact leaked into addon source: {path}')
    require('.github' not in path.parts, f'.github leaked into addon source: {path}')

# Regenerate manifest after all integration changes.
hashes = {}
for path in sorted(ROOT.rglob('*')):
    if not path.is_file() or path.name == 'hashes.json':
        continue
    rel = path.relative_to(Path('upload')).as_posix()
    hashes[rel] = hashlib.sha256(path.read_bytes()).hexdigest()
(ROOT / 'hashes.json').write_text(json.dumps(hashes, indent=4, sort_keys=True) + '\n', encoding='utf-8')

if errors:
    for error in errors:
        print(f'ERROR: {error}', file=sys.stderr)
    raise SystemExit(1)

print(f'1.0.1 validated; {len(hashes)} SHA-256 hashes generated')
