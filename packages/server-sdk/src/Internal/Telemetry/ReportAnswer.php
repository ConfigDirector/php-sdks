<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Telemetry;

final readonly class ReportAnswer
{
    private const int REPORT_TOO_LARGE_STATUS = 413;

    private const int TOO_MANY_REQUESTS_STATUS = 429;

    private function __construct(
        public ReportAnswerKind $kind,
        public ?int $status = null,
        public string $detail = '',
    ) {}

    public static function withStatus(int $status): self
    {
        $kind = match (true) {
            $status >= 200 && $status < 300 => ReportAnswerKind::Accepted,
            $status === self::REPORT_TOO_LARGE_STATUS => ReportAnswerKind::Refused,
            $status === self::TOO_MANY_REQUESTS_STATUS => ReportAnswerKind::Refused,
            $status >= 400 && $status < 500 => ReportAnswerKind::RefusedUnrecoverably,
            default => ReportAnswerKind::Refused,
        };

        return new self($kind, status: $status);
    }

    public static function unanswered(string $detail): self
    {
        return new self(ReportAnswerKind::Unanswered, detail: $detail);
    }
}
