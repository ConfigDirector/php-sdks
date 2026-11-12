<?php

declare(strict_types=1);

namespace ConfigDirector;

/**
 * The user or other entity, such as a device or an organization, that configs are evaluated for.
 * It is used for targeting rules evaluation.
 */
final readonly class Context
{
    /**
     * @param string|null $id The context's identifier. This should be a value that uniquely
     *     identifies the user or other entity among contexts of the same kind. It places the
     *     context in a share of a percentage rollout, so a change to it can move the context to
     *     another share. A context without an `id` skips percentage rollouts.
     * @param string|null $kind The kind of entity this context represents, such as `user`,
     *     `device`, `organization`, `tenant` or `listing`. You can use any other kind that fits
     *     your application. A kind has 1 to 64 characters, using only lowercase letters (`a`-`z`),
     *     digits, `-` and `_`, and is used exactly as given. Leaving it out means `user`. A
     *     context with an invalid kind is ignored as a whole: the SDK logs an error and evaluates
     *     as if no context had been provided.
     * @param string|null $name The context's display name. It is shown in the ConfigDirector
     *     dashboard and may be used in targeting rules.
     * @param array<string, mixed> $traits Any traits of the context. They are shown in the
     *     ConfigDirector dashboard and may be used in targeting rules.
     * @param bool $anonymous Whether to treat the context as anonymous. When `true`, the context
     *     is used for targeting rules evaluation but is not saved and does not appear in the
     *     dashboard.
     */
    public function __construct(
        public ?string $id = null,
        public ?string $kind = null,
        public ?string $name = null,
        public array $traits = [],
        public bool $anonymous = false,
    ) {}
}
