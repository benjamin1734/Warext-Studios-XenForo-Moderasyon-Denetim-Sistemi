from pathlib import Path
import json
import re

ROOT = Path('upload/src/addons/Warext/ModerationAudit')
DATA = ROOT / '_data'


def update_addon():
    path = ROOT / 'addon.json'
    data = json.loads(path.read_text(encoding='utf-8'))
    data['version_id'] = 1000103
    data['version_string'] = '1.0.3'
    path.write_text(json.dumps(data, ensure_ascii=False, indent=4) + '\n', encoding='utf-8')


def update_snapshot_builder():
    path = ROOT / 'Service/Audit/SnapshotBuilder.php'
    text = path.read_text(encoding='utf-8')

    if "'report_comments' => $this->fetchReportComments($reportId)" not in text:
        old = """                'last_modified_date' => (int)$this->value($report, 'last_modified_date', 0)\n            ]\n        ];"""
        new = """                'last_modified_date' => (int)$this->value($report, 'last_modified_date', 0)\n            ],\n            'report_comments' => $this->fetchReportComments($reportId)\n        ];"""
        if old not in text:
            raise RuntimeError('Could not locate report snapshot block in SnapshotBuilder.php')
        text = text.replace(old, new, 1)

    if 'protected function fetchReportComments(int $reportId): array' not in text:
        marker = '    protected function fetchContent(string $contentType, int $contentId): ?array\n'
        method = r'''    protected function fetchReportComments(int $reportId): array
    {
        if ($reportId <= 0)
        {
            return [];
        }

        try
        {
            $finder = \XF::finder('XF:ReportComment')
                ->where('report_id', $reportId)
                ->order('comment_date', 'ASC')
                ->limit(100);

            $rows = [];
            foreach ($finder->fetch() as $comment)
            {
                $rows[] = $this->genericEntity($comment, [
                    'report_comment_id', 'comment_date', 'user_id', 'username',
                    'message', 'state_change', 'is_report'
                ]);
            }
            return $rows;
        }
        catch (\Throwable $e)
        {
            return [];
        }
    }

'''
        if marker not in text:
            raise RuntimeError('Could not locate fetchContent marker in SnapshotBuilder.php')
        text = text.replace(marker, method + marker, 1)

    path.write_text(text, encoding='utf-8')


