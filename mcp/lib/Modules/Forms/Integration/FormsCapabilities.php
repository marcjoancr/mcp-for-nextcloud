<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Integration;

use OCA\Mcp\Access\AppAvailability\AppEnablement;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\App\IAppManager;
use OCP\IUserManager;

/** Admission is per feature; the historical API floor never admits unknown writes. */
final class FormsCapabilities {
    public const QUESTION_TYPES = ['color','date','datetime','dropdown','file','grid','linearscale','long','multiple','multiple_unique','ranking','short','time'];
    public const LIMITS = ['pageItems'=>100,'requestBytes'=>1048576,'responseBytes'=>1048576,'optionBatch'=>100,'files'=>10,'fileBytes'=>26214400,'stepSeconds'=>20,'downloadSeconds'=>300];

    /** @param \Closure():?string $session @param \Closure():array $guard */
    public function __construct(private IAppManager $apps, private IUserManager $users, private \Closure $session, private \Closure $guard) {}

    public function forUser(string $uid): array {
        if (($this->session)() !== $uid) { throw new ToolFailure(ToolFailure::FORBIDDEN); }
        $user = $this->users->get($uid);
        if ($user === null || !$user->isEnabled()) { throw new ToolFailure(ToolFailure::FORBIDDEN); }
        $version = (string)$this->apps->getAppVersion('forms');
        $enabled = AppEnablement::forUser($this->apps, 'forms', $user);
        $known = in_array($version, ['5.4.2'], true);
        $guard = $enabled && $known ? ($this->guard)() : [];
        $qualified = $enabled && $known && ($guard['qualified'] ?? false) === true && ($guard['protocol'] ?? null) === 1;
        return [
            'nativeVersion'=>$version,
            'legacyReads'=>$enabled,
            'completeMetadata'=>$qualified,
            'guardedWrites'=>$qualified,
            'writeUnavailableReason'=>$qualified ? null : ($enabled && $known ? 'native_guard_unqualified' : 'native_version_unqualified'),
            'questionTypes'=>self::QUESTION_TYPES,
            'creatableQuestionTypes'=>array_values(array_diff(self::QUESTION_TYPES, ['datetime'])),
            'limits'=>self::LIMITS,
        ];
    }
}
