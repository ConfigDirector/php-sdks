<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

final readonly class TelemetryFiles
{
    private const int MAXIMUM_BYTES_OF_TELEMETRY = 8 * 1024 * 1024;

    private const int MAXIMUM_BYTES_OF_DROPPED_COUNTS = 1024 * 1024;

    private const int MAXIMUM_BYTES_OF_THE_TALLY = 65_536;

    public string $lockFilePath;

    public AppendedLinesFile $telemetry;

    public AppendedLinesFile $droppedCounts;

    public StoreFile $tally;

    public function __construct(private string $storeFolder)
    {
        $this->lockFilePath = $storeFolder . DIRECTORY_SEPARATOR . 'telemetry.lock';
        $this->telemetry = new AppendedLinesFile($storeFolder, 'telemetry.jsonl', self::MAXIMUM_BYTES_OF_TELEMETRY);
        $this->droppedCounts = new AppendedLinesFile(
            $storeFolder,
            'telemetry-dropped.jsonl',
            self::MAXIMUM_BYTES_OF_DROPPED_COUNTS,
        );
        $this->tally = new StoreFile($storeFolder, 'telemetry-tally.json');
    }

    public function collectingSinceEpochMilliseconds(): ?int
    {
        $json = $this->tally->exists() ? $this->tally->contentsOfAtMost(self::MAXIMUM_BYTES_OF_THE_TALLY) : null;
        $tally = $json === null ? null : json_decode($json, associative: true);
        $collectingSince = is_array($tally) ? $tally['collectingSince'] ?? null : null;

        return StoredNumber::isEpochMilliseconds($collectingSince) ? $collectingSince : null;
    }

    public function startCollectingAt(int $epochMilliseconds): bool
    {
        return $this->tally->replaceContentsWith(
            json_encode(['collectingSince' => $epochMilliseconds], JSON_THROW_ON_ERROR),
        );
    }

    public function sendingPauseFile(string $telemetryUrl): TelemetrySendingPauseFile
    {
        return new TelemetrySendingPauseFile($this->storeFolder, $telemetryUrl);
    }
}
