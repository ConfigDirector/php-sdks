<?php

declare(strict_types=1);

namespace ConfigDirector\Testing;

use ConfigDirector\ConfigDirectorValidationException;
use ConfigDirector\Internal\Client\ClientFactory;
use Psr\Log\LoggerInterface;

/**
 * The entry point of the testing tools of the ConfigDirector server SDK.
 *
 * ```
 * $testClient = ConfigDirectorTesting::createTestClient(values: ['new-checkout' => true]);
 * $service = new CheckoutService($testClient->client);
 * $testClient->client->initialize();
 *
 * self::assertTrue($service->isNewCheckoutEnabled('user-123'));
 *
 * $testClient->setValue('new-checkout', false);
 * self::assertFalse($service->isNewCheckoutEnabled('user-123'));
 * ```
 */
final class ConfigDirectorTesting
{
    private function __construct() {}

    /**
     * Creates a {@see TestClient}: the SDK's real client over an in-memory connection that the
     * test controls. It needs no server SDK key, never connects to ConfigDirector, creates no
     * local store and sends nothing.
     *
     * @param array<string, bool|int|float|string|array<array-key, mixed>>|null $values The values to
     *     serve, keyed by config key; see {@see TestClient::setValue()} for how each becomes a
     *     config. Defaults to none.
     * @param int|null $timeout The connection timeout of the client, in milliseconds, which is how
     *     long `initialize()` waits while initialization is held. Defaults to 3,000 milliseconds
     *     (3 seconds), as for a production client.
     * @param LoggerInterface|null $logger A PSR-3 logger. Without one the SDK writes warnings and
     *     errors through `error_log`, as for a production client.
     *
     * @return TestClient A test client whose `client` is not initialized yet.
     *
     * @throws ConfigDirectorValidationException When `$values` holds a blank config key or a
     *     value the SDK cannot serve, as {@see TestClient::setValue()} describes, or when
     *     `$timeout` is zero or less.
     */
    public static function createTestClient(
        ?array $values = null,
        ?int $timeout = null,
        ?LoggerInterface $logger = null,
    ): TestClient {
        return ClientFactory::testClientServing($values, $timeout, $logger);
    }
}
