<?php

namespace Oritech\Daywatch\Console;

use Oritech\Daywatch\Server\AgentServer;
use Oritech\Daywatch\Server\Forwarder;
use Illuminate\Console\Command;
use React\EventLoop\Loop;
use React\Http\Browser;

class RunAgentCommand extends Command
{
    protected $signature = 'daywatch:run
        {--token= : Tenant token (defaults to NIGHTWATCH_TOKEN)}
        {--port=2407 : TCP port to listen on for Nightwatch events}
        {--url= : Daywatch digest URL (defaults to DAYWATCH_URL)}';

    protected $description = 'Run the Daywatch local agent: receive Nightwatch events and forward them to Daywatch.';

    public function handle(): int
    {
        $token = (string) ($this->option('token') ?: env('NIGHTWATCH_TOKEN', ''));
        if ($token === '') {
            $this->error('A token is required (--token or NIGHTWATCH_TOKEN).');
            return self::FAILURE;
        }

        $port = (int) $this->option('port');
        $url = (string) ($this->option('url') ?: env('DAYWATCH_URL', 'http://127.0.0.1:9988/digest'));

        $loop = Loop::get();
        $browser = (new Browser())->withTimeout(15.0);
        $forwarder = new Forwarder($browser, $url, $token);

        $server = new AgentServer($loop, "127.0.0.1:{$port}", $forwarder, $this->output);
        $server->run();

        $this->info("daywatch agent → forwarding to {$url}");

        foreach ([SIGINT, SIGTERM] as $signal) {
            $loop->addSignal($signal, function () use ($loop) {
                $this->line('shutting down…');
                $loop->stop();
            });
        }

        $loop->run();

        return self::SUCCESS;
    }
}
