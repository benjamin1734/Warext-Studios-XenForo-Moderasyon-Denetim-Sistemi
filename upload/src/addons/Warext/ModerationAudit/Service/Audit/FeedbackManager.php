<?php

namespace Warext\ModerationAudit\Service\Audit;

use Warext\ModerationAudit\Entity\AuditFeedback;
use Warext\ModerationAudit\Entity\AuditFeedbackEvent;
use XF\Entity\User;
use XF\Service\AbstractService;

class FeedbackManager extends AbstractService
{
    public const TYPES = ['appeal', 'suggestion'];
    public const STATUSES = ['open', 'under_review', 'accepted', 'rejected', 'implemented', 'closed'];
    public const PRIORITIES = ['low', 'normal', 'high', 'critical'];

    public function create(string $type, int $caseId, string $subject, string $message, string $priority, User $actor): AuditFeedback
    {
        $type = trim($type);
        $subject = trim($subject);
        $message = trim($message);
        $priority = trim($priority);

        if (!in_array($type, self::TYPES, true))
        {
            throw new \InvalidArgumentException('Başvuru türü geçersiz.');
        }
        if ($type === 'appeal' && $caseId <= 0)
        {
            throw new \InvalidArgumentException('İtiraz için denetim vakası seçilmelidir.');
        }
        if (mb_strlen($subject) < 5 || mb_strlen($subject) > 150)
        {
            throw new \InvalidArgumentException('Konu 5 ile 150 karakter arasında olmalıdır.');
        }
        if (mb_strlen($message) < 20 || mb_strlen($message) > 10000)
        {
            throw new \InvalidArgumentException('Açıklama 20 ile 10.000 karakter arasında olmalıdır.');
        }
        if (!in_array($priority, self::PRIORITIES, true))
        {
            $priority = 'normal';
        }

        $db = $this->app->db();
        $db->beginTransaction();
        try
        {
            /** @var AuditFeedback $feedback */
            $feedback = $this->app->em()->create('Warext\ModerationAudit:AuditFeedback');
            $feedback->feedback_type = $type;
            $feedback->case_id = $caseId;
            $feedback->submitted_by_user_id = (int)$actor->user_id;
            $feedback->subject = $subject;
            $feedback->message = $message;
            $feedback->status = 'open';
            $feedback->priority = $priority;
            $feedback->assigned_to_user_id = 0;
            $feedback->last_response_date = 0;
            $feedback->created_date = time();
            $feedback->updated_date = time();
            $feedback->save();

            $this->appendEvent($feedback, 'created', $actor, '', 'open', [
                'feedback_type' => $type,
                'subject' => $subject,
                'priority' => $priority,
                'case_id' => $caseId
            ]);

            $db->commit();
            return $feedback;
        }
        catch (\Throwable $e)
        {
            $db->rollback();
            throw $e;
        }
    }

    public function managementResponse(AuditFeedback $feedback, string $response, string $status, int $assignedToUserId, User $actor): AuditFeedback
    {
        $response = trim($response);
        $status = trim($status);

        if (mb_strlen($response) < 3 || mb_strlen($response) > 10000)
        {
            throw new \InvalidArgumentException('Yönetim dönüşü 3 ile 10.000 karakter arasında olmalıdır.');
        }
        if (!in_array($status, self::STATUSES, true))
        {
            throw new \InvalidArgumentException('Başvuru durumu geçersiz.');
        }
        if ($assignedToUserId > 0 && !$this->app->em()->find('XF:User', $assignedToUserId))
        {
            throw new \InvalidArgumentException('Atanacak kullanıcı bulunamadı.');
        }

        $oldStatus = (string)$feedback->status;
        $db = $this->app->db();
        $db->beginTransaction();
        try
        {
            $feedback->status = $status;
            $feedback->assigned_to_user_id = $assignedToUserId;
            $feedback->last_response_date = time();
            $feedback->updated_date = time();
            $feedback->save();

            $this->appendEvent($feedback, 'management_response', $actor, $oldStatus, $status, [
                'response' => $response,
                'assigned_to_user_id' => $assignedToUserId
            ]);

            $db->commit();
            return $feedback;
        }
        catch (\Throwable $e)
        {
            $db->rollback();
            throw $e;
        }
    }

    public function appendEvent(AuditFeedback $feedback, string $eventType, User $actor, string $fromStatus, string $toStatus, array $data): AuditFeedbackEvent
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false)
        {
            throw new \RuntimeException('Olay verisi JSON olarak hazırlanamadı.');
        }

        $createdDate = time();
        $hashPayload = implode('|', [
            (int)$feedback->feedback_id,
            $eventType,
            (int)$actor->user_id,
            $fromStatus,
            $toStatus,
            $createdDate,
            $json
        ]);

        /** @var AuditFeedbackEvent $event */
        $event = $this->app->em()->create('Warext\ModerationAudit:AuditFeedbackEvent');
        $event->feedback_id = (int)$feedback->feedback_id;
        $event->event_type = $eventType;
        $event->actor_user_id = (int)$actor->user_id;
        $event->from_status = $fromStatus;
        $event->to_status = $toStatus;
        $event->event_data = $json;
        $event->data_hash = hash('sha256', $hashPayload);
        $event->created_date = $createdDate;
        $event->save();
        return $event;
    }

    public function verifyEvent(AuditFeedbackEvent $event): bool
    {
        $hashPayload = implode('|', [
            (int)$event->feedback_id,
            (string)$event->event_type,
            (int)$event->actor_user_id,
            (string)$event->from_status,
            (string)$event->to_status,
            (int)$event->created_date,
            (string)$event->event_data
        ]);
        return hash_equals((string)$event->data_hash, hash('sha256', $hashPayload));
    }

    public function decodeEvent(AuditFeedbackEvent $event): array
    {
        $decoded = json_decode((string)$event->event_data, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function getTypeLabels(): array
    {
        return ['appeal' => 'İtiraz', 'suggestion' => 'Öneri'];
    }

    public function getStatusLabels(): array
    {
        return [
            'open' => 'Açık',
            'under_review' => 'İnceleniyor',
            'accepted' => 'Kabul edildi',
            'rejected' => 'Reddedildi',
            'implemented' => 'Uygulandı',
            'closed' => 'Kapatıldı'
        ];
    }

    public function getPriorityLabels(): array
    {
        return ['low' => 'Düşük', 'normal' => 'Normal', 'high' => 'Yüksek', 'critical' => 'Kritik'];
    }
}
