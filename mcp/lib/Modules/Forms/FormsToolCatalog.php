<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms;

use OCA\Mcp\Mcp\Tools\Tool;

final class FormsToolCatalog {
    /** @return list<class-string<Tool>> */
    public static function classes(): array {
        return [Tools\ListForms::class, Tools\ReadForm::class, Tools\ReadResponses::class,
            Tools\SummarizeResponses::class, Tools\ExportResponses::class,
            Tools\Capabilities::class,
            Tools\SearchForms::class,
            Tools\ReadSettings::class,
            Tools\CreateForm::class,
            Tools\CloneForm::class,
            Tools\UpdateForm::class,
            Tools\SetState::class,
            Tools\SetLock::class,
            Tools\ReleaseLock::class,
            Tools\TransferForm::class,
            Tools\DeleteForm::class,
            Tools\RestoreForm::class,
            Tools\CreateQuestion::class,
            Tools\CloneQuestion::class,
            Tools\ReadQuestion::class,
            Tools\UpdateQuestion::class,
            Tools\DeleteQuestion::class,
            Tools\ReorderQuestions::class,
            Tools\AddOptions::class,
            Tools\UpdateOption::class,
            Tools\DeleteOption::class,
            Tools\ReorderOptions::class,
            Tools\ListShares::class,
            Tools\ReadLinks::class,
            Tools\ShareForm::class,
            Tools\UpdateShare::class,
            Tools\UnshareForm::class,
            Tools\GenerateLinkToken::class,
            Tools\GetEmbed::class,
            Tools\SubmitResponse::class,
            Tools\ReadOwnResponses::class,
            Tools\ReadResponse::class,
            Tools\UpdateOwnResponse::class,
            Tools\UpdateResponse::class,
            Tools\DeleteOwnResponse::class,
            Tools\DeleteResponse::class,
            Tools\DeleteResponses::class,
            Tools\UploadResponseFiles::class,
            Tools\ListResponseFiles::class,
            Tools\SaveResponseFile::class,
            Tools\SummarizeResults::class,
            Tools\DownloadResults::class,
            Tools\ExportResults::class,
            Tools\LinkResultsFile::class,
            Tools\UnlinkResultsFile::class,
            Tools\ListComments::class,
            Tools\AddComment::class,
            Tools\UpdateComment::class,
            Tools\DeleteComment::class,
            Tools\ReadAdminSettings::class,
            Tools\UpdateAdminSettings::class,
            Tools\ReadResult::class,
            Tools\GetOperation::class,
            Tools\ListOperations::class,
            Tools\ContinueOperation::class,
            Tools\CancelOperation::class];
    }

    /** @return array<string, array<string, mixed>> */
    public static function legacyDefinitions(): array {
        return array_intersect_key(self::definitions(), array_fill_keys(['forms_list','forms_read','forms_read_responses','forms_summarize_responses','forms_export_responses'], true));
    }

    /** @return array<string, array<string, mixed>> */
    public static function definitions(): array {
        $result = [];
        foreach (self::classes() as $class) {
            if (!is_a($class, Tool::class, true)) {
                throw new \LogicException('Catalog entries must implement Tool');
            }
            $definition = $class::definition();
            if (isset($result[$definition->name])) {
                throw new \LogicException('Duplicate tool: ' . $definition->name);
            }
            $result[$definition->name] = $definition->toArray();
        }

        return $result;
    }
}
