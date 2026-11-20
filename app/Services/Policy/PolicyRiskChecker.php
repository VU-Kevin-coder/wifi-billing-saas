<?php

namespace App\Services\Policy;

class PolicyRiskChecker
{
    public function evaluate(array $policyValues): array
    {
        $checks = [];

        foreach (PolicyDefinitions::all() as $key => $field) {
            $definition = $field['risk_check'] ?? null;
            if (! is_array($definition)) {
                continue;
            }

            $conditions = array_map(function (array $condition) use ($policyValues): array {
                $current = match ($condition['source'] ?? null) {
                    'policy' => $policyValues[$condition['key']] ?? null,
                    'config' => config($condition['key']),
                    default => null,
                };

                return [...$condition, 'current' => $current];
            }, $definition['conditions'] ?? []);

            $violations = array_map(
                fn (array $condition): bool => $this->violates($condition),
                $conditions
            );
            $active = ($definition['match'] ?? 'any') === 'all'
                ? $violations !== [] && ! in_array(false, $violations, true)
                : in_array(true, $violations, true);

            $checks[] = [
                'id' => $key,
                'title' => $definition['title'],
                'description' => $definition['description'],
                'severity' => $definition['severity'] ?? 'critical',
                'review_tab' => $definition['review_tab'],
                'match' => $definition['match'] ?? 'any',
                'conditions' => $conditions,
                'active' => $active,
            ];
        }

        return $checks;
    }

    private function violates(array $condition): bool
    {
        return match ($condition['operator'] ?? null) {
            'not_true' => ($condition['current'] ?? null) !== true,
            'equals' => ($condition['current'] ?? null) === ($condition['value'] ?? null),
            'not_in' => ! is_array($condition['value'] ?? null)
                || ! in_array($condition['current'] ?? null, $condition['value'], true),
            default => true,
        };
    }
}
