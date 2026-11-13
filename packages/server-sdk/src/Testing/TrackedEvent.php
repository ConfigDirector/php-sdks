<?php

declare(strict_types=1);

namespace ConfigDirector\Testing;

use ConfigDirector\Context;

/**
 * An event the code under test tracked, as a {@see TestClient} recorded it. Read them with
 * {@see TestClient::trackedEvents()}.
 */
final readonly class TrackedEvent
{
    /**
     * @param string $name The name the event was tracked with.
     * @param Context|null $context The context the event was tracked for, or `null` when there was
     *     none or its kind was invalid.
     * @param float|null $value The value the event was tracked with, or `null` when there was none
     *     or it was not finite.
     * @param array<array-key, mixed>|null $details The details as the SDK would send them, read back
     *     as arrays, or `null` when there were none or the SDK would leave them out. An object in
     *     them is recorded as it is written as JSON.
     */
    public function __construct(
        public string $name,
        public ?Context $context,
        public ?float $value,
        public ?array $details,
    ) {}
}
