<?php

declare(strict_types=1);

namespace Deadcode\Reporter\Symfony;

use Deadcode\Reporter\ProcessReporter;
use Deadcode\Reporter\Reporter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Flushes the reporter after each message a Messenger worker consumes, so that
 * a consumer that is killed rather than shut down has still reported its handlers.
 */
final class FlushAfterMessageSubscriber implements EventSubscriberInterface
{
    private readonly ProcessReporter $reporter;

    public function __construct(?ProcessReporter $reporter = null)
    {
        $this->reporter = $reporter ?? ProcessReporter::shared();
    }

    public static function getSubscribedEvents(): array
    {
        // A handler that threw still ran.
        return [
            WorkerMessageHandledEvent::class => 'flush',
            WorkerMessageFailedEvent::class => 'flush',
        ];
    }

    public function flush(): void
    {
        $this->reporter->flush(Reporter::SOURCE_WORKER);
    }
}
