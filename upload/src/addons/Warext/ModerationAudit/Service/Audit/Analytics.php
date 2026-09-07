<?php

namespace Warext\ModerationAudit\Service\Audit;

use XF\Service\AbstractService;

class Analytics extends AbstractService
{
    public function buildSummary(int $periodStart, int $periodEnd): array
    {
        if ($periodStart <= 0 || $periodEnd <= $periodStart)
        {
            throw new \InvalidArgumentException('Rapor dönem aralığı geçersiz.');
        }

        $rows = $this->app->db()->fetchAll("
            SELECT
                c.case_id, c.moderator_user_id, c.target_user_id, c.action, c.action_date,
                c.source_type, c.risk_level, c.rule_key, c.required_reviews, c.status,
                r.review_id, r.auditor_user_id, r.verdict, r.rule_rating, r.penalty_rating,
                r.communication_rating, r.confidence
            FROM xf_warext_audit_case AS c
            LEFT JOIN xf_warext_audit_review AS r ON (
                r.case_id = c.case_id
                AND r.verdict <> ''
                AND EXISTS (
                    SELECT 1
                    FROM xf_warext_audit_assignment AS a
                    WHERE a.case_id = r.case_id
                        AND a.auditor_user_id = r.auditor_user_id
                        AND a.status = 'completed'
                )
                AND NOT EXISTS (
                    SELECT 1
                    FROM xf_warext_audit_conflict AS cf
                    WHERE cf.case_id = r.case_id
                        AND cf.auditor_user_id = r.auditor_user_id
                )
            )
            WHERE c.action_date >= ? AND c.action_date < ?
            ORDER BY c.case_id ASC, r.review_id ASC
        ", [$periodStart, $periodEnd]);

        $cases = [];
        foreach ($rows as $row)
        {
            $caseId = (int)$row['case_id'];
            if (!isset($cases[$caseId]))
            {
                $cases[$caseId] = [
                    'case_id' => $caseId,
                    'moderator_user_id' => (int)$row['moderator_user_id'],
                    'target_user_id' => (int)$row['target_user_id'],
                    'action' => (string)$row['action'],
                    'action_date' => (int)$row['action_date'],
                    'source_type' => (string)$row['source_type'],
                    'risk_level' => (string)$row['risk_level'],
                    'rule_key' => (string)$row['rule_key'],
                    'required_reviews' => max(1, (int)$row['required_reviews']),
                    'status' => (string)$row['status'],
                    'reviews' => []
                ];
            }
            if (!empty($row['review_id']))
            {
                $cases[$caseId]['reviews'][] = [
                    'review_id' => (int)$row['review_id'],
                    'auditor_user_id' => (int)$row['auditor_user_id'],
                    'verdict' => (string)$row['verdict'],
                    'rule_rating' => (string)$row['rule_rating'],
                    'penalty_rating' => (string)$row['penalty_rating'],
                    'communication_rating' => (string)$row['communication_rating'],
                    'confidence' => max(1, min(100, (int)$row['confidence']))
                ];
            }
        }

        $summary = [
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'generated_at' => time(),
            'totals' => [
                'actions' => count($cases),
                'audited_cases' => 0,
                'decidable_cases' => 0,
                'review_count' => 0,
                'coverage_percent' => 0.0,
                'accuracy_score' => null,
                'problem_rate' => null,
                'average_confidence' => null
            ],
            'verdicts' => $this->emptyCounts(['correct', 'partly_correct', 'wrong', 'insufficient_evidence']),
            'rule_ratings' => $this->emptyCounts(['correct', 'partly_correct', 'wrong', 'not_applicable', 'insufficient_evidence']),
            'penalty_ratings' => $this->emptyCounts(['proportionate', 'too_light', 'too_severe', 'mixed', 'not_applicable', 'insufficient_evidence']),
            'communication_ratings' => $this->emptyCounts(['professional', 'needs_improvement', 'inappropriate', 'mixed', 'not_applicable', 'insufficient_evidence']),
            'risk' => $this->emptyCounts(['normal', 'elevated', 'critical']),
            'sources' => [],
            'actions' => [],
            'rules' => [],
            'moderators' => [],
            'problem_cases' => []
        ];

        $accuracyPoints = 0.0;
        $problemCount = 0;
        $confidenceTotal = 0;
        $confidenceCount = 0;

        foreach ($cases as $case)
        {
            $risk = $case['risk_level'] ?: 'normal';
            $summary['risk'][$risk] = ($summary['risk'][$risk] ?? 0) + 1;
            $this->incrementBreakdown($summary['sources'], $case['source_type'] ?: 'unknown');
            $this->incrementBreakdown($summary['actions'], $case['action'] ?: 'unknown');
            if ($case['rule_key'] !== '')
            {
                $this->incrementBreakdown($summary['rules'], $case['rule_key']);
            }

            $moderatorId = (int)$case['moderator_user_id'];
            if (!isset($summary['moderators'][$moderatorId]))
            {
                $summary['moderators'][$moderatorId] = $this->newModeratorRow($moderatorId);
            }
            $mod = &$summary['moderators'][$moderatorId];
            $mod['actions']++;

            foreach ($case['reviews'] as $review)
            {
                $summary['totals']['review_count']++;
                $confidenceTotal += (int)$review['confidence'];
                $confidenceCount++;
            }

            if (count($case['reviews']) < $case['required_reviews'])
            {
                $mod['pending_audits']++;
                unset($mod);
                continue;
            }

            $summary['totals']['audited_cases']++;
            $mod['audited_cases']++;

            $verdict = $this->consensus($case['reviews'], 'verdict', 'partly_correct');
            $rule = $this->consensus($case['reviews'], 'rule_rating', 'partly_correct');
            $penalty = $this->consensus($case['reviews'], 'penalty_rating', 'mixed');
            $communication = $this->consensus($case['reviews'], 'communication_rating', 'mixed');

            $summary['verdicts'][$verdict] = ($summary['verdicts'][$verdict] ?? 0) + 1;
            $summary['rule_ratings'][$rule] = ($summary['rule_ratings'][$rule] ?? 0) + 1;
            $summary['penalty_ratings'][$penalty] = ($summary['penalty_ratings'][$penalty] ?? 0) + 1;
            $summary['communication_ratings'][$communication] = ($summary['communication_ratings'][$communication] ?? 0) + 1;

            $mod['verdicts'][$verdict] = ($mod['verdicts'][$verdict] ?? 0) + 1;
            if ($rule === 'partly_correct' || $rule === 'wrong')
            {
                $mod['rule_issues']++;
            }
            if (in_array($penalty, ['too_light', 'too_severe', 'mixed'], true))
            {
                $mod['penalty_issues']++;
            }
            if (in_array($communication, ['needs_improvement', 'inappropriate', 'mixed'], true))
            {
                $mod['communication_issues']++;
            }

            $caseProblem = false;
            if (in_array($verdict, ['correct', 'partly_correct', 'wrong'], true))
            {
                $summary['totals']['decidable_cases']++;
                $mod['decidable_cases']++;
                $points = $verdict === 'correct' ? 100.0 : ($verdict === 'partly_correct' ? 50.0 : 0.0);
                $accuracyPoints += $points;
                $mod['accuracy_points'] += $points;
                if ($verdict !== 'correct')
                {
                    $problemCount++;
                    $mod['problematic_cases']++;
                    $caseProblem = true;
                }
            }

            if ($caseProblem || $rule === 'wrong' || in_array($penalty, ['too_severe', 'too_light'], true) || $communication === 'inappropriate')
            {
                $summary['problem_cases'][] = [
                    'case_id' => (int)$case['case_id'],
                    'moderator_user_id' => $moderatorId,
                    'action' => (string)$case['action'],
                    'action_date' => (int)$case['action_date'],
                    'risk_level' => (string)$case['risk_level'],
                    'verdict' => $verdict,
                    'rule_rating' => $rule,
                    'penalty_rating' => $penalty,
                    'communication_rating' => $communication
                ];
            }
            unset($mod);
        }

        $actions = max(0, (int)$summary['totals']['actions']);
        $audited = (int)$summary['totals']['audited_cases'];
        $decidable = (int)$summary['totals']['decidable_cases'];
        $summary['totals']['coverage_percent'] = $actions ? round(($audited / $actions) * 100, 2) : 0.0;
        $summary['totals']['accuracy_score'] = $decidable ? round($accuracyPoints / $decidable, 2) : null;
        $summary['totals']['problem_rate'] = $decidable ? round(($problemCount / $decidable) * 100, 2) : null;
        $summary['totals']['average_confidence'] = $confidenceCount ? round($confidenceTotal / $confidenceCount, 2) : null;

        foreach ($summary['moderators'] as &$mod)
        {
            $mod['coverage_percent'] = $mod['actions'] ? round(($mod['audited_cases'] / $mod['actions']) * 100, 2) : 0.0;
            $mod['accuracy_score'] = $mod['decidable_cases'] ? round($mod['accuracy_points'] / $mod['decidable_cases'], 2) : null;
            $mod['problem_rate'] = $mod['decidable_cases'] ? round(($mod['problematic_cases'] / $mod['decidable_cases']) * 100, 2) : null;
            unset($mod['accuracy_points']);
        }
        unset($mod);

        uasort($summary['moderators'], static function (array $a, array $b): int
        {
            return [$b['actions'], $b['audited_cases']] <=> [$a['actions'], $a['audited_cases']];
        });
        arsort($summary['sources']);
        arsort($summary['actions']);
        arsort($summary['rules']);

        usort($summary['problem_cases'], static function (array $a, array $b): int
        {
            $rank = ['wrong' => 4, 'partly_correct' => 3, 'insufficient_evidence' => 2, 'correct' => 1];
            $ar = $rank[$a['verdict']] ?? 0;
            $br = $rank[$b['verdict']] ?? 0;
            return [$br, $b['action_date']] <=> [$ar, $a['action_date']];
        });
        $summary['problem_cases'] = array_slice($summary['problem_cases'], 0, 50);

        return $summary;
    }

    protected function consensus(array $reviews, string $field, string $tieValue): string
    {
        $weights = [];
        foreach ($reviews as $review)
        {
            $value = (string)($review[$field] ?? '');
            if ($value === '')
            {
                continue;
            }
            $weights[$value] = ($weights[$value] ?? 0) + max(1, (int)($review['confidence'] ?? 1));
        }
        if (!$weights)
        {
            return 'insufficient_evidence';
        }

        arsort($weights);
        $keys = array_keys($weights);
        if (count($keys) > 1 && $weights[$keys[0]] === $weights[$keys[1]])
        {
            return $tieValue;
        }
        return (string)$keys[0];
    }

    protected function emptyCounts(array $keys): array
    {
        return array_fill_keys($keys, 0);
    }

    protected function incrementBreakdown(array &$breakdown, string $key): void
    {
        $breakdown[$key] = ($breakdown[$key] ?? 0) + 1;
    }

    protected function newModeratorRow(int $moderatorId): array
    {
        return [
            'moderator_user_id' => $moderatorId,
            'actions' => 0,
            'audited_cases' => 0,
            'pending_audits' => 0,
            'decidable_cases' => 0,
            'problematic_cases' => 0,
            'rule_issues' => 0,
            'penalty_issues' => 0,
            'communication_issues' => 0,
            'accuracy_points' => 0.0,
            'coverage_percent' => 0.0,
            'accuracy_score' => null,
            'problem_rate' => null,
            'verdicts' => $this->emptyCounts(['correct', 'partly_correct', 'wrong', 'insufficient_evidence'])
        ];
    }
}
