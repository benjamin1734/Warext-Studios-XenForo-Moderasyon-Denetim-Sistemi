<?php

namespace Warext\ModerationAudit\XF\Entity;

class Report extends XFCP_Report
{
    protected function _postSave()
    {
        $result = parent::_postSave();

        try
        {
            (new \Warext\ModerationAudit\Service\Audit\Capture($this->app()))->recordReportChange($this);
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, 'ModerationAudit report capture failed: ');
        }

        return $result;
    }
}
