<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Connection;

use ConfigDirector\ConfigsUpdatedEvent;
use ConfigDirector\Internal\Evaluation\Segment;
use Psr\Log\LoggerInterface;

final readonly class ServerSdkPayload
{
    /**
     * @param array<string, ConfigDefinition> $configs
     * @param array<string, Segment> $segments
     * @param array<string, string> $causesByUnreadableConfigKey
     * @param array<string, string> $causesByUnreadableSegmentId
     */
    public function __construct(
        public array $configs,
        public array $segments,
        private array $causesByUnreadableConfigKey = [],
        private array $causesByUnreadableSegmentId = [],
    ) {}

    public function configsUpdatedSince(?self $heldPayload): ConfigsUpdatedEvent
    {
        if ($heldPayload === null) {
            return new ConfigsUpdatedEvent($this->configKeys(), []);
        }

        $changedSegmentIds = $this->idsOfSegmentsDefinedOtherwiseIn($heldPayload);
        $changedConfigKeys = [];
        foreach ($this->configs as $configKey => $definition) {
            $heldDefinition = $heldPayload->configs[$configKey] ?? null;
            $isNewOrChanged = $heldDefinition === null || !$definition->isDefinedAs($heldDefinition);
            if ($isNewOrChanged || $definition->usesAnySegmentOf($changedSegmentIds)) {
                $changedConfigKeys[] = (string) $configKey;
            }
        }

        return new ConfigsUpdatedEvent(
            $changedConfigKeys,
            array_values(array_diff($heldPayload->configKeys(), $this->configKeys())),
        );
    }

    public function logUnreadableDefinitions(LoggerInterface $logger, string $logLevel): void
    {
        foreach ($this->causesByUnreadableConfigKey as $configKey => $cause) {
            $logger->log(
                $logLevel,
                "[ConfigDirectorClient] Skipping the config '{configKey}', its definition could not be read: {cause}",
                ['configKey' => (string) $configKey, 'cause' => $cause],
            );
        }
        foreach ($this->causesByUnreadableSegmentId as $segmentId => $cause) {
            $logger->log(
                $logLevel,
                "[ConfigDirectorClient] Skipping the segment '{segmentId}', its definition could not be read: {cause}",
                ['segmentId' => (string) $segmentId, 'cause' => $cause],
            );
        }
    }

    /**
     * @return list<string>
     */
    private function configKeys(): array
    {
        return array_map(strval(...), array_keys($this->configs));
    }

    /**
     * @return list<string>
     */
    private function idsOfSegmentsDefinedOtherwiseIn(self $otherPayload): array
    {
        $segmentIds = [];
        foreach (array_keys($this->segments + $otherPayload->segments) as $segmentId) {
            $segment = $this->segments[$segmentId] ?? null;
            $otherSegment = $otherPayload->segments[$segmentId] ?? null;
            if (serialize($segment) !== serialize($otherSegment)) {
                $segmentIds[] = (string) $segmentId;
            }
        }

        return $segmentIds;
    }
}
