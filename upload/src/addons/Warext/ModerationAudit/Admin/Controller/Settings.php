<?php

namespace Warext\ModerationAudit\Admin\Controller;

use XF\Admin\Controller\AbstractController;
use XF\Mvc\ParameterBag;

class Settings extends AbstractController
{
    protected function getSettings(): array
    {
        $state = $this->repository('Warext\ModerationAudit:AuditState');
        return [
            'sla_case_normal_hours' => (int)$state->get('sla_case_normal_hours', '72'),
            'sla_case_elevated_hours' => (int)$state->get('sla_case_elevated_hours', '36'),
            'sla_case_critical_hours' => (int)$state->get('sla_case_critical_hours', '12'),
            'sla_feedback_low_hours' => (int)$state->get('sla_feedback_low_hours', '96'),
            'sla_feedback_normal_hours' => (int)$state->get('sla_feedback_normal_hours', '48'),
            'sla_feedback_high_hours' => (int)$state->get('sla_feedback_high_hours', '24'),
            'sla_feedback_critical_hours' => (int)$state->get('sla_feedback_critical_hours', '6')
        ];
    }

    public function actionIndex()
    {
        return $this->view(
            'Warext\ModerationAudit:Settings',
            'warext_audit_admin_settings',
            ['settings' => $this->getSettings()]
        );
    }

    public function actionSave()
    {
        $this->assertPostOnly();

        $input = $this->filter([
            'sla_case_normal_hours' => 'uint',
            'sla_case_elevated_hours' => 'uint',
            'sla_case_critical_hours' => 'uint',
            'sla_feedback_low_hours' => 'uint',
            'sla_feedback_normal_hours' => 'uint',
            'sla_feedback_high_hours' => 'uint',
            'sla_feedback_critical_hours' => 'uint'
        ]);

        foreach ($input as $key => $value)
        {
            if ($value < 1 || $value > 720)
            {
                return $this->error('SLA süreleri 1 ile 720 saat arasında olmalıdır.');
            }
        }

        if (!($input['sla_case_critical_hours'] <= $input['sla_case_elevated_hours']
            && $input['sla_case_elevated_hours'] <= $input['sla_case_normal_hours']))
        {
            return $this->error('Vaka SLA sırası kritik ≤ yüksek risk ≤ normal şeklinde olmalıdır.');
        }

        if (!($input['sla_feedback_critical_hours'] <= $input['sla_feedback_high_hours']
            && $input['sla_feedback_high_hours'] <= $input['sla_feedback_normal_hours']
            && $input['sla_feedback_normal_hours'] <= $input['sla_feedback_low_hours']))
        {
            return $this->error('Başvuru SLA sırası kritik ≤ yüksek ≤ normal ≤ düşük öncelik şeklinde olmalıdır.');
        }

        $state = $this->repository('Warext\ModerationAudit:AuditState');
        foreach ($input as $key => $value)
        {
            $state->set($key, (string)$value);
        }

        return $this->redirect(
            $this->buildLink('warext-moderation-audit-settings'),
            'Moderasyon denetim sistemi ayarları kaydedildi.'
        );
    }

    protected function preDispatchController($action, ParameterBag $params): void
    {
        $this->assertAdminPermission('warextAudit');
    }
}
