<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\Polling;

use ConfigDirector\Internal\Polling\PollAnswerKind;
use ConfigDirector\Internal\Polling\PollingEndpoint;
use ConfigDirector\Metadata;
use ConfigDirector\Tests\Support\ScriptedSdkServer;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

final class PollingEndpointTest extends TestCase
{
    private ?ScriptedSdkServer $server = null;

    #[\Override]
    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    public function testAPollThatRunsPastItsTimeLimitIsAnsweredAsTimedOut(): void
    {
        $this->server = ScriptedSdkServer::answering([
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []], 'delayMilliseconds' => 1_500],
        ]);
        $endpoint = new PollingEndpoint($this->server->url, 'server-sdk-key', new Metadata());

        $answer = $endpoint->answerToAPoll('session-1', null, 300);

        self::assertSame(PollAnswerKind::TimedOut, $answer->kind);
    }

    public function testAPollWhoseConnectionIsClosedWithoutAnAnswerIsAnsweredAsUnanswered(): void
    {
        $this->server = ScriptedSdkServer::answering([['closes' => 'before-answering']]);
        $endpoint = new PollingEndpoint($this->server->url, 'server-sdk-key', new Metadata());

        $answer = $endpoint->answerToAPoll('session-1', null, 3_000);

        self::assertSame(PollAnswerKind::Unanswered, $answer->kind);
    }

    public function testAPollOfAPortNothingListensOnIsAnsweredAsUnanswered(): void
    {
        $this->server = ScriptedSdkServer::answering([]);
        $urlNothingListensOn = $this->server->url;
        $this->server->stop();
        $this->server = null;
        $endpoint = new PollingEndpoint($urlNothingListensOn, 'server-sdk-key', new Metadata());

        $answer = $endpoint->answerToAPoll('session-1', null, 3_000);

        self::assertSame(PollAnswerKind::Unanswered, $answer->kind);
    }

    public function testAPollOfAHostThatDoesNotResolveIsAnsweredAsUnanswered(): void
    {
        $endpoint = new PollingEndpoint('http://sdk-server.invalid', 'server-sdk-key', new Metadata());

        $answer = $endpoint->answerToAPoll('session-1', null, 3_000);

        self::assertSame(PollAnswerKind::Unanswered, $answer->kind);
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    #[RequiresPhpExtension('openssl')]
    public function testAServerWhoseCertificateIsNotTrustedIsSentNoPoll(): void
    {
        $this->server = ScriptedSdkServer::answeringOverTlsWithASelfSignedCertificate([['status' => 204]]);
        $endpoint = new PollingEndpoint($this->server->url, 'server-sdk-key', new Metadata());

        $answer = $endpoint->answerToAPoll('session-1', null, 3_000);

        self::assertSame(PollAnswerKind::Unanswered, $answer->kind);
        self::assertSame([], $this->server->requestsReceived());
    }

    public function testABaseUrlWithAProxyPathKeepsThePathBeforeThePollingPath(): void
    {
        $this->server = ScriptedSdkServer::answering([['status' => 204]]);
        $endpoint = new PollingEndpoint($this->server->url . '/configdirector/', 'server-sdk-key', new Metadata());

        $endpoint->answerToAPoll('session-1', null, 3_000);

        self::assertSame('/configdirector/server/polling/v1', $this->server->requestsReceived()[0]['path']);
    }
}
