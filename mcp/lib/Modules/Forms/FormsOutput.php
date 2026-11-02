<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms;

/** Explicit allowlists keep Forms internals, answers, and share tokens out of MCP results. */
final class FormsOutput {
    public const MAX_FORMS = 100;
    public const DEFAULT_FORMS = 50;
    public const MAX_QUESTIONS = 50;
    public const DEFAULT_QUESTIONS = 50;
    public const MAX_OPTIONS = 50;
    public const DEFAULT_OPTIONS = 50;
    private const MAX_TEXT_BYTES = 1024;
    private const MAX_SETTING_ITEMS = 100;
    private const SUMMARY_FIELDS = ['id', 'title', 'permissions'];
    private const FORM_FIELDS = [
        'id', 'title', 'description', 'ownerId', 'submissionMessage', 'created', 'expires',
        'isAnonymous', 'state', 'submitMultiple', 'allowEditSubmissions', 'showExpiration', 'permissions',
    ];
    private const QUESTION_FIELDS = ['id', 'order', 'type', 'isRequired', 'text', 'name'];
    private const OPTION_FIELDS = ['id', 'order', 'text', 'optionType'];
    private const SETTING_FIELDS = [
        'allowOtherAnswer', 'shuffleOptions', 'optionsLimitMax', 'optionsLimitMin', 'validationType',
        'allowedFileTypes', 'allowedFileExtensions', 'maxAllowedFilesCount', 'maxFileSize', 'validationRegex',
        'dateMax', 'dateMin', 'dateRange', 'timeMax', 'timeMin', 'timeRange',
        'optionsLowest', 'optionsHighest', 'optionsLabelLowest', 'optionsLabelHighest',
        'columns', 'rows', 'questionType',
    ];

    /** @param array<string, mixed> $form */
    public static function summary(array $form): array {
        return self::pick($form, self::SUMMARY_FIELDS);
    }

    /** @param array<string, mixed> $form */
    public static function form(array $form, int $questionOffset = 0, int $questionLimit = self::DEFAULT_QUESTIONS, int $optionOffset = 0, int $optionLimit = self::DEFAULT_OPTIONS): array {
        $output = self::pick($form, self::FORM_FIELDS);
        $questions = is_array($form['questions'] ?? null) ? $form['questions'] : [];
        $questionPage = array_slice($questions, $questionOffset, $questionLimit);
        $questionsHasMore = $questionOffset + count($questionPage) < count($questions);
        $output['questionsTotal'] = count($questions);
        $output['questionsOffset'] = $questionOffset;
        $output['questionsLimit'] = $questionLimit;
        $output['questionsHasMore'] = $questionsHasMore;
        $output['nextQuestionOffset'] = $questionsHasMore ? $questionOffset + count($questionPage) : null;
        $output['questions'] = [];
        foreach ($questionPage as $question) {
            if (!is_array($question)) {
                continue;
            }
            $item = self::pick($question, self::QUESTION_FIELDS);
            $options = is_array($question['options'] ?? null) ? $question['options'] : [];
            $optionPage = array_slice($options, $optionOffset, $optionLimit);
            $optionsHasMore = $optionOffset + count($optionPage) < count($options);
            $item['optionsTotal'] = count($options);
            $item['optionsOffset'] = $optionOffset;
            $item['optionsLimit'] = $optionLimit;
            $item['optionsHasMore'] = $optionsHasMore;
            $item['nextOptionOffset'] = $optionsHasMore ? $optionOffset + count($optionPage) : null;
            $item['options'] = [];
            foreach ($optionPage as $option) {
                if (is_array($option)) {
                    $item['options'][] = self::pick($option, self::OPTION_FIELDS);
                }
            }
            if (is_array($question['extraSettings'] ?? null)) {
                [$settings, $truncated] = self::settings($question['extraSettings']);
                $item['extraSettings'] = $settings;
                if ($truncated) {
                    $item['extraSettingsTruncated'] = true;
                }
            }
            $output['questions'][] = $item;
        }
        return $output;
    }

    /** @param array<string, mixed> $input @return array{array<string, mixed>, bool} */
    private static function settings(array $input): array {
        $output = [];
        $truncated = false;
        foreach (self::SETTING_FIELDS as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            $value = $input[$field];
            if (is_array($value)) {
                $value = array_values(array_filter($value, static fn(mixed $item): bool => is_scalar($item) || $item === null));
                if (count($value) > self::MAX_SETTING_ITEMS) {
                    $value = array_slice($value, 0, self::MAX_SETTING_ITEMS);
                    $truncated = true;
                }
                foreach ($value as $index => $item) {
                    if (is_string($item) && strlen($item) > self::MAX_TEXT_BYTES) {
                        $value[$index] = mb_strcut($item, 0, self::MAX_TEXT_BYTES, 'UTF-8');
                        $truncated = true;
                    }
                }
                $output[$field] = $value;
            } elseif (is_string($value)) {
                if (strlen($value) > self::MAX_TEXT_BYTES) {
                    $value = mb_strcut($value, 0, self::MAX_TEXT_BYTES, 'UTF-8');
                    $truncated = true;
                }
                $output[$field] = $value;
            } elseif (is_scalar($value) || $value === null) {
                $output[$field] = $value;
            }
        }
        return [$output, $truncated];
    }

    /** @param array<string, mixed> $data @param list<string> $fields @return array<string, mixed> */
    private static function pick(array $data, array $fields): array {
        $output = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field];
                if (is_string($value) && strlen($value) > self::MAX_TEXT_BYTES) {
                    $output[$field] = mb_strcut($value, 0, self::MAX_TEXT_BYTES, 'UTF-8');
                    $output[$field . 'Truncated'] = true;
                } else {
                    $output[$field] = $value;
                }
            }
        }
        return $output;
    }
}
