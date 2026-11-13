<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\LocalStore;

use ConfigDirector\Internal\LocalStore\LocalStore;
use ConfigDirector\Internal\LocalStore\StoredPayload;
use ConfigDirector\Internal\LocalStore\UnfilledStore;
use ConfigDirector\Tests\Support\PhpProcess;
use ConfigDirector\Tests\Support\RecordingLogger;
use ConfigDirector\Tests\Support\TemporaryFolder;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class LocalStoreFolderTest extends TestCase
{
    private const string FOLDER_NAME_OF_SERVER_SDK_KEY
        = 'add45d3fbae45285dcd74320ecebb931a054c563edc414cd120865f8ff4e09f8';

    private string $storagePath;

    private int $umaskBeforeTheTest;

    #[\Override]
    protected function setUp(): void
    {
        $this->storagePath = TemporaryFolder::create();
        $this->umaskBeforeTheTest = umask();
    }

    #[\Override]
    protected function tearDown(): void
    {
        umask($this->umaskBeforeTheTest);
        TemporaryFolder::remove($this->storagePath);
    }

    public function testAStoragePathThatDoesNotExistYetIsCreated(): void
    {
        $storagePath = $this->storagePath . DIRECTORY_SEPARATOR . 'not' . DIRECTORY_SEPARATOR . 'there-yet';

        self::storeAPayloadIn($storagePath);

        self::assertEquals(
            new StoredPayload(['configs' => ['stored' => []]], 1_000, 'session-1'),
            LocalStore::ofServerSdkKey('server-sdk-key', $storagePath, new RecordingLogger())->storedPayload(),
        );
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testTheStoreFolderIsReadableByItsOwnerOnly(): void
    {
        umask(0);

        self::storeAPayloadIn($this->storagePath);

        self::assertSame(0o700, fileperms($this->storeFolder()) & 0o777);
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testThePayloadFileIsReadableByItsOwnerOnly(): void
    {
        umask(0);

        self::storeAPayloadIn($this->storagePath);

        self::assertSame(0o600, fileperms($this->storeFolder() . '/payload.json') & 0o777);
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testEveryFileOfTheStoreIsReadableByItsOwnerOnly(): void
    {
        umask(0);

        self::storeAPayloadIn($this->storagePath);

        $modes = [];
        foreach (TemporaryFolder::entriesOf($this->storeFolder()) as $fileName) {
            $modes[$fileName] = fileperms($this->storeFolder() . '/' . $fileName) & 0o777;
        }
        self::assertSame(['lock' => 0o600, 'payload.json' => 0o600], $modes);
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testAStoreFolderThatIsASymbolicLinkIsNotRead(): void
    {
        $storagePath = $this->storagePathWhoseStoreFolderLinksToAStoreHoldingAPayload();

        $store = LocalStore::ofServerSdkKey('server-sdk-key', $storagePath, new RecordingLogger());

        self::assertNull($store->storedPayload());
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testAStoreFolderThatIsASymbolicLinkGivesNoRefreshLock(): void
    {
        $storagePath = $this->storagePathWhoseStoreFolderLinksToAStoreHoldingAPayload();

        $store = LocalStore::ofServerSdkKey('server-sdk-key', $storagePath, new RecordingLogger());

        self::assertNull($store->refreshLockAcquiredWithin(0));
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testAStoreFolderThatIsASymbolicLinkLogsOneErrorNamingTheFolder(): void
    {
        $storagePath = $this->storagePathWhoseStoreFolderLinksToAStoreHoldingAPayload();
        $logger = new RecordingLogger();

        $store = LocalStore::ofServerSdkKey('server-sdk-key', $storagePath, $logger);
        $store->storedPayload();
        $store->storedPayload();

        self::assertSame(
            [['folder' => $storagePath . '/' . self::FOLDER_NAME_OF_SERVER_SDK_KEY]],
            $logger->contextsLoggedAt(LogLevel::ERROR),
        );
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testAStoreFolderOwnedByAnotherSystemUserIsNotReadAndLogsOneErrorNamingTheFolder(): void
    {
        if (fileowner($this->storagePath) !== 0) {
            self::markTestSkipped('Only the root user can give a folder to another system user.');
        }
        self::storeAPayloadIn($this->storagePath);
        chown($this->storeFolder(), 65_534);
        $logger = new RecordingLogger();

        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, $logger);

        self::assertNull($store->storedPayload());
        self::assertNull($store->refreshLockAcquiredWithin(0));
        self::assertSame([['folder' => $this->storeFolder()]], $logger->contextsLoggedAt(LogLevel::ERROR));
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testAStoreFolderThatCannotBeWrittenToIsNotReadAndLogsOneErrorNamingTheFolder(): void
    {
        if (fileowner($this->storagePath) === 0) {
            self::markTestSkipped('The root user can write to every folder.');
        }
        self::storeAPayloadIn($this->storagePath);
        chmod($this->storeFolder(), 0o500);
        $logger = new RecordingLogger();

        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, $logger);

        self::assertNull($store->storedPayload());
        self::assertNull($store->refreshLockAcquiredWithin(0));
        self::assertSame([['folder' => $this->storeFolder()]], $logger->contextsLoggedAt(LogLevel::ERROR));
    }

    public function testAStoreFolderThatCannotBeCreatedHoldsNoPayloadAndGivesNoRefreshLock(): void
    {
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePathInsideAFile(), new RecordingLogger());

        self::assertNull($store->storedPayload());
        self::assertNull($store->refreshLockAcquiredWithin(0));
    }

    public function testAStoreFolderThatCannotBeCreatedLogsOneErrorNamingTheFolder(): void
    {
        $storagePath = $this->storagePathInsideAFile();
        $logger = new RecordingLogger();

        $store = LocalStore::ofServerSdkKey('server-sdk-key', $storagePath, $logger);
        $store->storedPayload();
        $store->refreshLockAcquiredWithin(0);

        self::assertSame(
            [['folder' => $storagePath . DIRECTORY_SEPARATOR . self::FOLDER_NAME_OF_SERVER_SDK_KEY]],
            $logger->contextsLoggedAt(LogLevel::ERROR),
        );
    }

    public function testAStoreFolderThatCannotBeCreatedIsNeverFetched(): void
    {
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePathInsideAFile(), new RecordingLogger());
        $fetches = 0;

        $filledPayload = $store->storedPayloadFilledWithin(
            1_000,
            static function () use (&$fetches): StoredPayload {
                $fetches++;

                return new StoredPayload(['configs' => ['fetched' => []]], 1_000, 'session-1');
            },
        );

        self::assertSame(0, $fetches);
        self::assertSame(UnfilledStore::UnusableAsItsErrorLineSays, $filledPayload);
    }

    public function testAStoragePathThatPhpIsNotAllowedToOpenIsNotUsedAndLogsOneErrorWithoutAPhpWarning(): void
    {
        $foldersPhpMayOpen = [dirname(__DIR__, 3), dirname(PhpProcess::autoloadFile())];

        $output = PhpProcess::running(
            __DIR__ . '/Processes/print-stored-payload.php',
            [$this->storagePath, 'server-sdk-key'],
            ['open_basedir' => implode(PATH_SEPARATOR, $foldersPhpMayOpen), 'display_errors' => '1'],
        )->finished()->output;

        self::assertSame(
            '{"payload":null,"confirmedFreshAtEpochMilliseconds":null,"sessionId":null,"errorsLogged":1}',
            $output,
        );
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testWithoutAStoragePathTheStoreIsAFolderOfTheSystemUserInTheSystemTempDirectory(): void
    {
        umask(0);

        $output = PhpProcess::running(
            __DIR__ . '/Processes/store-payload.php',
            ['', 'server-sdk-key', 'default-location', '10'],
            ['sys_temp_dir' => $this->storagePath],
        )->finished()->output;

        $folderOfTheSystemUser = $this->storagePath . '/configdirector-' . fileowner($this->storagePath);
        self::assertSame('stored', $output);
        self::assertFileExists(
            $folderOfTheSystemUser . '/' . self::FOLDER_NAME_OF_SERVER_SDK_KEY . '/payload.json',
        );
        self::assertSame(0o700, fileperms($folderOfTheSystemUser) & 0o777);
    }

    #[RequiresOperatingSystem('^WIN')]
    public function testWithoutAStoragePathOnWindowsTheStoreIsInTheConfigdirectorFolderOfTheSystemTempDirectory(): void
    {
        $output = PhpProcess::running(
            __DIR__ . '/Processes/store-payload.php',
            ['', 'server-sdk-key', 'default-location', '10'],
            ['sys_temp_dir' => $this->storagePath],
        )->finished()->output;

        self::assertSame('stored', $output);
        self::assertFileExists(
            $this->storagePath . '\\configdirector\\' . self::FOLDER_NAME_OF_SERVER_SDK_KEY . '\\payload.json',
        );
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testWithoutThePosixExtensionTheStoreIsStillAFolderOfTheSystemUser(): void
    {
        $output = PhpProcess::running(
            __DIR__ . '/Processes/store-payload.php',
            ['', 'server-sdk-key', 'default-location', '10'],
            ['sys_temp_dir' => $this->storagePath, 'disable_functions' => 'posix_geteuid'],
        )->finished()->output;

        self::assertSame('stored', $output);
        self::assertSame(
            ['configdirector-' . fileowner($this->storagePath)],
            TemporaryFolder::entriesOf($this->storagePath),
        );
        self::assertFileExists(
            $this->storagePath . '/configdirector-' . fileowner($this->storagePath)
            . '/' . self::FOLDER_NAME_OF_SERVER_SDK_KEY . '/payload.json',
        );
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testWithoutThePosixExtensionAndWithoutAWritableTempDirectoryTheStoreIsNotUsedAndLogsOneError(): void
    {
        $output = PhpProcess::running(
            __DIR__ . '/Processes/print-stored-payload.php',
            ['', 'server-sdk-key'],
            ['sys_temp_dir' => $this->storagePathInsideAFile(), 'disable_functions' => 'posix_geteuid'],
        )->finished()->output;

        self::assertSame(
            ['payload' => null, 'confirmedFreshAtEpochMilliseconds' => null, 'sessionId' => null, 'errorsLogged' => 1],
            json_decode($output, true),
        );
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testAFolderOfTheSystemUserThatIsASymbolicLinkIsNotReadAndLogsOneError(): void
    {
        $linkedTo = $this->storagePath . '/linked-to';
        self::storeAPayloadIn($linkedTo);
        $systemTempDirectory = $this->storagePath . '/system-temp';
        mkdir($systemTempDirectory);
        symlink($linkedTo, $systemTempDirectory . '/configdirector-' . fileowner($this->storagePath));

        $output = PhpProcess::running(
            __DIR__ . '/Processes/print-stored-payload.php',
            ['', 'server-sdk-key'],
            ['sys_temp_dir' => $systemTempDirectory],
        )->finished()->output;

        self::assertSame(
            ['payload' => null, 'confirmedFreshAtEpochMilliseconds' => null, 'sessionId' => null, 'errorsLogged' => 1],
            json_decode($output, true),
        );
    }

    private static function storeAPayloadIn(string $storagePath): void
    {
        $refreshLock = LocalStore::ofServerSdkKey('server-sdk-key', $storagePath, new RecordingLogger())
            ->refreshLockAcquiredWithin(0);
        self::assertNotNull($refreshLock);
        $refreshLock->replaceStoredPayload(new StoredPayload(['configs' => ['stored' => []]], 1_000, 'session-1'));
        $refreshLock->release();
    }

    private function storeFolder(): string
    {
        return $this->storagePath . DIRECTORY_SEPARATOR . self::FOLDER_NAME_OF_SERVER_SDK_KEY;
    }

    private function storagePathWhoseStoreFolderLinksToAStoreHoldingAPayload(): string
    {
        $linkedTo = $this->storagePath . '/linked-to';
        self::storeAPayloadIn($linkedTo);
        $storagePath = $this->storagePath . '/linking';
        mkdir($storagePath);
        symlink(
            $linkedTo . '/' . self::FOLDER_NAME_OF_SERVER_SDK_KEY,
            $storagePath . '/' . self::FOLDER_NAME_OF_SERVER_SDK_KEY,
        );

        return $storagePath;
    }

    private function storagePathInsideAFile(): string
    {
        $file = $this->storagePath . DIRECTORY_SEPARATOR . 'a-file';
        file_put_contents($file, 'not a folder');

        return $file . DIRECTORY_SEPARATOR . 'storage';
    }
}
