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
     * @param-out string|null $lastWarning
     *
     * @return TResult
     */
    public static function during(\Closure $filesystemCall, ?string &$lastWarning = null): mixed
    {
        $lastWarning = null;
        set_error_handler(static function (int $errorLevel, string $message) use (&$lastWarning): bool {
            $lastWarning = $message;

            return true;
        });
        try {
            return $filesystemCall();
        } finally {
            restore_error_handler();
        }
    }
}
