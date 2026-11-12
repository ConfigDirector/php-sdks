<?php

declare(strict_types=1);

namespace ConfigDirector;

/**
 * How a client gets its values.
 */
enum ConnectionMode: string
{
    /**
     * The client reads the values ConfigDirector serves and asks for them again once per polling
     * interval. This is the default.
     */
    case Polling = 'polling';

    /**
     * The client never connects to ConfigDirector and sends nothing. It needs no server SDK key.
     */
    case Offline = 'offline';
}
