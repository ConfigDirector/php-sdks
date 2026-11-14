<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Streaming;

use ConfigDirector\Internal\ServerSentEvents\ServerSentEvent;

final class ReceivedEvents
{
    /** @var list<ServerSentEvent> */
    private array $eventsNotTakenYet = [];

    public function add(ServerSentEvent $event): void
    {
        $this->eventsNotTakenYet[] = $event;
    }

    /**
     * @return list<ServerSentEvent>
     */
    public function takeAll(): array
    {
        $events = $this->eventsNotTakenYet;
        $this->eventsNotTakenYet = [];

        return $events;
    }
}
