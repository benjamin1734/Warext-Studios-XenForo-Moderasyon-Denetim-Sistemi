<?php

namespace Warext\ModerationAudit\Service\Audit;

use Warext\ModerationAudit\Entity\AuditAssignment;
use Warext\ModerationAudit\Entity\AuditCase;
use XF\App;
use XF\Entity\User;
use XF\Service\AbstractService;

class AssignmentManager extends AbstractService
{
    public function __construct(App $app)
    {
        parent::__construct($app);
    }

    public function getPool()
    {
        return $this->app->finder('Warext\ModerationAudit:AuditAuditor')
            ->with(['User', 'AssignedBy'])
            ->order('status', 'ASC')
            ->order('last_assigned_date', 'ASC')
            ->order('user_id', 'ASC')
            ->fetch();
    }

    public function addAuditor(User $user, User $manager, int $maxActive = 20, string $note = '')
    {
        $error = null;
        if (!$this->isPoolEligible($user, $error))
        {
            throw new \LogicException($error ?: 'Bu kullanıcı denetçi havuzuna eklenemez.');
        }

        $row = $this->app->finder('Warext\ModerationAudit:AuditAuditor')
            ->where('user_id', $user->user_id)
            ->fetchOne();
        if (!$row)
        {
            $row = $this->app->em()->create('Warext\ModerationAudit:AuditAuditor');
            $row->user_id = $user->user_id;
            $row->joined_date = time();
        }

        $row->status = 'active';
        $row->max_active_assignments = max(1, min(100, $maxActive));
        $row->assigned_by_user_id = (int)$manager->user_id;
        $row->note = substr(trim($note), 0, 255);
        $row->save();
        return $row;
    }

    public function setAuditorStatus(int $userId, string $status, User $manager): void
    {
        if (!in_array($status, ['active', 'paused', 'suspended'], true))
        {
            throw new \InvalidArgumentException('Geçersiz denetçi durumu.');
        }

        $row = $this->app->finder('Warext\ModerationAudit:AuditAuditor')
            ->where('user_id', $userId)
            ->with('User')
            ->fetchOne();
        if (!$row)
        {
            throw new \LogicException('Denetçi havuz kaydı bulunamadı.');
        }

        if ($status === 'active')
        {
            $error = null;
            if (!$row->User || !$this->isPoolEligible($row->User, $error))
            {
                throw new \LogicException($error ?: 'Bu kullanıcı aktif denetçi olamaz.');
            }
        }

        $row->status = $status;
        $row->assigned_by_user_id = (int)$manager->user_id;
        $row->save();

        if ($status !== 'active')
        {
            $this->cancelOpenAssignmentsForAuditor($userId, 'pool_' . $status, 'Denetçi havuz durumu ' . $status . ' olarak değiştirildi.', (int)$manager->user_id);
        }
    }

    public function isPoolEligible(User $user, ?string &$error = null): bool
    {
        $error = null;
        if (!$user->user_id || (string)$user->user_state !== 'valid')
        {
            $error = 'Denetçi hesabı aktif ve doğrulanmış olmalıdır.';
            return false;
        }
        if ((bool)$user->is_admin || (bool)$user->is_moderator)
        {
            $error = 'Aktif XenForo yönetici/moderatör hesapları bağımsız denetçi havuzuna alınamaz.';
            return false;
        }
        if ($user->hasPermission('general', 'warextAuditManage'))
        {
            $error = 'Denetçi havuzu yöneticileri aynı zamanda vaka denetçisi olamaz.';
            return false;
        }
        if (!$user->hasPermission('general', 'warextAuditView') || !$user->hasPermission('general', 'warextAuditReview'))
        {
            $error = 'Kullanıcıda denetim görüntüleme ve değerlendirme izinlerinin ikisi de bulunmalıdır.';
            return false;
        }
        return true;
    }

