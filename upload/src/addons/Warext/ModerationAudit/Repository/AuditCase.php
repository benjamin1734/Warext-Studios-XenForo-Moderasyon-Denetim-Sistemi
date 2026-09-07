<?php

namespace Warext\ModerationAudit\Repository;

use XF\Mvc\Entity\Finder;
use XF\Mvc\Entity\Repository;

class AuditCase extends Repository
{
    public function findPendingCases(): Finder
    {
        return $this->finder('Warext\ModerationAudit:AuditCase')
            ->where('status', ['pending', 'assigned', 'in_review'])
            ->setDefaultOrder('priority', 'DESC')
            ->order('action_date', 'ASC');
    }

    public function findCasesForModerator(int $userId): Finder
    {
        return $this->finder('Warext\ModerationAudit:AuditCase')
            ->where('moderator_user_id', $userId)
            ->setDefaultOrder('action_date', 'DESC');
    }

    public function findCasesInPeriod(int $start, int $end): Finder
    {
        return $this->finder('Warext\ModerationAudit:AuditCase')
            ->where('action_date', '>=', $start)
            ->where('action_date', '<=', $end)
            ->setDefaultOrder('action_date', 'DESC');
    }

    public function findCasesForAuditCenter(string $queue, array $filters = []): Finder
    {
        $finder = $this->finder('Warext\ModerationAudit:AuditCase')
            ->with(['Moderator', 'TargetUser']);

        if ($queue === 'pending')
        {
            $finder->where('status', 'pending');
        }
        elseif ($queue === 'assigned')
        {
            $finder->where('status', ['assigned', 'in_review']);
        }
        elseif ($queue === 'critical')
        {
            $finder->where('risk_level', 'critical')
                ->where('status', ['pending', 'assigned', 'in_review']);
        }

        if (!empty($filters['status']))
        {
            $finder->where('status', (string)$filters['status']);
        }
        if (!empty($filters['risk']))
        {
            $finder->where('risk_level', (string)$filters['risk']);
        }
        if (!empty($filters['source_type']))
        {
            $finder->where('source_type', (string)$filters['source_type']);
        }
        if (!empty($filters['action']))
        {
            $finder->where('action', (string)$filters['action']);
        }
        if (!empty($filters['moderator_user_id']))
        {
            $finder->where('moderator_user_id', (int)$filters['moderator_user_id']);
        }
        if (!empty($filters['target_user_id']))
        {
            $finder->where('target_user_id', (int)$filters['target_user_id']);
        }

        return $finder
            ->order('priority', 'DESC')
            ->order('action_date', 'DESC')
            ->order('case_id', 'DESC');
    }

    public function getAuditCenterCounts(): array
    {
        return [
            'pending' => $this->finder('Warext\ModerationAudit:AuditCase')
                ->where('status', 'pending')
                ->total(),
            'assigned' => $this->finder('Warext\ModerationAudit:AuditCase')
                ->where('status', ['assigned', 'in_review'])
                ->total(),
            'critical' => $this->finder('Warext\ModerationAudit:AuditCase')
                ->where('risk_level', 'critical')
                ->where('status', ['pending', 'assigned', 'in_review'])
                ->total(),
            'active' => $this->finder('Warext\ModerationAudit:AuditCase')
                ->where('status', ['pending', 'assigned', 'in_review'])
                ->total()
        ];
    }
}
