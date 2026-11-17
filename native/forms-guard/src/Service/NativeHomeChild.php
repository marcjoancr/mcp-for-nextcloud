<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace OCA\Forms\Mcp\Service;

use OCP\Files\Mount\IMountManager;
use OCP\Files\Storage\IStorageFactory;
use OCP\IUserSession;
use OCP\Server;

/** CLI child entry: one fresh Core lifetime, one target Home setup, no returned Core objects. */
final class NativeHomeChild {
    private static ?array $activeStep = null;
    private static ?array $receipt = null;

    /** Arm exactly one recursive dispatch for the child-owned step. */
    public static function armBulkStep(array $envelope, string $stepKey): void {
        $phase = $envelope['home_phase'] ?? null;
        if (self::$activeStep !== null || !is_array($phase) || ($phase['step_key'] ?? null) !== $stepKey
            || !is_string($phase['nonce'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $phase['nonce'])) {
            throw new \RuntimeException('Native Home step binding is unavailable');
        }
        self::$activeStep = ['uid'=>$envelope['uid'],'job_id'=>$envelope['job_id'],'version'=>$phase['version'],
            'step_key'=>$stepKey,'nonce'=>$phase['nonce'],'used'=>false];
    }

    /** Consume the one permitted redispatch; any nested or mismatched step is rejected. */
    public static function claimBulkStep(string $uid, string $jobId, int $version, string $stepKey): bool {
        if (self::$activeStep === null) { return false; }
        $binding = self::$activeStep;
        if ($binding['used'] || $binding['uid'] !== $uid || $binding['job_id'] !== $jobId
            || $binding['version'] !== $version || $binding['step_key'] !== $stepKey
            || !is_string($binding['nonce']) || !preg_match('/^[a-f0-9]{64}$/D', $binding['nonce'])) {
            throw new \RuntimeException('Unexpected nested Native Home bulk step');
        }
        self::$activeStep['used'] = true;
        return true;
    }
    public static function receipt(): ?array { return self::$receipt; }

    /** The legacy bulk API uses the original signed proof when no control proof was supplied. */
    public static function dispatchControl(array $envelope): array {
        $control = $envelope['control'] ?? $envelope['origin'] ?? null;
        if (!is_array($control)) { throw new \RuntimeException('Native Home control proof is unavailable'); }
        return $control;
    }

    public static function run(array $input, mixed $stream): array {
        $jobId = $input['job_id'] ?? null;
        if (!is_string($jobId) || !preg_match('/^[a-f0-9]{64}$/D', $jobId)) {
            throw new \RuntimeException('Native Home request is unavailable');
        }
        $store = Server::get(BulkStore::class);
        $job = $store->load($jobId);
        $claim = $job['native_home_claim'] ?? null;
        if (!is_array($claim) || !is_array($claim['home_phase'] ?? null)) {
            throw new \RuntimeException('Native Home claim is unavailable');
        }
        $action = $job['action'];
        $uid = $job['uid'];
        return OriginContext::within($uid, self::dispatchControl($input), 'lookup', [], static function () use ($input, $job, $claim, $uid, $action, $stream): array {
            $prepared = OriginContext::withBulkControl('forms_continue_operation', $input['origin'] ?? [], static function () use ($input, $job, $claim, $uid, $action, $stream): array {
                return OriginContext::within($uid, $input['origin'], $action, $job['input'], static function () use ($input, $job, $claim, $uid, $stream): array {
                    $expected = OriginContext::nativeHomeEnvelope($job, $claim);
                    if (!hash_equals(json_encode($expected, JSON_THROW_ON_ERROR), json_encode($input, JSON_THROW_ON_ERROR))) {
                        throw new \RuntimeException('Native Home request binding differs');
                    }
                    return self::initialize($expected, $stream, $job, (string)($claim['home_phase']['step_key'] ?? ''));
                });
            });
            return self::dispatchPreparedStep($prepared);
        });
    }

    private static function initialize(array $envelope, mixed $stream, array $job, string $stepKey): array {
        if (Server::get(FormsRevisionFence::class)->active()) {
            throw new \RuntimeException('Native Home child cannot borrow a Forms transaction');
        }
        $target = (new FreshCore($envelope['target_uid']))->user();
        $actor = (new FreshCore($envelope['uid']))->user();
        if (Server::get(IUserSession::class)->getUser()?->getUID() !== $envelope['uid']) {
            Server::get(IUserSession::class)->setUser($actor);
        }
        if (Server::get(IUserSession::class)->getUser()?->getUID() !== $envelope['uid']) {
            throw new \RuntimeException('Native Home original session is unavailable');
        }

        $mounts = Server::get(IMountManager::class);
        if ($mounts::class !== 'OC\\Files\\Mount\\Manager' || $mounts->getAll() !== []) {
            throw new \RuntimeException('Native Home child mount graph is already materialized');
        }
        $setup = $mounts->getSetupManager();
        if ($setup::class !== 'OC\\Files\\SetupManager' || $setup->isSetupComplete($target)) {
            throw new \RuntimeException('Native Home setup manager is unavailable');
        }
        $factory = Server::get(IStorageFactory::class);
        if ($factory::class !== 'OC\\Files\\Storage\\StorageFactory') {
            throw new \RuntimeException('Native Home storage factory is unavailable');
        }
        NativeHomeProfile::prepareBuiltinWrappers();
        NativeHomeProfile::assertCurrent();
        if ($mounts->getAll() !== []) {
            throw new \RuntimeException('Native Home preparation materialized a mount');
        }

        $journal = NativeHomeJournal::open($stream, $envelope);
        $authority = static function () use ($envelope): void {
            Server::get(\OCA\Mcp\Modules\Forms\Access\FormsNativeAuthorization::class)
                ->assertNativeHome($envelope['origin'], $envelope['control'], $envelope['target_uid']);
            OriginContext::assertFresh();
        };
        self::installStorageGuard($factory, NativeHomeProcess::wrapperClosure($envelope['target_uid'], $journal, $authority));

        $fence = Server::get(FormsRevisionFence::class);
        $profile = NativeHomePath::assertDefaultLocalConfig(
            $envelope['target_uid'], $target->getHome(),
            (string)Server::get(\OCP\IConfig::class)->getSystemValue('datadirectory', ''),
            Server::get(\OCP\IConfig::class)->getSystemValue('objectstore', false),
            Server::get(\OCP\IConfig::class)->getSystemValue('objectstore_multibucket', false),
        );
        $targetIdentity = null;
        if ($envelope['action'] === 'forms_transfer') {
            $targetIdentity = $job['target_native_actor'] ?? null;
            if (!NativeHistoryIdentity::ready() || !is_string($targetIdentity)) {
                throw new \RuntimeException('Native Home target incarnation is unavailable');
            }
            NativeHistoryIdentity::assertCurrent($envelope['target_uid'], $targetIdentity);
        }
        if ($stepKey !== ($job['native_home_claim']['home_phase']['step_key'] ?? null)) {
            throw new \RuntimeException('Native Home step claim differs');
        }
        self::assertRestoreBackup($envelope, $job);
        $fence->transaction(static function () use ($setup, $target, $envelope, $authority, $journal, $targetIdentity, $profile, $job): void {
            $authority();
            self::assertRestoreBackup($envelope, $job);
            $setup->setupForUser($target);
            $folder = Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($envelope['target_uid']);
            $mounts = Server::get(IMountManager::class)->getAll();
            $home = NativeHomePath::selectedHomeMount($envelope['target_uid'], $mounts);
            if ($home === null || !$folder instanceof \OCP\Files\Folder
                || !NativeHomePath::matchesSelectedFilesPath($folder->getPath(), $envelope['files_path'])) {
                throw new \RuntimeException('Native Home setup did not produce the selected destination');
            }
            $authority();
            $fresh = (new FreshCore($envelope['target_uid']))->user();
            if ($targetIdentity !== null) { NativeHistoryIdentity::assertCurrent($envelope['target_uid'], $targetIdentity); }
            elseif ($fresh->getUID() !== $envelope['uid']) { throw new \RuntimeException('Native Home actor identity changed during setup'); }
            $authority();
            NativeHomeProfile::assertCurrent();
            NativeHomeStorage::assertAllHealthy();
            self::assertRestoreBackup($envelope, $job);
            $journal->assertComplete();
        });
        $journalSummary = $journal->seal('committed');
        self::$receipt = NativeHomeJournal::read($stream, $envelope);
        NativeHomeStorage::guardedInitialization(false);
        return ['envelope' => $envelope, 'step_key' => $stepKey, 'journal' => $journalSummary];
    }

    /** Run the public bulk entrypoint only after the setup control frame has unwound. */
    private static function dispatchPreparedStep(array $prepared): array {
        $envelope = $prepared['envelope'] ?? null;
        $stepKey = $prepared['step_key'] ?? null;
        $journalSummary = $prepared['journal'] ?? null;
        if (!is_array($envelope) || !is_string($stepKey) || !is_array($journalSummary)
            || ($envelope['home_phase']['step_key'] ?? null) !== $stepKey) {
            throw new \RuntimeException('Native Home prepared step is unavailable');
        }
        $fence = Server::get(FormsRevisionFence::class);
        self::armBulkStep($envelope, $stepKey);
        try {
            $dto = Server::get(\OCA\Forms\Mcp\Guard::class)->bulkStep(
                $envelope['uid'], $envelope['job_id'], self::dispatchControl($envelope), $envelope['home_phase']['version'], $stepKey,
                static fn(string $tool, array $manifest) => Server::get(\OCA\Mcp\Modules\Forms\Access\FormsNativeAuthorization::class)
                    ->assertFiles(self::dispatchControl($envelope), $tool, $manifest),
            );
        } finally { self::$activeStep = null; }
        if (!is_array($dto)) { throw new \RuntimeException('Native Home step receipt is unavailable'); }
        NativeHomeProfile::assertCurrent();
        NativeHomeStorage::assertAllHealthy();
        $fence->transaction(static function () use ($envelope, $stepKey, $dto): void {
            $store = Server::get(BulkStore::class);
            $current = $store->load($envelope['job_id']);
            if (($current['native_home_claim']['home_phase']['step_key'] ?? null) !== $stepKey
                || (int)($current['version'] ?? 0) !== (int)($dto['version'] ?? -1)) {
                throw new \RuntimeException('Native Home step claim changed');
            }
            $store->putMapping($current, 'native_home', $envelope['home_phase']['version'], self::$receipt);
            $current['native_home_receipt'] = ['phase'=>$envelope['home_phase']['phase'],'version'=>$envelope['home_phase']['version'],
                'effects'=>self::$receipt['effects'],'chain'=>self::$receipt['chain']];
            if (in_array($dto['state'] ?? null, ['pending', 'completed', 'partial', 'cancelled', 'failed_before_effect'], true)) {
                unset($current['native_home_claim'], $current['native_home_journal']);
            }
            $store->save($current);
        });
        return ['protocol' => 1, 'ok' => true, 'job_id' => $envelope['job_id'], 'phase' => $envelope['home_phase']['phase'],
            'target_uid' => $envelope['target_uid'], 'journal' => $journalSummary, 'dto' => $dto];
    }

    /** Install the guard as the last (innermost) storage wrapper before any mount exists. */
    public static function installStorageGuard(object $factory, \Closure $wrapper): void {
        $name = 'forms_native_home_guard_v1';
        NativeHomeProfile::registerOwnedWrapper($name, $wrapper);
        if (!is_callable([$factory, 'addStorageWrapper'])
            || $factory->addStorageWrapper($name, $wrapper, PHP_INT_MIN, []) !== true) {
            throw new \RuntimeException('Native Home storage guard could not be installed');
        }
    }

    private static function assertRestoreBackup(array $envelope, array $job): void {
        if ($envelope['action'] !== 'forms_restore') { return; }
        $backupId = $envelope['backup_id'] ?? null;
        $seal = $envelope['backup_seal'] ?? null;
        if (!is_string($backupId) || !preg_match('/^[a-f0-9]{64}$/D', $backupId)
            || !is_string($seal) || !preg_match('/^[a-f0-9]{64}$/D', $seal)) {
            throw new \RuntimeException('Native Home restore backup is unavailable');
        }
        $backup = Server::get(BulkStore::class)->load($backupId);
        if (($backup['id'] ?? null) !== $backupId || ($backup['sealed'] ?? false) !== true
            || ($backup['uid'] ?? null) !== $job['uid'] || ($backup['actor'] ?? null) !== $job['actor']
            || ($backup['form'] ?? null) !== $job['form'] || ($backup['counts'] ?? null) !== $job['counts']
            || ($backup['backup_format'] ?? 1) !== ($job['backup_format'] ?? 1)
            || ($backup['seal'] ?? null) !== $seal) {
            throw new \RuntimeException('Native Home restore backup changed');
        }
    }
}
