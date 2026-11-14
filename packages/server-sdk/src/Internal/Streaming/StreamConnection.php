<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Streaming;

final readonly class StreamConnection
{
    public \CurlHandle $request;

    public StreamAnswer $answer;

    public function __construct(
        StreamEndpoint $endpoint,
        public string $sessionId,
        string $lastEventId,
    ) {
        $this->answer = new StreamAnswer($endpoint, $lastEventId);
        $this->request = $endpoint->requestOpeningAStream($sessionId, $lastEventId);
        curl_setopt_array($this->request, [
            CURLOPT_HEADERFUNCTION => $this->answer->takeInHeaderLine(...),
            CURLOPT_WRITEFUNCTION => $this->answer->takeInBodyChunk(...),
        ]);
    }
}