def update_viewer():
    path = ROOT / 'Service/Audit/Viewer.php'
    text = path.read_text(encoding='utf-8')

    if "'action_label' => $this->getActionLabel($action)" not in text:
        old = """            'action' => (string)$case->action,\n            'action_date' => (int)$case->action_date,"""
        new = """            'action' => (string)$case->action,\n            'action_label' => $this->getActionLabel((string)$case->action),\n            'source_label' => $this->getSourceLabel((string)$case->source_type),\n            'action_date' => (int)$case->action_date,"""
        if old not in text:
            raise RuntimeError('Could not locate prepareCase action fields')
        text = text.replace(old, new, 1)

    if "'evidence' => $validJson ? $this->buildReadableEvidence($decoded) : []" not in text:
        old = """            'data_hash' => (string)$snapshot->data_hash,\n            'pretty' => $pretty === false ? $raw : (string)$pretty\n        ];"""
        new = """            'data_hash' => (string)$snapshot->data_hash,\n            'pretty' => $pretty === false ? $raw : (string)$pretty,\n            'evidence' => $validJson ? $this->buildReadableEvidence($decoded) : []\n        ];"""
        if old not in text:
            raise RuntimeError('Could not locate prepareSnapshot return block')
        text = text.replace(old, new, 1)

    if 'protected function buildReadableEvidence(array $data): array' not in text:
        marker = '    public function prepareReview(Entity $review, bool $anonymizeAuditor = false): array\n'
        methods = r'''    protected function buildReadableEvidence(array $data): array
    {
        $base = is_array($data['base'] ?? null) ? $data['base'] : [];
        $context = is_array($data['context'] ?? null) ? $data['context'] : [];
        $buffer = is_array($data['buffer'] ?? null)
            ? $data['buffer']
            : (is_array($context['buffer'] ?? null) ? $context['buffer'] : []);

        $content = is_array($data['content'] ?? null) ? $data['content'] : [];
        if (!$content)
        {
            $content = $this->pickBufferedContent($buffer);
        }

        $thread = is_array($context['thread'] ?? null)
            ? $context['thread']
            : (is_array($data['thread'] ?? null) ? $data['thread'] : []);

        $surrounding = [];
        foreach (['surrounding_posts', 'edge_posts'] as $key)
        {
            $candidate = $context[$key] ?? ($data[$key] ?? []);
            if (is_array($candidate) && $candidate)
            {
                $surrounding = array_values(array_filter($candidate, 'is_array'));
                break;
            }
        }

        $comments = [];
        foreach (($data['report_comments'] ?? []) as $comment)
        {
            if (!is_array($comment))
            {
                continue;
            }
            $state = (string)($comment['state_change'] ?? '');
            $comment['state_change_label'] = $this->getReportStateLabel($state);
            $comments[] = $comment;
        }

        $oldState = (string)($base['old_state'] ?? '');
        $newState = (string)($base['new_state'] ?? '');

        return [
            'base' => $base,
            'content' => $content,
            'thread' => $thread,
            'surrounding_posts' => $surrounding,
            'report' => is_array($data['report'] ?? null) ? $data['report'] : [],
            'report_comments' => $comments,
            'user' => is_array($data['user'] ?? null) ? $data['user'] : [],
            'existing' => is_array($data['existing'] ?? null) ? $data['existing'] : [],
            'transition' => [
                'from' => $oldState,
                'from_label' => $this->getReportStateLabel($oldState),
                'to' => $newState,
                'to_label' => $this->getReportStateLabel($newState)
            ]
        ];
    }

    protected function pickBufferedContent(array $buffer): array
    {
        foreach (['before_delete', 'before_save', 'after_save'] as $stage)
        {
            $stageData = $buffer[$stage]['data'] ?? null;
            if (!is_array($stageData))
            {
                continue;
            }
            foreach (['post', 'thread'] as $key)
            {
                if (is_array($stageData[$key] ?? null))
                {
                    return $stageData[$key];
                }
            }
        }
        return [];
    }

    protected function getReportStateLabel(string $state): string
    {
        return match ($state)
        {
            'open' => 'Açık',
            'assigned' => 'Atandı',
            'resolved' => 'Çözüldü',
            'rejected' => 'Reddedildi',
            '' => '—',
            default => $state
        };
    }

    protected function getSourceLabel(string $source): string
    {
        return match ($source)
        {
            'report' => 'Rapor',
            'warning' => 'Uyarı',
            'user_ban' => 'Kullanıcı yasağı',
            'moderator_log' => 'Moderatör işlemi',
            default => $source ?: 'Sistem'
        };
    }

    protected function getActionLabel(string $action): string
    {
        return match ($action)
        {
            'report_state_open' => 'Rapor yeniden açıldı',
            'report_state_assigned' => 'Rapor incelemeye alındı',
            'report_state_resolved' => 'Rapor çözüldü',
            'report_state_rejected' => 'Rapor reddedildi',
            'report_assigned' => 'Rapor ataması değiştirildi',
            'warning_insert' => 'Uyarı verildi',
            'warning_update' => 'Uyarı güncellendi',
            'warning_delete' => 'Uyarı kaldırıldı',
            'user_ban_insert' => 'Kullanıcı yasaklandı',
            'user_ban_update' => 'Yasaklama güncellendi',
            'user_ban_delete' => 'Yasaklama kaldırıldı',
            'delete' => 'İçerik silindi',
            'delete_hard' => 'İçerik kalıcı silindi',
            'undelete' => 'İçerik geri getirildi',
            'approve' => 'İçerik onaylandı',
            'unapprove' => 'İçerik onaydan kaldırıldı',
            'edit' => 'İçerik düzenlendi',
            'move' => 'İçerik taşındı',
            'merge' => 'İçerik birleştirildi',
            'lock' => 'Konu kilitlendi',
            'unlock' => 'Konu kilidi açıldı',
            'stick' => 'Konu sabitlendi',
            'unstick' => 'Konu sabitlemesi kaldırıldı',
            'spam_clean' => 'Spam temizliği uygulandı',
            default => ucfirst(str_replace('_', ' ', $action ?: 'moderasyon işlemi'))
        };
    }

'''
        if marker not in text:
            raise RuntimeError('Could not locate prepareReview marker in Viewer.php')
        text = text.replace(marker, methods + marker, 1)

    path.write_text(text, encoding='utf-8')


