<?php

declare(strict_types=1);

namespace ConfigDirector\Tests;

use ConfigDirector\ConfigDirector;
use ConfigDirector\ConfigEvaluation;
use ConfigDirector\ConnectionMode;
use ConfigDirector\ConnectionOptions;
use ConfigDirector\EvaluationReason;
use ConfigDirector\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;

final class ClientOfflineTest extends TestCase
{
    public function testAnOfflineClientWithoutValuesIsReadyAndReturnsInCodeDefaultValues(): void
    {
        $client = ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline),
            logger: new RecordingLogger(),
        );

        $client->initialize();

        self::assertTrue($client->isReady());
        self::assertEquals(
            new ConfigEvaluation('page-size', 10, null, null, true, EvaluationReason::ConfigStateMissing, null),
            $client->evaluate('page-size', 10),
        );
    }
}
