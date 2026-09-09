<?php

namespace Warext\ModerationAudit\Service\Audit;

use XF\App;
use XF\Mvc\Entity\Entity;
use XF\Service\AbstractService;

class Viewer extends AbstractService
{
    protected array $statusLabels = [
        'pending' => 'Bekliyor',
        'assigned' => 'Atandı',
        'in_review' => 'İnceleniyor',
        'reviewed' => 'İncelendi',
        'final' => 'Sonuçlandı',
        'skipped' => 'Atlandı'
    ];

    protected array $riskLabels = [
        'normal' => 'Normal',
        'elevated' => 'Yükseltilmiş',
        'critical' => 'Kritik'
    ];

    protected array $verdictLabels = [
        'correct' => 'Doğru',
        'partly_correct' => 'Kısmen doğru',
        'wrong' => 'Yanlış',
        'insufficient_evidence' => 'Kanıt yetersiz'
    ];

    protected array $ruleRatingLabels = [
        'correct' => 'Kural doğru uygulandı',
        'partly_correct' => 'Kural kısmen doğru uygulandı',
        'wrong' => 'Kural yanlış uygulandı',
        'not_applicable' => 'Uygulanamaz',
        'insufficient_evidence' => 'Kanıt yetersiz'
    ];

    protected array $penaltyRatingLabels = [
        'proportionate' => 'Orantılı',
        'too_light' => 'Gereğinden hafif',
        'too_severe' => 'Gereğinden ağır',
        'not_applicable' => 'Ceza yok / uygulanamaz',
        'insufficient_evidence' => 'Kanıt yetersiz'
    ];

    protected array $communicationRatingLabels = [
        'professional' => 'Profesyonel / uygun',
        'needs_improvement' => 'İyileştirilmeli',
        'inappropriate' => 'Uygunsuz',
        'not_applicable' => 'İletişim yok / uygulanamaz',
        'insufficient_evidence' => 'Kanıt yetersiz'
    ];

    public function __construct(App $app)
    {
        parent::__construct($app);
    }

    public function getStatusLabels(): array { return $this->statusLabels; }
    public function getRiskLabels(): array { return $this->riskLabels; }
    public function getVerdictLabels(): array { return $this->verdictLabels; }
    public function getRuleRatingLabels(): array { return $this->ruleRatingLabels; }
    public function getPenaltyRatingLabels(): array { return $this->penaltyRatingLabels; }
    public function getCommunicationRatingLabels(): array { return $this->communicationRatingLabels; }

    public function prepareCase(Entity $case): array
    {
        $moderator = $case->Moderator;
        $target = $case->TargetUser;
        $status = (string)$case->status;
        $risk = (string)$case->risk_level;

        return [
            'case_id' => (int)$case->case_id,
            'event_uid' => (string)$case->event_uid,
            'source_type' => (string)$case->source_type,
            'source_id' => (int)$case->source_id,
            'source_log_id' => (int)$case->source_log_id,
            'moderator_user_id' => (int)$case->moderator_user_id,
            'moderator_name' => $moderator ? (string)$moderator->username : ((int)$case->moderator_user_id ? '#' . (int)$case->moderator_user_id : 'Sistem'),
            'target_user_id' => (int)$case->target_user_id,
            'target_name' => $target ? (string)$target->username : ((int)$case->target_user_id ? '#' . (int)$case->target_user_id : '—'),
            'content_type' => (string)$case->content_type,
            'content_id' => (int)$case->content_id,
            'action' => (string)$case->action,
            'action_label' => $this->getActionLabel((string)$case->action),
            'source_label' => $this->getSourceLabel((string)$case->source_type),
            'action_date' => (int)$case->action_date,
            'risk' => $risk,
            'risk_label' => $this->riskLabels[$risk] ?? $risk,
            'status' => $status,
            'status_label' => $this->statusLabels[$status] ?? $status,
            'rule_key' => (string)$case->rule_key,
            'reason' => (string)$case->reason,
            'required_reviews' => (int)$case->required_reviews,
            'priority' => (int)$case->priority,
            'is_critical' => (bool)$case->is_critical,
            'final_verdict' => (string)$case->final_verdict,
            'created_date' => (int)$case->created_date
        ];
    }

