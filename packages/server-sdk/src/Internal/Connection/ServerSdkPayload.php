<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Connection;

use ConfigDirector\ConfigsUpdatedEvent;
use ConfigDirector\Internal\Evaluation\Segment;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

final class ServerSdkPayload
{
    /** @var array<string, ConfigDefinition> */
    private array $configsRead = [];

    /** @var array<string, true> */
    private array $keysOfConfigsThatCannotBeRead = [];

    private bool $isEveryConfigRead = false;

    /** @var array<string, Segment>|null */
    private ?array $segmentsRead = null;

    /**
     * @param array<array-key, mixed> $wireConfigsNotAllReadYet
     * @param array<array-key, mixed> $wireSegments
     */
    private function __construct(
        private array $wireConfigsNotAllReadYet,
        private readonly array $wireSegments,
        private readonly string $logLevelOfUnreadableDefinitions,
    ) {}

    /**
     * @param array<string, ConfigDefinition> $configs
     * @param array<string, Segment> $segments
     */
    public static function ofDefinitions(array $configs, array $segments): self
    {
        $payload = new self([], [], LogLevel::DEBUG);
        $payload->configsRead = $configs;
        $payload->isEveryConfigRead = true;
        $payload->segmentsRead = $segments;

        return $payload;
    }

    /**
     * @param array<array-key, mixed> $wireConfigs
     * @param array<array-key, mixed> $wireSegments
     */
    public static function readingEachWireDefinitionOnItsFirstUse(
        array $wireConfigs,
        array $wireSegments,
        string $logLevelOfUnreadableDefinitions,
    ): self {
        return new self($wireConfigs, $wireSegments, $logLevelOfUnreadableDefinitions);
    }

    public function configWithKey(string $configKey, LoggerInterface $logger): ?ConfigDefinition
    {
        $isAWireConfigNotReadYet = !$this->isEveryConfigRead
            && !isset($this->configsRead[$configKey])
            && !isset($this->keysOfConfigsThatCannotBeRead[$configKey])
            && array_key_exists($configKey, $this->wireConfigsNotAllReadYet);
        if ($isAWireConfigNotReadYet) {
            $this->readTheWireConfigWithKey($configKey, $logger);
        }

        return $this->configsRead[$configKey] ?? null;
    }

    /**
     * @return array<string, ConfigDefinition>
     */
    public function configs(LoggerInterface $logger): array
    {
        if ($this->isEveryConfigRead) {
            return $this->configsRead;
        }

        $configsInTheOrderOfThePayload = [];
        foreach (array_keys($this->wireConfigsNotAllReadYet) as $configKey) {
            $definition = $this->configWithKey((string) $configKey, $logger);
            if ($definition !== null) {
                $configsInTheOrderOfThePayload[(string) $configKey] = $definition;
            }
        }
        $this->configsRead = $configsInTheOrderOfThePayload;
        $this->isEveryConfigRead = true;
        $this->wireConfigsNotAllReadYet = [];

        return $this->configsRead;
    }

    /**
     * @return array<string, Segment>
     */
    public function segments(LoggerInterface $logger): array
    {
        if ($this->segmentsRead !== null) {
            return $this->segmentsRead;
        }

        $segments = [];
        $causesByUnreadableSegmentId = [];
        foreach ($this->wireSegments as $segmentId => $wireSegment) {
            try {
                $segments[(string) $segmentId] = ServerSdkPayloadParser::segmentDefinedBy($wireSegment);
            } catch (UnreadableDefinition $unreadable) {
                $causesByUnreadableSegmentId[(string) $segmentId] = $unreadable->getMessage();
            }
        }
        $this->segmentsRead = $segments;
        foreach ($causesByUnreadableSegmentId as $segmentId => $cause) {
            $logger->log(
                $this->logLevelOfUnreadableDefinitions,
                "[ConfigDirectorClient] Skipping the segment '{segmentId}', its definition could not be read: {cause}",
                ['segmentId' => (string) $segmentId, 'cause' => $cause],
            );
        }

        return $segments;
    }

    public function readEveryDefinition(LoggerInterface $logger): void
    {
        $this->configs($logger);
        $this->segments($logger);
    }

    public function configsUpdatedSince(?self $heldPayload, LoggerInterface $logger): ConfigsUpdatedEvent
    {
        if ($heldPayload === null) {
            return new ConfigsUpdatedEvent($this->configKeys($logger), []);
        }

        $changedSegmentIds = $this->idsOfSegmentsDefinedOtherwiseIn($heldPayload, $logger);
        $heldConfigs = $heldPayload->configs($logger);
        $changedConfigKeys = [];
        foreach ($this->configs($logger) as $configKey => $definition) {
            $heldDefinition = $heldConfigs[$configKey] ?? null;
            $isNewOrChanged = $heldDefinition === null || !$definition->isDefinedAs($heldDefinition);
            if ($isNewOrChanged || $definition->usesAnySegmentOf($changedSegmentIds)) {
                $changedConfigKeys[] = (string) $configKey;
            }
        }

        return new ConfigsUpdatedEvent(
            $changedConfigKeys,
            array_values(array_diff($heldPayload->configKeys($logger), $this->configKeys($logger))),
        );
    }

    private function readTheWireConfigWithKey(string $configKey, LoggerInterface $logger): void
    {
        try {
            $this->configsRead[$configKey] = ServerSdkPayloadParser::configDefinedBy(
                $this->wireConfigsNotAllReadYet[$configKey],
            );
        } catch (UnreadableDefinition $unreadable) {
            $this->keysOfConfigsThatCannotBeRead[$configKey] = true;
            $logger->log(
                $this->logLevelOfUnreadableDefinitions,
                "[ConfigDirectorClient] Skipping the config '{configKey}', its definition could not be read: {cause}",
                ['configKey' => $configKey, 'cause' => $unreadable->getMessage()],
            );
        }
    }

    /**
     * @return list<string>
     */
    private function configKeys(LoggerInterface $logger): array
    {
        return array_map(strval(...), array_keys($this->configs($logger)));
    }

    /**
     * @return list<string>
     */
    private function idsOfSegmentsDefinedOtherwiseIn(self $otherPayload, LoggerInterface $logger): array
    {
        $segments = $this->segments($logger);
        $otherSegments = $otherPayload->segments($logger);
        $segmentIds = [];
        foreach (array_keys($segments + $otherSegments) as $segmentId) {
            $segment = $segments[$segmentId] ?? null;
            $otherSegment = $otherSegments[$segmentId] ?? null;
            if (serialize($segment) !== serialize($otherSegment)) {
                $segmentIds[] = (string) $segmentId;
            }
        }

        return $segmentIds;
    }
}
