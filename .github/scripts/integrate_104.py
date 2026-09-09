from pathlib import Path
import json

ROOT = Path('upload/src/addons/Warext/ModerationAudit')
DATA = ROOT / '_data'

# Version bump
addon_path = ROOT / 'addon.json'
addon = json.loads(addon_path.read_text(encoding='utf-8'))
addon['version_id'] = 1000104
addon['version_string'] = '1.0.4'
addon_path.write_text(json.dumps(addon, ensure_ascii=False, indent=4) + '\n', encoding='utf-8')

# The audit center is a staff-only moderation tool. It must not occupy the public main navigation.
(DATA / 'navigation.xml').write_text(
    '<?xml version="1.0" encoding="utf-8"?>\n<navigation>\n</navigation>\n',
    encoding='utf-8'
)

# Keep Moderator tools integration as the public-side entry point for staff.
mod_path = DATA / 'template_modifications.xml'
mod_text = mod_path.read_text(encoding='utf-8')
if 'warextAuditModeratorTools' not in mod_text or "link('denetim')" not in mod_text:
    raise RuntimeError('Moderator tools integration is missing')

readme = Path('README.md')
text = readme.read_text(encoding='utf-8')
text = text.replace('**1.0.3**', '**1.0.4**', 1)
text = text.replace('Warext-ModerationAudit-1.0.3.zip', 'Warext-ModerationAudit-1.0.4.zip')
section = '''\n## 1.0.4\n\n- Public ana navbar üzerindeki `Denetim` sekmesi tamamen kaldırıldı.\n- Moderasyon denetimi artık yalnızca `Moderator tools` menüsünden, doğrudan yetkili URL'lerinden ve ACP'deki bağımsız yönetim bölümünden erişilir.\n- Normal kullanıcıların ana navigasyonunda moderasyon denetim sistemiyle ilgili herhangi bir menü öğesi bulunmaz.\n- Super admin otomatik erişimi ve normal moderatör/denetçi izin kontrolleri korunur.\n'''
if '## 1.0.4' not in text:
    text += section
readme.write_text(text, encoding='utf-8')

print('1.0.4 integration applied')