def update_templates():
    path = DATA / 'templates.xml'
    text = path.read_text(encoding='utf-8')

    text = text.replace('{$row.action}</span>', '{$row.action_label}</span>')
    text = text.replace('<div><b>İşlem</b><span>{$caseView.action}</span></div>', '<div><b>İşlem</b><span>{$caseView.action_label}</span></div>')
    text = text.replace('<div><b>Kaynak</b><span>{$caseView.source_type} #{$caseView.source_id}</span></div>', '<div><b>Kaynak</b><span>{$caseView.source_label}</span></div>')

    replacement = r'''<div class="block"><div class="block-container"><h2 class="block-header">İşlem ve incelenen içerik</h2><div class="block-body">
<xf:if is="$snapshots">
    <xf:foreach loop="$snapshots" value="$snapshot">
        <xf:if is="!$snapshot.is_sensitive">
            <section class="warextAudit-readableEvidence">
                <div class="warextAudit-evidenceTop">
                    <div><strong>{$caseView.action_label}</strong><span class="warextAudit-muted"> · <xf:date time="$caseView.action_date" /></span></div>
                    <xf:if is="$snapshot.integrity_valid"><span class="warextAudit-integrity is-valid">✓ Kanıt bütünlüğü doğrulandı</span><xf:else /><span class="warextAudit-integrity is-invalid">BÜTÜNLÜK HATASI</span></xf:if>
                </div>

                <xf:if is="$snapshot.evidence.transition.from AND $snapshot.evidence.transition.to">
                    <div class="warextAudit-actionStrip"><b>Durum değişikliği:</b> {$snapshot.evidence.transition.from_label} → {$snapshot.evidence.transition.to_label}</div>
                </xf:if>

                <xf:if is="$caseView.reason"><div class="warextAudit-reason"><b>İşlem gerekçesi:</b> {$caseView.reason}</div></xf:if>

                <xf:if is="$snapshot.evidence.thread.title">
                    <div class="warextAudit-contextTitle"><span>Konu</span><strong>{$snapshot.evidence.thread.title}</strong></div>
                </xf:if>

                <xf:if is="$snapshot.evidence.content">
                    <div class="warextAudit-contentCard">
                        <div class="warextAudit-contentMeta">
                            <strong>{{ $snapshot.evidence.content.username ?: 'Bilinmeyen kullanıcı' }}</strong>
                            <xf:if is="$snapshot.evidence.content.post_date"><span><xf:date time="$snapshot.evidence.content.post_date" /></span></xf:if>
                            <xf:if is="$snapshot.evidence.content.comment_date"><span><xf:date time="$snapshot.evidence.content.comment_date" /></span></xf:if>
                        </div>
                        <xf:if is="$snapshot.evidence.content.title"><div class="warextAudit-contentTitle">{$snapshot.evidence.content.title}</div></xf:if>
                        <xf:if is="$snapshot.evidence.content.message"><div class="warextAudit-contentMessage">{$snapshot.evidence.content.message}</div><xf:else /><div class="warextAudit-muted">Bu içerik türünde kaydedilmiş mesaj metni bulunmuyor.</div></xf:if>
                    </div>
                <xf:elseif is="$snapshot.evidence.user" />
                    <div class="warextAudit-contentCard"><div class="warextAudit-contentMeta"><strong>{{ $snapshot.evidence.user.username ?: $caseView.target_name }}</strong></div><div class="warextAudit-contentMessage">{{ $caseView.reason ?: 'Kullanıcı hakkında uygulanan moderasyon işlemi.' }}</div></div>
                </xf:if>

                <xf:if is="$snapshot.evidence.report_comments">
                    <div class="warextAudit-evidenceSection">
                        <h3>Rapor nedeni ve inceleme kayıtları</h3>
                        <xf:foreach loop="$snapshot.evidence.report_comments" value="$comment">
                            <div class="warextAudit-reportComment {{ $comment.is_report ? 'is-report' : '' }}">
                                <div class="warextAudit-contentMeta"><strong>{{ $comment.is_report ? 'Rapor nedeni' : ($comment.username ?: 'Yetkili notu') }}</strong><span><xf:date time="$comment.comment_date" /></span><xf:if is="$comment.state_change"><span>{$comment.state_change_label}</span></xf:if></div>
                                <xf:if is="$comment.message"><div class="warextAudit-contentMessage">{$comment.message}</div><xf:else /><div class="warextAudit-muted">Metin içermeyen durum değişikliği.</div></xf:if>
                            </div>
                        </xf:foreach>
                    </div>
                </xf:if>

                <xf:if is="$snapshot.evidence.surrounding_posts">
                    <details class="warextAudit-contextDetails">
                        <summary>İçerik bağlamındaki yakın mesajları göster</summary>
                        <div class="warextAudit-contextPosts">
                            <xf:foreach loop="$snapshot.evidence.surrounding_posts" value="$contextPost">
                                <div class="warextAudit-contextPost {{ $contextPost.is_focus ? 'is-focus' : '' }}">
                                    <div class="warextAudit-contentMeta"><strong>{{ $contextPost.username ?: 'Bilinmeyen kullanıcı' }}</strong><xf:if is="$contextPost.post_date"><span><xf:date time="$contextPost.post_date" /></span></xf:if><xf:if is="$contextPost.is_focus"><span>İncelenen içerik</span></xf:if></div>
                                    <div class="warextAudit-contentMessage">{{ $contextPost.message ?: '—' }}</div>
                                </div>
                            </xf:foreach>
                        </div>
                    </details>
                </xf:if>

                <xf:if is="!$snapshot.evidence.content AND !$snapshot.evidence.user AND !$snapshot.evidence.report_comments"><div class="blockMessage">Bu işlem için okunabilir içerik kanıtı bulunamadı. Teknik kanıt kaydı arka planda korunuyor.</div></xf:if>
            </section>
        </xf:if>
    </xf:foreach>
<xf:else /><div class="blockMessage">Görüntülenebilir kanıt yok.</div></xf:if>
</div></div></div>

<xf:if is="$canManageAudit">
<details class="block warextAudit-technical"><summary class="block-header">Teknik detaylar</summary><div class="block-container"><div class="block-body">
    <div class="blockMessage">Bu alan yalnızca sistem doğrulaması içindir; denetim kararında ham JSON okuman gerekmez.</div>
    <h3>Teknik metadata</h3><pre class="warextAudit-json">{$metadata.pretty}</pre>
    <xf:foreach loop="$snapshots" value="$snapshot"><h3>Snapshot #{$snapshot.snapshot_id} · {$snapshot.snapshot_type}</h3><div class="warextAudit-snapshotMeta"><span>{{ $snapshot.is_sensitive ? 'Hassas / orijinal' : 'Maskelenmiş' }}</span><span>SHA-256: <code>{$snapshot.data_hash}</code></span></div><pre class="warextAudit-json">{$snapshot.pretty}</pre></xf:foreach>
</div></div></details>
</xf:if>

<div class="block"><div class="block-container"><h2 class="block-header">Denetçi değerlendirmesi</h2>'''

    pattern = re.compile(
        r'<div class="block"><div class="block-container"><h2 class="block-header">Olay metadatası</h2>.*?<div class="block"><div class="block-container"><h2 class="block-header">Denetçi değerlendirmesi</h2>',
        re.S
    )
    text, count = pattern.subn(replacement, text, count=1)
    if count != 1 and 'İşlem ve incelenen içerik' not in text:
        raise RuntimeError('Could not replace raw metadata/snapshot UI in warext_audit_case')

    for title in ['warext_audit_index', 'warext_audit_case', 'warext_audit.less']:
        pat = re.compile(r'(<template type="public" title="' + re.escape(title) + r'" )version_id="[^"]+" version_string="[^"]+"')
        text = pat.sub(r'\1version_id="1000103" version_string="1.0.3"', text, count=1)

    css_marker = '.warextAudit-readableEvidence'
    if css_marker not in text:
        css = r'''

.warextAudit-readableEvidence{display:flex;flex-direction:column;gap:14px}
.warextAudit-evidenceTop,.warextAudit-contentMeta{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.warextAudit-evidenceTop{justify-content:space-between}
.warextAudit-actionStrip,.warextAudit-reason,.warextAudit-contextTitle{padding:10px 12px;border:1px solid @xf-borderColor;border-radius:@xf-borderRadiusMedium;background:xf-intensify(@xf-contentBg,2%)}
.warextAudit-contextTitle{display:flex;gap:10px;align-items:center}.warextAudit-contextTitle span{color:@xf-textColorMuted}
.warextAudit-contentCard,.warextAudit-reportComment,.warextAudit-contextPost{padding:14px;border:1px solid @xf-borderColor;border-radius:@xf-borderRadiusMedium;background:@xf-contentBg}
.warextAudit-contentMeta{font-size:12px;color:@xf-textColorMuted;margin-bottom:8px}.warextAudit-contentMeta strong{font-size:14px;color:@xf-textColor}
.warextAudit-contentTitle{font-size:16px;font-weight:700;margin-bottom:8px}
.warextAudit-contentMessage{white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.55}
.warextAudit-evidenceSection h3{margin:0 0 10px}.warextAudit-reportComment+.warextAudit-reportComment{margin-top:8px}.warextAudit-reportComment.is-report{border-left:3px solid @xf-paletteAccent2}
.warextAudit-contextDetails{border-top:1px solid @xf-borderColor;padding-top:10px}.warextAudit-contextDetails summary{cursor:pointer;font-weight:600}.warextAudit-contextPosts{display:grid;gap:8px;margin-top:10px}.warextAudit-contextPost.is-focus{box-shadow:inset 3px 0 0 @xf-paletteAccent2}
.warextAudit-technical>summary{cursor:pointer;list-style:none}.warextAudit-technical>summary::-webkit-details-marker{display:none}
'''
        less_pat = re.compile(r'(<template type="public" title="warext_audit\.less"[^>]*><!\[CDATA\[)(.*?)(\]\]></template>)', re.S)
        match = less_pat.search(text)
        if not match:
            raise RuntimeError('warext_audit.less template not found')
        body = match.group(2) + css
        text = text[:match.start()] + match.group(1) + body + match.group(3) + text[match.end():]

    path.write_text(text, encoding='utf-8')


