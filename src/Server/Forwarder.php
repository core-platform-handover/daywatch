<?php

namespace Oritech\Daywatch\Server;

use React\Http\Browser;
use React\Promise\PromiseInterface;

/**
 * Forwards a batch of raw Nightwatch records to the Daywatch digest endpoint
 * over HTTPS, authenticated with the tenant token. Holds no database access.
 */
class Forwarder
{
    public function __construct(
        private Browser $browser,
        private string $url,
        private string $token,
    ) {}

    /**
     * @param  array<int,mixed>  $batch
     */
    public function send(array $batch): PromiseInterface
    {
        $body = json_encode($batch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->browser->post($this->url, [
            'Authorization' => 'Bearer ' . $this->token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], $body);
    }
}
