<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

final class SilencedWarnings
{
    /**
     * @template TResult
     *
     * @param \Closure(): TResult $filesystemCall
     *
     * @return TResult
     */
    public static function during(\Closure $filesystemCall): mixed
    {
        set_error_handler(static fn(): bool => true);
        try {
            return $filesystemCall();
        } finally {
            restore_error_handler();
        }
    }
}