def update_readme():
    path = Path('README.md')
    text = path.read_text(encoding='utf-8')
    text = text.replace('**1.0.2**', '**1.0.3**', 1)
    text = text.replace('Warext-ModerationAudit-1.0.2.zip', 'Warext-ModerationAudit-1.0.3.zip')
    section = '''\n## 1.0.3\n\n- Vaka ekranındaki ham **Olay metadatası** ve büyük JSON **Kanıt anlık görüntüleri** ana görünümden kaldırıldı.\n- Denetçiye artık yapılan işlem, işlem zamanı, durum geçişi, gerçek içerik, konu başlığı, rapor nedeni/notları ve yakın içerik bağlamı okunabilir kartlar halinde gösterilir.\n- SHA-256 doğrulaması arka planda korunur; ana ekranda yalnızca kanıt bütünlüğü sonucu gösterilir.\n- Ham metadata, hash ve snapshot JSON verileri sadece denetim yöneticileri için kapalı **Teknik detaylar** alanında tutulur.\n- Rapor vakalarında rapor yorumları/nedenleri bundan sonraki olay snapshot'larına değişmez kanıt olarak dahil edilir.\n- Teknik action/source kodları yerine kullanıcı dostu Türkçe işlem ve kaynak adları gösterilir.\n'''
    if '## 1.0.3' not in text:
        text += section
    path.write_text(text, encoding='utf-8')


def main():
    update_addon()
    update_snapshot_builder()
    update_viewer()
    update_templates()
    update_readme()
    print('1.0.3 readable evidence integration applied')


if __name__ == '__main__':
    main()
