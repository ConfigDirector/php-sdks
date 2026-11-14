<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Streaming;

use ConfigDirector\ConfigDirectorConnectionException;
use ConfigDirector\ConfigDirectorException;
use ConfigDirector\Internal\LocalStore\LocalStoreOpenedOnFirstUse;
use ConfigDirector\Internal\LocalStore\StoredTelemetry;
use ConfigDirector\Internal\LocalStore\StreamingProcessLock;
use ConfigDirector\Internal\LocalStore\TelemetryLock;
use ConfigDirector\Internal\LocalStore\UnacquiredFileLock;
use ConfigDirector\Internal\Telemetry\EventReporter;
use ConfigDirector\Internal\Time\Clock;
use ConfigDirector\StreamingProcess;
use Psr\Log\LoggerInterface;

final class DefaultStreamingProcess implements StreamingProcess
{
    private const int MILLISECONDS_BETWEEN_LOOKS_AT_WHAT_IS_DUE = 250;

    private ?StreamingProcessLock $streamingProcessLock = null;

    private int $telemetryDueAtMonotonicMilliseconds = 0;

    private bool $isToldToStop = false;

    public function __construct(
        private readonly LocalStoreOpenedOnFirstUse $localStore,
        private readonly StreamingTransport $transport,
        private readonly LocalStoreFedByTheStream $localStoreFedByTheStream,
        private readonly EventReporter $eventReporter,
        private readonly int $flushIntervalMilliseconds,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {}

    #[\Override]
    public function run(): void
    {
        $this->takeHoldOfTheLocalStore();
        $this->isToldToStop = false;
        $restoreTheSignalHandlers = $this->stopOnATerminationSignal();
        try {
            while (!$this->isToldToStop) {
                $this->advance();
                $this->transport->waitForActivityAtMost(self::MILLISECONDS_BETWEEN_LOOKS_AT_WHAT_IS_DUE);
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
            }
            $this->sendTheStoredTelemetry();
            $this->logger->info('[StreamingProcess] Stopped.');
        } finally {
            $restoreTheSignalHandlers();
            $this->letGoOfTheLocalStore();
        }
    }

    /**
     * @throws ConfigDirectorException
     */
    public function takeHoldOfTheLocalStore(): void
    {
        $store = $this->localStore->store();
        $folder = $store->usableFolder();
        if ($folder === null) {
            throw new ConfigDirectorException('The local store cannot be used, so the streaming process cannot run.');
        }

        $streamingProcessLock = StreamingProcessLock::acquiredIn($folder);
        if (!$streamingProcessLock instanceof StreamingProcessLock) {
            [$errorLine, $errorContext, $exceptionMessage] = match ($streamingProcessLock) {
                UnacquiredFileLock::HeldPastTheTimeout => [
                    '[StreamingProcess] Another streaming process already holds the streaming connection for the '
                    . 'local store in {folder}. This one stops.',
                    ['folder' => $folder],
                    'Another streaming process already holds the streaming connection for the local store in '
                    . "{$folder}.",
                ],
                UnacquiredFileLock::LockFileUnusable => [
                    '[LocalStore] {file} could not be written or locked, so the streaming process cannot run.',
                    ['file' => StreamingProcessLock::lockFilePathIn($folder)],
                    'The local store cannot be used, so the streaming process cannot run.',
                ],
            };
            $this->logger->error($errorLine, $errorContext);

            throw new ConfigDirectorException($exceptionMessage);
        }

        $this->streamingProcessLock = $streamingProcessLock;
        $this->telemetryDueAtMonotonicMilliseconds = $this->clock->monotonicMilliseconds()
            + $this->flushIntervalMilliseconds;
        $this->logger->info(
            '[StreamingProcess] Keeping the local store in {folder} up to date.',
            ['folder' => $folder],
        );
    }

    /**
     * @throws ConfigDirectorConnectionException
     */
    public function advance(): void
    {
        try {
            $this->transport->advance();
        } catch (ConfigDirectorConnectionException $unrecoverableError) {
            $this->logger->error(
                '[StreamingProcess] The connection encountered an unrecoverable error and will not be retried. The '
                . 'streaming process stops.',
                ['exception' => $unrecoverableError],
            );

            throw $unrecoverableError;
        }

        if ($this->transport->hasStoredAPayloadOfTheOpenStream()) {
            $this->localStoreFedByTheStream->confirmTheStoredPayloadIsFreshWhenDue();
        }
        if ($this->clock->monotonicMilliseconds() >= $this->telemetryDueAtMonotonicMilliseconds) {
            $this->telemetryDueAtMonotonicMilliseconds = $this->clock->monotonicMilliseconds()
                + $this->flushIntervalMilliseconds;
            $this->streamingProcessLock?->showTheLockFileIsInUse();
            $this->sendTheStoredTelemetry();
        }
    }

    private function sendTheStoredTelemetry(): void
    {
        $store = $this->localStore->store();
        $storedTelemetry = $store->whileHoldingTheTelemetryLock(
            fn(TelemetryLock $telemetryLock): ?StoredTelemetry => $this->eventReporter->sendingIsPausedUnder(
                $telemetryLock,
            ) ? null : $telemetryLock->takeStoredTelemetry($this->clock->epochMilliseconds()),
        );
        if ($storedTelemetry !== null) {
            $this->eventReporter->send($storedTelemetry, $store);
        }
    }

    public function letGoOfTheLocalStore(): void
    {
        $this->transport->close();
        $this->streamingProcessLock?->release();
        $this->streamingProcessLock = null;
    }

    /**
     * @return \Closure(): void
     */
    private function stopOnATerminationSignal(): \Closure
    {
        if (!function_exists('pcntl_signal')) {
            return static function (): void {};
        }

        $handlersBeforeTheRun = [];
        foreach ([SIGTERM, SIGINT] as $signal) {
            $handlersBeforeTheRun[$signal] = pcntl_signal_get_handler($signal);
            pcntl_signal($signal, function (): void {
                $this->isToldToStop = true;
            });
        }

        return static function () use ($handlersBeforeTheRun): void {
            foreach ($handlersBeforeTheRun as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
        };
    }
}
