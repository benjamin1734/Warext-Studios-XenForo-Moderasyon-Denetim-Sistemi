<?php

namespace Warext\ModerationAudit\Service\Audit;

use Warext\ModerationAudit\Entity\AuditEscalation;
use Warext\ModerationAudit\Entity\AuditNotice;
use XF\Entity\User;
use XF\Service\AbstractService;

class GovernanceManager extends AbstractService
{
    protected function stateInt(string $key, int $default): int
    {
        $repo = $this->app->repository('Warext\ModerationAudit:AuditState');
        return max(1, (int)$repo->get($key, (string)$default));
    }

    public function getCaseSlaHours(string $risk): int
    {
        return match ($risk)
        {
            'critical' => $this->stateInt('sla_case_critical_hours', 12),
            'elevated' => $this->stateInt('sla_case_elevated_hours', 36),
            default => $this->stateInt('sla_case_normal_hours', 72)
        };
    }

    public function getFeedbackSlaHours(string $priority): int
    {
        return match ($priority)
        {
            'critical' => $this->stateInt('sla_feedback_critical_hours', 6),
            'high' => $this->stateInt('sla_feedback_high_hours', 24),
            'low' => $this->stateInt('sla_feedback_low_hours', 96),
            default => $this->stateInt('sla_feedback_normal_hours', 48)
        };
    }

    public function getEscalationLevel(int $baseDate, int $slaHours, ?int $now = null): int
    {
        $now ??= time();
        if ($baseDate <= 0 || $slaHours <= 0)
        {
            return 0;
        }

        $overdue = $now - ($baseDate + ($slaHours * 3600));
        if ($overdue < 0)
        {
            return 0;
        }
        if ($overdue >= ($slaHours * 3600 * 3))
        {
            return 3;
        }
        if ($overdue >= ($slaHours * 3600))
        {
            return 2;
        }
        return 1;
    }

    public function scan(?int $now = null): array
    {
        $now ??= time();
        $result = ['cases' => 0, 'feedback' => 0, 'resolved' => 0, 'notices' => 0];

        $cases = $this->app->finder('Warext\ModerationAudit:AuditCase')
            ->where('status', ['pending', 'assigned', 'in_review', 'reviewed'])
            ->fetch();
        foreach ($cases as $case)
        {
            $sla = $this->getCaseSlaHours((string)$case->risk_level);
            $level = $this->getEscalationLevel((int)$case->action_date, $sla, $now);
            if ($level > 0 && $this->ensureEscalation('case', (int)$case->case_id, $level, "Denetim vakası {$sla} saatlik SLA süresini aştı.", $now))
            {
                $result['cases']++;
                if ((int)$case->moderator_user_id > 0 && $this->ensureNotice((int)$case->moderator_user_id, 'case_escalation_' . $level, 'case', (int)$case->case_id, "Denetim vakası #{$case->case_id} SLA eskalasyon seviyesi {$level} durumuna geçti.", $now))
                {
                    $result['notices']++;
                }
            }
        }

        $feedbackRows = $this->app->finder('Warext\ModerationAudit:AuditFeedback')
            ->where('status', ['open', 'under_review'])
            ->fetch();
        foreach ($feedbackRows as $feedback)
        {
            $sla = $this->getFeedbackSlaHours((string)$feedback->priority);
            $baseDate = (int)($feedback->last_response_date ?: $feedback->created_date);
            $level = $this->getEscalationLevel($baseDate, $sla, $now);
            if ($level > 0 && $this->ensureEscalation('feedback', (int)$feedback->feedback_id, $level, "İtiraz / öneri {$sla} saatlik SLA süresini aştı.", $now))
            {
                $result['feedback']++;
                $targets = array_values(array_unique(array_filter([
                    (int)$feedback->submitted_by_user_id,
                    (int)$feedback->assigned_to_user_id
                ])));
                foreach ($targets as $userId)
                {
                    if ($this->ensureNotice($userId, 'feedback_escalation_' . $level, 'feedback', (int)$feedback->feedback_id, "Başvuru #{$feedback->feedback_id} SLA eskalasyon seviyesi {$level} durumuna geçti.", $now))
                    {
                        $result['notices']++;
                    }
                }
            }
        }

        $result['resolved'] += $this->autoResolveClosedSources($now);
        return $result;
    }

