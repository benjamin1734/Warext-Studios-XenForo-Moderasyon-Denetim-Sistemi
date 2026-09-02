<?php

namespace Warext\ModerationAudit\Cron;

class Reports
{
    public static function run(): void
    {
        try
        {
            /** @var \Warext\ModerationAudit\Service\Audit\ReportManager $manager */
            $manager = \XF::service('Warext\ModerationAudit:Audit\ReportManager');
            $now = time();

            if ((int)date('N', $now) === 1)
            {
                [$start, $end] = $manager->previousWeekRange($now);
                $manager->generate('weekly', $start, $end, 0, true);
            }

            if ((int)date('j', $now) === 1)
            {
                [$start, $end] = $manager->previousMonthRange($now);
                $manager->generate('monthly', $start, $end, 0, true);
            }
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, 'Warext ModerationAudit report cron: ');
        }
    }
}
