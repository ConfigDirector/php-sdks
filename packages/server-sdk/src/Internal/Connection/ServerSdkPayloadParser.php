<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Connection;

use ConfigDirector\Internal\Evaluation\AttributeCondition;
use ConfigDirector\Internal\Evaluation\Condition;
use ConfigDirector\Internal\Evaluation\Rule;
use ConfigDirector\Internal\Evaluation\ScalarText;
use ConfigDirector\Internal\Evaluation\Segment;
use ConfigDirector\Internal\Evaluation\SegmentCondition;
use ConfigDirector\Internal\Evaluation\Share;
use ConfigDirector\Internal\Evaluation\TargetingRules;
use ConfigDirector\Variation;

final class ServerSdkPayloadParser
{
    private function __construct() {}

    /**
     * @param array<array-key, mixed> $decodedPayload
     */
    public static function payloadIn(array $decodedPayload): ServerSdkPayload
    {
        $configs = [];
        $causesByUnreadableConfigKey = [];
        foreach (self::entriesIn($decodedPayload, 'configs') as $configKey => $wireConfig) {
            try {
                $configs[(string) $configKey] = self::configDefinedBy($wireConfig);
            } catch (UnreadableDefinition $unreadable) {
                $causesByUnreadableConfigKey[(string) $configKey] = $unreadable->getMessage();
            }
        }

        $segments = [];
        $causesByUnreadableSegmentId = [];
        foreach (self::entriesIn($decodedPayload, 'segments') as $segmentId => $wireSegment) {
            try {
                $segments[(string) $segmentId] = self::segmentDefinedBy($wireSegment);
            } catch (UnreadableDefinition $unreadable) {
                $causesByUnreadableSegmentId[(string) $segmentId] = $unreadable->getMessage();
            }
        }

        return new ServerSdkPayload($configs, $segments, $causesByUnreadableConfigKey, $causesByUnreadableSegmentId);
    }

    private static function configDefinedBy(mixed $wireConfig): ConfigDefinition
    {
        $wireTarget = is_array($wireConfig) ? $wireConfig['target'] ?? null : null;
        if (!is_array($wireConfig) || !is_array($wireTarget)) {
            throw new UnreadableDefinition('the config has no target');
        }

        return new ConfigDefinition(
            self::textRequiredIn($wireConfig, 'id', 'the config'),
            self::textRequiredIn($wireConfig, 'key', 'the config'),
            self::textRequiredIn($wireConfig, 'type', 'the config'),
            new TargetingRules(
                ScalarText::of($wireTarget['defaultValue'] ?? null) ?? '',
                self::textOrNullIn($wireTarget, 'defaultValueId'),
                self::textOrNullIn($wireTarget, 'defaultVariationId'),
                array_map(self::ruleDefinedBy(...), self::listIn($wireTarget, 'rules', 'the config')),
            ),
            self::variationsListedIn($wireConfig),
        );
    }

    /**
     * @param array<array-key, mixed> $wireConfig
     *
     * @return list<Variation>
     */
    private static function variationsListedIn(array $wireConfig): array
    {
        $variations = [];
        foreach (self::listIn($wireConfig, 'variations', 'the config') as $wireVariation) {
            $variationId = is_array($wireVariation) ? self::textOrNullIn($wireVariation, 'id') : null;
            if (is_array($wireVariation) && $variationId !== null) {
                $variations[] = new Variation($variationId, self::textOrNullIn($wireVariation, 'name'));
            }
        }

        return $variations;
    }

    private static function ruleDefinedBy(mixed $wireRule): Rule
    {
        if (!is_array($wireRule)) {
            throw new UnreadableDefinition('a rule is not an object');
        }

        $ruleId = self::textRequiredIn($wireRule, 'id', 'a rule');
        $order = $wireRule['order'] ?? null;
        $hasShares = ($wireRule['percentages'] ?? null) !== null;

        return new Rule(
            $ruleId,
            is_int($order) ? $order : null,
            self::textOrNullIn($wireRule, 'type') ?? '',
            self::textOrNullIn($wireRule, 'target') ?? '',
            ScalarText::of($wireRule['value'] ?? null),
            self::textOrNullIn($wireRule, 'valueId'),
            self::textOrNullIn($wireRule, 'variationId'),
            array_map(self::conditionDefinedBy(...), self::listIn($wireRule, 'conditions', "rule {$ruleId}")),
            $hasShares
                ? array_map(self::shareDefinedBy(...), self::listIn($wireRule, 'percentages', "rule {$ruleId}"))
                : null,
        );
    }