    protected function ensureEscalation(string $sourceType, int $sourceId, int $level, string $reason, int $createdDate): bool
    {
        $exists = $this->app->finder('Warext\ModerationAudit:AuditEscalation')
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('level', $level)
            ->fetchOne();
        if ($exists)
        {
            return false;
        }

        $hashPayload = implode('|', [$sourceType, $sourceId, $level, $reason, $createdDate]);
        /** @var AuditEscalation $entity */
        $entity = $this->app->em()->create('Warext\ModerationAudit:AuditEscalation');
        $entity->source_type = $sourceType;
        $entity->source_id = $sourceId;
        $entity->level = $level;
        $entity->reason = $reason;
        $entity->created_date = $createdDate;
        $entity->event_hash = hash('sha256', $hashPayload);
        $entity->save();
        return true;
    }

    protected function ensureNotice(int $userId, string $noticeType, string $sourceType, int $sourceId, string $message, int $createdDate): bool
    {
        if ($userId <= 0)
        {
            return false;
        }
        $exists = $this->app->finder('Warext\ModerationAudit:AuditNotice')
            ->where('user_id', $userId)
            ->where('notice_type', $noticeType)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->fetchOne();
        if ($exists)
        {
            return false;
        }

        /** @var AuditNotice $notice */
        $notice = $this->app->em()->create('Warext\ModerationAudit:AuditNotice');
        $notice->user_id = $userId;
        $notice->notice_type = $noticeType;
        $notice->source_type = $sourceType;
        $notice->source_id = $sourceId;
        $notice->message = $message;
        $notice->created_date = $createdDate;
        $notice->read_date = 0;
        $notice->save();
        return true;
    }

    public function verifyEscalation(AuditEscalation $escalation): bool
    {
        $payload = implode('|', [
            (string)$escalation->source_type,
            (int)$escalation->source_id,
            (int)$escalation->level,
            (string)$escalation->reason,
            (int)$escalation->created_date
        ]);
        return hash_equals((string)$escalation->event_hash, hash('sha256', $payload));
    }

    public function verifyResolution(AuditEscalation $escalation): bool
    {
        if (!(int)$escalation->resolved_date)
        {
            return true;
        }
        $payload = implode('|', [
            (int)$escalation->escalation_id,
            (int)$escalation->resolved_by_user_id,
            (int)$escalation->resolved_date,
            (string)$escalation->resolution_note
        ]);
        return hash_equals((string)$escalation->resolution_hash, hash('sha256', $payload));
    }

    public function resolve(AuditEscalation $escalation, string $note, ?User $actor = null, ?int $resolvedDate = null): AuditEscalation
    {
        if ((int)$escalation->resolved_date)
        {
            return $escalation;
        }
        $note = trim($note);
        if (mb_strlen($note) < 3 || mb_strlen($note) > 10000)
        {
            throw new \InvalidArgumentException('Çözüm notu 3 ile 10.000 karakter arasında olmalıdır.');
        }
        $resolvedDate ??= time();
        $resolvedBy = $actor ? (int)$actor->user_id : 0;
        $payload = implode('|', [(int)$escalation->escalation_id, $resolvedBy, $resolvedDate, $note]);

        $escalation->resolved_by_user_id = $resolvedBy;
        $escalation->resolved_date = $resolvedDate;
        $escalation->resolution_note = $note;
        $escalation->resolution_hash = hash('sha256', $payload);
        $escalation->save();
        return $escalation;
    }

    protected function autoResolveClosedSources(int $now): int
    {
        $resolved = 0;
        $active = $this->app->finder('Warext\ModerationAudit:AuditEscalation')
            ->where('resolved_date', 0)
            ->fetch();
        foreach ($active as $escalation)
        {
            $closed = false;
            if ((string)$escalation->source_type === 'case')
            {
                $case = $this->app->em()->find('Warext\ModerationAudit:AuditCase', (int)$escalation->source_id);
                $closed = !$case || in_array((string)$case->status, ['final', 'skipped'], true);
            }
            else if ((string)$escalation->source_type === 'feedback')
            {
                $feedback = $this->app->em()->find('Warext\ModerationAudit:AuditFeedback', (int)$escalation->source_id);
                $closed = !$feedback || in_array((string)$feedback->status, ['accepted', 'rejected', 'implemented', 'closed'], true);
            }
            if ($closed)
            {
                $this->resolve($escalation, 'Kaynak kayıt sonuçlandığı için sistem tarafından otomatik kapatıldı.', null, $now);
                $resolved++;
            }
        }
        return $resolved;
    }

    public function markNoticeRead(AuditNotice $notice, User $visitor): AuditNotice
    {
        if ((int)$notice->user_id !== (int)$visitor->user_id)
        {
            throw new \InvalidArgumentException('Bu bildirim bu kullanıcıya ait değil.');
        }
        if (!(int)$notice->read_date)
        {
            $notice->read_date = time();
            $notice->save();
        }
        return $notice;
    }
}
