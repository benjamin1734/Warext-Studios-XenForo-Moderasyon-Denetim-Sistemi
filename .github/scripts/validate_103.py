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
require(addon.get('version_id') == 1000103, 'addon version_id must be 1000103')
require(addon.get('version_string') == '1.0.3', 'addon version_string must be 1.0.3')

for path in sorted(DATA.glob('*.xml')):
    try:
        ET.parse(path)
    except Exception as exc:
        errors.append(f'XML parse failed: {path}: {exc}')

templates = read(DATA / 'templates.xml')
require(' ~ ' not in templates, 'unsupported XenForo concat operator ~ returned')
require('İşlem ve incelenen içerik' in templates, 'readable evidence block missing')
require('✓ Kanıt bütünlüğü doğrulandı' in templates, 'integrity status missing')
require('Rapor nedeni ve inceleme kayıtları' in templates, 'report reason UI missing')
require('İçerik bağlamındaki yakın mesajları göster' in templates, 'context disclosure missing')
require('Teknik detaylar' in templates, 'manager technical details disclosure missing')
require('<h2 class="block-header">Olay metadatası</h2>' not in templates, 'old raw metadata block still visible')
require('<h2 class="block-header">Kanıt anlık görüntüleri</h2>' not in templates, 'old raw snapshot block still visible')
require('$canManageAudit' in templates and 'Teknik metadata' in templates, 'technical JSON is not manager-gated')
require('warextAudit-readableEvidence' in templates, 'readable evidence styling missing')
require('{$caseView.action_label}' in templates, 'friendly action label not used on case page')

viewer = read(ROOT / 'Service/Audit/Viewer.php')
for needle in [
    "'action_label' => $this->getActionLabel((string)$case->action)",
    "'source_label' => $this->getSourceLabel((string)$case->source_type)",
    "'evidence' => $validJson ? $this->buildReadableEvidence($decoded) : []",
    'protected function buildReadableEvidence(array $data): array',
    'protected function pickBufferedContent(array $buffer): array',
    'protected function getActionLabel(string $action): string',
    'protected function getReportStateLabel(string $state): string'
]:
    require(needle in viewer, f'Viewer readable evidence feature missing: {needle}')

snapshot_builder = read(ROOT / 'Service/Audit/SnapshotBuilder.php')
require("'report_comments' => $this->fetchReportComments($reportId)" in snapshot_builder, 'report comments not captured in report snapshot')
require('protected function fetchReportComments(int $reportId): array' in snapshot_builder, 'report comment snapshot helper missing')
require("\\XF::finder('XF:ReportComment')" in snapshot_builder, 'ReportComment finder missing')
require("'message', 'state_change', 'is_report'" in snapshot_builder, 'report comment evidence fields incomplete')

# Preserve 1.0.2 access/menu fixes.
permission_helper = read(ROOT / 'Support/Permission.php')
require('is_super_admin' in permission_helper and "hasPermission('general', $permissionId)" in permission_helper, 'super admin permission bypass missing')
modifications = read(DATA / 'template_modifications.xml')
require('<!--[XF:mod_tools_menu:top]-->' in modifications, 'Moderator tools hook missing')
require("$xf.visitor.is_super_admin OR $xf.visitor.hasPermission('general', 'warextAuditView')" in modifications, 'Moderator tools super admin access missing')

# Public route/controllers still present.
routes = read(DATA / 'routes.xml')
for needle in ['route_prefix="denetim"', 'route_prefix="denetim-geribildirim"', 'route_prefix="denetim-takip"']:
    require(needle in routes, f'missing public route: {needle}')

# Source tree must be clean.
for path in ROOT.rglob('*'):
    if not path.is_file():
        continue
    require(path.suffix not in {'.py', '.zip'}, f'development artifact leaked into addon source: {path}')
    require('.github' not in path.parts, f'.github leaked into addon source: {path}')

# Refresh XenForo package hash manifest after integration.
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

print(f'1.0.3 validated; {len(hashes)} SHA-256 hashes generated')
