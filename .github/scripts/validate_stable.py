from pathlib import Path
import hashlib
import json
import re
import sys
import xml.etree.ElementTree as ET

ROOT = Path('upload/src/addons/Warext/ModerationAudit')
DATA = ROOT / '_data'
ERRORS = []


def fail(message):
    ERRORS.append(message)


def require(condition, message):
    if not condition:
        fail(message)


def read(path):
    return path.read_text(encoding='utf-8')


def map_addon_class(class_name):
    prefix = 'Warext\\ModerationAudit\\'
    if not class_name.startswith(prefix):
        return None
    relative = class_name[len(prefix):].replace('\\', '/') + '.php'
    return ROOT / relative


# 1) Version / package identity.
addon_path = ROOT / 'addon.json'
require(addon_path.exists(), 'addon.json missing')
addon = json.loads(read(addon_path)) if addon_path.exists() else {}
require(addon.get('version_id') == 1000100, f"unexpected version_id: {addon.get('version_id')}")
require(addon.get('version_string') == '1.0.0', f"unexpected version_string: {addon.get('version_string')}")
require(addon.get('require', {}).get('XF', [0])[0] >= 2030000, 'XenForo minimum requirement must be 2.3.0+')
require(addon.get('require', {}).get('php', ['0'])[0] >= '8.1.0', 'PHP minimum requirement must be 8.1.0+')

# 2) Every exported XML must be well-formed.
xml_roots = {}
for path in sorted(DATA.glob('*.xml')):
    try:
        xml_roots[path.name] = ET.parse(path).getroot()
    except ET.ParseError as exc:
        fail(f'XML parse failed for {path}: {exc}')

# 3) Final feature files must exist.
required_files = [
    ROOT / 'Setup.php',
    ROOT / 'Entity/AuditCase.php',
    ROOT / 'Entity/AuditSnapshot.php',
    ROOT / 'Entity/AuditReview.php',
    ROOT / 'Entity/AuditReviewRevision.php',
    ROOT / 'Entity/AuditAssignment.php',
    ROOT / 'Entity/AuditConflict.php',
    ROOT / 'Entity/AuditAuditor.php',
    ROOT / 'Entity/AuditReport.php',
    ROOT / 'Entity/AuditFeedback.php',
    ROOT / 'Entity/AuditFeedbackEvent.php',
    ROOT / 'Entity/AuditEscalation.php',
    ROOT / 'Entity/AuditNotice.php',
    ROOT / 'Service/Audit/FeedbackManager.php',
    ROOT / 'Service/Audit/GovernanceManager.php',
    ROOT / 'Pub/Controller/Audit.php',
    ROOT / 'Pub/Controller/Feedback.php',
    ROOT / 'Pub/Controller/Governance.php',
    ROOT / 'Cron/Reports.php',
    ROOT / 'Cron/Governance.php',
]
for path in required_files:
    require(path.exists(), f'missing required file: {path}')

# 4) Public route -> controller cross-check.
routes_root = xml_roots.get('routes.xml')
if routes_root is None:
    fail('routes.xml missing or invalid')
else:
    for route in routes_root.iter('route'):
        controller = route.attrib.get('controller', '')
        if controller.startswith('Warext\\ModerationAudit:'):
            short = controller.split(':', 1)[1].replace('\\', '/')
            controller_path = ROOT / 'Pub/Controller' / f'{short}.php'
            require(controller_path.exists(), f'route {route.attrib.get("route_prefix")} points to missing controller {controller_path}')

# 5) Cron -> callback file/method cross-check.
cron_root = xml_roots.get('cron_entries.xml')
if cron_root is None:
    fail('cron_entries.xml missing or invalid')
else:
    for callback in cron_root.iter('callback'):
        class_name = callback.attrib.get('class', '')
        method = callback.attrib.get('method', '')
        callback_path = map_addon_class(class_name)
        if callback_path:
            require(callback_path.exists(), f'cron callback class missing: {class_name} -> {callback_path}')
            if callback_path.exists():
                callback_text = read(callback_path)
                require(bool(re.search(r'function\s+' + re.escape(method) + r'\s*\(', callback_text)),
                        f'cron callback method missing: {class_name}::{method}')

# 6) Class extension target implementation files.
ext_root = xml_roots.get('class_extensions.xml')
if ext_root is not None:
    for node in ext_root.iter():
        to_class = node.attrib.get('to_class', '')
        target = map_addon_class(to_class)
        if target:
            require(target.exists(), f'class extension implementation missing: {to_class} -> {target}')

