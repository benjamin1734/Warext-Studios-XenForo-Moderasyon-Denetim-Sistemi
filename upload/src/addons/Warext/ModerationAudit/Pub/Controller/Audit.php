<?php

namespace Warext\ModerationAudit\Pub\Controller;

use XF\Mvc\ParameterBag;
use XF\Pub\Controller\AbstractController;

class Audit extends AbstractController
{
    protected function canViewAudit(): bool
    {
        return \Warext\ModerationAudit\Support\Permission::has(\XF::visitor(), 'warextAuditView');
    }

    protected function canReviewAudit(): bool
    {
        return \Warext\ModerationAudit\Support\Permission::has(\XF::visitor(), 'warextAuditReview');
    }

    protected function canViewReports(): bool
    {
        return \Warext\ModerationAudit\Support\Permission::has(\XF::visitor(), 'warextAuditReports');
    }

    protected function canViewSensitive(): bool
    {
        return \Warext\ModerationAudit\Support\Permission::has(\XF::visitor(), 'warextAuditSensitive');
    }

    protected function canManageAudit(): bool
    {
        return \Warext\ModerationAudit\Support\Permission::has(\XF::visitor(), 'warextAuditManage');
    }

    protected function getAuditCase(int $caseId)
    {
        return $this->finder('Warext\ModerationAudit:AuditCase')
            ->where('case_id', $caseId)
            ->with(['Moderator', 'TargetUser'])
            ->fetchOne();
    }

    protected function getAssignmentManager()
    {
        return $this->service('Warext\ModerationAudit:Audit\AssignmentManager');
    }

    protected function getBlindPolicy()
    {
        return $this->service('Warext\ModerationAudit:Audit\BlindPolicy');
    }

    protected function canMutateAuditManagement(?string &$error = null): bool
    {
        $error = null;
        if (!$this->canManageAudit())
        {
            $error = 'Denetim havuzunu yönetme iznin yok.';
            return false;
        }
        if ($this->getAssignmentManager()->hasOpenAssignments((int)\XF::visitor()->user_id))
        {
            $error = 'Aktif bir vaka denetimin varken denetçi havuzu veya vaka atamalarını yönetemezsin.';
            return false;
        }
        return true;
    }

