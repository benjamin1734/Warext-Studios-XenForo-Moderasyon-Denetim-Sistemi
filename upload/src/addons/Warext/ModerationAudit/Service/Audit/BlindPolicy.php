<?php

namespace Warext\ModerationAudit\Service\Audit;

use Warext\ModerationAudit\Entity\AuditCase;
use XF\App;
use XF\Entity\User;
use XF\Service\AbstractService;

class BlindPolicy extends AbstractService
{
    public function __construct(App $app)
    {
        parent::__construct($app);
    }

    public function canManage(User $viewer): bool
    {
        return \Warext\ModerationAudit\Support\Permission::has($viewer, 'warextAuditManage');
    }

    public function getAssignment(AuditCase $case, int $userId)
    {
        if (!$userId)
        {
            return null;
        }
        return $this->app->finder('Warext\ModerationAudit:AuditAssignment')
            ->where('case_id', $case->case_id)
            ->where('auditor_user_id', $userId)
            ->fetchOne();
    }

    public function canAccessCase(AuditCase $case, User $viewer): bool
    {
        if ($this->canManage($viewer))
        {
            return true;
        }
        $assignment = $this->getAssignment($case, (int)$viewer->user_id);
        return $assignment && !in_array((string)$assignment->status, ['recused', 'cancelled'], true);
    }

    public function getBlindMode(AuditCase $case, User $viewer): string
    {
        if ($this->canManage($viewer))
        {
            return 'none';
        }
        $assignment = $this->getAssignment($case, (int)$viewer->user_id);
        if (!$assignment)
        {
            return 'full';
        }
        $mode = (string)$assignment->blind_mode;
        return in_array($mode, ['none', 'moderator', 'full'], true) ? $mode : 'moderator';
    }

    public function getIdentityRedactions(AuditCase $case, User $viewer): array
    {
        $mode = $this->getBlindMode($case, $viewer);
        if ($mode === 'none')
        {
            return [];
        }

        $redactions = [];
        $moderator = $case->Moderator;
        $redactions[] = [
            'role' => 'moderator',
            'id' => (int)$case->moderator_user_id,
            'name' => $moderator ? (string)$moderator->username : '',
            'label' => '[DENETLENEN YETKİLİ]'
        ];

        if ($mode === 'full')
        {
            $target = $case->TargetUser;
            $redactions[] = [
                'role' => 'target',
                'id' => (int)$case->target_user_id,
                'name' => $target ? (string)$target->username : '',
                'label' => '[İŞLEM HEDEFİ]'
            ];
        }

        return $redactions;
    }

    public function maskPreparedCase(array $caseView, AuditCase $case, User $viewer): array
    {
        $mode = $this->getBlindMode($case, $viewer);
        if ($mode === 'none')
        {
            return $caseView;
        }

        $caseView['moderator_user_id'] = 0;
        $caseView['moderator_name'] = 'Denetlenen yetkili';
        $caseView['reason'] = $this->redactString((string)$caseView['reason'], $this->getIdentityRedactions($case, $viewer));

        if ($mode === 'full')
        {
            $caseView['target_user_id'] = 0;
            $caseView['target_name'] = 'İşlem hedefi';
        }

        return $caseView;
    }

    public function redactValue($value, array $redactions, ?string $key = null)
    {
        if (is_array($value))
        {
            $result = [];
            foreach ($value as $childKey => $childValue)
            {
                $result[$childKey] = $this->redactValue($childValue, $redactions, (string)$childKey);
            }
            return $result;
        }

        foreach ($redactions as $redaction)
        {
            $id = (int)($redaction['id'] ?? 0);
            $name = (string)($redaction['name'] ?? '');
            $label = (string)($redaction['label'] ?? '[GİZLİ]');
            $role = (string)($redaction['role'] ?? '');
            $lowerKey = strtolower((string)$key);

            if ($id && is_numeric($value) && (int)$value === $id)
            {
                $identityKey = $role === 'moderator'
                    ? (str_contains($lowerKey, 'moderator') || $lowerKey === 'user_id' || str_contains($lowerKey, 'staff'))
                    : (str_contains($lowerKey, 'target') || str_contains($lowerKey, 'content_user') || str_contains($lowerKey, 'reported_user'));
                if ($identityKey)
                {
                    return $label;
                }
            }

            if (is_string($value))
            {
                $value = $this->redactString($value, [$redaction]);
            }
        }

        return $value;
    }

    public function redactString(string $value, array $redactions): string
    {
        foreach ($redactions as $redaction)
        {
            $name = trim((string)($redaction['name'] ?? ''));
            $id = (int)($redaction['id'] ?? 0);
            $label = (string)($redaction['label'] ?? '[GİZLİ]');
            if ($name !== '')
            {
                $value = str_ireplace($name, $label, $value);
            }
            if ($id)
            {
                $quotedId = preg_quote((string)$id, '/');
                $value = preg_replace('/#' . $quotedId . '(?!\d)/', $label, $value) ?? $value;
                $value = preg_replace('/((?:user|moderator|target|content_user)_id\s*[:=]\s*[\"\']?)' . $quotedId . '(?!\d)/i', '$1' . $label, $value) ?? $value;
            }
        }
        return $value;
    }

    public function canSeePeerReviews(User $viewer): bool
    {
        return $this->canManage($viewer);
    }
}
