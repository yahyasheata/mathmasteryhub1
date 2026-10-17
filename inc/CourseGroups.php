<?php
declare(strict_types=1);

if (!function_exists('mmh_course_group_options')) {
    /** @return array<string, string> */
    function mmh_course_group_options(): array
    {
        return [
            'cambridge_a_level' => 'Cambridge A-Level Mathematics',
            'edexcel_a_level' => 'Edexcel A-Level Mathematics',
        ];
    }
}

if (!function_exists('mmh_course_group_from_input')) {
    /** Returns false for an invalid key and null for the explicit ungrouped option. */
    function mmh_course_group_from_input($value): string|false|null
    {
        if ($value === null || $value === '') return null;
        $value = (string) $value;
        return array_key_exists($value, mmh_course_group_options()) ? $value : false;
    }
}

if (!function_exists('mmh_group_enrolled_course_cards')) {
    /** @return array<int, array{key:string,label:string,cards:array<int,array>}> */
    function mmh_group_enrolled_course_cards(array $cards): array
    {
        $labels = mmh_course_group_options();
        $grouped = [];
        foreach ($cards as $card) {
            $key = (string) ($card['course']['my_courses_group_key'] ?? '');
            if (!array_key_exists($key, $labels)) $key = '';
            $grouped[$key][] = $card;
        }

        $hasNamedGroup = isset($grouped['cambridge_a_level']) || isset($grouped['edexcel_a_level']);
        if (!$hasNamedGroup) {
            return $cards ? [['key' => '', 'label' => '', 'cards' => array_values($cards)]] : [];
        }

        $result = [];
        foreach ($labels as $key => $label) {
            if (!empty($grouped[$key])) $result[] = ['key' => $key, 'label' => $label, 'cards' => $grouped[$key]];
        }
        if (!empty($grouped[''])) $result[] = ['key' => '', 'label' => 'Other Courses', 'cards' => $grouped['']];
        return $result;
    }
}
