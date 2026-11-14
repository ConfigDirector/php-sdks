<?php

declare(strict_types=1);

namespace Tests\Feature;

use ConfigDirector\ConfigDirectorConnectionException;
use Illuminate\Foundation\Testing\TestCase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\Support\PhpProcess;
use Tests\Support\ScriptedStreamServer;
use Tests\Support\TemporaryFolder;

final class StreamCommandTest extends TestCase
{
    private const string SERVER_SDK_KEY = 'server-sdk-key-of-the-sample';

    private const array THE_FIVE_CONFIGS = [
        'temporary-feature-flag' => [
            'id' => 'config-temporary-feature-flag',
            'key' => 'temporary-feature-flag',
            'type' => 'boolean',
            'target' => ['rules' => [], 'defaultValue' => 'false', 'defaultValueId' => 'value-flag-off'],
        ],
        'permanent-kill-switch' => [
            'id' => 'config-permanent-kill-switch',
            'key' => 'permanent-kill-switch',
            'type' => 'boolean',
            'target' => ['rules' => [], 'defaultValue' => 'true', 'defaultValueId' => 'value-kill-switch-on'],
        ],
        'integer-config' => self::INTEGER_CONFIG_OF_42,
        'day-of-the-week-config' => [
            'id' => 'config-day-of-the-week',
            'key' => 'day-of-the-week-config',
            'type' => 'string',
            'target' => [
                'rules' => [[
                    'id' => 'rule-pro-plan',
                    'order' => 0,
                    'type' => 'conditional',
                    'target' => 'value',
                    'percentages' => null,
                    'value' => 'Tuesday',
                    'valueId' => 'value-tuesday',
                    'conditions' => [[
                        'kind' => 'attribute',
                        'id' => 'condition-pro-plan',
                        'attribute' => 'traits',
                        'trait' => '/plan',
                        'operator' => 'equals',
                        'targetType' => 'text',
                        'targetValues' => ['pro'],
                    ]],
                ]],
                'defaultValue' => 'Monday',
                'defaultValueId' => 'value-monday',
            ],
        ],
        'json-value-config' => [
            'id' => 'config-json-value',
            'key' => 'json-value-config',
            'type' => 'json',
            'target' => ['rules' => [], 'defaultValue' => '{"greeting":"hello"}', 'defaultValueId' => 'value-hello'],
        ],
    ];

    private const array INTEGER_CONFIG_OF_42 = [
        'id' => 'config-integer',
        'key' => 'integer-config',
        'type' => 'integer',
        'target' => ['rules' => [], 'defaultValue' => '42', 'defaultValueId' => 'value-42'],
    ];

    private const array INTEGER_CONFIG_OF_43 = [
        'id' => 'config-integer',
        'key' => 'integer-config',
        'type' => 'integer',
        'target' => ['rules' => [], 'defaultValue' => '43', 'defaultValueId' => 'value-43'],
    ];

    private string $storagePath;

    private string $fileOfStandardError;

    private ?ScriptedStreamServer $server = null;

