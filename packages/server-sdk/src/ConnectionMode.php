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
     * The client never connects to ConfigDirector and sends nothing, so the application is
     * invisible to the ConfigDirector dashboard. It needs no server SDK key. It serves the values
     * of {@see ConnectionOptions::$values} or {@see ConnectionOptions::$file} to every context,
     * without applying targeting rules, or the in-code default values when neither is given.
     */
    case Offline = 'offline';
}
