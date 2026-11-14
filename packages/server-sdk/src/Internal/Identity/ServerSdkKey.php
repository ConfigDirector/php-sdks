<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Identity;

final readonly class ServerSdkKey
{
    /** @var \SensitiveParameterValue<string> */
    private \SensitiveParameterValue $textKeptOutOfDumps;

    public function __construct(#[\SensitiveParameter] string $text)
    {
        $this->textKeptOutOfDumps = new \SensitiveParameterValue($text);
    }

    public function text(): string
    {
        return $this->textKeptOutOfDumps->getValue();
    }
}