    public function actionIndex()
    {
        if (!$this->canViewAudit())
        {
            return $this->noPermission();
        }

        $visitor = \XF::visitor();
        $isManager = $this->canManageAudit();
        if (!$isManager)
        {
            $eligibilityError = null;
            if (!$this->getAssignmentManager()->isPoolEligible($visitor, $eligibilityError))
            {
                return $this->error($eligibilityError ?: 'Bu hesap aktif bağımsız denetçi havuzunda değil.');
            }
        }
        $page = $this->filterPage();
        $perPage = 30;
        $queue = $this->filter('queue', 'str');
        if ($isManager)
        {
            if (!in_array($queue, ['all', 'pending', 'assigned', 'critical'], true))
            {
                $queue = 'pending';
            }
        }
        else
        {
            $queue = 'mine';
        }

        $filters = [
            'status' => $this->filter('status', 'str'),
            'risk' => $this->filter('risk', 'str'),
            'source_type' => trim($this->filter('source_type', 'str')),
            'action' => trim($this->filter('action', 'str')),
            'moderator_user_id' => $isManager ? $this->filter('moderator_user_id', 'uint') : 0,
            'target_user_id' => $isManager ? $this->filter('target_user_id', 'uint') : 0
        ];

        if (!in_array($filters['status'], ['', 'pending', 'assigned', 'in_review', 'reviewed', 'final', 'skipped'], true))
        {
            $filters['status'] = '';
        }
        if (!in_array($filters['risk'], ['', 'normal', 'elevated', 'critical'], true))
        {
            $filters['risk'] = '';
        }

        $repo = $this->repository('Warext\ModerationAudit:AuditCase');
        $finder = $repo->findCasesForAuditCenter($isManager ? $queue : 'all', $filters);
        $ownCaseIds = [];
        if (!$isManager)
        {
            $assignments = $this->finder('Warext\ModerationAudit:AuditAssignment')
                ->where('auditor_user_id', $visitor->user_id)
                ->where('status', ['assigned', 'opened', 'completed'])
                ->fetch();
            foreach ($assignments as $assignment)
            {
                $ownCaseIds[] = (int)$assignment->case_id;
            }
            $finder->where('case_id', $ownCaseIds ?: [0]);
        }

        $finder->limitByPage($page, $perPage);
        $cases = $finder->fetch();
        $total = $finder->total();

        $viewer = $this->service('Warext\ModerationAudit:Audit\Viewer');
        $policy = $this->getBlindPolicy();
        $rows = [];
        foreach ($cases as $case)
        {
            $prepared = $viewer->prepareCase($case);
            $rows[] = $policy->maskPreparedCase($prepared, $case, $visitor);
        }

        if ($isManager)
        {
            $counts = $repo->getAuditCenterCounts();
        }
        else
        {
            $mineFinder = $this->finder('Warext\ModerationAudit:AuditAssignment')
                ->where('auditor_user_id', $visitor->user_id)
                ->where('status', ['assigned', 'opened']);
            $counts = [
                'pending' => 0,
                'assigned' => $mineFinder->total(),
                'critical' => \XF::db()->fetchOne(
                    'SELECT COUNT(*) FROM xf_warext_audit_assignment AS a INNER JOIN xf_warext_audit_case AS c ON (c.case_id = a.case_id) WHERE a.auditor_user_id = ? AND a.status IN (?, ?) AND c.risk_level = ?',
                    [$visitor->user_id, 'assigned', 'opened', 'critical']
                ),
                'active' => $mineFinder->total()
            ];
        }

        $linkParams = array_filter([
            'queue' => $isManager && $queue !== 'pending' ? $queue : null,
            'status' => $filters['status'] ?: null,
            'risk' => $filters['risk'] ?: null,
            'source_type' => $filters['source_type'] ?: null,
            'action' => $filters['action'] ?: null,
            'moderator_user_id' => $filters['moderator_user_id'] ?: null,
            'target_user_id' => $filters['target_user_id'] ?: null
        ], static fn($value) => $value !== null && $value !== '');

        return $this->view('Warext\ModerationAudit:AuditIndex', 'warext_audit_index', [
            'rows' => $rows,
            'queue' => $queue,
            'filters' => $filters,
            'counts' => $counts,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'linkParams' => $linkParams,
            'canViewSensitive' => $this->canViewSensitive(),
            'canManageAudit' => $isManager,
            'statusOptions' => $viewer->getStatusLabels(),
            'riskOptions' => $viewer->getRiskLabels()
        ]);
    }

    public function actionVaka(ParameterBag $params)
    {
        if (!$this->canViewAudit())
        {
            return $this->noPermission();
        }

        $caseId = $this->filter('case_id', 'uint');
        if (!$caseId)
        {
            return $this->notFound();
        }

        $case = $this->getAuditCase($caseId);
        if (!$case)
        {
            return $this->notFound();
        }

        $assignmentManager = $this->getAssignmentManager();
        try
        {
            $assignmentManager->ensureAssignments($case);
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, 'Warext ModerationAudit assignment validation: ');
        }

        $visitor = \XF::visitor();
        $policy = $this->getBlindPolicy();
        if (!$policy->canAccessCase($case, $visitor))
        {
            return $this->noPermission();
        }

        if (!$this->canManageAudit())
        {
            $assignmentManager->markOpened($case, (int)$visitor->user_id);
        }

        $showSensitive = (bool)$this->filter('sensitive', 'bool');
        if ($showSensitive && !$this->canViewSensitive())
        {
            return $this->noPermission();
        }

