<?php

namespace Warext\ModerationAudit\Service\Audit;

use Warext\ModerationAudit\Entity\AuditCase;
use Warext\ModerationAudit\Entity\AuditReview;
use XF\App;
use XF\Entity\User;
use XF\Service\AbstractService;

class ReviewManager extends AbstractService
{
    protected AuditCase $case;

    protected array $verdicts = ['correct', 'partly_correct', 'wrong', 'insufficient_evidence'];
    protected array $ruleRatings = ['correct', 'partly_correct', 'wrong', 'not_applicable', 'insufficient_evidence'];
    protected array $penaltyRatings = ['proportionate', 'too_light', 'too_severe', 'not_applicable', 'insufficient_evidence'];
    protected array $communicationRatings = ['professional', 'needs_improvement', 'inappropriate', 'not_applicable', 'insufficient_evidence'];

    public function __construct(App $app, AuditCase $case)
    {
        parent::__construct($app);
        $this->case = $case;
    }

    public function getCase(): AuditCase
    {
        return $this->case;
    }

    public function getReviewForAuditor(int $userId): ?AuditReview
    {
        if (!$userId)
        {
            return null;
        }

        return $this->app->finder('Warext\ModerationAudit:AuditReview')
            ->where('case_id', $this->case->case_id)
            ->where('auditor_user_id', $userId)
            ->fetchOne();
    }

    public function canReview(User $auditor, ?string &$error = null): bool
    {
        $error = null;

        if (!$auditor->user_id)
        {
            $error = 'Denetim değerlendirmesi için oturum açmış bir kullanıcı gerekir.';
            return false;
        }

        $review = $this->getReviewForAuditor((int)$auditor->user_id);

        if (in_array((string)$this->case->status, ['final', 'skipped'], true))
        {
            $error = 'Sonuçlandırılmış veya atlanmış bir vaka yeniden değerlendirilemez.';
            return false;
        }

        if ((string)$this->case->status === 'reviewed' && !$review)
        {
            $error = 'Bu vaka için gerekli değerlendirme sayısı tamamlanmış.';
            return false;
        }

        if ((int)$this->case->moderator_user_id === (int)$auditor->user_id)
        {
            $error = 'Bir yetkili kendi moderasyon işlemini denetleyemez.';
            return false;
        }

        $assignmentManager = new AssignmentManager($this->app);
        $assignment = $assignmentManager->getAssignment($this->case, (int)$auditor->user_id);
        if (!$assignment || in_array((string)$assignment->status, ['recused', 'cancelled'], true))
        {
            $error = 'Bu vaka sana atanmadığından değerlendirme yapamazsın.';
            return false;
        }
        if (!in_array((string)$assignment->assignment_source, ['legacy'], true))
        {
            $eligibilityError = null;
            if (!$assignmentManager->isEligibleForCase($auditor, $this->case, $eligibilityError))
            {
                $error = $eligibilityError ?: 'Denetçi rol ayrımı nedeniyle bu vaka değerlendirilemez.';
                return false;
            }
        }

        $conflict = $this->app->finder('Warext\ModerationAudit:AuditConflict')
            ->where('case_id', $this->case->case_id)
            ->where('auditor_user_id', $auditor->user_id)
            ->fetchOne();
        if ($conflict)
        {
            $error = 'Bu vaka için çıkar çatışması kaydın bulunduğundan değerlendirme yapamazsın.';
            return false;
        }

        if ($review && $review->locked_date)
        {
            $error = 'Bu değerlendirme kilitlenmiş ve artık değiştirilemez.';
            return false;
        }

        return true;
    }

    public function validate(array $input): array
    {
        $errors = [];

        if (!in_array((string)($input['verdict'] ?? ''), $this->verdicts, true))
        {
            $errors[] = 'Geçerli bir genel karar seçmelisin.';
        }
        if (!in_array((string)($input['rule_rating'] ?? ''), $this->ruleRatings, true))
        {
            $errors[] = 'Kural uygulaması değerlendirmesi geçersiz.';
        }
        if (!in_array((string)($input['penalty_rating'] ?? ''), $this->penaltyRatings, true))
        {
            $errors[] = 'Ceza/orantı değerlendirmesi geçersiz.';
        }
        if (!in_array((string)($input['communication_rating'] ?? ''), $this->communicationRatings, true))
        {
            $errors[] = 'İletişim değerlendirmesi geçersiz.';
        }

        $confidence = (int)($input['confidence'] ?? 0);
        if ($confidence < 1 || $confidence > 100)
        {
            $errors[] = 'Güven seviyesi 1 ile 100 arasında olmalıdır.';
        }

        $reason = trim((string)($input['reason'] ?? ''));
        $reasonLength = function_exists('mb_strlen') ? mb_strlen($reason) : strlen($reason);
        if ($reasonLength < 30)
        {
            $errors[] = 'Değerlendirme gerekçesi en az 30 karakter olmalıdır.';
        }
        elseif ($reasonLength > 10000)
        {
            $errors[] = 'Değerlendirme gerekçesi en fazla 10.000 karakter olabilir.';
        }

        return $errors;
    }

