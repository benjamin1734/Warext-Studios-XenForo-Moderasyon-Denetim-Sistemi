from pathlib import Path
import hashlib
import json
import xml.etree.ElementTree as ET

ROOT = Path('upload/src/addons/Warext/ModerationAudit')

addon = json.loads((ROOT / 'addon.json').read_text(encoding='utf-8'))
assert addon['version_id'] == 1000080, addon
assert addon['version_string'] == '1.0.0 Alpha 8', addon

for path in (ROOT / '_data').glob('*.xml'):
    ET.parse(path)

required = [
    ROOT / 'Entity/AuditFeedback.php',
    ROOT / 'Entity/AuditFeedbackEvent.php',
    ROOT / 'Service/Audit/FeedbackManager.php',
    ROOT / 'Pub/Controller/Feedback.php',
]
for path in required:
    if not path.exists():
        raise SystemExit(f'missing required file: {path}')

templates = (ROOT / '_data/templates.xml').read_text(encoding='utf-8')
for title in ['warext_audit_feedback_index', 'warext_audit_feedback_create', 'warext_audit_feedback_view']:
    if f'title="{title}"' not in templates:
        raise SystemExit(f'missing template: {title}')

routes = (ROOT / '_data/routes.xml').read_text(encoding='utf-8')
if 'denetim-geribildirim' not in routes:
    raise SystemExit('feedback route missing')

permissions = (ROOT / '_data/permissions.xml').read_text(encoding='utf-8')
for permission in ['warextAuditAppeal', 'warextAuditSuggest']:
    if permission not in permissions:
        raise SystemExit(f'missing permission: {permission}')

setup = (ROOT / 'Setup.php').read_text(encoding='utf-8')
for needle in ['upgrade1000080Step1', 'xf_warext_audit_feedback', 'xf_warext_audit_feedback_event']:
    if needle not in setup:
        raise SystemExit(f'setup integration missing: {needle}')

hashes = {}
for path in sorted(ROOT.rglob('*')):
    if not path.is_file() or path.name == 'hashes.json':
        continue
    rel = path.relative_to(Path('upload')).as_posix()
    hashes[rel] = hashlib.sha256(path.read_bytes()).hexdigest()
(ROOT / 'hashes.json').write_text(json.dumps(hashes, indent=4, sort_keys=True) + '\n', encoding='utf-8')

print(f'Alpha 8 metadata/XML/integration validated; {len(hashes)} hashes generated')
