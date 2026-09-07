<?php

namespace Warext\ModerationAudit\XF\Entity;

class ModeratorLog extends XFCP_ModeratorLog
{
    protected function _postSave()
    {
        $result = parent::_postSave();

        if ($this->isInsert())
        {
            try
            {
                (new \Warext\ModerationAudit\Service\Audit\Capture($this->app()))->recordModeratorLog($this);
            }
            catch (\Throwable $e)
            {
                \XF::logException($e, false, 'ModerationAudit moderator-log capture failed: ');
            }
        }

        return $result;
    }
}