    public function prepareSnapshot(Entity $snapshot, array $redactions = []): array
    {
        $raw = (string)$snapshot->snapshot_data;
        $decoded = json_decode($raw, true);
        $validJson = is_array($decoded);

        if ($validJson)
        {
            if ($redactions)
            {
                $policy = new BlindPolicy($this->app);
                $decoded = $policy->redactValue($decoded, $redactions);
            }
            $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        else
        {
            $pretty = $redactions ? (new BlindPolicy($this->app))->redactString($raw, $redactions) : $raw;
        }

        return [
            'snapshot_id' => (int)$snapshot->snapshot_id,
            'snapshot_type' => (string)$snapshot->snapshot_type,
            'is_sensitive' => (bool)$snapshot->is_sensitive,
            'redaction_level' => (string)$snapshot->redaction_level,
            'created_date' => (int)$snapshot->created_date,
            'integrity_valid' => $snapshot->isIntegrityValid(),
            'json_valid' => $validJson,
            'data_hash' => (string)$snapshot->data_hash,
            'pretty' => $pretty === false ? $raw : (string)$pretty,
            'evidence' => $validJson ? $this->buildReadableEvidence($decoded) : []
        ];
    }

    protected function buildReadableEvidence(array $data): array
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

    public function prepareReview(Entity $review, bool $anonymizeAuditor = false): array
    {
        $auditor = $review->Auditor;
        $verdict = (string)$review->verdict;
        $rule = (string)$review->rule_rating;
        $penalty = (string)$review->penalty_rating;
        $communication = (string)$review->communication_rating;

        $revisionCount = $this->app->finder('Warext\ModerationAudit:AuditReviewRevision')
            ->where('review_id', $review->review_id)
            ->total();
        $assignment = $this->app->finder('Warext\ModerationAudit:AuditAssignment')
            ->where('case_id', $review->case_id)
            ->where('auditor_user_id', $review->auditor_user_id)
            ->fetchOne();
        $assignmentStatus = $assignment ? (string)$assignment->status : '';

        return [
            'review_id' => (int)$review->review_id,
            'auditor_user_id' => $anonymizeAuditor ? 0 : (int)$review->auditor_user_id,
            'auditor_name' => $anonymizeAuditor ? 'Bağımsız denetçi' : ($auditor ? (string)$auditor->username : '#' . (int)$review->auditor_user_id),
            'verdict' => $verdict,
            'verdict_label' => $this->verdictLabels[$verdict] ?? ($verdict ?: 'Taslak'),
            'rule_rating' => $rule,
            'rule_rating_label' => $this->ruleRatingLabels[$rule] ?? ($rule ?: '—'),
            'penalty_rating' => $penalty,
            'penalty_rating_label' => $this->penaltyRatingLabels[$penalty] ?? ($penalty ?: '—'),
            'communication_rating' => $communication,
            'communication_rating_label' => $this->communicationRatingLabels[$communication] ?? ($communication ?: '—'),
            'confidence' => (int)$review->confidence,
            'reason' => (string)$review->reason,
            'revision' => (int)$review->revision,
            'revision_count' => $revisionCount,
            'created_date' => (int)$review->created_date,
            'updated_date' => (int)$review->updated_date,
            'locked_date' => (int)$review->locked_date,
            'assignment_status' => $assignmentStatus,
            'counts_for_case' => $assignmentStatus === 'completed'
        ];
    }

    public function prepareReviewRevision(Entity $revision): array
    {
        $raw = (string)$revision->review_data;
        $data = json_decode($raw, true);
        $valid = is_array($data);
        $data = $valid ? $data : [];

        $verdict = (string)($data['verdict'] ?? '');
        $rule = (string)($data['rule_rating'] ?? '');
        $penalty = (string)($data['penalty_rating'] ?? '');
        $communication = (string)($data['communication_rating'] ?? '');

        return [
            'revision_id' => (int)$revision->revision_id,
            'revision' => (int)$revision->revision,
            'created_date' => (int)$revision->created_date,
            'integrity_valid' => method_exists($revision, 'isIntegrityValid') ? $revision->isIntegrityValid() : false,
            'data_hash' => (string)$revision->data_hash,
            'json_valid' => $valid,
            'verdict_label' => $this->verdictLabels[$verdict] ?? ($verdict ?: '—'),
            'rule_rating_label' => $this->ruleRatingLabels[$rule] ?? ($rule ?: '—'),
            'penalty_rating_label' => $this->penaltyRatingLabels[$penalty] ?? ($penalty ?: '—'),
            'communication_rating_label' => $this->communicationRatingLabels[$communication] ?? ($communication ?: '—'),
            'confidence' => (int)($data['confidence'] ?? 0),
            'reason' => (string)($data['reason'] ?? ''),
            'saved_date' => (int)($data['saved_date'] ?? 0)
        ];
    }

    public function prepareMetadata(string $metadata, array $redactions = []): array
    {
        if ($metadata === '')
        {
            return ['valid' => true, 'pretty' => '{}'];
        }

        $decoded = json_decode($metadata, true);
        if (!is_array($decoded))
        {
            $pretty = $redactions ? (new BlindPolicy($this->app))->redactString($metadata, $redactions) : $metadata;
            return ['valid' => false, 'pretty' => $pretty];
        }

        if ($redactions)
        {
            $decoded = (new BlindPolicy($this->app))->redactValue($decoded, $redactions);
        }
        $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return ['valid' => true, 'pretty' => $pretty === false ? $metadata : $pretty];
    }
}
