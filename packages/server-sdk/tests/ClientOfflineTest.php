<?php

declare(strict_types=1);

namespace ConfigDirector\Tests;

use ConfigDirector\ConfigDirector;
use ConfigDirector\ConfigDirectorException;
use ConfigDirector\ConfigDirectorInitializationException;
use ConfigDirector\ConfigEvaluation;
use ConfigDirector\ConfigState;
use ConfigDirector\ConfigsUpdatedEvent;
use ConfigDirector\ConnectionMode;
use ConfigDirector\ConnectionOptions;
use ConfigDirector\Context;
use ConfigDirector\EvaluationReason;
use ConfigDirector\Tests\Support\RecordingLogger;
use ConfigDirector\Tests\Support\ScriptedSdkServer;
use ConfigDirector\Tests\Support\TemporaryFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class ClientOfflineTest extends TestCase
{
    private string $folder;

    private string $workingDirectory;

    private ?ScriptedSdkServer $server = null;

    #[\Override]
    protected function setUp(): void
    {
        $this->folder = TemporaryFolder::create();
        $this->workingDirectory = (string) getcwd();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->server?->stop();
        chdir($this->workingDirectory);
        TemporaryFolder::remove($this->folder);
    }

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

    public function testAnOfflineClientServesEachInlineValue(): void
    {
        $client = ConfigDirector::createClient(
            connection: new ConnectionOptions(
                mode: ConnectionMode::Offline,
                values: [
                    'new-checkout' => true,
                    'max-items' => 20,
                    'discount-rate' => 0.15,
                    'greeting' => 'hello',
                    'theme' => ['color' => 'blue', 'sizes' => [1, 2]],
                ],
            ),
            logger: new RecordingLogger(),
        );

        $client->initialize();

        self::assertTrue($client->getValue('new-checkout', false));
        self::assertSame(20, $client->getValue('max-items', 0));
        self::assertSame(0.15, $client->getValue('discount-rate', 0.0));
        self::assertSame('hello', $client->getValue('greeting', ''));
        self::assertSame(['color' => 'blue', 'sizes' => [1, 2]], $client->getValue('theme', []));
    }

    public function testAnOfflineValueIsAFoundMatchWithoutAValueIdOrAVariation(): void
    {
        $client = ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, values: ['new-checkout' => true]),
            logger: new RecordingLogger(),
        );
        $context = new Context(id: 'user-123');

        $client->initialize();

        self::assertEquals(
            new ConfigEvaluation('new-checkout', true, null, null, false, EvaluationReason::FoundMatch, $context),
            $client->evaluate('new-checkout', false, $context),
        );
    }

    public function testEveryContextReceivesTheSameOfflineValue(): void
    {
        $client = ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, values: ['max-items' => 20]),
            logger: new RecordingLogger(),
        );

        $client->initialize();

        self::assertSame(20, $client->getValue('max-items', 0));
        self::assertSame(20, $client->getValue('max-items', 0, new Context(id: 'user-123')));
        self::assertSame(
            20,
            $client->getValue('max-items', 0, new Context(id: 'org-9', kind: 'organization', traits: ['plan' => 'pro'])),
        );
    }

    public function testAKeyWithoutAnOfflineValueReturnsTheInCodeDefaultValueWithConfigStateMissing(): void
    {
        $client = ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, values: ['new-checkout' => true]),
            logger: new RecordingLogger(),
        );

        $client->initialize();

        self::assertEquals(
            new ConfigEvaluation('page-size', 10, null, null, true, EvaluationReason::ConfigStateMissing, null),
            $client->evaluate('page-size', 10),
        );
    }

    public function testAnOfflineValueReadAsAnotherTypeReturnsTheInCodeDefaultValueWithTypeMismatch(): void
    {
        $client = ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, values: ['new-checkout' => true]),
            logger: new RecordingLogger(),
        );

        $client->initialize();

        self::assertEquals(
            new ConfigEvaluation('new-checkout', 'fallback', null, null, true, EvaluationReason::TypeMismatch, null),
            $client->evaluate('new-checkout', 'fallback'),
        );
    }

    public function testGetAllConfigsReturnsTheStateOfEachOfflineValue(): void
    {
        $client = ConfigDirector::createClient(
            connection: new ConnectionOptions(
                mode: ConnectionMode::Offline,
                values: ['new-checkout' => true, 'max-items' => 20, 'greeting' => 'hello'],
            ),
            logger: new RecordingLogger(),
        );

        $client->initialize();

        self::assertEquals(
            [
                'new-checkout' => new ConfigState('offline:new-checkout', 'new-checkout', 'boolean', 'true'),
                'max-items' => new ConfigState('offline:max-items', 'max-items', 'integer', '20'),
                'greeting' => new ConfigState('offline:greeting', 'greeting', 'string', 'hello'),
            ],
            $client->getAllConfigs(new Context(id: 'user-123')),
        );
    }

    public function testAnOfflineFloatReadsBackAsTheSameFloat(): void
    {
        $client = ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, values: ['discount-rate' => 0.1 + 0.2]),
            logger: new RecordingLogger(),
        );

        $client->initialize();

        self::assertSame(0.30000000000000004, $client->getValue('discount-rate', 0.0));
    }

    public function testAWholeFloatIsAFloatConfigServedWithoutAFraction(): void
    {
        $client = ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, values: ['max-items' => 25.0]),
            logger: new RecordingLogger(),
        );

        $client->initialize();

        self::assertEquals(
            ['max-items' => new ConfigState('offline:max-items', 'max-items', 'float', '25')],
            $client->getAllConfigs(),
        );
    }

    public function testAWholeFloatEndingInZerosIsServedWithItsZeros(): void
    {
        $client = ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, values: ['max-items' => 2000.0]),
            logger: new RecordingLogger(),
        );

        $client->initialize();

        self::assertEquals(
            ['max-items' => new ConfigState('offline:max-items', 'max-items', 'float', '2000')],
            $client->getAllConfigs(),
        );
    }

    public function testAFloatBelowOneIsServedWithALeadingZero(): void
    {
        $client = ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, values: ['discount-rate' => 0.15]),
            logger: new RecordingLogger(),
        );

        $client->initialize();

        self::assertEquals(
            ['discount-rate' => new ConfigState('offline:discount-rate', 'discount-rate', 'float', '0.15')],
            $client->getAllConfigs(),
        );
    }

    public function testAFloatOfZeroIsServedAsZero(): void
    {
        $client = ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, values: ['discount-rate' => 0.0]),
            logger: new RecordingLogger(),
        );

        $client->initialize();

        self::assertEquals(
            ['discount-rate' => new ConfigState('offline:discount-rate', 'discount-rate', 'float', '0')],
            $client->getAllConfigs(),
        );
    }

    public function testASmallFloatIsServedAsPlainDecimalText(): void
    {
        $client = ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, values: ['tolerance' => 1.0E-7]),
            logger: new RecordingLogger(),
        );

        $client->initialize();

        self::assertEquals(
            ['tolerance' => new ConfigState('offline:tolerance', 'tolerance', 'float', '0.0000001')],
            $client->getAllConfigs(),
        );
    }

    public function testALargeFloatIsServedAsPlainDecimalText(): void
    {
        $client = ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, values: ['distance' => -1.5E+25]),
            logger: new RecordingLogger(),
        );

        $client->initialize();

        self::assertEquals(
            ['distance' => new ConfigState('offline:distance', 'distance', 'float', '-15000000000000000000000000')],
            $client->getAllConfigs(),
        );
    }

    public function testAFloatWithAFractionIsServedAsPlainDecimalText(): void
    {
        $client = ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, values: ['discount-rate' => 123.456]),
            logger: new RecordingLogger(),
        );

        $client->initialize();

        self::assertEquals(
            ['discount-rate' => new ConfigState('offline:discount-rate', 'discount-rate', 'float', '123.456')],
            $client->getAllConfigs(),
        );
    }

    public function testAnArrayIsAJsonConfigServedAsCompactJsonWithoutEscapedSlashesOrLetters(): void
    {
        $client = ConfigDirector::createClient(
            connection: new ConnectionOptions(
                mode: ConnectionMode::Offline,
                values: ['theme' => ['link' => 'https://example.com/café', 'sizes' => [1, 2]]],
            ),
            logger: new RecordingLogger(),
        );

        $client->initialize();

        self::assertEquals(
            [
                'theme' => new ConfigState(
                    'offline:theme',
                    'theme',
                    'json',
                    '{"link":"https://example.com/café","sizes":[1,2]}',
                ),
            ],
            $client->getAllConfigs(),
        );
    }

    public function testAWholeFloatInsideAnArrayReadsBackAsAFloat(): void
    {
        $client = ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, values: ['theme' => ['ratio' => 2.0]]),
            logger: new RecordingLogger(),
        );

        $client->initialize();

        self::assertSame(['ratio' => 2.0], $client->getValue('theme', []));
    }

    public function testANullInsideAnArrayIsServed(): void
    {
        $client = ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, values: ['theme' => ['accent' => null]]),
            logger: new RecordingLogger(),
        );

        $client->initialize();

        self::assertSame(['accent' => null], $client->getValue('theme', []));
    }

    public function testAStdClassInsideAnArrayIsServedAsAJsonObject(): void
    {
        $client = ConfigDirector::createClient(
            connection: new ConnectionOptions(
                mode: ConnectionMode::Offline,
                values: ['theme' => ['layout' => new \stdClass(), 'colors' => (object) ['accent' => 'blue']]],
            ),
            logger: new RecordingLogger(),
        );

        $client->initialize();

        self::assertEquals(
            ['theme' => new ConfigState('offline:theme', 'theme', 'json', '{"layout":{},"colors":{"accent":"blue"}}')],
            $client->getAllConfigs(),
        );
    }

    public function testAnOfflineClientServesTheValuesOfAJsonFile(): void
    {
        $file = $this->fileHolding(
            '{"new-checkout": true, "max-items": 20, "discount-rate": 0.15, "greeting": "hello", '
            . '"theme": {"color": "blue", "sizes": [1, 2]}}',
        );
        $client = ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, file: $file),
            logger: new RecordingLogger(),
        );

        $client->initialize();

        self::assertTrue($client->getValue('new-checkout', false));
        self::assertSame(20, $client->getValue('max-items', 0));
        self::assertSame(0.15, $client->getValue('discount-rate', 0.0));
        self::assertSame('hello', $client->getValue('greeting', ''));
        self::assertSame(['color' => 'blue', 'sizes' => [1, 2]], $client->getValue('theme', []));
    }

    public function testARelativeFilePathIsReadFromTheWorkingDirectory(): void
    {
        $file = $this->fileHolding('{"new-checkout": true}');
        chdir(dirname($file));
        $client = ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, file: basename($file)),
            logger: new RecordingLogger(),
        );

        $client->initialize();

        self::assertTrue($client->getValue('new-checkout', false));
    }

    public function testAJsonObjectInTheFileIsServedAsAJsonObject(): void
    {
        $file = $this->fileHolding('{"theme": {}, "layout": {"columns": {}, "rows": []}}');
        $client = ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, file: $file),
            logger: new RecordingLogger(),
        );

        $client->initialize();

        self::assertEquals(
            [
                'theme' => new ConfigState('offline:theme', 'theme', 'json', '{}'),
                'layout' => new ConfigState('offline:layout', 'layout', 'json', '{"columns":{},"rows":[]}'),
            ],
            $client->getAllConfigs(),
        );
    }

    public function testInitializingAgainReadsTheFileAgain(): void
    {
        $file = $this->fileHolding('{"max-items": 20}');
        $client = ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, file: $file),
            logger: new RecordingLogger(),
        );
        $client->initialize();
        file_put_contents($file, '{"max-items": 30}');

        $client->initialize();

        self::assertSame(30, $client->getValue('max-items', 0));
    }

    public function testInitializingAgainNamesOnlyTheConfigsWhoseValueChangedInConfigsUpdated(): void
    {
        $file = $this->fileHolding('{"new-checkout": true, "max-items": 20, "greeting": "hello"}');
        $client = ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, file: $file),
            logger: new RecordingLogger(),
        );
        $client->initialize();
        file_put_contents($file, '{"new-checkout": true, "max-items": 30}');
        $updates = [];
        $client->on('configsUpdated', function (ConfigsUpdatedEvent $event) use (&$updates): void {
            $updates[] = [$event->keys, $event->removedKeys];
        });

        $client->initialize();

        self::assertSame([[['max-items'], ['greeting']]], $updates);
    }

    public function testALaterInitializeThatCannotReadTheFileKeepsTheValuesReadBefore(): void
    {
        $file = $this->fileHolding('{"max-items": 20}');
        $client = ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, file: $file),
            logger: new RecordingLogger(),
        );
        $client->initialize();
        unlink($file);

        try {
            $client->initialize();
            self::fail('initialize() read a file that is gone.');
        } catch (ConfigDirectorInitializationException) {
        }

        self::assertTrue($client->isReady());
        self::assertSame(20, $client->getValue('max-items', 0));
    }

    public function testAMissingFileThrowsFromInitialize(): void
    {
        $client = ConfigDirector::createClient(
            connection: new ConnectionOptions(
                mode: ConnectionMode::Offline,
                file: $this->pathOfAMissingFile(),
            ),
            logger: new RecordingLogger(),
        );

        $this->expectException(ConfigDirectorInitializationException::class);

        $client->initialize();
    }

    public function testAnInitializationExceptionIsAConfigDirectorException(): void
    {
        $client = ConfigDirector::createClient(
            connection: new ConnectionOptions(
                mode: ConnectionMode::Offline,
                file: $this->pathOfAMissingFile(),
            ),
            logger: new RecordingLogger(),
        );

        $this->expectException(ConfigDirectorException::class);

        $client->initialize();
    }

    public function testAClientWhoseFileIsMissingStaysNotReady(): void
    {
        $client = ConfigDirector::createClient(
            connection: new ConnectionOptions(
                mode: ConnectionMode::Offline,
                file: $this->pathOfAMissingFile(),
            ),
            logger: new RecordingLogger(),
        );

        try {
            $client->initialize();
            self::fail('initialize() read a file that does not exist.');
        } catch (ConfigDirectorInitializationException) {
        }

        self::assertFalse($client->isReady());
        self::assertEquals(
            new ConfigEvaluation('max-items', 10, null, null, true, EvaluationReason::ClientNotReady, null),
            $client->evaluate('max-items', 10),
        );
    }

    public function testAFolderGivenAsTheFileThrowsFromInitialize(): void
    {
        $client = ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, file: $this->folder),
            logger: new RecordingLogger(),
        );

        $this->expectException(ConfigDirectorInitializationException::class);

        $client->initialize();
    }

    public function testAFileThatIsNotJsonThrowsFromInitialize(): void
    {
        $client = ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, file: $this->fileHolding('{ not json')),
            logger: new RecordingLogger(),
        );

        $this->expectException(ConfigDirectorInitializationException::class);

        $client->initialize();
    }

    public function testAFileHoldingAJsonArrayThrowsFromInitialize(): void
    {
        $client = ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, file: $this->fileHolding('[true, 20]')),
            logger: new RecordingLogger(),
        );

        $this->expectException(ConfigDirectorInitializationException::class);

        $client->initialize();
    }

    public function testAFileHoldingANullValueThrowsFromInitialize(): void
    {
        $client = ConfigDirector::createClient(
            connection: new ConnectionOptions(
                mode: ConnectionMode::Offline,
                file: $this->fileHolding('{"new-checkout": null}'),
            ),
            logger: new RecordingLogger(),
        );

        $this->expectException(ConfigDirectorInitializationException::class);

        $client->initialize();
    }

    public function testAUrlGivenAsTheFileThrowsFromInitializeWithoutARequest(): void
    {
        $this->server = ScriptedSdkServer::answering([['status' => 200, 'body' => ['new-checkout' => true]]]);
        $client = ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, file: "{$this->server->url}/flags.json"),
            logger: new RecordingLogger(),
        );

        try {
            $client->initialize();
            self::fail('initialize() read values from a URL.');
        } catch (ConfigDirectorInitializationException) {
        }

        self::assertSame([], $this->server->requestsReceived());
    }

    public function testAStreamWrapperAroundAUrlGivenAsTheFileThrowsFromInitializeWithoutARequest(): void
    {
        $this->server = ScriptedSdkServer::answering([['status' => 200, 'body' => ['new-checkout' => true]]]);
        $client = ConfigDirector::createClient(
            connection: new ConnectionOptions(
                mode: ConnectionMode::Offline,
                file: "php://filter/resource={$this->server->url}/flags.json",
            ),
            logger: new RecordingLogger(),
        );

        try {
            $client->initialize();
            self::fail('initialize() read values from a URL.');
        } catch (ConfigDirectorInitializationException) {
        }

        self::assertSame([], $this->server->requestsReceived());
    }

    public function testAnOfflineClientCreatesNoLocalStoreAndSendsNoRequest(): void
    {
        $this->server = ScriptedSdkServer::answering([]);
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(
                mode: ConnectionMode::Offline,
                url: $this->server->url,
                values: ['new-checkout' => true],
                storagePath: $this->folder,
            ),
            logger: new RecordingLogger(),
        );

        $client->initialize();
        $client->getValue('new-checkout', false);
        $client->getAllConfigs();
        $client->dispose();

        self::assertSame([], TemporaryFolder::entriesOf($this->folder));
        self::assertSame([], $this->server->requestsReceived());
    }

    public function testAnOfflineClientLogsAContextWithAnInvalidKindOnEveryRead(): void
    {
        $logger = new RecordingLogger();
        $client = ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, values: ['max-items' => 20]),
            logger: $logger,
        );
        $client->initialize();

        $client->getValue('max-items', 0, new Context(id: 'device-1', kind: 'Device'));
        $client->getValue('max-items', 0, new Context(id: 'device-2', kind: 'Device'));

        self::assertSame(
            [['kindSample' => 'Device'], ['kindSample' => 'Device']],
            $logger->contextsLoggedAt(LogLevel::ERROR),
        );
    }

    public function testInitializeFiresConfigsUpdatedNamingEveryKeyThenTheWatchesThenClientReady(): void
    {
        $deliveries = [];
        $client = ConfigDirector::createClient(
            connection: new ConnectionOptions(
                mode: ConnectionMode::Offline,
                values: ['new-checkout' => true, 'max-items' => 20],
            ),
            logger: new RecordingLogger(),
        );
        $client->on('configsUpdated', function (ConfigsUpdatedEvent $event) use (&$deliveries): void {
            $deliveries[] = ['configsUpdated', $event->keys, $event->removedKeys];
        });
        $client->watch('max-items', 0, function (int $value) use (&$deliveries): void {
            $deliveries[] = ['watch', $value];
        });
        $client->on('clientReady', function () use (&$deliveries): void {
            $deliveries[] = ['clientReady'];
        });

        $client->initialize();

        self::assertSame(
            [
                ['configsUpdated', ['new-checkout', 'max-items'], []],
                ['watch', 20],
                ['clientReady'],
            ],
            $deliveries,
        );
    }

    public function testAWatchIsCalledOnceWithTheOfflineValueAndNeverAgain(): void
    {
        $watchedValues = [];
        $file = $this->fileHolding('{"new-checkout": true}');
        $client = ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, file: $file),
            logger: new RecordingLogger(),
        );
        $client->watch('new-checkout', false, function (bool $value) use (&$watchedValues): void {
            $watchedValues[] = $value;
        });
        $client->initialize();
        file_put_contents($file, '{"new-checkout": false}');

        $client->getValue('new-checkout', false);
        $client->getAllConfigs();
        $client->isReady();

        self::assertSame([true], $watchedValues);
    }

    private function pathOfAMissingFile(): string
    {
        return $this->folder . DIRECTORY_SEPARATOR . 'missing.json';
    }

    private function fileHolding(string $json): string
    {
        $file = $this->folder . DIRECTORY_SEPARATOR . 'flags.json';
        file_put_contents($file, $json);

        return $file;
    }
}
