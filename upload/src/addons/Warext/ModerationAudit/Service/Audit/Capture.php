<?php

namespace Warext\ModerationAudit\Service\Audit;

use XF\App;
use XF\Mvc\Entity\Entity;
use XF\Service\AbstractService;

class Capture extends AbstractService
{
    protected Recorder $recorder;
    protected SnapshotBuilder $snapshotBuilder;

    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->recorder = new Recorder($app);
        $this->snapshotBuilder = new SnapshotBuilder($app);
    }

    public function recordModeratorLog(Entity $log): ?Entity
    {
        $logId = (int)$this->value($log, 'moderator_log_id', 0);
        if (!$logId)
        {
            return null;
        }

        $action = (string)$this->value($log, 'action', 'moderator_action');
        $risk = $this->classifyModeratorLogRisk($action);
        $params = $this->normalizeParams($this->value($log, 'action_params', []));

        $event = [
            'source_type' => 'moderator_log',
            'source_id' => $logId,
            'source_log_id' => $logId,
            'moderator_user_id' => (int)$this->value($log, 'user_id', 0),
            'target_user_id' => (int)$this->value($log, 'content_user_id', 0),
            'content_type' => (string)$this->value($log, 'content_type', ''),
            'content_id' => (int)$this->value($log, 'content_id', 0),
            'action' => $action,
            'action_date' => (int)$this->value($log, 'log_date', time()),
            'risk_level' => $risk,
            'required_reviews' => $this->requiredReviews($risk),
            'priority' => $this->priority($risk),
            'is_critical' => $risk === 'critical',
            'reason' => $this->extractReason($params),
            'metadata' => [
                'content_username' => (string)$this->value($log, 'content_username', ''),
                'content_title' => (string)$this->value($log, 'content_title', ''),
                'content_url' => (string)$this->value($log, 'content_url', ''),
                'discussion_content_type' => (string)$this->value($log, 'discussion_content_type', ''),
                'discussion_content_id' => (int)$this->value($log, 'discussion_content_id', 0),
                'action_params' => $params
            ]
        ];

        $snapshot = [
            'source' => 'xf_moderator_log',
            'moderator_log_id' => $logId,
            'log_date' => $event['action_date'],
            'moderator_user_id' => $event['moderator_user_id'],
            'target_user_id' => $event['target_user_id'],
            'content_type' => $event['content_type'],
            'content_id' => $event['content_id'],
            'content_username' => $event['metadata']['content_username'],
            'content_title' => $event['metadata']['content_title'],
            'content_url' => $event['metadata']['content_url'],
            'discussion_content_type' => $event['metadata']['discussion_content_type'],
            'discussion_content_id' => $event['metadata']['discussion_content_id'],
            'action' => $action,
            'action_params' => $params
        ];

        $case = $this->recorder->record($event);
        if ($case)
        {
            $this->recorder->recordSnapshotSet((int)$case->case_id, $this->snapshotBuilder->forModeratorLog($log, $snapshot));
        }

        return $case;
    }

    public function recordReportChange(Entity $report): ?Entity
    {
        if ($report->isInsert())
        {
            return null;
        }

        $stateChanged = $this->hasColumn($report, 'report_state') && $report->isChanged('report_state');
        $assigneeChanged = $this->hasColumn($report, 'assigned_user_id') && $report->isChanged('assigned_user_id');
        if (!$stateChanged && !$assigneeChanged)
        {
            return null;
        }

        $reportId = (int)$this->value($report, 'report_id', 0);
        $newState = (string)$this->value($report, 'report_state', '');
        $oldState = $stateChanged ? (string)$report->getExistingValue('report_state') : $newState;
        $oldAssignee = $assigneeChanged ? (int)$report->getExistingValue('assigned_user_id') : (int)$this->value($report, 'assigned_user_id', 0);
        $newAssignee = (int)$this->value($report, 'assigned_user_id', 0);
        $action = $stateChanged ? 'report_state_' . ($newState ?: 'changed') : 'report_assigned';
        $risk = in_array($newState, ['resolved', 'rejected'], true) ? 'elevated' : 'normal';

        $event = [
            'source_type' => 'report',
            'source_id' => $reportId,
            'moderator_user_id' => (int)\XF::visitor()->user_id,
            'target_user_id' => (int)$this->value($report, 'content_user_id', 0),
            'content_type' => (string)$this->value($report, 'content_type', 'report'),
            'content_id' => (int)$this->value($report, 'content_id', 0),
            'action' => $action,
            'action_date' => time(),
            'risk_level' => $risk,
            'required_reviews' => $this->requiredReviews($risk),
            'priority' => $this->priority($risk),
            'metadata' => [
                'report_id' => $reportId,
                'old_state' => $oldState,
                'new_state' => $newState,
                'old_assigned_user_id' => $oldAssignee,
                'new_assigned_user_id' => $newAssignee,
                'report_count' => (int)$this->value($report, 'report_count', 0),
                'comment_count' => (int)$this->value($report, 'comment_count', 0)
            ]
        ];
        $baseSnapshot = [
            'source' => 'xf_report',
            'report_id' => $reportId,
            'old_state' => $oldState,
            'new_state' => $newState,
            'old_assigned_user_id' => $oldAssignee,
            'new_assigned_user_id' => $newAssignee,
            'content_type' => (string)$this->value($report, 'content_type', ''),
            'content_id' => (int)$this->value($report, 'content_id', 0),
            'content_user_id' => (int)$this->value($report, 'content_user_id', 0)
        ];

        $case = $this->recorder->record($event);
        if ($case)
        {
            $this->recorder->recordSnapshotSet((int)$case->case_id, $this->snapshotBuilder->forReport($report, $baseSnapshot));
        }

        return $case;
    }

    public function recordWarning(Entity $warning, string $operation): ?Entity
    {
        $warningId = (int)$this->value($warning, 'warning_id', 0);
        if (!$warningId)
        {
            return null;
        }

        if ($operation === 'update')
        {
            $watched = ['warning_definition_id', 'title', 'points', 'expiry_date', 'is_expired'];
            $changed = false;
            foreach ($watched as $column)
            {
                if ($this->hasColumn($warning, $column) && $warning->isChanged($column))
                {
                    $changed = true;
                    break;
                }
            }
            if (!$changed)
            {
                return null;
            }
        }

        $points = (int)$this->value($warning, 'points', 0);
        $risk = $points >= 10 ? 'elevated' : 'normal';
        $moderatorId = $operation === 'insert'
            ? (int)$this->value($warning, 'warning_user_id', 0)
            : (int)\XF::visitor()->user_id;

        $actionDate = $operation === 'insert'
            ? (int)$this->value($warning, 'warning_date', time())
            : time();

        $event = [
            'event_uid' => 'warning:' . $warningId . ':' . $operation . ':' . substr(hash('sha256', implode('|', [$actionDate, $points, (int)$this->value($warning, 'expiry_date', 0), (string)$this->value($warning, 'title', ''), (int)$this->value($warning, 'is_expired', false)])), 0, 32),
            'source_type' => 'warning',
            'source_id' => $warningId,
            'moderator_user_id' => $moderatorId,
            'target_user_id' => (int)$this->value($warning, 'user_id', 0),
            'content_type' => (string)$this->value($warning, 'content_type', 'user'),
            'content_id' => (int)$this->value($warning, 'content_id', 0),
            'action' => 'warning_' . $operation,
            'action_date' => $actionDate,
            'risk_level' => $risk,
            'required_reviews' => $this->requiredReviews($risk),
            'priority' => $this->priority($risk),
            'reason' => (string)$this->value($warning, 'title', ''),
            'metadata' => [
                'warning_definition_id' => (int)$this->value($warning, 'warning_definition_id', 0),
                'title' => (string)$this->value($warning, 'title', ''),
                'points' => $points,
                'expiry_date' => (int)$this->value($warning, 'expiry_date', 0),
                'is_expired' => (bool)$this->value($warning, 'is_expired', false)
            ]
        ];
        $baseSnapshot = [
            'source' => 'xf_warning',
            'warning_id' => $warningId,
            'operation' => $operation,
            'target_user_id' => (int)$this->value($warning, 'user_id', 0),
            'moderator_user_id' => $moderatorId,
            'content_type' => (string)$this->value($warning, 'content_type', ''),
            'content_id' => (int)$this->value($warning, 'content_id', 0),
            'content_title' => (string)$this->value($warning, 'content_title', ''),
            'title' => (string)$this->value($warning, 'title', ''),
            'points' => $points,
            'expiry_date' => (int)$this->value($warning, 'expiry_date', 0),
            'is_expired' => (bool)$this->value($warning, 'is_expired', false)
        ];

        $case = $this->recorder->record($event);
        if ($case)
        {
            $this->recorder->recordSnapshotSet((int)$case->case_id, $this->snapshotBuilder->forWarning($warning, $operation, $baseSnapshot));
        }

        return $case;
    }

    public function recordUserBan(Entity $ban, string $operation): ?Entity
    {
        $userId = (int)$this->value($ban, 'user_id', 0);
        if (!$userId)
        {
            return null;
        }

        if ($operation === 'update')
        {
            $watched = ['end_date', 'user_reason', 'triggered'];
            $changed = false;
            foreach ($watched as $column)
            {
                if ($this->hasColumn($ban, $column) && $ban->isChanged($column))
                {
                    $changed = true;
                    break;
                }
            }
            if (!$changed)
            {
                return null;
            }
        }

        $endDate = (int)$this->value($ban, 'end_date', 0);
        $banDate = (int)$this->value($ban, 'ban_date', time());
        $permanent = $endDate === 0;
        $longBan = !$permanent && ($endDate - $banDate) >= 30 * 86400;
        $risk = ($permanent || $longBan) ? 'critical' : 'elevated';
        $moderatorId = $operation === 'insert'
            ? (int)$this->value($ban, 'ban_user_id', 0)
            : (int)\XF::visitor()->user_id;
        $actionDate = $operation === 'insert' ? $banDate : time();

        $event = [
            'event_uid' => 'user_ban:' . $userId . ':' . $operation . ':' . substr(hash('sha256', implode('|', [$actionDate, $endDate, (string)$this->value($ban, 'user_reason', ''), (int)$this->value($ban, 'triggered', false)])), 0, 32),
            'source_type' => 'user_ban',
            'source_id' => $userId,
            'moderator_user_id' => $moderatorId,
            'target_user_id' => $userId,
            'content_type' => 'user',
            'content_id' => $userId,
            'action' => 'user_ban_' . $operation,
            'action_date' => $actionDate,
            'risk_level' => $risk,
            'required_reviews' => $this->requiredReviews($risk),
            'priority' => $this->priority($risk),
            'is_critical' => $risk === 'critical',
            'reason' => (string)$this->value($ban, 'user_reason', ''),
            'metadata' => [
                'ban_date' => $banDate,
                'end_date' => $endDate,
                'permanent' => $permanent,
                'triggered' => (bool)$this->value($ban, 'triggered', false)
            ]
        ];
        $baseSnapshot = [
            'source' => 'xf_user_ban',
            'operation' => $operation,
            'target_user_id' => $userId,
            'moderator_user_id' => $moderatorId,
            'ban_date' => $banDate,
            'end_date' => $endDate,
            'permanent' => $permanent,
            'triggered' => (bool)$this->value($ban, 'triggered', false),
            'reason' => (string)$this->value($ban, 'user_reason', '')
        ];

        $case = $this->recorder->record($event);
        if ($case)
        {
            $this->recorder->recordSnapshotSet((int)$case->case_id, $this->snapshotBuilder->forUserBan($ban, $operation, $baseSnapshot));
        }

        return $case;
    }

    protected function classifyModeratorLogRisk(string $action): string
    {
        $action = strtolower($action);

        foreach (['delete_hard', 'spam_clean', 'ban', 'reject', 'discourage'] as $needle)
        {
            if (str_contains($action, $needle))
            {
                return 'critical';
            }
        }

        foreach (['delete', 'warning', 'warn', 'merge', 'move', 'approve', 'unapprove', 'lock', 'unlock', 'edit', 'stick', 'unstick'] as $needle)
        {
            if (str_contains($action, $needle))
            {
                return 'elevated';
            }
        }

        return 'normal';
    }

    protected function requiredReviews(string $risk): int
    {
        return match ($risk)
        {
            'critical' => 3,
            'elevated' => 2,
            default => 1
        };
    }

    protected function priority(string $risk): int
    {
        return match ($risk)
        {
            'critical' => 100,
            'elevated' => 50,
            default => 10
        };
    }

    protected function extractReason(array $params): string
    {
        foreach (['reason', 'delete_reason', 'message', 'note'] as $key)
        {
            if (isset($params[$key]) && is_scalar($params[$key]))
            {
                return substr((string)$params[$key], 0, 255);
            }
        }

        return '';
    }

    protected function normalizeParams(mixed $value): array
    {
        if (is_array($value))
        {
            return $value;
        }

        if (is_string($value) && $value !== '')
        {
            $decoded = json_decode($value, true);
            if (is_array($decoded))
            {
                return $decoded;
            }

            return ['raw' => substr($value, 0, 2000)];
        }

        return [];
    }

    protected function hasColumn(Entity $entity, string $column): bool
    {
        return isset($entity->structure()->columns[$column]);
    }

    protected function value(Entity $entity, string $column, mixed $default = null): mixed
    {
        if (!$this->hasColumn($entity, $column))
        {
            return $default;
        }

        $value = $entity->get($column);
        return $value === null ? $default : $value;
    }
}