        $redactions = $policy->getIdentityRedactions($case, $visitor);
        $blindMode = $policy->getBlindMode($case, $visitor);

        $snapshotFinder = $this->finder('Warext\ModerationAudit:AuditSnapshot')
            ->where('case_id', $caseId)
            ->order('is_sensitive', 'ASC')
            ->order('snapshot_id', 'ASC');
        if (!$showSensitive)
        {
            $snapshotFinder->where('is_sensitive', false);
        }

        $assignmentFinder = $this->finder('Warext\ModerationAudit:AuditAssignment')
            ->where('case_id', $caseId)
            ->with('Auditor')
            ->order('assignment_id', 'ASC');
        $reviewFinder = $this->finder('Warext\ModerationAudit:AuditReview')
            ->where('case_id', $caseId)
            ->with('Auditor')
            ->order('review_id', 'ASC');
        $conflictFinder = $this->finder('Warext\ModerationAudit:AuditConflict')
            ->where('case_id', $caseId)
            ->with(['Auditor', 'CreatedBy'])
            ->order('conflict_id', 'ASC');

        if (!$this->canManageAudit())
        {
            $assignmentFinder->where('auditor_user_id', $visitor->user_id);
            $reviewFinder->where('auditor_user_id', $visitor->user_id);
            $conflictFinder->where('auditor_user_id', $visitor->user_id);
        }

        $assignments = $assignmentFinder->fetch();
        $reviews = $reviewFinder->fetch();
        $conflicts = $conflictFinder->fetch();

        $viewer = $this->service('Warext\ModerationAudit:Audit\Viewer');
        $snapshots = [];
        foreach ($snapshotFinder->fetch() as $snapshot)
        {
            $snapshots[] = $viewer->prepareSnapshot($snapshot, $redactions);
        }

        $reviewRows = [];
        foreach ($reviews as $review)
        {
            $reviewRows[] = $viewer->prepareReview($review, !$this->canManageAudit());
        }

        $reviewManager = $this->service('Warext\ModerationAudit:Audit\ReviewManager', $case);
        $currentReview = $reviewManager->getReviewForAuditor((int)$visitor->user_id);
        $reviewError = null;
        $canReviewCase = $this->canReviewAudit() && $reviewManager->canReview($visitor, $reviewError);
        if (!$this->canReviewAudit())
        {
            $reviewError = 'Bu hesabın denetim değerlendirmesi yapma izni yok.';
        }

        $reviewForm = [
            'verdict' => $currentReview ? (string)$currentReview->verdict : '',
            'rule_rating' => $currentReview ? (string)$currentReview->rule_rating : '',
            'penalty_rating' => $currentReview ? (string)$currentReview->penalty_rating : '',
            'communication_rating' => $currentReview ? (string)$currentReview->communication_rating : '',
            'confidence' => $currentReview ? (int)$currentReview->confidence : 75,
            'reason' => $currentReview ? (string)$currentReview->reason : ''
        ];

        $ownAssignment = $assignmentManager->getAssignment($case, (int)$visitor->user_id);
        $canRecuse = !$this->canManageAudit() && $ownAssignment
            && in_array((string)$ownAssignment->status, ['assigned', 'opened'], true)
            && !$currentReview;
        $manageMutationError = null;
        $canManageMutations = $this->canMutateAuditManagement($manageMutationError);

