from pathlib import Path
import hashlib
import json
import xml.etree.ElementTree as ET

ROOT = Path('upload/src/addons/Warext/ModerationAudit')

addon = json.loads((ROOT / 'addon.json').read_text(encoding='utf-8'))
assert addon['version_id'] == 1000090, addon
assert addon['version_string'] == '1.0.0 Alpha 9', addon

for path in (ROOT / '_data').glob('*.xml'):
    ET.parse(path)

required = [
    ROOT / 'Entity/AuditEscalation.php',
    ROOT / 'Entity/AuditNotice.php',
    ROOT / 'Service/Audit/GovernanceManager.php',
    ROOT / 'Pub/Controller/Governance.php',
    ROOT / 'Cron/Governance.php'
]
for path in required:
    if not path.exists():
        raise SystemExit(f'missing required file: {path}')

setup = (ROOT / 'Setup.php').read_text(encoding='utf-8')
for needle in ['upgrade1000090Step1', 'xf_warext_audit_escalation', 'xf_warext_audit_notice', "'schema_version' => '6'"]:
    if needle not in setup:
        raise SystemExit(f'setup integration missing: {needle}')

routes = (ROOT / '_data/routes.xml').read_text(encoding='utf-8')
if 'denetim-takip' not in routes or 'ModerationAudit:Governance' not in routes:
    raise SystemExit('governance route missing')

cron = (ROOT / '_data/cron_entries.xml').read_text(encoding='utf-8')
if 'warextAuditGovernance' not in cron or 'Cron\\Governance' not in cron:
    raise SystemExit('governance cron missing')

templates = (ROOT / '_data/templates.xml').read_text(encoding='utf-8')
if 'title="warext_audit_governance"' not in templates:
    raise SystemExit('governance template missing')

service = (ROOT / 'Service/Audit/GovernanceManager.php').read_text(encoding='utf-8')
for state_key in [
    'sla_case_normal_hours', 'sla_case_elevated_hours', 'sla_case_critical_hours',
    'sla_feedback_low_hours', 'sla_feedback_normal_hours', 'sla_feedback_high_hours', 'sla_feedback_critical_hours'
]:
    if state_key not in service or state_key not in setup:
        raise SystemExit(f'SLA state missing: {state_key}')

hashes = {}
for path in sorted(ROOT.rglob('*')):
    if not path.is_file() or path.name == 'hashes.json':
        continue
    rel = path.relative_to(Path('upload')).as_posix()
    hashes[rel] = hashlib.sha256(path.read_bytes()).hexdigest()
(ROOT / 'hashes.json').write_text(json.dumps(hashes, indent=4, sort_keys=True) + '\n', encoding='utf-8')

print(f'Alpha 9 validated; {len(hashes)} hashes generated')