    public function isEligibleForCase(User $user, AuditCase $case, ?string &$error = null): bool
    {
        $error = null;
        $pool = $this->app->finder('Warext\ModerationAudit:AuditAuditor')
            ->where('user_id', $user->user_id)
            ->fetchOne();
        if (!$pool || (string)$pool->status !== 'active')
        {
            $error = 'Bu kullanıcı aktif bağımsız denetçi havuzunda değil.';
            return false;
        }
        if (!$this->isPoolEligible($user, $error))
        {
            return false;
        }
        if ((int)$case->moderator_user_id === (int)$user->user_id)
        {
            $error = 'Yetkili kendi işlemini denetleyemez.';
            return false;
        }
        if ((int)$case->target_user_id && (int)$case->target_user_id === (int)$user->user_id)
        {
            $error = 'İşlemin hedefindeki kullanıcı bu vakayı denetleyemez.';
            return false;
        }
        $conflict = $this->app->finder('Warext\ModerationAudit:AuditConflict')
            ->where('case_id', $case->case_id)
            ->where('auditor_user_id', $user->user_id)
            ->fetchOne();
        if ($conflict)
        {
            $error = 'Bu kullanıcı için vaka üzerinde çıkar çatışması kaydı var.';
            return false;
        }
        return true;
    }

    public function ensureAssignments(AuditCase $case): array
    {
        if (in_array((string)$case->status, ['final', 'skipped'], true))
        {
            return [];
        }

        $this->revalidateOpenAssignments($case);

        $validAssignments = $this->app->finder('Warext\ModerationAudit:AuditAssignment')
            ->where('case_id', $case->case_id)
            ->where('status', ['assigned', 'opened', 'completed'])
            ->fetch();

        $existingIds = [];
        foreach ($validAssignments as $assignment)
        {
            $existingIds[(int)$assignment->auditor_user_id] = true;
        }

        $required = max(1, min(3, (int)$case->required_reviews));
        $missing = max(0, $required - count($existingIds));
        if (!$missing)
        {
            return [];
        }

        $pool = $this->app->finder('Warext\ModerationAudit:AuditAuditor')
            ->where('status', 'active')
            ->with('User')
            ->fetch();

        $candidates = [];
        foreach ($pool as $auditor)
        {
            $user = $auditor->User;
            if (!$user || isset($existingIds[(int)$user->user_id]))
            {
                continue;
            }
            $error = null;
            if (!$this->isEligibleForCase($user, $case, $error))
            {
                continue;
            }
            $active = $this->getActiveAssignmentCount((int)$user->user_id);
            if ($active >= max(1, (int)$auditor->max_active_assignments))
            {
                continue;
            }
            $candidates[] = [
                'row' => $auditor,
                'user' => $user,
                'active' => $active,
                'recent' => $this->getRecentAssignmentCount((int)$user->user_id),
                'last' => (int)$auditor->last_assigned_date
            ];
        }

        usort($candidates, static function (array $a, array $b): int
        {
            return [$a['active'], $a['recent'], $a['last'], (int)$a['user']->user_id]
                <=> [$b['active'], $b['recent'], $b['last'], (int)$b['user']->user_id];
        });

        $created = [];
        $selectedIds = array_keys($existingIds);
        while ($missing > 0 && $candidates)
        {
            $bestIndex = 0;
            $bestScore = null;
            foreach ($candidates as $index => $candidate)
            {
                $pairPenalty = $this->getPairingPenalty((int)$candidate['user']->user_id, $selectedIds);
                $score = [
                    (int)$candidate['active'],
                    (int)$candidate['recent'],
                    (int)$pairPenalty,
                    (int)$candidate['last'],
                    (int)$candidate['user']->user_id
                ];
                if ($bestScore === null || ($score <=> $bestScore) < 0)
                {
                    $bestScore = $score;
                    $bestIndex = $index;
                }
            }

            $candidate = $candidates[$bestIndex];
            array_splice($candidates, $bestIndex, 1);
            $closedAssignments = $this->app->finder('Warext\ModerationAudit:AuditAssignment')
                ->where('case_id', $case->case_id)
                ->where('status', ['recused', 'cancelled'])
                ->total();
            $assignment = $this->createAssignment($case, (int)$candidate['user']->user_id, $closedAssignments ? 'replacement' : 'auto');
            $candidate['row']->last_assigned_date = time();
            $candidate['row']->save();
            $created[] = $assignment;
            $selectedIds[] = (int)$candidate['user']->user_id;
            $missing--;
        }

        $assignedTotal = $this->app->finder('Warext\ModerationAudit:AuditAssignment')
            ->where('case_id', $case->case_id)
            ->where('status', ['assigned', 'opened', 'completed'])
            ->total();
        if ($assignedTotal > 0 && (string)$case->status === 'pending')
        {
            $case->status = 'assigned';
            $case->updated_date = time();
            $case->save();
        }

        return $created;
    }