        $caseView = $policy->maskPreparedCase($viewer->prepareCase($case), $case, $visitor);
        return $this->view('Warext\ModerationAudit:AuditCase', 'warext_audit_case', [
            'case' => $case,
            'caseView' => $caseView,
            'metadata' => $viewer->prepareMetadata((string)$case->metadata, $redactions),
            'snapshots' => $snapshots,
            'assignments' => $assignments,
            'reviewRows' => $reviewRows,
            'conflicts' => $conflicts,
            'showSensitive' => $showSensitive,
            'canViewSensitive' => $this->canViewSensitive(),
            'hasSensitiveSnapshots' => $this->finder('Warext\ModerationAudit:AuditSnapshot')
                ->where('case_id', $caseId)
                ->where('is_sensitive', true)
                ->total() > 0,
            'canReviewCase' => $canReviewCase,
            'reviewError' => $reviewError,
            'currentReview' => $currentReview,
            'reviewForm' => $reviewForm,
            'verdictOptions' => $viewer->getVerdictLabels(),
            'ruleRatingOptions' => $viewer->getRuleRatingLabels(),
            'penaltyRatingOptions' => $viewer->getPenaltyRatingLabels(),
            'communicationRatingOptions' => $viewer->getCommunicationRatingLabels(),
            'blindMode' => $blindMode,
            'isBlind' => $blindMode !== 'none',
            'canManageAudit' => $this->canManageAudit(),
            'canManageMutations' => $canManageMutations,
            'manageMutationError' => $manageMutationError,
            'canRecuse' => $canRecuse,
            'ownAssignment' => $ownAssignment
        ]);
    }

    public function actionDegerlendir(ParameterBag $params)
    {
        $this->assertPostOnly();

        if (!$this->canViewAudit() || !$this->canReviewAudit())
        {
            return $this->noPermission();
        }

        $caseId = $this->filter('case_id', 'uint');
        $case = $caseId ? $this->getAuditCase($caseId) : null;
        if (!$case)
        {
            return $this->notFound();
        }
        if (!$this->getBlindPolicy()->canAccessCase($case, \XF::visitor()))
        {
            return $this->noPermission();
        }

        $input = [
            'verdict' => $this->filter('verdict', 'str'),
            'rule_rating' => $this->filter('rule_rating', 'str'),
            'penalty_rating' => $this->filter('penalty_rating', 'str'),
            'communication_rating' => $this->filter('communication_rating', 'str'),
            'confidence' => $this->filter('confidence', 'uint'),
            'reason' => $this->filter('reason', 'str')
        ];

        $manager = $this->service('Warext\ModerationAudit:Audit\ReviewManager', $case);
        $permissionError = null;
        if (!$manager->canReview(\XF::visitor(), $permissionError))
        {
            return $this->error($permissionError ?: 'Bu vakayı değerlendiremezsin.');
        }

        $errors = $manager->validate($manager->normalizeInput($input));
        if ($errors)
        {
            return $this->error($errors);
        }

        try
        {
            $manager->saveReview(\XF::visitor(), $input);
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, 'Warext ModerationAudit review save: ');
            return $this->error('Değerlendirme kaydedilemedi. Lütfen tekrar dene veya sistem kayıtlarını kontrol et.');
        }

        return $this->redirect(
            $this->buildLink('denetim/vaka', null, ['case_id' => $caseId]),
            'Denetim değerlendirmen kaydedildi ve revizyon geçmişine işlendi.'
        );
    }

    public function actionCekil(ParameterBag $params)
    {
        $this->assertPostOnly();
        if (!$this->canViewAudit() || !$this->canReviewAudit() || $this->canManageAudit())
        {
            return $this->noPermission();
        }
        $caseId = $this->filter('case_id', 'uint');
        $reason = $this->filter('reason', 'str');
        $case = $caseId ? $this->getAuditCase($caseId) : null;
        if (!$case || !$this->getBlindPolicy()->canAccessCase($case, \XF::visitor()))
        {
            return $this->notFound();
        }
        try
        {
            $this->getAssignmentManager()->recuse($case, \XF::visitor(), $reason);
        }
        catch (\Throwable $e)
        {
            return $this->error($e->getMessage());
        }
        return $this->redirect($this->buildLink('denetim'), 'Çıkar çatışması kaydedildi, ataman kapatıldı ve yedek denetçi seçimi çalıştırıldı.');
    }

    public function actionCatisma(ParameterBag $params)
    {
        $this->assertPostOnly();
        $manageError = null;
        if (!$this->canMutateAuditManagement($manageError))
        {
            return $this->error($manageError ?: 'Bu işlem için iznin yok.');
        }
        $caseId = $this->filter('case_id', 'uint');
        $auditorUserId = $this->filter('auditor_user_id', 'uint');
        $type = $this->filter('conflict_type', 'str');
        $reason = $this->filter('reason', 'str');
        $case = $caseId ? $this->getAuditCase($caseId) : null;
        if (!$case || !$auditorUserId)
        {
            return $this->notFound();
        }
        if (!$this->getAssignmentManager()->getAssignment($case, $auditorUserId))
        {
            return $this->error('Bu kullanıcı bu vakaya atanmış bir denetçi değil.');
        }
        try
        {
            $this->getAssignmentManager()->markConflict($case, $auditorUserId, $type, $reason, \XF::visitor());
        }
        catch (\Throwable $e)
        {
            return $this->error($e->getMessage());
        }
        return $this->redirect($this->buildLink('denetim/vaka', null, ['case_id' => $caseId]), 'Çıkar çatışması kaydedildi ve gerekiyorsa yedek denetçi atandı.');
    }

    public function actionYenidenAta(ParameterBag $params)
    {
        $this->assertPostOnly();
        $manageError = null;
        if (!$this->canMutateAuditManagement($manageError))
        {
            return $this->error($manageError ?: 'Bu işlem için iznin yok.');
        }
        $caseId = $this->filter('case_id', 'uint');
        $case = $caseId ? $this->getAuditCase($caseId) : null;
        if (!$case)
        {
            return $this->notFound();
        }
        try
        {
            $this->getAssignmentManager()->ensureAssignments($case);
        }
        catch (\Throwable $e)
        {
            return $this->error($e->getMessage());
        }
        return $this->redirect($this->buildLink('denetim/vaka', null, ['case_id' => $caseId]), 'Atama havuzu yeniden değerlendirildi.');
    }

    public function actionHavuz()
    {
        if (!$this->canViewAudit() || !$this->canManageAudit())
        {
            return $this->noPermission();
        }
        $manager = $this->getAssignmentManager();
        $rows = [];
        foreach ($manager->getPool() as $auditor)
        {
            $user = $auditor->User;
            $eligibilityError = null;
            $eligible = $user ? $manager->isPoolEligible($user, $eligibilityError) : false;
            $rows[] = [
                'user_id' => (int)$auditor->user_id,
                'username' => $user ? (string)$user->username : '#' . (int)$auditor->user_id,
                'status' => (string)$auditor->status,
                'max_active_assignments' => (int)$auditor->max_active_assignments,
                'joined_date' => (int)$auditor->joined_date,
                'last_assigned_date' => (int)$auditor->last_assigned_date,
                'note' => (string)$auditor->note,
                'eligible' => $eligible,
                'eligibility_error' => $eligibilityError,
                'open_assignments' => $this->finder('Warext\ModerationAudit:AuditAssignment')->where('auditor_user_id', $auditor->user_id)->where('status', ['assigned', 'opened'])->total(),
                'completed_assignments' => $this->finder('Warext\ModerationAudit:AuditAssignment')->where('auditor_user_id', $auditor->user_id)->where('status', 'completed')->total()
            ];
        }
        $mutationError = null;
        $canMutate = $this->canMutateAuditManagement($mutationError);
        return $this->view('Warext\ModerationAudit:AuditPool', 'warext_audit_pool', [
            'rows' => $rows,
            'canMutate' => $canMutate,
            'mutationError' => $mutationError
        ]);
    }

    public function actionHavuzEkle()
    {
        $this->assertPostOnly();
        $manageError = null;
        if (!$this->canMutateAuditManagement($manageError))
        {
            return $this->error($manageError ?: 'Bu işlem için iznin yok.');
        }
        $username = trim($this->filter('username', 'str'));
        $maxActive = $this->filter('max_active_assignments', 'uint');
        $note = $this->filter('note', 'str');
        $user = $username !== '' ? $this->finder('XF:User')->where('username', $username)->fetchOne() : null;
        if (!$user)
        {
            return $this->error('Kullanıcı bulunamadı.');
        }
        try
        {
            $this->getAssignmentManager()->addAuditor($user, \XF::visitor(), $maxActive ?: 20, $note);
            $this->getAssignmentManager()->assignBacklog(200);
        }
        catch (\Throwable $e)
        {
            return $this->error($e->getMessage());
        }
        return $this->redirect($this->buildLink('denetim/havuz'), 'Kullanıcı bağımsız denetçi havuzuna eklendi.');
    }

    public function actionHavuzDagit()
    {
        $this->assertPostOnly();
        $manageError = null;
        if (!$this->canMutateAuditManagement($manageError))
        {
            return $this->error($manageError ?: 'Bu işlem için iznin yok.');
        }
        try
        {
            $result = $this->getAssignmentManager()->assignBacklog(500);
        }
        catch (\Throwable $e)
        {
            return $this->error($e->getMessage());
        }
        return $this->redirect(
            $this->buildLink('denetim/havuz'),
            sprintf('%d aktif vaka tarandı, %d yeni denetçi ataması oluşturuldu.', (int)$result['processed'], (int)$result['created'])
        );
    }

    public function actionHavuzDurum()
    {
        $this->assertPostOnly();
        $manageError = null;
        if (!$this->canMutateAuditManagement($manageError))
        {
            return $this->error($manageError ?: 'Bu işlem için iznin yok.');
        }
        $userId = $this->filter('user_id', 'uint');
        $status = $this->filter('status', 'str');
        try
        {
            $this->getAssignmentManager()->setAuditorStatus($userId, $status, \XF::visitor());
        }
        catch (\Throwable $e)
        {
            return $this->error($e->getMessage());
        }
        return $this->redirect($this->buildLink('denetim/havuz'), 'Denetçi havuz durumu güncellendi.');
    }

    public function actionRaporlar()
    {
        if (!$this->canViewAudit() || !$this->canViewReports())
        {
            return $this->noPermission();
        }

        $page = $this->filterPage();
        $perPage = 25;
        $finder = $this->finder('Warext\ModerationAudit:AuditReport')->order('period_end', 'DESC');
        $finder->limitByPage($page, $perPage);
        $reports = $finder->fetch();
        $total = $finder->total();

        return $this->view('Warext\ModerationAudit:AuditReports', 'warext_audit_reports', [
            'reports' => $reports,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'canManageAudit' => $this->canManageAudit()
        ]);
    }

    public function actionRapor()
    {
        if (!$this->canViewAudit() || !$this->canViewReports())
        {
            return $this->noPermission();
        }
        $reportId = $this->filter('report_id', 'uint');
        $report = $reportId ? $this->finder('Warext\ModerationAudit:AuditReport')->where('report_id', $reportId)->with('GeneratedBy')->fetchOne() : null;
        if (!$report)
        {
            return $this->notFound();
        }
        $anonymized = (bool)$this->filter('anon', 'bool');
        if (!$this->canManageAudit())
        {
            $anonymized = true;
        }
        $manager = $this->service('Warext\ModerationAudit:Audit\ReportManager');
        $summary = $manager->prepareForDisplay($report, $anonymized);
        return $this->view('Warext\ModerationAudit:AuditReport', 'warext_audit_report', [
            'report' => $report,
            'summary' => $summary,
            'anonymized' => $anonymized,
            'hashValid' => $manager->verify($report),
            'canManageAudit' => $this->canManageAudit()
        ]);
    }

    public function actionRaporOlustur()
    {
        $this->assertPostOnly();
        if (!$this->canViewReports() || !$this->canManageAudit())
        {
            return $this->noPermission();
        }
        $kind = $this->filter('kind', 'str');
        $manager = $this->service('Warext\ModerationAudit:Audit\ReportManager');
        if ($kind === 'previous_week')
        {
            [$start, $end] = $manager->previousWeekRange();
            $type = 'weekly';
        }
        elseif ($kind === 'previous_month')
        {
            [$start, $end] = $manager->previousMonthRange();
            $type = 'monthly';
        }
        else
        {
            $startText = trim($this->filter('start_date', 'str'));
            $endText = trim($this->filter('end_date', 'str'));
            $start = $startText !== '' ? strtotime($startText . ' 00:00:00') : false;
            $endInclusive = $endText !== '' ? strtotime($endText . ' 00:00:00') : false;
            if (!$start || !$endInclusive || $endInclusive < $start)
            {
                return $this->error('Özel rapor için geçerli başlangıç ve bitiş tarihleri seçmelisin.');
            }
            $end = strtotime('+1 day', $endInclusive);
            $type = 'custom';
        }
        try
        {
            $report = $manager->generate($type, (int)$start, (int)$end, (int)\XF::visitor()->user_id, false);
        }
        catch (\Throwable $e)
        {
            return $this->error($e->getMessage());
        }
        return $this->redirect($this->buildLink('denetim/rapor', null, ['report_id' => $report->report_id]), 'Denetim raporu oluşturuldu.');
    }

    public function actionRaporSonuclandir()
    {
        $this->assertPostOnly();
        if (!$this->canViewReports() || !$this->canManageAudit())
        {
            return $this->noPermission();
        }
        $reportId = $this->filter('report_id', 'uint');
        $report = $reportId ? $this->finder('Warext\ModerationAudit:AuditReport')->where('report_id', $reportId)->fetchOne() : null;
        if (!$report)
        {
            return $this->notFound();
        }
        try
        {
            $this->service('Warext\ModerationAudit:Audit\ReportManager')->finalize($report, (int)\XF::visitor()->user_id);
        }
        catch (\Throwable $e)
        {
            return $this->error($e->getMessage());
        }
        return $this->redirect($this->buildLink('denetim/rapor', null, ['report_id' => $reportId]), 'Rapor sonuçlandırıldı ve değiştirilemez olarak kilitlendi.');
    }

    public function actionRevizyonlar(ParameterBag $params)
    {
        if (!$this->canViewAudit())
        {
            return $this->noPermission();
        }

        $reviewId = $this->filter('review_id', 'uint');
        if (!$reviewId)
        {
            return $this->notFound();
        }

        $review = $this->finder('Warext\ModerationAudit:AuditReview')
            ->where('review_id', $reviewId)
            ->with(['Case', 'Auditor'])
            ->fetchOne();
        if (!$review || !$review->Case)
        {
            return $this->notFound();
        }

        if (!$this->canManageAudit() && (int)$review->auditor_user_id !== (int)\XF::visitor()->user_id)
        {
            return $this->noPermission();
        }
        if (!$this->getBlindPolicy()->canAccessCase($review->Case, \XF::visitor()))
        {
            return $this->noPermission();
        }

        $revisions = $this->finder('Warext\ModerationAudit:AuditReviewRevision')
            ->where('review_id', $reviewId)
            ->order('revision', 'DESC')
            ->fetch();

        $viewer = $this->service('Warext\ModerationAudit:Audit\Viewer');
        $revisionRows = [];
        foreach ($revisions as $revision)
        {
            $revisionRows[] = $viewer->prepareReviewRevision($revision);
        }
        $caseView = $viewer->prepareCase($review->Case);
        $caseView = $this->getBlindPolicy()->maskPreparedCase($caseView, $review->Case, \XF::visitor());

        return $this->view('Warext\ModerationAudit:AuditReviewRevisions', 'warext_audit_review_revisions', [
            'review' => $review,
            'reviewView' => $viewer->prepareReview($review, !$this->canManageAudit()),
            'caseView' => $caseView,
            'revisionRows' => $revisionRows
        ]);
    }
}
