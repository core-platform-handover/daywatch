<?php

namespace Oritech\Daywatch\Server;

use React\EventLoop\LoopInterface;
use React\Http\Message\ResponseException;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Accepts Nightwatch agent connections, parses the framed protocol
 * (<len>:v1:<tokenHash>:<payload>, acked with "2:OK"), buffers the records, and
 * flushes batches to Daywatch on an interval or when the buffer is full.
 */
class AgentServer
{
    /** @var array<int,mixed> */
    private array $buffer = [];

    public function __construct(
        private LoopInterface $loop,
        private string $listen,
        private Forwarder $forwarder,
        private OutputInterface $out,
        private int $maxBatch = 1000,
        private float $flushInterval = 5.0,
    ) {}

    public function run(): void
    {
        $socket = new SocketServer($this->listen, [], $this->loop);
        $socket->on('connection', fn(ConnectionInterface $conn) => $this->onConnection($conn));
        $this->loop->addPeriodicTimer($this->flushInterval, fn() => $this->flush());
        $this->out->writeln("<info>agent listening on {$this->listen}</info>");
    }

    private function onConnection(ConnectionInterface $conn): void
    {
        $buf = '';
        $done = false;

        $conn->on('data', function (string $chunk) use (&$buf, &$done, $conn) {
            if ($done) {
                return;
            }
            $buf .= $chunk;
            $payload = $this->extractPayload($buf);
            if ($payload === false) {
                return; // frame incomplete, await more bytes
            }
            $done = true;
            $this->ingest($payload);
            $conn->end('2:OK'); // ack on receipt (client opens one connection per flush)
        });

        $conn->on('error', fn() => $conn->close());
    }

    /**
     * Pull the payload out of one frame, or false if not fully received yet.
     */
    private function extractPayload(string $buf): string|false
    {
        $colon = strpos($buf, ':');
        if ($colon === false) {
            return false;
        }
        $len = (int) substr($buf, 0, $colon);
        $start = $colon + 1;
        if (strlen($buf) < $start + $len) {
            return false;
        }
        $body = substr($buf, $start, $len); // v1:<tokenHash>:<payload>
        $p1 = strpos($body, ':');
        $p2 = $p1 === false ? false : strpos($body, ':', $p1 + 1);
        if ($p2 === false) {
            return '';
        }
        return substr($body, $p2 + 1);
    }

    private function ingest(string $payload): void
    {
        $trimmed = ltrim($payload);
        if ($trimmed === '' || $trimmed[0] !== '[') {
            return; // PING or non-JSON — already acked
        }
        $records = json_decode($payload, true);
        if (! is_array($records)) {
            return;
        }
        foreach ($records as $record) {
            $this->buffer[] = $record;
        }
        if (count($this->buffer) >= $this->maxBatch) {
            $this->flush();
        }
    }

    private function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }
        $batch = array_splice($this->buffer, 0, $this->maxBatch);
        $count = count($batch);

        $this->forwarder->send($batch)->then(
            function ($response) use ($count) {
                $this->out->writeln("forwarded {$count} events ({$response->getStatusCode()})");
            },
            function (Throwable $e) use ($batch, $count) {
                $code = $e->getCode();
                if ($e instanceof ResponseException && $code >= 400 && $code < 500) {
                    $this->out->writeln("<error>dropped {$count} events: HTTP {$code} {$e->getMessage()}</error>");
                    return; // client error — retrying won't help
                }
                $this->out->writeln("<comment>forward failed ({$e->getMessage()}); requeueing {$count}</comment>");
                array_splice($this->buffer, 0, 0, $batch); // prepend for retry next tick
            },
        );

        if ($this->buffer !== []) {
            $this->loop->futureTick(fn() => $this->flush());
        }
    }
}
