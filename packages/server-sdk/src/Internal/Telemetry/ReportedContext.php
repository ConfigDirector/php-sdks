<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Telemetry;

use ConfigDirector\Context;

final readonly class ReportedContext
{
    private const string USER_CONTEXT_KIND = 'user';

    private const int JSON_FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_PARTIAL_OUTPUT_ON_ERROR;

    private function __construct(
        public string $kind,
        public string $identifier,
        private Context $context,
    ) {}

    public static function of(?Context $context): ?self
    {
        $isIdentified = $context !== null && $context->id !== null && $context->id !== '' && !$context->anonymous;

        return $isIdentified ? new self($context->kind ?? self::USER_CONTEXT_KIND, $context->id, $context) : null;
    }

    public function kindAndIdentifier(): string
    {
        return "{$this->kind}:{$this->identifier}";
    }

    public function capturedContextJson(): ?string
    {
        $capturedContext = ['id' => $this->identifier, 'kind' => $this->kind];
        if ($this->context->name !== null) {
            $capturedContext['name'] = $this->context->name;
        }
        if ($this->context->traits !== []) {
            $capturedContext['traits'] = $this->context->traits;
        }
        $capturedContext['anonymous'] = false;

        $json = json_encode($capturedContext, self::JSON_FLAGS);

        return $json === false ? null : $json;
    }
}