    public function normalizeInput(array $input): array
    {
        return [
            'verdict' => (string)($input['verdict'] ?? ''),
            'rule_rating' => (string)($input['rule_rating'] ?? ''),
            'penalty_rating' => (string)($input['penalty_rating'] ?? ''),
            'communication_rating' => (string)($input['communication_rating'] ?? ''),
            'confidence' => max(0, min(100, (int)($input['confidence'] ?? 0))),
            'reason' => trim((string)($input['reason'] ?? ''))
        ];
    }

    public function saveReview(User $auditor, array $input): AuditReview
    {
        $permissionError = null;
        if (!$this->canReview($auditor, $permissionError))
        {
            throw new \LogicException($permissionError ?: 'Bu vaka değerlendirilemez.');
        }

        $input = $this->normalizeInput($input);
        $errors = $this->validate($input);
        if ($errors)
        {
            throw new \InvalidArgumentException(implode("\n", $errors));
        }

        $db = $this->app->db();
        $db->beginTransaction();

        try
        {
            $now = time();
            $review = $this->getReviewForAuditor((int)$auditor->user_id);
            $isNew = !$review;

            if (!$review)
            {
                /** @var AuditReview $review */
                $review = $this->app->em()->create('Warext\ModerationAudit:AuditReview');
                $review->case_id = $this->case->case_id;
                $review->auditor_user_id = $auditor->user_id;
                $review->created_date = $now;
                $review->revision = 1;
            }
            else
            {
                $unchanged = true;
                foreach ($input as $key => $value)
                {
                    if ((string)$review->get($key) !== (string)$value)
                    {
                        $unchanged = false;
                        break;
                    }
                }
                if ($unchanged)
                {
                    $db->commit();
                    return $review;
                }

                $review->revision = max(1, (int)$review->revision + 1);
            }

            foreach ($input as $key => $value)
            {
                $review->set($key, $value);
            }
            $review->updated_date = $now;
            $review->save();

            $revisionData = [
                'case_id' => (int)$this->case->case_id,
                'review_id' => (int)$review->review_id,
                'auditor_user_id' => (int)$auditor->user_id,
                'revision' => (int)$review->revision,
                'verdict' => (string)$review->verdict,
                'rule_rating' => (string)$review->rule_rating,
                'penalty_rating' => (string)$review->penalty_rating,
                'communication_rating' => (string)$review->communication_rating,
                'confidence' => (int)$review->confidence,
                'reason' => (string)$review->reason,
                'saved_date' => $now
            ];
            $json = json_encode($revisionData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false)
            {
                throw new \RuntimeException('Değerlendirme revizyonu JSON olarak kaydedilemedi.');
            }

            $revision = $this->app->em()->create('Warext\ModerationAudit:AuditReviewRevision');
            $revision->review_id = $review->review_id;
            $revision->revision = $review->revision;
            $revision->review_data = $json;
            $revision->data_hash = hash('sha256', $json);
            $revision->created_date = $now;
            $revision->save();

            $assignment = $this->app->finder('Warext\ModerationAudit:AuditAssignment')
                ->where('case_id', $this->case->case_id)
                ->where('auditor_user_id', $auditor->user_id)
                ->fetchOne();
            if ($assignment && !in_array((string)$assignment->status, ['recused', 'cancelled'], true))
            {
                if (!$assignment->opened_date)
                {
                    $assignment->opened_date = $now;
                }
                $assignment->status = 'completed';
                $assignment->completed_date = $now;
                $assignment->save();
            }

            $completedReviews = (int)$db->fetchOne(
                'SELECT COUNT(DISTINCT r.review_id) FROM xf_warext_audit_review AS r INNER JOIN xf_warext_audit_assignment AS a ON (a.case_id = r.case_id AND a.auditor_user_id = r.auditor_user_id) WHERE r.case_id = ? AND r.verdict <> ? AND a.status = ?',
                [$this->case->case_id, '', 'completed']
            );
            $required = max(1, (int)$this->case->required_reviews);
            $this->case->status = $completedReviews >= $required ? 'reviewed' : 'in_review';
            $this->case->updated_date = $now;
            $this->case->save();

            $db->commit();
            return $review;
        }
        catch (\Throwable $e)
        {
            $db->rollback();
            throw $e;
        }
    }
}
