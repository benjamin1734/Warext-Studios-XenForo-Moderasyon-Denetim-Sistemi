<?php

namespace Warext\ModerationAudit\Service\Audit;

use XF\App;
use XF\Mvc\Entity\Entity;
use XF\Service\AbstractService;

class Recorder extends AbstractService
{
    protected int $startModeratorLogId;
    protected int $auditStartDate;

    public function __construct(App $app)
    {
        parent::__construct($app);

        $stateRepo = $this->repository('Warext\ModerationAudit:AuditState');
        $this->startModeratorLogId = (int)$stateRepo->get('start_moderator_log_id', 0);
        $this->auditStartDate = (int)$stateRepo->get('audit_start_date', 0);
    }

    public function canRecord(array $event): bool
    {
        $actionDate = (int)($event['action_date'] ?? time());
        if ($this->auditStartDate && $actionDate < $this->auditStartDate)
        {
            return false;
        }

        if (($event['source_type'] ?? '') === 'moderator_log')
        {
            $sourceLogId = (int)($event['source_log_id'] ?? 0);
            if ($sourceLogId && $sourceLogId <= $this->startModeratorLogId)
            {
                return false;
            }
        }

        return true;
    }

    public function record(array $event, ?array $snapshot = null): ?Entity
    {
        if (!$this->canRecord($event))
        {
            return null;
        }

        $normalized = $this->normalizeEvent($event);
        $existing = \XF::finder('Warext\ModerationAudit:AuditCase')
            ->where('event_uid', $normalized['event_uid'])
            ->fetchOne();

        if ($existing)
        {
            return $existing;
        }

        $case = \XF::em()->create('Warext\ModerationAudit:AuditCase');
        $case->bulkSet($normalized);

        try
        {
            $case->save();
        }
        catch (\Throwable $e)
        {
            $existing = \XF::finder('Warext\ModerationAudit:AuditCase')
                ->where('event_uid', $normalized['event_uid'])
                ->fetchOne();

            if ($existing)
            {
                return $existing;
            }

            throw $e;
        }

        if ($snapshot !== null)
        {
            $this->recordSnapshot($case->case_id, 'primary', $snapshot, false, 'standard');
        }

        try
        {
            (new AssignmentManager($this->app))->ensureAssignments($case);
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, 'Warext ModerationAudit auto-assignment failed: ');
        }

        return $case;
    }

    public function recordSnapshot(int $caseId, string $type, array $snapshot, bool $isSensitive = false, string $redactionLevel = 'none'): Entity
    {
        $encoded = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $encoded);

        $entity = \XF::finder('Warext\ModerationAudit:AuditSnapshot')
            ->where('case_id', $caseId)
            ->where('snapshot_type', $type)
            ->fetchOne();

        if ($entity)
        {
            if (!hash_equals((string)$entity->data_hash, $hash))
            {
                \XF::logError(sprintf(
                    'Warext ModerationAudit immutable snapshot mismatch. Case %d, type %s.',
                    $caseId,
                    $type
                ));
            }
            return $entity;
        }

        $entity = \XF::em()->create('Warext\ModerationAudit:AuditSnapshot');
        $entity->case_id = $caseId;
        $entity->snapshot_type = $type;
        $entity->snapshot_data = $encoded;
        $entity->data_hash = $hash;
        $entity->is_sensitive = $isSensitive;
        $entity->redaction_level = substr($redactionLevel, 0, 20);
        $entity->created_date = time();

        try
        {
            $entity->save();
        }
        catch (\Throwable $e)
        {
            $existing = \XF::finder('Warext\ModerationAudit:AuditSnapshot')
                ->where('case_id', $caseId)
                ->where('snapshot_type', $type)
                ->fetchOne();
            if ($existing)
            {
                return $existing;
            }
            throw $e;
        }

        return $entity;
    }

    public function recordSnapshotSet(int $caseId, array $snapshots): void
    {
        foreach ($snapshots as $type => $definition)
        {
            if (!is_array($definition) || !array_key_exists('data', $definition))
            {
                continue;
            }

            $this->recordSnapshot(
                $caseId,
                substr((string)$type, 0, 25),
                is_array($definition['data']) ? $definition['data'] : ['value' => $definition['data']],
                !empty($definition['is_sensitive']),
                (string)($definition['redaction_level'] ?? 'none')
            );
        }
    }

    public function verifySnapshot(Entity $snapshot): bool
    {
        $encoded = (string)$snapshot->snapshot_data;
        $expected = (string)$snapshot->data_hash;
        if ($encoded === '' || $expected === '')
        {
            return false;
        }

        return hash_equals($expected, hash('sha256', $encoded));
    }

    protected function normalizeEvent(array $event): array
    {
        $now = time();
        $metadata = $event['metadata'] ?? [];
        if (!is_array($metadata))
        {
            $metadata = ['value' => $metadata];
        }

        $normalized = [
            'source_type' => substr((string)($event['source_type'] ?? 'custom'), 0, 32),
            'source_id' => (int)($event['source_id'] ?? 0),
            'source_log_id' => (int)($event['source_log_id'] ?? 0),
            'moderator_user_id' => (int)($event['moderator_user_id'] ?? 0),
            'target_user_id' => (int)($event['target_user_id'] ?? 0),
            'content_type' => substr((string)($event['content_type'] ?? ''), 0, 25),
            'content_id' => (int)($event['content_id'] ?? 0),
            'action' => substr((string)($event['action'] ?? ''), 0, 50),
            'action_date' => (int)($event['action_date'] ?? $now),
            'risk_level' => $this->normalizeRisk((string)($event['risk_level'] ?? 'normal')),
            'status' => 'pending',
            'rule_key' => substr((string)($event['rule_key'] ?? ''), 0, 50),
            'reason' => substr((string)($event['reason'] ?? ''), 0, 255),
            'required_reviews' => max(1, min(3, (int)($event['required_reviews'] ?? 1))),
            'priority' => max(0, (int)($event['priority'] ?? 0)),
            'is_critical' => !empty($event['is_critical']),
            'final_verdict' => '',
            'finalized_date' => 0,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'created_date' => $now,
            'updated_date' => $now
        ];

        $normalized['event_uid'] = $this->buildEventUid($event, $normalized);
        return $normalized;
    }

    protected function buildEventUid(array $event, array $normalized): string
    {
        if (!empty($event['event_uid']))
        {
            return substr((string)$event['event_uid'], 0, 64);
        }

        $parts = [
            $normalized['source_type'],
            $normalized['source_id'],
            $normalized['source_log_id'],
            $normalized['moderator_user_id'],
            $normalized['target_user_id'],
            $normalized['content_type'],
            $normalized['content_id'],
            $normalized['action'],
            $normalized['action_date']
        ];

        return hash('sha256', implode('|', $parts));
    }

    protected function normalizeRisk(string $risk): string
    {
        return in_array($risk, ['normal', 'elevated', 'critical'], true) ? $risk : 'normal';
    }
}