    protected function createAssignment(AuditCase $case, int $userId, string $source): AuditAssignment
    {
        $existing = $this->app->finder('Warext\ModerationAudit:AuditAssignment')
            ->where('case_id', $case->case_id)
            ->where('auditor_user_id', $userId)
            ->fetchOne();
        if ($existing)
        {
            return $existing;
        }

        $assignment = $this->app->em()->create('Warext\ModerationAudit:AuditAssignment');
        $assignment->case_id = $case->case_id;
        $assignment->auditor_user_id = $userId;
        $assignment->status = 'assigned';
        $stateRepo = $this->repository('Warext\ModerationAudit:AuditState');
        $blindKey = (string)$case->risk_level . '_blind_mode';
        $blindMode = (string)$stateRepo->get($blindKey, (string)$case->risk_level === 'critical' ? 'full' : 'moderator');
        $assignment->blind_mode = in_array($blindMode, ['none', 'moderator', 'full'], true) ? $blindMode : 'moderator';
        $assignment->assignment_source = in_array($source, ['auto', 'replacement', 'legacy'], true) ? $source : 'auto';
        $assignment->assigned_by_user_id = 0;
        $assignment->replacement_for_assignment_id = 0;
        $assignment->assigned_date = time();
        try
        {
            $assignment->save();
        }
        catch (\Throwable $e)
        {
            $existing = $this->app->finder('Warext\ModerationAudit:AuditAssignment')
                ->where('case_id', $case->case_id)
                ->where('auditor_user_id', $userId)
                ->fetchOne();
            if ($existing)
            {
                return $existing;
            }
            throw $e;
        }
        return $assignment;
    }

    public function assignBacklog(int $limit = 500): array
    {
        $limit = max(1, min(1000, $limit));
        $cases = $this->app->finder('Warext\ModerationAudit:AuditCase')
            ->where('status', ['pending', 'assigned', 'in_review'])
            ->order('priority', 'DESC')
            ->order('action_date', 'ASC')
            ->limit($limit)
            ->fetch();
        $processed = 0;
        $created = 0;
        foreach ($cases as $case)
        {
            $processed++;
            $created += count($this->ensureAssignments($case));
        }
        return ['processed' => $processed, 'created' => $created];
    }

    public function markOpened(AuditCase $case, int $userId): void
    {
        $assignment = $this->getAssignment($case, $userId);
        if (!$assignment || (string)$assignment->status !== 'assigned')
        {
            return;
        }
        $assignment->status = 'opened';
        $assignment->opened_date = time();
        $assignment->save();
        if ((string)$case->status === 'assigned')
        {
            $case->status = 'in_review';
            $case->updated_date = time();
            $case->save();
        }
    }

    public function recuse(AuditCase $case, User $auditor, string $reason): void
    {
        $reason = trim($reason);
        if ((function_exists('mb_strlen') ? mb_strlen($reason) : strlen($reason)) < 10)
        {
            throw new \InvalidArgumentException('Çekilme gerekçesi en az 10 karakter olmalıdır.');
        }
        $assignment = $this->getAssignment($case, (int)$auditor->user_id);
        if (!$assignment || !in_array((string)$assignment->status, ['assigned', 'opened'], true))
        {
            throw new \LogicException('Bu vakada çekilebileceğin aktif bir atama yok.');
        }
        if ($this->app->finder('Warext\ModerationAudit:AuditReview')->where('case_id', $case->case_id)->where('auditor_user_id', $auditor->user_id)->fetchOne())
        {
            throw new \LogicException('Değerlendirme kaydından sonra çekilme yapılamaz; denetim yöneticisine başvurulmalıdır.');
        }

        $this->recordConflict($case, (int)$auditor->user_id, 'self_recusal', $reason, (int)$auditor->user_id);
        $assignment->status = 'recused';
        $assignment->completed_date = time();
        $assignment->save();
        $this->ensureAssignments($case);
    }

    public function markConflict(AuditCase $case, int $auditorUserId, string $type, string $reason, User $manager): void
    {
        $reason = trim($reason);
        if ((function_exists('mb_strlen') ? mb_strlen($reason) : strlen($reason)) < 10)
        {
            throw new \InvalidArgumentException('Çıkar çatışması gerekçesi en az 10 karakter olmalıdır.');
        }
        $type = preg_replace('/[^a-z0-9_\-]/i', '', $type) ?: 'manager_conflict';
        $this->recordConflict($case, $auditorUserId, substr($type, 0, 30), $reason, (int)$manager->user_id);

        $assignment = $this->getAssignment($case, $auditorUserId);
        if ($assignment && in_array((string)$assignment->status, ['assigned', 'opened', 'completed'], true))
        {
            $assignment->status = 'cancelled';
            if (!$assignment->completed_date)
            {
                $assignment->completed_date = time();
            }
            $assignment->save();
            if ((string)$case->status === 'reviewed')
            {
                $case->status = 'assigned';
                $case->updated_date = time();
                $case->save();
            }
        }
        $this->ensureAssignments($case);
    }

