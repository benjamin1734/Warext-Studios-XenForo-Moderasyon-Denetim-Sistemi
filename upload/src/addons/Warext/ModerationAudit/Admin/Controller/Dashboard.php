<?php

namespace Warext\ModerationAudit\Admin\Controller;

use XF\Admin\Controller\AbstractController;
use XF\Mvc\ParameterBag;

class Dashboard extends AbstractController
{
    public function actionIndex()
    {
        $state = $this->repository('Warext\ModerationAudit:AuditState');

        $viewParams = [
            'version' => '1.0.1',
            'schemaVersion' => (int)$state->get('schema_version', '0'),
            'caseCount' => $this->finder('Warext\ModerationAudit:AuditCase')->total(),
            'openCaseCount' => $this->finder('Warext\ModerationAudit:AuditCase')
                ->where('status', ['pending', 'assigned', 'in_review', 'reviewed'])
                ->total(),
            'feedbackCount' => $this->finder('Warext\ModerationAudit:AuditFeedback')->total(),
            'openFeedbackCount' => $this->finder('Warext\ModerationAudit:AuditFeedback')
                ->where('status', ['open', 'under_review'])
                ->total(),
            'activeEscalationCount' => $this->finder('Warext\ModerationAudit:AuditEscalation')
                ->where('resolved_date', 0)
                ->total(),
            'settings' => [
                'sla_case_normal_hours' => (int)$state->get('sla_case_normal_hours', '72'),
                'sla_case_elevated_hours' => (int)$state->get('sla_case_elevated_hours', '36'),
                'sla_case_critical_hours' => (int)$state->get('sla_case_critical_hours', '12'),
                'sla_feedback_low_hours' => (int)$state->get('sla_feedback_low_hours', '96'),
                'sla_feedback_normal_hours' => (int)$state->get('sla_feedback_normal_hours', '48'),
                'sla_feedback_high_hours' => (int)$state->get('sla_feedback_high_hours', '24'),
                'sla_feedback_critical_hours' => (int)$state->get('sla_feedback_critical_hours', '6')
            ]
        ];

        return $this->view(
            'Warext\ModerationAudit:Dashboard',
            'warext_audit_admin_dashboard',
            $viewParams
        );
    }

    protected function preDispatchController($action, ParameterBag $params): void
    {
        if (!\XF::visitor()->is_super_admin)
        {
            $this->assertAdminPermission('warextAudit');
        }
    }
}