# 7) Controller -> template cross-check.
templates_root = xml_roots.get('templates.xml')
template_titles = set()
if templates_root is None:
    fail('templates.xml missing or invalid')
else:
    for node in templates_root.iter():
        title = node.attrib.get('title')
        if title:
            template_titles.add(title)

for controller_path in sorted((ROOT / 'Pub/Controller').glob('*.php')):
    controller_text = read(controller_path)
    for match in re.finditer(r'->view\(\s*[^,]+,\s*[\'\"]([^\'\"]+)[\'\"]', controller_text, flags=re.S):
        title = match.group(1)
        require(title in template_titles, f'{controller_path.name} references missing template: {title}')

# 8) Permission definition/use cross-check.
permissions_root = xml_roots.get('permissions.xml')
permission_ids = set()
if permissions_root is None:
    fail('permissions.xml missing or invalid')
else:
    for node in permissions_root.iter():
        permission_id = node.attrib.get('permission_id')
        if permission_id:
            permission_ids.add(permission_id)

permission_pattern = re.compile(r'hasPermission\(\s*[\'\"]general[\'\"]\s*,\s*[\'\"](warextAudit[A-Za-z0-9_]+)[\'\"]\s*\)')
scan_texts = []
for path in sorted(ROOT.rglob('*.php')):
    scan_texts.append((path, read(path)))
if (DATA / 'templates.xml').exists():
    scan_texts.append((DATA / 'templates.xml', read(DATA / 'templates.xml')))
for path, text in scan_texts:
    for permission_id in permission_pattern.findall(text):
        require(permission_id in permission_ids, f'{path} uses undefined permission: {permission_id}')

# 9) Add-on alias references -> entity/repository/service implementation.
entity_pattern = re.compile(r'(?:finder|create|find)\(\s*[\'\"]Warext\\\\ModerationAudit:([A-Za-z0-9_]+)[\'\"]')
repository_pattern = re.compile(r'repository\(\s*[\'\"]Warext\\\\ModerationAudit:([A-Za-z0-9_\\\\]+)[\'\"]')
service_pattern = re.compile(r'service\(\s*[\'\"]Warext\\\\ModerationAudit:([A-Za-z0-9_\\\\]+)[\'\"]')
for path in sorted(ROOT.rglob('*.php')):
    text = read(path)
    for alias in entity_pattern.findall(text):
        entity_path = ROOT / 'Entity' / f'{alias}.php'
        require(entity_path.exists(), f'{path} references missing entity alias {alias}: {entity_path}')
    for alias in repository_pattern.findall(text):
        repository_path = ROOT / 'Repository' / (alias.replace('\\', '/') + '.php')
        require(repository_path.exists(), f'{path} references missing repository alias {alias}: {repository_path}')
    for alias in service_pattern.findall(text):
        service_path = ROOT / 'Service' / (alias.replace('\\', '/') + '.php')
        require(service_path.exists(), f'{path} references missing service alias {alias}: {service_path}')

# 10) Entity structure columns must match Setup table columns.
setup_path = ROOT / 'Setup.php'
setup_text = read(setup_path) if setup_path.exists() else ''
for entity_path in sorted((ROOT / 'Entity').glob('*.php')):
    text = read(entity_path)
    table_match = re.search(r"\$structure->table\s*=\s*'([^']+)'", text)
    columns_match = re.search(r'\$structure->columns\s*=\s*\[(.*?)\n\s*\];', text, flags=re.S)
    if not table_match or not columns_match:
        continue
    table = table_match.group(1)
    entity_columns = set(re.findall(r"^\s*'([A-Za-z0-9_]+)'\s*=>", columns_match.group(1), flags=re.M))
    table_start = setup_text.find(f"createTable('{table}'")
    require(table_start >= 0, f'{entity_path.name} table not created in Setup.php: {table}')
    if table_start < 0:
        continue
    table_end = setup_text.find('});', table_start)
    require(table_end >= 0, f'cannot parse Setup.php createTable block for {table}')
    if table_end < 0:
        continue
    table_block = setup_text[table_start:table_end]
    setup_columns = set(re.findall(r"addColumn\('([A-Za-z0-9_]+)'", table_block))
    missing_in_setup = sorted(entity_columns - setup_columns)
    extra_in_setup = sorted(setup_columns - entity_columns)
    require(not missing_in_setup, f'{entity_path.name} columns missing from Setup.php {table}: {missing_in_setup}')
    require(not extra_in_setup, f'Setup.php {table} columns missing from {entity_path.name}: {extra_in_setup}')

