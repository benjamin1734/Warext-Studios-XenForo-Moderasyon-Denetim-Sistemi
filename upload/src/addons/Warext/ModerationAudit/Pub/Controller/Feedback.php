<?php

namespace Warext\ModerationAudit\Pub\Controller;

use Warext\ModerationAudit\Entity\AuditFeedback;
use XF\Mvc\ParameterBag;
use XF\Pub\Controller\AbstractController;

class Feedback extends AbstractController
{
    protected function canManage(): bool
    {
        return \Warext\ModerationAudit\Support\Permission::has(\XF::visitor(), 'warextAuditManage');
    }

    protected function canAppeal(): bool
    {
        return \Warext\ModerationAudit\Support\Permission::has(\XF::visitor(), 'warextAuditAppeal');
    }

    protected function canSuggest(): bool
    {
        return \Warext\ModerationAudit\Support\Permission::has(\XF::visitor(), 'warextAuditSuggest');
    }

    protected function canAccessFeedbackCenter(): bool
    {
        return $this->canManage() || $this->canAppeal() || $this->canSuggest();
    }

    protected function getFeedback(int $feedbackId): ?AuditFeedback
    {
        return $this->finder('Warext\ModerationAudit:AuditFeedback')
            ->where('feedback_id', $feedbackId)
            ->with(['Case', 'Submitter', 'Assignee'])
            ->fetchOne();
    }

    protected function canViewFeedback(AuditFeedback $feedback): bool
    {
        return $this->canManage() || (int)$feedback->submitted_by_user_id === (int)\XF::visitor()->user_id;
    }

    protected function assertAppealCaseAccess(int $caseId)
    {
        $case = $this->finder('Warext\ModerationAudit:AuditCase')
            ->where('case_id', $caseId)
            ->fetchOne();
        if (!$case)
        {
            throw $this->exception($this->notFound('Denetim vakası bulunamadı.'));
        }

        if (!$this->canManage() && (int)$case->moderator_user_id !== (int)\XF::visitor()->user_id)
        {
            throw $this->exception($this->noPermission('Yalnızca kendi moderasyon işleminle ilişkili denetim vakası için itiraz oluşturabilirsin.'));
        }

        return $case;
    }

    public function actionIndex()
    {
        if (!$this->canAccessFeedbackCenter())
        {
            return $this->noPermission();
        }

        $isManager = $this->canManage();
        $page = $this->filterPage();
        $perPage = 30;
        $type = $this->filter('type', 'str');
        $status = $this->filter('status', 'str');

        $manager = $this->service('Warext\ModerationAudit:Audit\FeedbackManager');
        if (!in_array($type, ['', 'appeal', 'suggestion'], true))
        {
            $type = '';
        }
        if (!in_array($status, array_merge([''], array_keys($manager->getStatusLabels())), true))
        {
            $status = '';
        }

        $finder = $this->finder('Warext\ModerationAudit:AuditFeedback')
            ->with(['Case', 'Submitter', 'Assignee'])
            ->order('created_date', 'DESC');
        if (!$isManager)
        {
            $finder->where('submitted_by_user_id', \XF::visitor()->user_id);
        }
        if ($type !== '')
        {
            $finder->where('feedback_type', $type);
        }
        if ($status !== '')
        {
            $finder->where('status', $status);
        }

        $finder->limitByPage($page, $perPage);
        $feedback = $finder->fetch();
        $total = $finder->total();

        return $this->view('Warext\ModerationAudit:FeedbackIndex', 'warext_audit_feedback_index', [
            'feedback' => $feedback,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'type' => $type,
            'status' => $status,
            'isManager' => $isManager,
            'canAppeal' => $this->canAppeal(),
            'canSuggest' => $this->canSuggest(),
            'typeLabels' => $manager->getTypeLabels(),
            'statusLabels' => $manager->getStatusLabels(),
            'priorityLabels' => $manager->getPriorityLabels()
        ]);
    }

