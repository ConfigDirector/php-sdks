<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

final readonly class StoreFolder
{
    private function __construct(
        public string $path,
        public ?FolderRefusal $refusal,
    ) {}

    public static function openedFor(string $serverSdkKey, ?string $storagePath): self
    {
        $folderNameOfTheKey = hash('sha256', $serverSdkKey);

        return SilencedWarnings::during(static function () use ($storagePath, $folderNameOfTheKey): self {
            if (PHP_OS_FAMILY === 'Windows') {
                return self::madeIn($storagePath, 'configdirector', $folderNameOfTheKey, null);
            }

            $folderForAProbeFile = $storagePath ?? sys_get_temp_dir();
            $systemUserId = self::effectiveSystemUserId($folderForAProbeFile);

            return $systemUserId === null
                ? new self($folderForAProbeFile, FolderRefusal::NotWritable)
                : self::madeIn($storagePath, "configdirector-{$systemUserId}", $folderNameOfTheKey, $systemUserId);
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

        return new self($folder, null);
    }

    private static function refusalOf(string $folder, ?int $requiredOwnerId): ?FolderRefusal
    {
        clearstatcache(true, $folder);
        if (!is_dir($folder) && mkdir($folder, 0o700, true)) {
            chmod($folder, 0o700);
        }
        if (is_link($folder)) {
            return FolderRefusal::SymbolicLink;
        }
        if (!is_dir($folder)) {
            return FolderRefusal::NotWritable;
        }
        if ($requiredOwnerId !== null && fileowner($folder) !== $requiredOwnerId) {
            return FolderRefusal::OwnedByAnotherUser;
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
