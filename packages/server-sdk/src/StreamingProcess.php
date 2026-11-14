<?php

declare(strict_types=1);

namespace ConfigDirector;

/**
 * The streaming process of one host: it holds a streaming connection to ConfigDirector, writes
 * every change it receives to the local store that the application's requests read, and sends the
 * telemetry those requests collected there. Create it with
 * {@see ConfigDirector::createStreamingProcess()}, or run the command
 * `vendor/bin/configdirector-stream`.
 */
interface StreamingProcess
{
    /**
     * Holds the streaming connection until the process receives SIGTERM or SIGINT, then sends the
     * telemetry the local store holds and returns. It reconnects by itself when the connection
     * drops or ConfigDirector cannot be reached.
     *
     * Stopping on a signal needs the pcntl extension. While it runs, this method handles SIGTERM
     * and SIGINT itself, and it puts back the handlers that were there before it returns. Without
     * the extension, and on Windows, a signal ends the PHP process at once.
     *
     * @throws ConfigDirectorConnectionException When ConfigDirector refuses the connection with a
     *     4xx status other than 429, as it does for a server SDK key it does not know. Its `status`
     *     is that status. An error is logged first.
     * @throws ConfigDirectorException When another streaming process is already running on the same
     *     local store, or when the local store cannot be used. An error is logged first.
     */
    public function run(): void;
}