    public function actionOlustur(ParameterBag $params)
    {
        if (!$this->canAccessFeedbackCenter())
        {
            return $this->noPermission();
        }

        $type = $this->filter('type', 'str');
        if (!in_array($type, ['appeal', 'suggestion'], true))
        {
            $type = $this->canAppeal() ? 'appeal' : 'suggestion';
        }
        if ($type === 'appeal' && !$this->canAppeal() && !$this->canManage())
        {
            return $this->noPermission('İtiraz oluşturma iznin yok.');
        }
        if ($type === 'suggestion' && !$this->canSuggest() && !$this->canManage())
        {
            return $this->noPermission('Öneri oluşturma iznin yok.');
        }

        $caseId = $this->filter('case_id', 'uint');
        $case = null;
        if ($type === 'appeal' && $caseId)
        {
            $case = $this->assertAppealCaseAccess($caseId);
        }

        if ($this->isPost())
        {
            $subject = $this->filter('subject', 'str');
            $message = $this->filter('message', 'str');
            $priority = $this->filter('priority', 'str');

            if ($type === 'appeal')
            {
                if (!$caseId)
                {
                    return $this->error('İtiraz için vaka numarası gereklidir.');
                }
                $this->assertAppealCaseAccess($caseId);
            }
            else
            {
                $caseId = 0;
            }

            try
            {
                $feedback = $this->service('Warext\ModerationAudit:Audit\FeedbackManager')
                    ->create($type, $caseId, $subject, $message, $priority, \XF::visitor());
            }
            catch (\InvalidArgumentException $e)
            {
                return $this->error($e->getMessage());
            }

            return $this->redirect($this->buildLink('denetim-geribildirim/goruntule', null, ['feedback_id' => $feedback->feedback_id]));
        }

        return $this->view('Warext\ModerationAudit:FeedbackCreate', 'warext_audit_feedback_create', [
            'type' => $type,
            'caseId' => $caseId,
            'case' => $case,
            'canAppeal' => $this->canAppeal() || $this->canManage(),
            'canSuggest' => $this->canSuggest() || $this->canManage(),
            'priorityLabels' => $this->service('Warext\ModerationAudit:Audit\FeedbackManager')->getPriorityLabels()
        ]);
    }

    public function actionGoruntule(ParameterBag $params)
    {
        if (!$this->canAccessFeedbackCenter())
        {
            return $this->noPermission();
        }

        $feedbackId = $this->filter('feedback_id', 'uint');
        $feedback = $this->getFeedback($feedbackId);
        if (!$feedback)
        {
            return $this->notFound();
        }
        if (!$this->canViewFeedback($feedback))
        {
            return $this->noPermission();
        }

        $manager = $this->service('Warext\ModerationAudit:Audit\FeedbackManager');
        $events = $this->finder('Warext\ModerationAudit:AuditFeedbackEvent')
            ->where('feedback_id', $feedback->feedback_id)
            ->with('Actor')
            ->order('created_date', 'ASC')
            ->fetch();
        $eventRows = [];
        foreach ($events as $event)
        {
            $eventRows[] = [
                'event' => $event,
                'data' => $manager->decodeEvent($event),
                'integrity_valid' => $manager->verifyEvent($event)
            ];
        }

        return $this->view('Warext\ModerationAudit:FeedbackView', 'warext_audit_feedback_view', [
            'feedback' => $feedback,
            'eventRows' => $eventRows,
            'isManager' => $this->canManage(),
            'typeLabels' => $manager->getTypeLabels(),
            'statusLabels' => $manager->getStatusLabels(),
            'priorityLabels' => $manager->getPriorityLabels()
        ]);
    }

    public function actionYonetim(ParameterBag $params)
    {
        if (!$this->canManage())
        {
            return $this->noPermission();
        }
        $this->assertPostOnly();

        $feedbackId = $this->filter('feedback_id', 'uint');
        $feedback = $this->getFeedback($feedbackId);
        if (!$feedback)
        {
            return $this->notFound();
        }

        try
        {
            $this->service('Warext\ModerationAudit:Audit\FeedbackManager')->managementResponse(
                $feedback,
                $this->filter('response', 'str'),
                $this->filter('status', 'str'),
                $this->filter('assigned_to_user_id', 'uint'),
                \XF::visitor()
            );
        }
        catch (\InvalidArgumentException $e)
        {
            return $this->error($e->getMessage());
        }

        return $this->redirect($this->buildLink('denetim-geribildirim/goruntule', null, ['feedback_id' => $feedback->feedback_id]), 'Yönetim dönüşü kaydedildi.');
    }
}
