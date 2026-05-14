<?php

/**
 * Multi-vendor 4G smartwatch simulator
 *
 * Usage:
 *   php simulator/simulate.php --model WONLEX-PRO --imei 865028000000306 --command upHeartRate
 *   php simulator/simulate.php --model VIVISTAR-LITE --imei 865028000000309 --command AP49
 *   php simulator/simulate.php --list-models
 *   php simulator/simulate.php --model WONLEX-PRO --capabilities
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Registry\DeviceCapabilities;

// --- Argument parsing ---

$args = parseArgs($argv);
$command = $args['command'] ?? null;

// --- List models ---

if (isset($args['list-models'])) {
    echo "Available models:\n";
    foreach (DeviceCapabilities::allModels() as $model) {
        $name = DeviceCapabilities::modelName($model);
        echo "  - $model ($name)\n";
    }
    exit(0);
}

// --- Show model capabilities ---

if (isset($args['capabilities'])) {
    $model = $args['model'] ?? '';
    $caps = DeviceCapabilities::forModel($model);
    if (!$caps) {
        echo "Error: Unknown model '$model'.\n";
        exit(1);
    }
    echo "Model: $model (" . $caps->getName() . ")\n\n";
    echo "PASSIVE commands (watch -> server):\n";
    foreach ($caps->getPassive() as $c) {
        echo "  - $c\n";
    }
    echo "\nACTIVE commands (server -> watch):\n";
    foreach ($caps->getActive() as $c) {
        echo "  - $c\n";
    }
    echo "\nNORMALIZED FEATURES:\n";
    foreach ($caps->getFeatures() as $feature => $commands) {
        $passive = implode(', ', $commands['passive'] ?? []);
        $active = implode(', ', $commands['active'] ?? []);
        echo "  - $feature\n";
        echo "      passive: " . ($passive ?: '-') . "\n";
        echo "      active:  " . ($active ?: '-') . "\n";
    }
    exit(0);
}

// --- Required variables ---

$model = $args['model'] ?? '';
$imei = $args['imei'] ?? '';

if (!$model || !$imei) {
    echo "Usage:\n";
    echo "  php simulator/simulate.php --model MODEL --imei IMEI [options]\n\n";
    echo "Options:\n";
    echo "  --command TYPE        Send a single command (ex: upHeartRate)\n";
    echo "  --data JSON           Command data (ex: '{\"value\":75}')\n";
    echo "  --interactive         Interactive mode (console)\n";
    echo "  --listen              Keep the connection open and listen for commands\n";
    echo "  --server URL          WebSocket server URL (default: ws://127.0.0.1:8080)\n";
    echo "  --list-models         List available models\n";
    echo "  --capabilities        Show capabilities for a model\n";
    exit(1);
}

// --- Load capabilities ---

$caps = DeviceCapabilities::forModel($model);
if (!$caps) {
    echo "Error: Unknown model '$model'.\n";
    exit(1);
}

// --- Load simulation profile ---

$profileFile = __DIR__ . "/profiles/" . strtolower(str_replace('_', '-', $model)) . ".json";
$profile = [];
if (file_exists($profileFile)) {
    $profile = json_decode(file_get_contents($profileFile), true);
}

// --- Config ---

$serverUrl = $args['server'] ?? 'ws://127.0.0.1:8080';
$dataJson = $args['data'] ?? null;
$interactive = isset($args['interactive']);
$listen = isset($args['listen']);

// --- WebSocketClient ---

class WsClient
{
    private $socket;
    private bool $connected = false;

    public function __construct(string $url)
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '127.0.0.1';
        $port = $parts['port'] ?? 8080;
        $path = $parts['path'] ?? '/';

        $errno = 0;
        $errstr = '';

        $this->socket = @fsockopen($host, $port, $errno, $errstr, 5);
        if (!$this->socket) {
            throw new RuntimeException("Failed to connect: $errstr ($errno)");
        }

        $key = base64_encode(random_bytes(16));
        $upgrade = "GET $path HTTP/1.1\r\n"
            . "Host: $host:$port\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: $key\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "\r\n";

        fwrite($this->socket, $upgrade);

        $response = '';
        while (!feof($this->socket)) {
            $line = fgets($this->socket);
            if ($line === false) break;
            $response .= $line;
            if ($line === "\r\n") break;
        }

        if (!str_contains($response, '101 Switching Protocols')) {
            fclose($this->socket);
            throw new RuntimeException("Handshake failed. Response:\n$response");
        }

        $this->connected = true;
    }

    public function send(string $data): void
    {
        $frame = $this->encodeFrame($data);
        fwrite($this->socket, $frame);
    }

    public function receive(?int $timeout = null): ?string
    {
        if (!is_resource($this->socket)) {
            return null;
        }

        if ($timeout !== null) {
            stream_set_timeout($this->socket, $timeout);
        } else {
            stream_set_timeout($this->socket, 0);
        }

        if (feof($this->socket)) {
            return null;
        }

        // Read frame header (2 bytes minimum).
        $header = fread($this->socket, 2);
        if ($header === false || strlen($header) < 2) {
            return null;
        }

        $byte1 = ord($header[0]);
        $byte2 = ord($header[1]);
        $opcode = $byte1 & 0x0F;
        $masked = ($byte2 & 0x80) !== 0;
        $len = $byte2 & 0x7F;

        if ($opcode === 8) {
            return null; // close frame
        }

        if ($opcode === 9) {
            // ping - reply pong
            $this->sendPong();
            return $this->receive($timeout);
        }

        if ($len === 126) {
            $ext = fread($this->socket, 2);
            $len = unpack('n', $ext)[1];
        } elseif ($len === 127) {
            $ext = fread($this->socket, 8);
            $len = unpack('J', $ext)[1];
        }

        if ($masked) {
            $mask = fread($this->socket, 4);
        }

        $payload = fread($this->socket, $len);
        if ($payload === false || strlen($payload) < $len) {
            return null;
        }

        if ($masked) {
            $unmasked = '';
            for ($i = 0; $i < strlen($payload); $i++) {
                $unmasked .= chr(ord($payload[$i]) ^ ord($mask[$i % 4]));
            }
            $payload = $unmasked;
        }

        return $payload;
    }

    private function encodeFrame(string $data): string
    {
        $len = strlen($data);
        $maskKey = random_bytes(4);

        // Mask data (RFC 6455: client -> server must be masked).
        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= chr(ord($data[$i]) ^ ord($maskKey[$i % 4]));
        }

        $frame = chr(0x82); // FIN + opcode binary

        if ($len < 126) {
            $frame .= chr(0x80 | $len); // mascara + length
        } elseif ($len < 65536) {
            $frame .= chr(0x80 | 126) . pack('n', $len);
        } else {
            $frame .= chr(0x80 | 127) . pack('J', $len);
        }

        return $frame . $maskKey . $masked;
    }

    private function sendPong(): void
    {
        fwrite($this->socket, chr(0x8A) . chr(0x00));
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            if ($this->connected) {
                fwrite($this->socket, chr(0x88) . chr(0x00));
            }
            fclose($this->socket);
        }
        $this->connected = false;
        $this->socket = null;
    }

    public function __destruct()
    {
        $this->close();
    }
}

// --- Helper functions ---

function sendPacket(WsClient $ws, array $data): void
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    $packet = pack("nn", 0xFCAF, strlen($json)) . $json;
    $ws->send($packet);
}

function receivePacket(WsClient $ws, ?int $timeout = null): ?array
{
    $raw = $ws->receive($timeout);
    if ($raw === null || $raw === '') return null;
    if (strlen($raw) < 4) return null;

    $header = unpack("nstart/nlength", substr($raw, 0, 4));
    if ($header['start'] !== 0xFCAF) return null;

    $jsonStr = substr($raw, 4, $header['length']);
    $data = json_decode($jsonStr, true);
    return $data ?: null;
}

function now(): int
{
    return (int)round(microtime(true) * 1000);
}

function withToken(array $data, string $sessionToken): array
{
    if ($sessionToken !== '') {
        $data['sessionToken'] = $sessionToken;
    }
    return $data;
}

// --- Connect ---

echo "=== Simulator: $model ($imei) ===\n";
echo "Server: $serverUrl\n";

try {
    $ws = new WsClient($serverUrl);
    echo "[OK] Connected to WebSocket server.\n";
} catch (Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    exit(1);
}

// --- Handshake: Login ---

$loginData = $profile['login'] ?? [
    'deviceModel' => $model,
    'firmware' => 'unknown',
    'platform' => 'unknown',
    'batteryLevel' => 100
];
$ident = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
$sessionToken = '';

sendPacket($ws, [
    'type' => 'login',
    'ident' => $ident,
    'ref' => 'w:update',
    'imei' => $imei,
    'data' => $loginData,
    'timestamp' => now(),
]);

echo "[login] Waiting for response...\n";
$response = receivePacket($ws, 5);

if (!$response) {
    echo "[ERROR] No response from server.\n";
    exit(1);
}

if ($response['type'] === 'login_error') {
    echo "[ERROR] Login rejected: " . ($response['data']['error'] ?? 'unknown reason') . "\n";
    exit(1);
}

if ($response['type'] === 'login_ok') {
    $sessionToken = $response['data']['sessionToken'] ?? '';
    $capsFromServer = $response['data']['capabilities'] ?? [];
    echo "[OK] Login accepted. Session: $sessionToken\n";
    echo "     Capabilities received from server:\n";
    echo "       Passive: " . count($capsFromServer['passive'] ?? []) . " commands\n";
    echo "       Active:  " . count($capsFromServer['active'] ?? []) . " commands\n";
} else {
    echo "[?] Unexpected response: " . json_encode($response) . "\n";
    exit(1);
}

// --- Interactive mode ---

$templates = $profile['dataTemplates'] ?? [];

if ($interactive) {
    echo "\n=== Interactive Mode ===\n";
    echo "Available passive commands:\n";
    foreach ($caps->getPassive() as $c) {
        echo "  $c\n";
    }
    echo "\nType 'quit' to exit.\n\n";

    stream_set_blocking(STDIN, false);

    while (true) {
        // Check server messages (non-blocking).
        $serverMsg = receivePacket($ws, 0);
        if ($serverMsg) {
            $type = $serverMsg['type'] ?? '?';
            $ref = $serverMsg['ref'] ?? '?';
            if ($ref === 's:down') {
                echo "\n[COMMAND] {$serverMsg['type']}: " . json_encode($serverMsg['data'] ?? []) . "\n";
                sendPacket($ws, [
                    'type' => $type,
                    'ident' => $serverMsg['ident'] ?? '',
                    'ref' => 'w:reply',
                    'imei' => $imei,
                    'data' => withToken(['status' => 'ok'], $sessionToken),
                    'timestamp' => now(),
                ]);
                echo "[reply] Response sent.\n> ";
            } elseif ($ref === 's:reply') {
                echo "\n[ACK] {$serverMsg['type']} (ident={$serverMsg['ident']})\n> ";
            }
        }

        // Read input.
        $input = trim(fgets(STDIN));
        if (!$input) {
            usleep(100000);
            continue;
        }

        if ($input === 'quit' || $input === 'exit') {
            break;
        }

        $parts = explode(' ', $input, 2);
        $cmdType = $parts[0];
        $cmdData = isset($parts[1]) ? json_decode($parts[1], true) : ($templates[$cmdType] ?? []);

        if (!$caps->supportsPassive($cmdType)) {
            echo "[!] Command '$cmdType' is not passive for this model.\n";
            continue;
        }

        if ($cmdData === null) {
            echo "[!] Invalid JSON.\n";
            continue;
        }

        $cmdIdent = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        sendPacket($ws, [
            'type' => $cmdType,
            'ident' => $cmdIdent,
            'ref' => 'w:update',
            'imei' => $imei,
            'data' => withToken($cmdData, $sessionToken),
            'timestamp' => now(),
        ]);

        // Wait for confirmation.
        $ack = null;
        $start = time();
        while (!$ack && (time() - $start) < 5) {
            $ack = receivePacket($ws, 1);
            if ($ack) {
                if (($ack['ident'] ?? '') === $cmdIdent) {
                    echo "[OK] Confirmed (ident=$cmdIdent)\n";
                } elseif (($ack['type'] ?? '') === 'error') {
                    echo "[ERROR] {$ack['data']['message']}\n";
                }
            }
        }
        if (!$ack) {
            echo "[!] No confirmation (timeout)\n";
        }
    }

    $ws->close();
    echo "\nSimulator stopped.\n";
    exit(0);
}

// --- Single command mode ---

if ($command) {
    if (!$caps->supportsPassive($command)) {
        echo "Error: Command '$command' is not passive for model '$model'.\n";
        echo "Passive commands: " . implode(', ', $caps->getPassive()) . "\n";
        $ws->close();
        exit(1);
    }

    $cmdData = $dataJson ? json_decode($dataJson, true) : ($templates[$command] ?? []);
    if ($cmdData === null) {
        echo "Error: Invalid JSON.\n";
        $ws->close();
        exit(1);
    }

    $cmdIdent = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    sendPacket($ws, [
        'type' => $command,
        'ident' => $cmdIdent,
        'ref' => 'w:update',
        'imei' => $imei,
        'data' => withToken($cmdData, $sessionToken),
        'timestamp' => now(),
    ]);

    echo "[$command] Sent (ident=$cmdIdent). Waiting for confirmation...\n";

    $ack = null;
    for ($i = 0; $i < 50; $i++) {
        $ack = receivePacket($ws, 1);
        if ($ack) {
            if (($ack['ident'] ?? '') === $cmdIdent) {
                echo "[OK] Confirmed by server.\n";
                break;
            }
            if (($ack['type'] ?? '') === 'error') {
                echo "[ERROR] {$ack['data']['message']}\n";
                break;
            }
        }
    }

    if (!$ack) {
        echo "[!] Timeout: no response from server.\n";
    }

    $ws->close();
    exit(0);
}

// --- Listen mode ---

if ($listen) {
    echo "\n=== Listen Mode ===\n";
    echo "Waiting for server commands... (Ctrl+C to exit)\n\n";

    while (true) {
        $msg = receivePacket($ws, 5);
        if ($msg) {
            $ref = $msg['ref'] ?? '';
            if ($ref === 's:down') {
                echo "[COMMAND] {$msg['type']}\n";
                echo "  Data: " . json_encode($msg['data'] ?? []) . "\n";

                sendPacket($ws, [
                    'type' => $msg['type'],
                    'ident' => $msg['ident'] ?? '',
                    'ref' => 'w:reply',
                    'imei' => $imei,
                    'data' => withToken(['status' => 'ok', 'received' => true], $sessionToken),
                    'timestamp' => now(),
                ]);
                echo "[reply] Response sent.\n";
            } elseif ($ref === 's:reply') {
                echo "[ACK] {$msg['type']}\n";
            }
        } else {
            echo ".";
        }
    }
}

// --- Helper function ---

function parseArgs(array $argv): array
{
    $args = [];
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if (str_starts_with($arg, '--')) {
            $key = substr($arg, 2);
            if (isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '--')) {
                $args[$key] = $argv[$i + 1];
                $i++;
            } else {
                $args[$key] = true;
            }
        }
    }
    return $args;
}
