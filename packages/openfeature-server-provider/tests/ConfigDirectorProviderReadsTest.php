<?php

declare(strict_types=1);

namespace ConfigDirector\OpenFeature\Tests;

use ConfigDirector\ConnectionOptions;
use ConfigDirector\OpenFeature\ConfigDirectorProvider;
use ConfigDirector\OpenFeature\Tests\Support\ErrorOfTheEvaluation;
use ConfigDirector\OpenFeature\Tests\Support\ScriptedSdkServer;
use ConfigDirector\OpenFeature\Tests\Support\TemporaryFolder;
use OpenFeature\interfaces\flags\Client;
use OpenFeature\interfaces\provider\Reason;
use OpenFeature\isolated\OpenFeatureAPIFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @phpstan-type WireConfig array{
 *     id: string,
 *     key: string,
 *     type: string,
 *     typeConstraints: null,
 *     variations: list<array<string, string>>,
 *     target: array<string, mixed>,
 * }
 */
final class ConfigDirectorProviderReadsTest extends TestCase
{
    private static ?ScriptedSdkServer $sdkServer = null;

    private static string $storagePath = '';

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::$sdkServer = ScriptedSdkServer::answeringEveryRequestWith(200, self::serverSdkPayload());
        self::$storagePath = TemporaryFolder::create();
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        self::$sdkServer?->stop();
        self::$sdkServer = null;
        TemporaryFolder::remove(self::$storagePath);
    }

    public function testABooleanFlagResolvesToItsValueWithATargetingMatchAndItsValueIdAsTheVariant(): void
    {
        $client = self::openFeatureClient();

        $details = $client->getBooleanDetails('new-checkout', false);

        self::assertTrue($details->getValue());
        self::assertSame(Reason::TARGETING_MATCH, $details->getReason());
        self::assertSame('value-new-checkout-on', $details->getVariant());
        self::assertNull($details->getError());
    }

    public function testAStringFlagResolvesToItsValueWithATargetingMatchAndItsValueIdAsTheVariant(): void
    {
        $client = self::openFeatureClient();

        $details = $client->getStringDetails('checkout-banner', 'none');

        self::assertSame('upgrade-offer', $details->getValue());
        self::assertSame(Reason::TARGETING_MATCH, $details->getReason());
        self::assertSame('value-checkout-banner', $details->getVariant());
        self::assertNull($details->getError());
    }

    public function testAnIntegerFlagResolvesToItsValueWithATargetingMatchAndItsValueIdAsTheVariant(): void
    {
        $client = self::openFeatureClient();

        $details = $client->getIntegerDetails('page-size', 10);

        self::assertSame(25, $details->getValue());
        self::assertSame(Reason::TARGETING_MATCH, $details->getReason());
        self::assertSame('value-page-size', $details->getVariant());
        self::assertNull($details->getError());
    }

    public function testAFloatFlagResolvesToItsValueWithATargetingMatchAndItsValueIdAsTheVariant(): void
    {
        $client = self::openFeatureClient();

        $details = $client->getFloatDetails('discount-rate', 0.0);

        self::assertSame(0.15, $details->getValue());
        self::assertSame(Reason::TARGETING_MATCH, $details->getReason());
        self::assertSame('value-discount-rate', $details->getVariant());
        self::assertNull($details->getError());
    }

    public function testAnObjectFlagResolvesToItsValueWithATargetingMatchAndItsValueIdAsTheVariant(): void
    {
        $client = self::openFeatureClient();

        $details = $client->getObjectDetails('checkout-layout', []);

        self::assertSame(['columns' => 2, 'showSummary' => true], $details->getValue());
        self::assertSame(Reason::TARGETING_MATCH, $details->getReason());
        self::assertSame('value-checkout-layout', $details->getVariant());
        self::assertNull($details->getError());
    }

    public function testAFlagThatDoesNotExistResolvesToTheInCodeDefaultValueWithFlagNotFound(): void
    {
        $client = self::openFeatureClient();

        $details = $client->getBooleanDetails('flag-that-does-not-exist', true);

        self::assertTrue($details->getValue());
        self::assertSame(Reason::ERROR, $details->getReason());
        self::assertNull($details->getVariant());
        self::assertSame('FLAG_NOT_FOUND', ErrorOfTheEvaluation::codeIn($details));
        self::assertStringContainsString("'flag-that-does-not-exist'", ErrorOfTheEvaluation::messageIn($details));
    }

    public function testAStringReadOfABooleanFlagResolvesToTheInCodeDefaultValueWithTypeMismatch(): void
    {
        $client = self::openFeatureClient();

        $details = $client->getStringDetails('new-checkout', 'off');

        self::assertSame('off', $details->getValue());
        self::assertSame(Reason::ERROR, $details->getReason());
        self::assertNull($details->getVariant());
        self::assertSame('TYPE_MISMATCH', ErrorOfTheEvaluation::codeIn($details));
        self::assertStringContainsString("'new-checkout'", ErrorOfTheEvaluation::messageIn($details));
    }

    public function testAnIntegerReadOfTextThatIsNotANumberResolvesToTheInCodeDefaultValueWithTypeMismatch(): void
    {
        $client = self::openFeatureClient();

        $details = $client->getIntegerDetails('checkout-banner', 10);

        self::assertSame(10, $details->getValue());
        self::assertSame(Reason::ERROR, $details->getReason());
        self::assertNull($details->getVariant());
        self::assertSame('TYPE_MISMATCH', ErrorOfTheEvaluation::codeIn($details));
        self::assertStringContainsString("'checkout-banner'", ErrorOfTheEvaluation::messageIn($details));
    }

    public function testAnObjectReadOfANumberResolvesToTheInCodeDefaultValueWithTypeMismatch(): void
    {
        $client = self::openFeatureClient();

        $details = $client->getObjectDetails('page-size', ['columns' => 1]);

        self::assertSame(['columns' => 1], $details->getValue());
        self::assertSame(Reason::ERROR, $details->getReason());
        self::assertNull($details->getVariant());
        self::assertSame('TYPE_MISMATCH', ErrorOfTheEvaluation::codeIn($details));
        self::assertStringContainsString("'page-size'", ErrorOfTheEvaluation::messageIn($details));
    }

    public function testABooleanReadOfTextThatIsNotABooleanResolvesToTheInCodeDefaultValueWithTypeMismatch(): void
    {
        $client = self::openFeatureClient();

        $details = $client->getBooleanDetails('checkout-banner', true);

        self::assertTrue($details->getValue());
        self::assertSame(Reason::ERROR, $details->getReason());
        self::assertNull($details->getVariant());
        self::assertSame('TYPE_MISMATCH', ErrorOfTheEvaluation::codeIn($details));
        self::assertStringContainsString("'checkout-banner'", ErrorOfTheEvaluation::messageIn($details));
    }

    public function testAFlagServedByANamedVariationHasTheNameOfTheVariationAsTheVariant(): void
    {
        $client = self::openFeatureClient();

        $details = $client->getStringDetails('search-engine', 'none');

        self::assertSame('vector', $details->getValue());
        self::assertSame(Reason::TARGETING_MATCH, $details->getReason());
        self::assertSame('Vector', $details->getVariant());
        self::assertNull($details->getError());
    }

    public function testAFlagServedByAVariationWithoutANameHasItsValueIdAsTheVariant(): void
    {
        $client = self::openFeatureClient();

        $details = $client->getStringDetails('theme', 'light');

        self::assertSame('dark', $details->getValue());
        self::assertSame(Reason::TARGETING_MATCH, $details->getReason());
        self::assertSame('value-dark', $details->getVariant());
        self::assertNull($details->getError());
    }

    public function testARolloutSkippedForAContextWithoutATargetingKeyResolvesWithTheReasonDefaultAndItsVariant(): void
    {
        $client = self::openFeatureClient();

        $details = $client->getStringDetails('new-onboarding', 'none');

        self::assertSame('classic', $details->getValue());
        self::assertSame(Reason::DEFAULT, $details->getReason());
        self::assertSame('Classic', $details->getVariant());
        self::assertNull($details->getError());
    }

    public function testAFlagWithoutAValueResolvesToTheInCodeDefaultValueWithTheReasonDefaultAndNoError(): void
    {
        $client = self::openFeatureClient();

        $details = $client->getStringDetails('config-without-a-value', 'fallback');

        self::assertSame('fallback', $details->getValue());
        self::assertSame(Reason::DEFAULT, $details->getReason());
        self::assertNull($details->getVariant());
        self::assertNull($details->getError());
    }

    private static function openFeatureClient(): Client
    {
        if (self::$sdkServer === null) {
            throw new \LogicException('The scripted SDK server is not running.');
        }

        $api = OpenFeatureAPIFactory::createAPI();
        $api->setProvider(new ConfigDirectorProvider(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(url: self::$sdkServer->url, storagePath: self::$storagePath),
            logger: new NullLogger(),
        ));

        return $api->getClient(null, null);
    }

    /**
     * @return array<string, mixed>
     */
    private static function serverSdkPayload(): array
    {
        return [
            'payloadVersion' => 1,
            'environmentId' => '3f1b6c0a-52d4-4e8b-9a7c-0d2e4f6a8b10',
            'projectId' => '9c4d2e7f-1a3b-4c5d-8e6f-7a8b9c0d1e20',
            'configs' => [
                'new-checkout' => self::configServing('new-checkout', 'boolean', 'true', 'value-new-checkout-on'),
                'checkout-banner' => self::configServing(
                    'checkout-banner',
                    'string',
                    'upgrade-offer',
                    'value-checkout-banner',
                ),
                'page-size' => self::configServing('page-size', 'integer', '25', 'value-page-size'),
                'discount-rate' => self::configServing('discount-rate', 'float', '0.15', 'value-discount-rate'),
                'checkout-layout' => self::configServing(
                    'checkout-layout',
                    'json',
                    '{"columns":2,"showSummary":true}',
                    'value-checkout-layout',
                ),
                'search-engine' => self::configServingItsVariation(
                    'search-engine',
                    ['id' => 'variation-vector', 'name' => 'Vector', 'value' => 'vector'],
                    'value-vector',
                ),
                'theme' => self::configServingItsVariation(
                    'theme',
                    ['id' => 'variation-dark', 'value' => 'dark'],
                    'value-dark',
                ),
                'new-onboarding' => self::configWithARolloutAndClassicAsItsDefaultTargetingRuleValue(),
                'config-without-a-value' => self::configServing('config-without-a-value', 'string', '', ''),
            ],
            'segments' => new \stdClass(),
            'kind' => 'full',
            'timestamp' => '2026-11-15T12:00:00.000000Z',
        ];
    }

    /**
     * @return WireConfig
     */
    private static function configServing(
        string $configKey,
        string $type,
        string $defaultTargetingRuleValue,
        string $valueId,
    ): array {
        return [
            'id' => "id-of-{$configKey}",
            'key' => $configKey,
            'type' => $type,
            'typeConstraints' => null,
            'variations' => [],
            'target' => [
                'environmentId' => '3f1b6c0a-52d4-4e8b-9a7c-0d2e4f6a8b10',
                'rules' => [],
                'defaultValue' => $defaultTargetingRuleValue,
                'defaultValueId' => $valueId,
            ],
        ];
    }

    /**
     * @param array<string, string> $variation
     *
     * @return WireConfig
     */
    private static function configServingItsVariation(string $configKey, array $variation, string $valueId): array
    {
        $config = self::configServing($configKey, 'string', $variation['value'], $valueId);
        $config['variations'] = [$variation];
        $config['target']['defaultVariationId'] = $variation['id'];

        return $config;
    }

    /**
     * @return WireConfig
     */
    private static function configWithARolloutAndClassicAsItsDefaultTargetingRuleValue(): array
    {
        $config = self::configServingItsVariation(
            'new-onboarding',
            ['id' => 'variation-classic', 'name' => 'Classic', 'value' => 'classic'],
            'value-classic',
        );
        $config['variations'][] = ['id' => 'variation-guided', 'name' => 'Guided', 'value' => 'guided'];
        $config['target']['rules'] = [
            [
                'id' => 'rule-rollout',
                'order' => 0,
                'type' => 'percentage',
                'target' => 'percentage',
                'percentages' => [
                    [
                        'id' => 'share-guided',
                        'percentage' => 100,
                        'value' => 'guided',
                        'valueId' => 'value-guided',
                        'variationId' => 'variation-guided',
                    ],
                ],
                'value' => null,
                'valueId' => null,
                'conditions' => null,
            ],
        ];

        return $config;
    }
}
