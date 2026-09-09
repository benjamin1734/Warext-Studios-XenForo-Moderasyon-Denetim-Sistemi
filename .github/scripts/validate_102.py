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
require(addon.get('version_id') == 1000102, 'addon version_id must be 1000102')
require(addon.get('version_string') == '1.0.2', 'addon version_string must be 1.0.2')

for path in sorted(DATA.glob('*.xml')):
    try:
        ET.parse(path)
    except Exception as exc:
        errors.append(f'XML parse failed: {path}: {exc}')

# Previous install regression must stay fixed.
templates = read(DATA / 'templates.xml')
require(' ~ ' not in templates, 'unsupported XenForo concat operator ~ returned')

# Super-admin access helper.
helper = ROOT / 'Support/Permission.php'
require(helper.exists(), 'Support/Permission.php missing')
helper_text = read(helper) if helper.exists() else ''
require('is_super_admin' in helper_text, 'super admin bypass missing from Permission helper')
require("hasPermission('general', $permissionId)" in helper_text, 'normal permission fallback missing')

# No direct Warext permission checks may remain in PHP outside the central helper.
raw_php = re.compile(r"->hasPermission\(\s*'general'\s*,\s*'warextAudit[A-Za-z]+'")
for path in ROOT.rglob('*.php'):
    if path == helper:
        continue
    require(not raw_php.search(read(path)), f'raw Warext permission check bypasses super-admin helper: {path}')

# Template permission expressions must also include the super-admin bypass.
for path in [DATA / 'templates.xml', DATA / 'navigation.xml']:
    text = read(path)
    for m in re.finditer(r"\$xf\.visitor\.hasPermission\('general',\s*'warextAudit[A-Za-z]+'\)", text):
        prefix = text[max(0, m.start() - 80):m.start()]
        require('$xf.visitor.is_super_admin' in prefix, f'template permission lacks super-admin bypass: {path}')

# ACP guard retains normal admin permission but bypasses for super admins.
for rel in ['Admin/Controller/Dashboard.php', 'Admin/Controller/Settings.php']:
    text = read(ROOT / rel)
    require('is_super_admin' in text, f'{rel} super-admin bypass missing')
    require("assertAdminPermission('warextAudit')" in text, f'{rel} normal admin permission guard missing')

# Moderator tools integration uses XenForo's stable hook marker.
mod_path = DATA / 'template_modifications.xml'
require(mod_path.exists(), 'template_modifications.xml missing')
mod = read(mod_path) if mod_path.exists() else ''
for needle in [
    'template="PAGE_CONTAINER"',
    'modification_key="warextAuditModeratorTools"',
    '<!--[XF:mod_tools_menu:top]-->',
    "link('denetim')",
    'warext_audit_moderator_tools',
    '$xf.visitor.is_super_admin'
]:
    require(needle in mod, f'Moderator tools integration missing: {needle}')

phrases = read(DATA / 'phrases.xml')
require('title="warext_audit_moderator_tools"' in phrases, 'moderator tools phrase missing')

navigation = read(DATA / 'navigation.xml')
require('$xf.visitor.is_super_admin' in navigation, 'main navigation does not show for super admin')

# Public routes must remain available.
routes = read(DATA / 'routes.xml')
for prefix in ['denetim', 'denetim-geribildirim', 'denetim-takip']:
    require(f'route_prefix="{prefix}"' in routes, f'public route missing: {prefix}')

# Install tree hygiene.
for path in ROOT.rglob('*'):
    if path.is_file():
        require(path.suffix not in {'.py', '.zip'}, f'development artifact leaked into addon: {path}')
        require('.github' not in path.parts, f'.github leaked into addon: {path}')

# Refresh XenForo hash manifest.
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

print(f'1.0.2 validated; {len(hashes)} SHA-256 hashes generated')
