<?php

declare(strict_types=1);

namespace ConfigDirector;

/**
 * Describes a failure to get values from ConfigDirector. A `connectionError` handler receives it
 * in a {@see ConnectionErrorEvent}.
 */
final class ConfigDirectorConnectionException extends ConfigDirectorException
{
    /**
     * @param string $message What failed.
     * @param int|null $status The HTTP status ConfigDirector answered with, or `null` when there
     *     was no answer.
     */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
    ) {
        parent::__construct($message);
    }
}
