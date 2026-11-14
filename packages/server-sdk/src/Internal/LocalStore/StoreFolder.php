<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

final readonly class StoreFolder
{
    private const int FILE_TYPE_BITS_OF_A_MODE = 0o170000;

    private const int FILE_TYPE_OF_A_SYMBOLIC_LINK = 0o120000;

    private const int FILE_TYPE_OF_A_FOLDER = 0o040000;

    private const int ACCESS_OF_OTHER_USERS_IN_A_MODE = 0o077;

    private function __construct(
        public string $path,
        public ?FolderRefusal $refusal,
    ) {}

    public static function openedFor(#[\SensitiveParameter] string $serverSdkKey, ?string $storagePath): self
    {
        $folderNameOfTheKey = hash('sha256', $serverSdkKey);

        return SilencedWarnings::during(static function () use ($storagePath, $folderNameOfTheKey): self {
            if (PHP_OS_FAMILY === 'Windows') {
                return self::madeIn($storagePath, 'configdirector', $folderNameOfTheKey, requiredOwnerId: null);
            }

            $folderForAProbeFile = $storagePath ?? sys_get_temp_dir();
            $systemUserId = self::effectiveSystemUserId($folderForAProbeFile);

            return $systemUserId === null
                ? new self($folderForAProbeFile, FolderRefusal::NotWritable)
                : self::madeIn(
                    $storagePath,
                    "configdirector-{$systemUserId}",
                    $folderNameOfTheKey,
                    requiredOwnerId: $systemUserId,
                );
        });
    }

    private static function madeIn(
        ?string $storagePath,
        string $folderNameInTheSystemTempDirectory,
        string $folderNameOfTheKey,
        ?int $requiredOwnerId,
    ): self {
        $parentFolder = $storagePath
            ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . $folderNameInTheSystemTempDirectory;
        $folder = $parentFolder . DIRECTORY_SEPARATOR . $folderNameOfTheKey;
        $foldersTheSdkMakes = $storagePath === null ? [$parentFolder, $folder] : [$folder];
        foreach ($foldersTheSdkMakes as $folderTheSdkMakes) {
            $refusal = self::refusalOf($folderTheSdkMakes, $requiredOwnerId);
            if ($refusal !== null) {
                return new self($folderTheSdkMakes, $refusal);
            }
        }

        return new self($folder, refusal: null);
    }

    private static function refusalOf(string $folder, ?int $requiredOwnerId): ?FolderRefusal
    {
        clearstatcache(true, $folder);
        if (!is_dir($folder) && mkdir($folder, 0o700, true)) {
            chmod($folder, 0o700);
        }
        $statusOfTheFolderItself = lstat($folder);
        if ($statusOfTheFolderItself === false) {
            return FolderRefusal::NotWritable;
        }

        $fileType = $statusOfTheFolderItself['mode'] & self::FILE_TYPE_BITS_OF_A_MODE;
        if ($fileType === self::FILE_TYPE_OF_A_SYMBOLIC_LINK) {
            return FolderRefusal::SymbolicLink;
        }
        if ($fileType !== self::FILE_TYPE_OF_A_FOLDER) {
            return FolderRefusal::NotWritable;
        }
        if ($requiredOwnerId === null) {
            return is_writable($folder) ? null : FolderRefusal::NotWritable;
        }
        if ($statusOfTheFolderItself['uid'] !== $requiredOwnerId) {
            return FolderRefusal::OwnedByAnotherUser;
        }

        $isOpenToOtherUsers = ($statusOfTheFolderItself['mode'] & self::ACCESS_OF_OTHER_USERS_IN_A_MODE) !== 0;
        if ($isOpenToOtherUsers && !chmod($folder, 0o700)) {
            return FolderRefusal::NotWritable;
        }

        return is_writable($folder) ? null : FolderRefusal::NotWritable;
    }

    private static function effectiveSystemUserId(string $folderForAProbeFile): ?int
    {
        if (function_exists('posix_geteuid')) {
            return posix_geteuid();
        }

        $probeFile = tempnam($folderForAProbeFile, 'cfd');
        if ($probeFile === false) {
            return null;
        }
        $ownerId = fileowner($probeFile);
        unlink($probeFile);

        return $ownerId === false ? null : $ownerId;
    }
}
