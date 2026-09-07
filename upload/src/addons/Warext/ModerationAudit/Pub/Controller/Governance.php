<?php

namespace Warext\ModerationAudit\Pub\Controller;

use XF\Mvc\ParameterBag;
use XF\Pub\Controller\AbstractController;

class Governance extends AbstractController
{
    protected function canManage(): bool
    {
        return \XF::visitor()->hasPermission('general', 'warextAuditManage');
    }

    protected function canAccess(): bool
    {
        $visitor = \XF::visitor();
        return $this->canManage()
            || $visitor->hasPermission('general', 'warextAuditView')
            || $visitor->hasPermission('general', 'warextAuditAppeal')
            || $visitor->hasPermission('general', 'warextAuditSuggest');
    }

    protected function governance()
    {
        return $this->service('Warext\ModerationAudit:Audit\GovernanceManager');
    }

    public function actionIndex()
    {
        if (!$this->canAccess())
        {
            return $this->noPermission();
        }

        $visitor = \XF::visitor();
        $isManager = $this->canManage();
        if ($isManager)
        {
            try
            {
                $this->governance()->scan();
            }
            catch (\Throwable $e)
            {
                \XF::logException($e, false, 'Warext ModerationAudit governance dashboard scan: ');
            }
        }

        $noticeFinder = $this->finder('Warext\ModerationAudit:AuditNotice')
            ->where('user_id', $visitor->user_id)
            ->order('created_date', 'DESC')
            ->limit(50);
        $notices = $noticeFinder->fetch();
        $unreadCount = $this->finder('Warext\ModerationAudit:AuditNotice')
            ->where('user_id', $visitor->user_id)
            ->where('read_date', 0)
            ->total();

        $escalations = [];
        $activeCount = 0;
        $finder = $this->finder('Warext\ModerationAudit:AuditEscalation')
            ->order('created_date', 'DESC');
        if ($isManager)
        {
            $finder->limit(100);
            $escalations = $finder->fetch();
            $activeCount = $this->finder('Warext\ModerationAudit:AuditEscalation')
                ->where('resolved_date', 0)
                ->total();
        }
        else
        {
            $all = $finder->limit(200)->fetch();
            foreach ($all as $escalation)
            {
                if ($this->canViewEscalation($escalation, (int)$visitor->user_id))
                {
                    $escalations[] = $escalation;
                    if (!(int)$escalation->resolved_date)
                    {
                        $activeCount++;
                    }
                }
            }
        }

        $rows = [];
        foreach ($escalations as $escalation)
        {
            $rows[] = [
                'entity' => $escalation,
                'integrity_valid' => $this->governance()->verifyEscalation($escalation),
                'resolution_valid' => $this->governance()->verifyResolution($escalation)
            ];
        }

        return $this->view('Warext\ModerationAudit:Governance', 'warext_audit_governance', [
            'notices' => $notices,
            'unreadCount' => $unreadCount,
            'escalationRows' => $rows,
            'activeCount' => $activeCount,
            'isManager' => $isManager
        ]);
    }

    protected function canViewEscalation($escalation, int $userId): bool
    {
        if ((string)$escalation->source_type === 'case')
        {
            $case = $this->app()->em()->find('Warext\ModerationAudit:AuditCase', (int)$escalation->source_id);
            return $case && (int)$case->moderator_user_id === $userId;
        }
        if ((string)$escalation->source_type === 'feedback')
        {
            $feedback = $this->app()->em()->find('Warext\ModerationAudit:AuditFeedback', (int)$escalation->source_id);
            return $feedback && in_array($userId, [(int)$feedback->submitted_by_user_id, (int)$feedback->assigned_to_user_id], true);
        }
        return false;
    }

    public function actionOku(ParameterBag $params)
    {
        if (!$this->canAccess())
        {
            return $this->noPermission();
        }
        $this->assertPostOnly();

        $noticeId = $this->filter('notice_id', 'uint');
        $notice = $this->finder('Warext\ModerationAudit:AuditNotice')
            ->where('notice_id', $noticeId)
            ->fetchOne();
        if (!$notice)
        {
            return $this->notFound();
        }
        try
        {
            $this->governance()->markNoticeRead($notice, \XF::visitor());
        }
        catch (\InvalidArgumentException $e)
        {
            return $this->noPermission($e->getMessage());
        }
        return $this->redirect($this->buildLink('denetim-takip'));
    }

    public function actionCoz(ParameterBag $params)
    {
        if (!$this->canManage())
        {
            return $this->noPermission();
        }
        $this->assertPostOnly();

        $escalationId = $this->filter('escalation_id', 'uint');
        $escalation = $this->finder('Warext\ModerationAudit:AuditEscalation')
            ->where('escalation_id', $escalationId)
            ->fetchOne();
        if (!$escalation)
        {
            return $this->notFound();
        }
        try
        {
            $this->governance()->resolve($escalation, $this->filter('resolution_note', 'str'), \XF::visitor());
        }
        catch (\InvalidArgumentException $e)
        {
            return $this->error($e->getMessage());
        }
        return $this->redirect($this->buildLink('denetim-takip'), 'Eskalasyon çözümlendi.');
    }

    public function actionTara(ParameterBag $params)
    {
        if (!$this->canManage())
        {
            return $this->noPermission();
        }
        $this->assertPostOnly();

        $result = $this->governance()->scan();
        $message = sprintf(
            'Tarama tamamlandı: %d vaka, %d başvuru eskalasyonu, %d otomatik çözüm, %d bildirim.',
            $result['cases'],
            $result['feedback'],
            $result['resolved'],
            $result['notices']
        );
        return $this->redirect($this->buildLink('denetim-takip'), $message);
    }
}
