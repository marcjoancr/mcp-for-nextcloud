<?php
declare(strict_types=1);

namespace OCA\Forms\Mcp\Service {
    final class NativeHomeEntrypointFixtureState {
        public static array $seen = [];
        public static array $input = [];
    }
    final class BulkStore {
        public function load(string $id): array { return NativeHomeEntrypointFixtureState::$input['job']; }
    }
    final class FormsRevisionFence {
        public function active(): bool { return true; }
    }
    final class OriginContext {
        public static function within(string $uid, array $origin, string $action, array $input, callable $work): mixed {
            NativeHomeEntrypointFixtureState::$seen[] = $origin;
            return $work();
        }
        public static function withBulkControl(string $tool, array $original, callable $work): mixed { return $work(); }
        public static function nativeHomeEnvelope(array $job, array $claim): array { return NativeHomeEntrypointFixtureState::$input['envelope']; }
    }
}

namespace OCP {
    final class Server {
        public static function get(string $service): object {
            if ($service === \OCA\Forms\Mcp\Service\BulkStore::class) { return new \OCA\Forms\Mcp\Service\BulkStore(); }
            if ($service === \OCA\Forms\Mcp\Service\FormsRevisionFence::class) { return new \OCA\Forms\Mcp\Service\FormsRevisionFence(); }
            throw new \RuntimeException('Unexpected fixture service');
        }
    }
}

namespace {
    require $argv[1];
    $input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
    \OCA\Forms\Mcp\Service\NativeHomeEntrypointFixtureState::$input = $input;
    $stream = fopen('php://memory', 'r+');
    try {
        \OCA\Forms\Mcp\Service\NativeHomeChild::run($input['envelope'], $stream);
        throw new RuntimeException('Entrypoint did not reach the expected transaction fence');
    } catch (RuntimeException $error) {
        echo json_encode(['error'=>$error->getMessage(), 'seen'=>\OCA\Forms\Mcp\Service\NativeHomeEntrypointFixtureState::$seen], JSON_THROW_ON_ERROR);
    }
}