    private ?PhpProcess $runningCommand = null;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->storagePath = TemporaryFolder::create();
        $this->fileOfStandardError = $this->storagePath . DIRECTORY_SEPARATOR . 'standard-error.txt';
    }

    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        $this->runningCommand?->stop();
        $this->server?->stop();
        TemporaryFolder::remove($this->storagePath);
    }

    public function testTheCommandFillsTheLocalStoreAndALaterRequestServesAChangeItReceives(): void
    {
        $server = $this->serverStreamingTheFiveConfigs();
        $this->startTheCommandWith(['CONFIGDIRECTOR_SERVER_KEY' => self::SERVER_SDK_KEY]);
        $this->waitUntilTheLocalStoreHoldsAPayload();
        $configsBeforeTheChange = $this->configsServedToANewRequest();

        $server->sendToTheOpenStream(ScriptedStreamServer::eventCarrying(
            ['kind' => 'delta', 'configs' => ['integer-config' => self::INTEGER_CONFIG_OF_43]],
        ));
        $deadline = hrtime(true) + 5_000_000_000;
        do {
            $configsAfterTheChange = $this->configsServedToANewRequest();
        } while ($configsAfterTheChange['integer-config'] !== 43 && hrtime(true) < $deadline);

        self::assertSame(
            [
                'temporary-feature-flag' => false,
                'permanent-kill-switch' => true,
                'integer-config' => 42,
                'day-of-the-week-config' => 'Tuesday',
                'json-value-config' => ['greeting' => 'hello'],
            ],
            $configsBeforeTheChange,
        );
        self::assertSame(43, $configsAfterTheChange['integer-config']);
    }

    public function testTheCommandWritesItsStartLineNamingTheLocalStoreFolderAtTheDefaultLogLevel(): void
    {
        $this->serverStreamingTheFiveConfigs();

        $this->startTheCommandWith(['CONFIGDIRECTOR_SERVER_KEY' => self::SERVER_SDK_KEY]);
        $this->waitUntilTheLocalStoreHoldsAPayload();

        $standardError = (string) file_get_contents($this->fileOfStandardError);
        self::assertStringContainsString($this->localStoreFolder(), $standardError);
        self::assertDoesNotMatchRegularExpression('/\{[a-z]+\}/i', $standardError);
    }

    public function testTheCommandWritesNothingWhileAllIsWellWhenTheLogLevelIsWarning(): void
    {
        $this->serverStreamingTheFiveConfigs();

        $this->startTheCommandWith([
            'CONFIGDIRECTOR_SERVER_KEY' => self::SERVER_SDK_KEY,
            'CONFIGDIRECTOR_LOG_LEVEL' => 'warning',
        ]);
        $this->waitUntilTheLocalStoreHoldsAPayload();

        self::assertSame('', file_get_contents($this->fileOfStandardError));
    }

    public function testTheCommandExitsWithOneAndWritesTheRefusalOnceWhenTheKeyIsRefused(): void
    {
        $this->server = ScriptedStreamServer::streaming([['status' => 401]]);

        $command = $this->commandWith(['CONFIGDIRECTOR_SERVER_KEY' => self::SERVER_SDK_KEY]);
        $exitCode = $command->exitCodeWithinTenSeconds();

        self::assertSame(1, $exitCode);
        self::assertSame(
            1,
            substr_count(
                (string) file_get_contents($this->fileOfStandardError),
                (new \ReflectionClass(ConfigDirectorConnectionException::class))->getShortName(),
            ),
        );
    }

    public function testTheCommandExitsWithOneInOfflineMode(): void
    {
        $this->serverStreamingTheFiveConfigs();

        $exitCode = $this->commandWith([
            'CONFIGDIRECTOR_SERVER_KEY' => self::SERVER_SDK_KEY,
            'CONFIGDIRECTOR_MODE' => 'offline',
        ])->exitCodeWithinTenSeconds();

        self::assertSame(1, $exitCode);
    }

    #[RequiresPhpExtension('pcntl')]
    public function testTheCommandExitsWithZeroAfterSigterm(): void
    {
        $this->serverStreamingTheFiveConfigs();
        $command = $this->commandWith(['CONFIGDIRECTOR_SERVER_KEY' => self::SERVER_SDK_KEY]);
        $this->waitUntilTheLocalStoreHoldsAPayload();

        $command->signal(SIGTERM);

        self::assertSame(0, $command->exitCodeWithinTenSeconds());
    }

    private function serverStreamingTheFiveConfigs(): ScriptedStreamServer
    {
        return $this->server = ScriptedStreamServer::streaming([[
            'chunks' => [ScriptedStreamServer::eventCarrying(['kind' => 'full', 'configs' => self::THE_FIVE_CONFIGS])],
        ]]);
    }

    /**
     * @param array<string, string> $settings
     */
    private function startTheCommandWith(array $settings): void
    {
        $this->runningCommand = $this->commandWith($settings);
    }

    /**
     * @param array<string, string> $settings
     */
    private function commandWith(array $settings): PhpProcess
    {
        return PhpProcess::running(
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'artisan',
            ['configdirector:stream'],
            [
                'CONFIGDIRECTOR_BASE_URL' => $this->urlOfTheScriptedServer(),
                'CONFIGDIRECTOR_STORAGE_PATH' => $this->storagePath,
                'CONFIGDIRECTOR_MODE' => '',
                'CONFIGDIRECTOR_TIMEOUT' => '',
                'CONFIGDIRECTOR_LOG_LEVEL' => '',
                ...$settings,
            ],
            $this->fileOfStandardError,
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private function configsServedToANewRequest(): array
    {
        config(['configdirector' => [
            'server_key' => self::SERVER_SDK_KEY,
            'base_url' => $this->urlOfTheScriptedServer(),
            'storage_path' => $this->storagePath,
        ]]);
        $this->app->forgetScopedInstances();

        $configs = $this->get('/configs?id=user-123&plan=pro')->json();
        self::assertIsArray($configs);

        return $configs;
    }

    private function urlOfTheScriptedServer(): string
    {
        return $this->server->url ?? throw new \LogicException('The scripted stream server is not running.');
    }

    private function localStoreFolder(): string
    {
        return $this->storagePath . DIRECTORY_SEPARATOR . hash('sha256', self::SERVER_SDK_KEY);
    }

    private function waitUntilTheLocalStoreHoldsAPayload(): void
    {
        $payloadFile = $this->localStoreFolder() . DIRECTORY_SEPARATOR . 'payload.json';
        $deadline = hrtime(true) + 5_000_000_000;
        while (!is_file($payloadFile)) {
            if (hrtime(true) > $deadline) {
                self::fail('The local store held no payload within five seconds.');
            }
            usleep(5_000);
            clearstatcache(true, $payloadFile);
        }
    }
}
