<?php

// https://amphp.org/getting-started/

require __DIR__ .'/../vendor/autoload.php';

use Amp\Delayed;
use Amp\Loop;
use Amp\Redis\SubscribeClient;
use  Amp\Socket\ServerSocket;
use function Amp\asyncCall;

Loop::run(function() {

    $server = new class {

        private $uri = 'tcp://127.0.0.1:1337';
        private $clients = [];
        private $usernames = [];
        private $redisClient;

        public function listen()
        {
            asyncCall(function() {

                $this->redisClient = new \Amp\Redis\Client('tcp://localhost:6379');

                $server = \Amp\Socket\listen($this->uri);

                $this->listenToRedis();

                print 'Listening on ' . $server->getAddress() . '...' . PHP_EOL;

                while ($socket = yield $server->accept()) {
                    $this->handleClient($socket);
                }
            });
        }

        private function handleClient(ServerSocket $socket)
        {
            asyncCall(function() use($socket) {

                $remoteAddr = $socket->getRemoteAddress();

                print "Accepted new client: {$remoteAddr}" . PHP_EOL;

                $this->broadcast($remoteAddr . ' joined the chat.' . PHP_EOL);

                $this->clients[$remoteAddr] = $socket;

                $buffer = '';
                while (null !== $chunk = yield $socket->read()) {
                    $buffer .= $chunk;

                    while (($pos = strpos($buffer, "\n")) !== false) {
                        $this->handleMessage($socket, substr($buffer, 0, $pos));
                        $buffer = substr($buffer, ++$pos);
                    }
                }

                unset($this->clients[$remoteAddr], $this->usernames[$remoteAddr]);

                print "Client disconnected: {$remoteAddr}" . PHP_EOL;

                $message = ($this->usernames[$remoteAddr] ??  $remoteAddr) . ' left the chat.' . PHP_EOL;

                yield $this->redisClient->publish('chat', $message);
            });
        }

        private function handleMessage(ServerSocket $socket, string $message)
        {
            if ($message === '')
                return;

            if ($message[0] === '/') {
                $message = substr($message, 1);
                $args = explode(' ', $message);
                $name = mb_strtolower(array_shift($args));

                switch ($name) {
                    case 'time':
                        $socket->write(date('l jS \of F Y h:i:s A') . PHP_EOL);
                        break;

                    case 'up':
                        $socket->write(mb_strtoupper(implode(' ', $args)) . PHP_EOL);
                        break;

                    case 'down':
                        $socket->write(mb_strtolower(implode(' ', $args)) . PHP_EOL);
                        break;

                    case 'exit':
                        $socket->end('Bye...' . PHP_EOL);
                        break;

                    case 'nick':
                        $nick = implode(' ', $args);

                        if (!preg_match('(^[a-z0-9-.]{3,15}$)i', $nick)) {
                            $socket->write('Username must only contain letters, digits and its length must be between 3 and 15 characters.' . PHP_EOL);
                            return;
                        }

                        $remoteAddress = $socket->getRemoteAddress();
                        $oldnick = $this->usernames[$remoteAddress] ?? $remoteAddress;
                        $this->usernames[$remoteAddress] = $nick;

                        $this->broadcast("{$oldnick} is now {$nick}" . PHP_EOL);
                        break;

                    default:
                        $socket->write('Unknown command: ' . $name .  PHP_EOL);
                }
                return;
            }
            $remoteAddress = $socket->getRemoteAddress();
            $user = $this->usernames[$remoteAddress] ?? $remoteAddress;
            $this->broadcast($user . ' says: ' . $message . PHP_EOL);
        }

        private function broadcast(string $message)
        {
            foreach ($this->clients as $client) {
                $client->write($message);
            }
        }

        private function listenToRedis()
        {
            asyncCall(function() {

                $redisClient = new SubscribeClient('tcp://localhost:6379');

                do {
                    try {
                        $subscription = yield $redisClient->subscribe('chat');

                        while (yield $subscription->advance()) {
                            $message = $subscription->getCurrent();
                            $this->broadcast($message);
                        }

                    } catch (RedisException $e) {
                        yield new Delayed(1000);
                    }
                } while (true);
            });
        }
    };

    $server->listen();
});