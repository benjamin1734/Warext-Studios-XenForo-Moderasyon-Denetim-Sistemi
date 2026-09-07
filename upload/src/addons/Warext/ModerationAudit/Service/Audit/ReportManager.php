<?php

namespace Warext\ModerationAudit\Service\Audit;

use Warext\ModerationAudit\Entity\AuditReport;
use XF\Service\AbstractService;

class ReportManager extends AbstractService
{
    public function generate(string $periodType, int $periodStart, int $periodEnd, int $generatedBy = 0, bool $final = false): AuditReport
    {
        if (!in_array($periodType, ['weekly', 'monthly', 'custom'], true))
        {
            throw new \InvalidArgumentException('Rapor türü geçersiz.');
        }
        if ($periodStart <= 0 || $periodEnd <= $periodStart)
        {
            throw new \InvalidArgumentException('Rapor tarih aralığı geçersiz.');
        }

        $existing = $this->app->finder('Warext\ModerationAudit:AuditReport')
            ->where('period_type', $periodType)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd)
            ->fetchOne();
        if ($existing && (string)$existing->status === 'final')
        {
            return $existing;
        }

        /** @var Analytics $analytics */
        $analytics = new Analytics($this->app);
        $summary = $analytics->buildSummary($periodStart, $periodEnd);

        $duration = $periodEnd - $periodStart;
        $previous = $analytics->buildSummary(max(1, $periodStart - $duration), $periodStart);
        $summary['comparison'] = [
            'previous_start' => max(1, $periodStart - $duration),
            'previous_end' => $periodStart,
            'previous_totals' => $previous['totals'],
            'coverage_delta' => $this->delta($summary['totals']['coverage_percent'], $previous['totals']['coverage_percent']),
            'accuracy_delta' => $this->deltaNullable($summary['totals']['accuracy_score'], $previous['totals']['accuracy_score']),
            'problem_rate_delta' => $this->deltaNullable($summary['totals']['problem_rate'], $previous['totals']['problem_rate'])
        ];

        $json = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false)
        {
            throw new \RuntimeException('Rapor özeti JSON olarak oluşturulamadı.');
        }

        $report = $existing ?: $this->app->em()->create('Warext\ModerationAudit:AuditReport');
        $report->period_type = $periodType;
        $report->period_start = $periodStart;
        $report->period_end = $periodEnd;
        $report->status = $final ? 'final' : 'draft';
        $report->generated_by = $generatedBy;
        $report->generated_date = time();
        $report->summary_data = $json;
        $report->report_hash = hash('sha256', $json);
        $report->save();

        return $report;
    }

    public function finalize(AuditReport $report, int $generatedBy = 0): AuditReport
    {
        if ((string)$report->status === 'final')
        {
            return $report;
        }
        $report->status = 'final';
        $report->generated_by = $generatedBy ?: (int)$report->generated_by;
        $report->generated_date = time();
        $report->report_hash = hash('sha256', (string)$report->summary_data);
        $report->save();
        return $report;
    }

    public function verify(AuditReport $report): bool
    {
        $hash = hash('sha256', (string)$report->summary_data);
        return hash_equals((string)$report->report_hash, $hash);
    }

    public function decode(AuditReport $report): array
    {
        $decoded = json_decode((string)$report->summary_data, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function prepareForDisplay(AuditReport $report, bool $anonymized = false): array
    {
        $summary = $this->decode($report);
        $salt = $this->getAnonSalt();
        if (!empty($summary['moderators']) && is_array($summary['moderators']))
        {
            foreach ($summary['moderators'] as $key => &$row)
            {
                $userId = (int)($row['moderator_user_id'] ?? $key);
                $row['display_name'] = $anonymized
                    ? $this->anonymousLabel($userId, $salt)
                    : $this->resolveUserName($userId);
            }
            unset($row);
        }
        if (!empty($summary['problem_cases']) && is_array($summary['problem_cases']))
        {
            foreach ($summary['problem_cases'] as &$row)
            {
                $userId = (int)($row['moderator_user_id'] ?? 0);
                $row['moderator_name'] = $anonymized
                    ? $this->anonymousLabel($userId, $salt)
                    : $this->resolveUserName($userId);
            }
            unset($row);
        }
        return $summary;
    }

    public function previousWeekRange(?int $now = null): array
    {
        $now = $now ?: time();
        $mondayThisWeek = strtotime('monday this week 00:00:00', $now);
        return [strtotime('-7 days', $mondayThisWeek), $mondayThisWeek];
    }

    public function previousMonthRange(?int $now = null): array
    {
        $now = $now ?: time();
        $firstThisMonth = strtotime('first day of this month 00:00:00', $now);
        return [strtotime('first day of previous month 00:00:00', $now), $firstThisMonth];
    }

    protected function resolveUserName(int $userId): string
    {
        if (!$userId)
        {
            return 'Sistem / bilinmiyor';
        }
        $user = $this->app->em()->find('XF:User', $userId);
        return $user ? (string)$user->username : '#' . $userId;
    }

    protected function anonymousLabel(int $userId, string $salt): string
    {
        if (!$userId)
        {
            return 'Sistem';
        }
        return 'YTK-' . strtoupper(substr(hash_hmac('sha256', (string)$userId, $salt), 0, 8));
    }

    protected function getAnonSalt(): string
    {
        $salt = (string)$this->app->db()->fetchOne('SELECT state_value FROM xf_warext_audit_state WHERE state_key = ?', 'report_anon_salt');
        if ($salt === '')
        {
            $salt = bin2hex(random_bytes(32));
            $this->app->db()->insert('xf_warext_audit_state', [
                'state_key' => 'report_anon_salt',
                'state_value' => $salt,
                'updated_date' => time()
            ], false, 'state_value = VALUES(state_value), updated_date = VALUES(updated_date)');
        }
        return $salt;
    }

    protected function delta(float $current, float $previous): float
    {
        return round($current - $previous, 2);
    }

    protected function deltaNullable($current, $previous): ?float
    {
        if ($current === null || $previous === null)
        {
            return null;
        }
        return round((float)$current - (float)$previous, 2);
    }
}
