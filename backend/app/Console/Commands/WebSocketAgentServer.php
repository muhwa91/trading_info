<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\StockController;
use Illuminate\Http\Request;

class WebSocketAgentServer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'agent:serve {--port=8080}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start the WebSocket real-time stock agent server';

    private $clients = [];
    private $clientStates = [];
    private $controller;
    private $noClientsTime = null;
    private $hasConnected = false;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->controller = new StockController();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $port = $this->option('port');
        $address = "0.0.0.0:$port";
        
        $server = stream_socket_server("tcp://$address", $errno, $errstr);
        if (!$server) {
            $this->error("Failed to start server: $errstr ($errno)");
            return 1;
        }

        $this->info("WebSocket Agent Server running on ws://127.0.0.1:$port");
        
        // Disable blocking on server socket
        stream_set_blocking($server, 0);

        $lastPushTime = 0;

        while (true) {
            $read = $this->clients;
            $read[] = $server;
            $write = null;
            $except = null;

            // Wait up to 1 second for socket activity
            $numChanged = @stream_select($read, $write, $except, 1, 0);

            if ($numChanged === false) {
                $this->error("Select error occurred.");
                break;
            }

            if ($numChanged > 0) {
                // If server socket has activity, it means new client connection
                if (in_array($server, $read)) {
                    $newClient = @stream_socket_accept($server);
                    if ($newClient) {
                        stream_set_blocking($newClient, 0);
                        $id = (int)$newClient;
                        $this->clients[$id] = $newClient;
                        $this->clientStates[$id] = [
                            'handshake' => false,
                            'subscriptions' => [],
                            'timeframes' => []
                        ];
                        $this->info("New client connected: ID $id");
                    }
                    // Remove server from read array to process client messages
                    $key = array_search($server, $read);
                    unset($read[$key]);
                }

                // Process client sockets
                foreach ($read as $clientSocket) {
                    $id = (int)$clientSocket;
                    $data = fread($clientSocket, 2048);

                    if ($data === false || strlen($data) === 0) {
                        $this->disconnect($clientSocket);
                        continue;
                    }

                    if (!$this->clientStates[$id]['handshake']) {
                        $this->performHandshake($clientSocket, $data);
                    } else {
                        $this->handleMessage($clientSocket, $data);
                    }
                }
            }

            // Periodic data push (every 3 seconds)
            $now = microtime(true);
            if ($now - $lastPushTime >= 3.0) {
                $this->pushRealtimeData();
                $lastPushTime = $now;
            }

            // Check client count for auto-shutdown
            $activeCount = 0;
            foreach ($this->clientStates as $state) {
                if (isset($state['handshake']) && $state['handshake']) {
                    $activeCount++;
                }
            }



            // Sleep briefly to save CPU cycles
            usleep(10000); // 10ms
        }

        fclose($server);
        return 0;
    }

    private function disconnect($socket)
    {
        $id = (int)$socket;
        if (isset($this->clients[$id])) {
            @fclose($socket);
            unset($this->clients[$id]);
            unset($this->clientStates[$id]);
            $this->info("Client $id disconnected");
        }
    }

    private function performHandshake($socket, $rawHeaders)
    {
        $id = (int)$socket;
        $headers = [];
        $lines = explode("\r\n", $rawHeaders);
        
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }

        if (isset($headers['sec-websocket-key'])) {
            $key = $headers['sec-websocket-key'];
            $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
            
            $response = "HTTP/1.1 101 Switching Protocols\r\n" .
                        "Upgrade: websocket\r\n" .
                        "Connection: Upgrade\r\n" .
                        "Sec-WebSocket-Accept: $accept\r\n\r\n";
            
            @fwrite($socket, $response);
            $this->clientStates[$id]['handshake'] = true;
            $this->hasConnected = true;
            $this->info("Handshake completed for client $id");
        } else {
            $this->disconnect($socket);
        }
    }

    private function handleMessage($socket, $rawPayload)
    {
        $decodedFrame = $this->decode($rawPayload);
        if (!$decodedFrame) return;

        $id = (int)$socket;

        if ($decodedFrame['type'] === 'close') {
            $this->disconnect($socket);
            return;
        }

        if ($decodedFrame['type'] === 'ping') {
            // Reply with Pong
            $pongFrame = pack('CC', 0x80 | 10, 0); // FIN = 1, Opcode = 10 (Pong)
            @fwrite($socket, $pongFrame);
            return;
        }

        if ($decodedFrame['type'] === 'text') {
            $message = json_decode($decodedFrame['data'], true);
            if (!$message) return;

            if (isset($message['type']) && $message['type'] === 'subscribe') {
                $tickers = $message['tickers'] ?? [];
                $timeframes = $message['timeframes'] ?? [];

                $this->clientStates[$id]['subscriptions'] = $tickers;
                $this->clientStates[$id]['timeframes'] = $timeframes;

                $tickerList = is_array($tickers) ? array_map(function($t) {
                    return is_array($t) ? json_encode($t) : (string)$t;
                }, $tickers) : [];
                $this->info("Client $id subscribed to: " . implode(', ', $tickerList));
                
                // Immediately push data to newly subscribed client
                $this->pushDataToClient($socket);
            }
        }
    }

    private function pushRealtimeData()
    {
        // 1. Find all active clients
        $activeClients = [];
        foreach ($this->clients as $id => $socket) {
            if ($this->clientStates[$id]['handshake'] && !empty($this->clientStates[$id]['subscriptions'])) {
                $activeClients[] = $socket;
            }
        }

        if (empty($activeClients)) return;

        // 2. Gather all unique subscriptions
        $uniquePairs = [];
        foreach ($this->clients as $id => $socket) {
            if (!$this->clientStates[$id]['handshake']) continue;
            
            $subs = $this->clientStates[$id]['subscriptions'];
            $tfs = $this->clientStates[$id]['timeframes'];
            
            foreach ($subs as $ticker) {
                if (!is_string($ticker) && !is_numeric($ticker)) {
                    continue;
                }
                $tf = $tfs[$ticker] ?? '1d';
                $key = "{$ticker}:{$tf}";
                $uniquePairs[$key] = [
                    'ticker' => $ticker,
                    'timeframe' => $tf
                ];
            }
        }

        // 3. Fetch data for unique pairs (using cache when available)
        $stockDataStore = [];
        foreach ($uniquePairs as $key => $pair) {
            $ticker = $pair['ticker'];
            $tf = $pair['timeframe'];
            
            try {
                $request = new Request();
                $request->query->set('timeframe', $tf);
                
                $start = microtime(true);
                $response = $this->controller->getStockData($request, $ticker);
                $elapsed = microtime(true) - $start;
                
                $stockDataStore[$key] = json_decode($response->getContent(), true);
                
                // If the query took >50ms, it was a network request (not cached).
                // Add a small delay to avoid hitting the KIS rate limit (초당 거래건수 제한 대비)
                if ($elapsed > 0.05) {
                    usleep(250000);
                }
            } catch (\Exception $e) {
                $this->error("Failed to fetch stock data for $key: " . $e->getMessage());
            }
        }

        // 4. Send push updates to clients
        foreach ($activeClients as $socket) {
            $id = (int)$socket;
            $subs = $this->clientStates[$id]['subscriptions'];
            $tfs = $this->clientStates[$id]['timeframes'];

            $clientData = [];
            foreach ($subs as $ticker) {
                if (!is_string($ticker) && !is_numeric($ticker)) {
                    continue;
                }
                $tf = $tfs[$ticker] ?? '1d';
                $key = "{$ticker}:{$tf}";
                if (isset($stockDataStore[$key])) {
                    $clientData[$ticker] = $stockDataStore[$key];
                }
            }

            if (!empty($clientData)) {
                $payload = json_encode([
                    'type' => 'update',
                    'stocks' => $clientData
                ]);
                $frame = $this->encode($payload);
                @fwrite($socket, $frame);
            }
        }
    }

    private function pushDataToClient($socket)
    {
        $id = (int)$socket;
        if (!$this->clientStates[$id]['handshake']) return;

        $subs = $this->clientStates[$id]['subscriptions'];
        $tfs = $this->clientStates[$id]['timeframes'];

        if (empty($subs)) return;

        $clientData = [];
        foreach ($subs as $ticker) {
            if (!is_string($ticker) && !is_numeric($ticker)) {
                continue;
            }
            $tf = $tfs[$ticker] ?? '1d';
            try {
                $request = new Request();
                $request->query->set('timeframe', $tf);
                
                $start = microtime(true);
                $response = $this->controller->getStockData($request, $ticker);
                $elapsed = microtime(true) - $start;
                
                $clientData[$ticker] = json_decode($response->getContent(), true);
                
                if ($elapsed > 0.05) {
                    usleep(250000);
                }
            } catch (\Exception $e) {
                $this->error("Immediate push failed for $ticker ($tf): " . $e->getMessage());
            }
        }

        if (!empty($clientData)) {
            $payload = json_encode([
                'type' => 'update',
                'stocks' => $clientData
            ]);
            $frame = $this->encode($payload);
            @fwrite($socket, $frame);
        }
    }

    private function decode($payload)
    {
        if (strlen($payload) < 2) return null;
        
        $firstByte = ord($payload[0]);
        $opcode = $firstByte & 0x0F;
        
        if ($opcode === 8) {
            return ['type' => 'close'];
        }
        
        if ($opcode === 9) {
            return ['type' => 'ping'];
        }
        
        if ($opcode !== 1) {
            return null;
        }

        $secondByte = ord($payload[1]);
        $masked = ($secondByte & 0x80) !== 0;
        $length = $secondByte & 0x7F;

        $offset = 2;
        if ($length === 126) {
            if (strlen($payload) < 4) return null;
            $length = unpack('n', substr($payload, 2, 2))[1];
            $offset = 4;
        } elseif ($length === 127) {
            if (strlen($payload) < 10) return null;
            $length = unpack('J', substr($payload, 2, 8))[1];
            $offset = 10;
        }

        if ($masked) {
            if (strlen($payload) < $offset + 4) return null;
            $mask = substr($payload, $offset, 4);
            $offset += 4;
            $data = substr($payload, $offset, $length);
            
            $decoded = '';
            for ($i = 0; $i < strlen($data); $i++) {
                $decoded .= $data[$i] ^ $mask[$i % 4];
            }
            return ['type' => 'text', 'data' => $decoded];
        } else {
            $data = substr($payload, $offset, $length);
            return ['type' => 'text', 'data' => $data];
        }
    }

    private function encode($text)
    {
        $b1 = 0x80 | 1; // FIN = 1, Opcode = 1 (Text)
        $length = strlen($text);

        if ($length <= 125) {
            $header = pack('CC', $b1, $length);
        } elseif ($length > 125 && $length < 65536) {
            $header = pack('CCn', $b1, 126, $length);
        } else {
            $header = pack('CCJ', $b1, 127, $length);
        }

        return $header . $text;
    }

    private function shutdownAllServers()
    {
        $this->info("Shutting down API server (Port 8000)...");
        $this->killProcessByPort(8000);

        $this->info("Shutting down Vite Frontend (Port 5173)...");
        $this->killProcessByPort(5173);

        $this->info("Shutting down WebSocket Agent itself...");
        exit(0);
    }

    private function killProcessByPort($port)
    {
        $output = [];
        exec("netstat -aon | findstr :$port", $output);
        foreach ($output as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            $parts = preg_split('/\s+/', $line);
            if (count($parts) >= 5) {
                $pid = $parts[4];
                if (is_numeric($pid) && $pid > 0) {
                    exec("taskkill /F /PID $pid");
                }
            }
        }
    }
}
