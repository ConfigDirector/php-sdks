<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

enum FolderRefusal
{
    case SymbolicLink;
    case OwnedByAnotherUser;
    case NotWritable;

    public function errorMessage(): string
    {
        return match ($this) {
            self::SymbolicLink => '[LocalStore] The local store folder {folder} is a symbolic link, so it is not used '
                . 'and every read returns its in-code default value. Remove it, or set '
                . 'ConnectionOptions(storagePath: ...) to another folder.',
            self::OwnedByAnotherUser => '[LocalStore] The local store folder {folder} belongs to another system user, '
                . 'so it is not used and every read returns its in-code default value. Set '
                . 'ConnectionOptions(storagePath: ...) to a folder this user owns.',
            self::NotWritable => '[LocalStore] The local store folder {folder} could not be created or written to, so '
                . 'every read returns its in-code default value. Set ConnectionOptions(storagePath: ...) to a folder '
                . 'this user can write to.',
        };
    }
}