# 11) Setup/version migration consistency.
for needle in [
    'upgrade1000090Step1',
    'xf_warext_audit_escalation',
    'xf_warext_audit_notice',
    "'schema_version' => '6'",
    'sla_case_normal_hours',
    'sla_case_elevated_hours',
    'sla_case_critical_hours',
    'sla_feedback_low_hours',
    'sla_feedback_normal_hours',
    'sla_feedback_high_hours',
    'sla_feedback_critical_hours',
]:
    require(needle in setup_text, f'Setup.php final integration missing: {needle}')

# 12) Stable hardening invariants.
feedback_text = read(ROOT / 'Service/Audit/FeedbackManager.php') if (ROOT / 'Service/Audit/FeedbackManager.php').exists() else ''
require("!$actor->hasPermission('general', 'warextAuditManage')" in feedback_text,
        'normal-user feedback priority hardening missing')
require("in_array($priority, ['high', 'critical'], true)" in feedback_text,
        'high/critical feedback priority restriction missing')

escalation_text = read(ROOT / 'Entity/AuditEscalation.php') if (ROOT / 'Entity/AuditEscalation.php').exists() else ''
require("getExistingValue('resolved_date')" in escalation_text,
        'resolved escalation immutability check missing')
require('Çözümlenmiş eskalasyonun kapanış kaydı değiştirilemez.' in escalation_text,
        'resolved escalation closure immutability message missing')

notice_text = read(ROOT / 'Entity/AuditNotice.php') if (ROOT / 'Entity/AuditNotice.php').exists() else ''
require("getExistingValue('read_date')" in notice_text,
        'read notice immutability check missing')
require('tekrar okunmamış duruma getirilemez' in notice_text,
        'notice read-state hardening missing')

governance_path = ROOT / 'Pub/Controller/Governance.php'
governance_text = read(governance_path) if governance_path.exists() else ''
require('governance dashboard scan' not in governance_text,
        'GET dashboard still performs governance scan/write side effect')
require('LEFT JOIN xf_warext_audit_case AS c' in governance_text and 'LEFT JOIN xf_warext_audit_feedback AS f' in governance_text,
        'user escalation query was not moved to DB-side filtering')
require(bool(re.search(r'function\s+actionTara\b.*?->scan\(\)', governance_text, flags=re.S)),
        'explicit manager scan action missing')

templates_text = read(DATA / 'templates.xml') if (DATA / 'templates.xml').exists() else ''
require("warextAuditAppeal') AND $caseView.moderator_user_id == $xf.visitor.user_id" in templates_text,
        'case appeal button permission/ownership condition missing')

# 13) Build/development junk must never enter upload/.
for path in ROOT.rglob('*'):
    if not path.is_file():
        continue
    rel_parts = path.relative_to(ROOT).parts
    if '.github' in rel_parts or path.suffix.lower() in {'.py', '.zip'}:
        fail(f'development/build artifact found inside upload package: {path}')

# 14) README must describe the stable package and retain the runtime-test limitation.
readme_path = Path('README.md')
readme_text = read(readme_path) if readme_path.exists() else ''
require('**1.0.0**' in readme_text, 'README stable version label missing')
require('Warext-ModerationAudit-1.0.0.zip' in readme_text, 'README stable ZIP name missing')
require('Adım 10/10' in readme_text, 'README final step section missing')
require('runtime testi' in readme_text.lower(), 'README must disclose live XenForo runtime-test limitation')

if ERRORS:
    print('Stable validation FAILED:', file=sys.stderr)
    for error in ERRORS:
        print(f' - {error}', file=sys.stderr)
    raise SystemExit(1)

# 15) Generate the final SHA-256 manifest only after all consistency checks pass.
hashes = {}
for path in sorted(ROOT.rglob('*')):
    if not path.is_file() or path.name == 'hashes.json':
        continue
    rel = path.relative_to(Path('upload')).as_posix()
    hashes[rel] = hashlib.sha256(path.read_bytes()).hexdigest()
(ROOT / 'hashes.json').write_text(json.dumps(hashes, indent=4, sort_keys=True) + '\n', encoding='utf-8')

print(f'Stable 1.0.0 validated; {len(hashes)} SHA-256 hashes generated')
