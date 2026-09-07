<?php

namespace Warext\ModerationAudit\Cron;

class Governance
{
    public static function run(): void
    {
        try
        {
            /** @var \Warext\ModerationAudit\Service\Audit\GovernanceManager $service */
            $service = \XF::service('Warext\ModerationAudit:Audit\GovernanceManager');
            $service->scan();
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, 'Warext ModerationAudit governance cron: ');
        }
    }
}