    public function getAssignment(AuditCase $case, int $userId): ?AuditAssignment
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

    public function hasOpenAssignments(int $userId): bool
    {
        return $userId > 0 && $this->app->finder('Warext\ModerationAudit:AuditAssignment')
            ->where('auditor_user_id', $userId)
            ->where('status', ['assigned', 'opened'])
            ->total() > 0;
    }

    public function revalidateOpenAssignments(AuditCase $case): void
    {
        $assignments = $this->app->finder('Warext\ModerationAudit:AuditAssignment')
            ->where('case_id', $case->case_id)
            ->where('status', ['assigned', 'opened'])
            ->with('Auditor')
            ->fetch();
        foreach ($assignments as $assignment)
        {
            $user = $assignment->Auditor;
            $error = null;
            if (!$user || !$this->isEligibleForCase($user, $case, $error))
            {
                $assignment->status = 'cancelled';
                $assignment->completed_date = time();
                $assignment->save();
                $this->recordConflict($case, (int)$assignment->auditor_user_id, 'role_ineligible', $error ?: 'Denetçi artık vaka için uygun değil.', 0);
            }
        }
    }

    public function cancelOpenAssignmentsForAuditor(int $userId, string $type, string $reason, int $createdBy): void
    {
        $assignments = $this->app->finder('Warext\ModerationAudit:AuditAssignment')
            ->where('auditor_user_id', $userId)
            ->where('status', ['assigned', 'opened'])
            ->with('Case')
            ->fetch();
        $cases = [];
        foreach ($assignments as $assignment)
        {
            if (!$assignment->Case)
            {
                continue;
            }
            $case = $assignment->Case;
            $assignment->status = 'cancelled';
            $assignment->completed_date = time();
            $assignment->save();
            $this->recordConflict($case, $userId, $type, $reason, $createdBy);
            $cases[(int)$case->case_id] = $case;
        }
        foreach ($cases as $case)
        {
            $this->ensureAssignments($case);
        }
    }

    protected function recordConflict(AuditCase $case, int $auditorUserId, string $type, string $reason, int $createdBy): void
    {
        $existing = $this->app->finder('Warext\ModerationAudit:AuditConflict')
            ->where('case_id', $case->case_id)
            ->where('auditor_user_id', $auditorUserId)
            ->where('conflict_type', $type)
            ->fetchOne();
        if ($existing)
        {
            return;
        }
        $conflict = $this->app->em()->create('Warext\ModerationAudit:AuditConflict');
        $conflict->case_id = $case->case_id;
        $conflict->auditor_user_id = $auditorUserId;
        $conflict->conflict_type = substr($type, 0, 30);
        $conflict->reason = substr(trim($reason), 0, 255);
        $conflict->created_by_user_id = $createdBy;
        $conflict->created_date = time();
        $conflict->save();
    }

    protected function getActiveAssignmentCount(int $userId): int
    {
        return $this->app->finder('Warext\ModerationAudit:AuditAssignment')
            ->where('auditor_user_id', $userId)
            ->where('status', ['assigned', 'opened'])
            ->total();
    }

    protected function getRecentAssignmentCount(int $userId): int
    {
        return $this->app->finder('Warext\ModerationAudit:AuditAssignment')
            ->where('auditor_user_id', $userId)
            ->where('assigned_date', '>=', time() - 2592000)
            ->total();
    }

    protected function getPairingPenalty(int $candidateId, array $selectedIds): int
    {
        if (!$selectedIds)
        {
            return 0;
        }
        $penalty = 0;
        foreach ($selectedIds as $selectedId)
        {
            $penalty += (int)$this->app->db()->fetchOne(
                'SELECT COUNT(DISTINCT a1.case_id) FROM xf_warext_audit_assignment AS a1 INNER JOIN xf_warext_audit_assignment AS a2 ON (a2.case_id = a1.case_id) WHERE a1.auditor_user_id = ? AND a2.auditor_user_id = ? AND a1.assigned_date >= ?',
                [$candidateId, (int)$selectedId, time() - 2592000]
            );
        }
        return $penalty;
    }
}