    private static function shareDefinedBy(mixed $wireShare): Share
    {
        $percentage = is_array($wireShare) ? $wireShare['percentage'] ?? null : null;
        if (!is_array($wireShare) || (!is_int($percentage) && !is_float($percentage))) {
            throw new UnreadableDefinition('a rollout share has no percentage');
        }

        return new Share(
            self::textRequiredIn($wireShare, 'id', 'a rollout share'),
            (float) $percentage,
            ScalarText::of($wireShare['value'] ?? null),
            self::textOrNullIn($wireShare, 'valueId'),
            self::textOrNullIn($wireShare, 'variationId'),
        );
    }

    private static function conditionDefinedBy(mixed $wireCondition): Condition
    {
        if (!is_array($wireCondition)) {
            throw new UnreadableDefinition('a condition is not an object');
        }

        $kind = $wireCondition['kind'] ?? 'attribute';

        return match ($kind) {
            'attribute' => self::attributeConditionDefinedBy($wireCondition),
            'segment' => new SegmentCondition(
                self::textRequiredIn($wireCondition, 'operator', 'a segment condition'),
                self::textRequiredIn($wireCondition, 'segmentId', 'a segment condition'),
            ),
            default => throw new UnreadableDefinition(sprintf(
                "a condition has the unknown kind '%s'",
                ScalarText::of($kind) ?? get_debug_type($kind),
            )),
        };
    }

    /**
     * @param array<array-key, mixed> $wireCondition
     */
    private static function attributeConditionDefinedBy(array $wireCondition): AttributeCondition
    {
        return new AttributeCondition(
            self::textRequiredIn($wireCondition, 'attribute', 'a condition'),
            self::textOrNullIn($wireCondition, 'trait'),
            self::textRequiredIn($wireCondition, 'operator', 'a condition'),
            self::textRequiredIn($wireCondition, 'targetType', 'a condition'),
            array_map(
                static fn(mixed $targetValue): string => ScalarText::of($targetValue)
                    ?? throw new UnreadableDefinition('a condition has a target value that is not text'),
                self::listIn($wireCondition, 'targetValues', 'a condition'),
            ),
        );
    }

    private static function segmentDefinedBy(mixed $wireSegment): Segment
    {
        $wireGroups = is_array($wireSegment) ? $wireSegment['groups'] ?? null : null;
        if (!is_array($wireGroups)) {
            throw new UnreadableDefinition('the segment has no groups');
        }

        return new Segment(array_map(self::conditionGroupDefinedBy(...), array_values($wireGroups)));
    }

    /**
     * @return list<AttributeCondition>
     */
    private static function conditionGroupDefinedBy(mixed $wireGroup): array
    {
        if (!is_array($wireGroup)) {
            throw new UnreadableDefinition('a condition group of the segment is not a list');
        }

        $conditions = [];
        foreach ($wireGroup as $wireCondition) {
            $isAnAttributeCondition = is_array($wireCondition)
                && ($wireCondition['kind'] ?? 'attribute') === 'attribute';
            if (!is_array($wireCondition) || !$isAnAttributeCondition) {
                throw new UnreadableDefinition('a condition group of the segment holds attribute conditions only');
            }
            $conditions[] = self::attributeConditionDefinedBy($wireCondition);
        }

        return $conditions;
    }

    /**
     * @param array<array-key, mixed> $wireObject
     *
     * @return array<array-key, mixed>
     */
    private static function entriesIn(array $wireObject, string $field): array
    {
        $entries = $wireObject[$field] ?? null;

        return is_array($entries) ? $entries : [];
    }

    /**
     * @param array<array-key, mixed> $wireObject
     *
     * @return list<mixed>
     */
    private static function listIn(array $wireObject, string $field, string $owner): array
    {
        $list = $wireObject[$field] ?? [];
        if (!is_array($list)) {
            throw new UnreadableDefinition("{$owner} has '{$field}' that is not a list");
        }

        return array_values($list);
    }

    /**
     * @param array<array-key, mixed> $wireObject
     */
    private static function textRequiredIn(array $wireObject, string $field, string $owner): string
    {
        $text = $wireObject[$field] ?? null;
        if (!is_string($text) || $text === '') {
            throw new UnreadableDefinition("{$owner} has no {$field}");
        }

        return $text;
    }

    /**
     * @param array<array-key, mixed> $wireObject
     */
    private static function textOrNullIn(array $wireObject, string $field): ?string
    {
        $text = $wireObject[$field] ?? null;

        return is_string($text) ? $text : null;
    }
}
