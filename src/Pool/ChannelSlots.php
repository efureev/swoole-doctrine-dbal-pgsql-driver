<?php

declare(strict_types=1);

namespace SwooleDoctrinePool\Pool;

use LogicException;
use Override;
use Swoole\Coroutine\Channel;

use function is_int;
use function sprintf;

/**
 * Семафор на Swoole\Coroutine\Channel: pop с таймаутом — это атомарно и FIFO-ожидание, и резервация.
 */
final class ChannelSlots implements Slots
{
    private readonly Channel $channel;
    private bool $closed = false;

    public function __construct(private readonly int $size)
    {
        $this->channel = new Channel($size);

        for ($i = 0; $i < $size; $i++) {
            $this->channel->push(true);
        }
    }

    #[Override]
    public function acquire(float $timeout): SlotResult
    {
        if ($this->closed) {
            return SlotResult::Closed;
        }

        // Для Channel::pop() 0 и отрицательное значение означают «ждать вечно», таймаут — только положительное.
        $token = $this->channel->pop($timeout > 0.0 ? $timeout : 0.001);

        if ($token !== false) {
            return SlotResult::Acquired;
        }

        return $this->channel->errCode === SWOOLE_CHANNEL_CLOSED ? SlotResult::Closed : SlotResult::TimedOut;
    }

    #[Override]
    public function tryAcquire(): bool
    {
        // Между isEmpty() и pop() нет точки переключения, а pop() при наличии данных не уступает управление.
        if ($this->closed || $this->channel->isEmpty()) {
            return false;
        }

        return $this->channel->pop(0.001) !== false;
    }

    #[Override]
    public function release(): void
    {
        if ($this->closed) {
            return;
        }

        if (!$this->channel->push(true, 1.0)) {
            throw new LogicException(sprintf(
                'Слот пула не удалось вернуть (errCode=%d, length=%d, size=%d): токенов стало больше, чем size — '
                . 'release() вызван для соединения, которое не брали.',
                $this->channel->errCode,
                $this->channel->length(),
                $this->size,
            ));
        }
    }

    #[Override]
    public function available(): int
    {
        return $this->closed ? 0 : $this->channel->length();
    }

    #[Override]
    public function waiting(): int
    {
        $consumers = $this->channel->stats()['consumer_num'] ?? 0;

        return is_int($consumers) ? $consumers : 0;
    }

    #[Override]
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->channel->close();
    }
}
