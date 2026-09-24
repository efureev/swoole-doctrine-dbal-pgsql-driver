<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Tests\Unit\Fake;

use Override;
use Psr\Log\AbstractLogger;
use Stringable;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    #[Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string)$level, 'message' => (string)$message, 'context' => $context];
    }

    /** @return list<string> */
    public function messages(string $level): array
    {
        return array_values(array_map(
            static fn(array $r): string => $r['message'],
            array_filter($this->records, static fn(array $r): bool => $r['level'] === $level),
        ));
    }
}
