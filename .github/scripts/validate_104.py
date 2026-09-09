from pathlib import Path
import hashlib
import json
import sys
import xml.etree.ElementTree as ET

ROOT = Path('upload/src/addons/Warext/ModerationAudit')
DATA = ROOT / '_data'
errors = []


def require(ok, message):
    if not ok:
        errors.append(message)

addon = json.loads((ROOT / 'addon.json').read_text(encoding='utf-8'))
require(addon.get('version_id') == 1000104, 'version_id must be 1000104')
require(addon.get('version_string') == '1.0.4', 'version_string must be 1.0.4')

for path in sorted(DATA.glob('*.xml')):
    try:
        ET.parse(path)
    except Exception as exc:
        errors.append(f'XML parse failed: {path}: {exc}')

nav_root = ET.parse(DATA / 'navigation.xml').getroot()
entries = nav_root.findall('navigation_entry')
require(len(entries) == 0, 'public navigation.xml must contain no audit navbar entry')
require('warextAudit' not in (DATA / 'navigation.xml').read_text(encoding='utf-8'), 'warextAudit leaked into public main navigation')

mods = (DATA / 'template_modifications.xml').read_text(encoding='utf-8')
require('warextAuditModeratorTools' in mods, 'Moderator tools template modification missing')
require('<!--[XF:mod_tools_menu:top]-->' in mods, 'official Moderator tools hook missing')
require("link('denetim')" in mods, 'Moderator tools audit link missing')
require("warextAuditView" in mods, 'Moderator tools permission guard missing')
require('is_super_admin' in mods, 'Moderator tools super admin access missing')

# Existing security route guards must remain in source.
for controller in ['Audit.php', 'Feedback.php', 'Governance.php']:
    path = ROOT / 'Pub/Controller' / controller
    require(path.exists(), f'missing public controller: {controller}')

# Regenerate source hash manifest.
hashes = {}
for path in sorted(ROOT.rglob('*')):
    if not path.is_file() or path.name == 'hashes.json':
        continue
    rel = path.relative_to(Path('upload')).as_posix()
    hashes[rel] = hashlib.sha256(path.read_bytes()).hexdigest()
(ROOT / 'hashes.json').write_text(json.dumps(hashes, indent=4, sort_keys=True) + '\n', encoding='utf-8')

if errors:
    for error in errors:
        print('ERROR:', error, file=sys.stderr)
    raise SystemExit(1)

print(f'1.0.4 validated; {len(hashes)} SHA-256 hashes generated')
