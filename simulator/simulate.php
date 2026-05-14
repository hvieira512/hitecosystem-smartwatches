<?php

/**
 * Multi-vendor 4G smartwatch simulator
 *
 * Usage:
 *   php simulator/simulate.php --model WONLEX-PRO --imei 865028000000306 --command upHeartRate
 *   php simulator/simulate.php --model VIVISTAR-LITE --imei 865028000000309 --command AP49
 *   php simulator/simulate.php --model VIVISTAR-CARE --imei 865028000000308 --command AP49 --server tcp://127.0.0.1:9000
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
    echo "  --command TYPE        Send a single command (ex: upHeartRate, AP49)\n";
    echo "  --data JSON           Command data (ex: '{\"value\":75}' or '{\"heartRate\":72}')\n";
    echo "  --interactive         Interactive mode (console)\n";
    echo "  --listen              Keep the connection open and listen for commands\n";
    echo "  --server URL          Ingress URL (default: ws://127.0.0.1:8080)\n";
    echo "                        Vivistar native TCP can use tcp://127.0.0.1:9000\n";
    echo "\nProtocol is auto-selected from model capabilities (wonlex-json / vivistar-iw).\n";
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
$protocol = $caps->getProtocol() ?? 'wonlex-json';

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

    public function send(string $data, int $opcode = 0x2): void
    {
        $frame = $this->encodeFrame($data, $opcode);
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

    private function encodeFrame(string $data, int $opcode = 0x2): string
    {
        $len = strlen($data);
        $maskKey = random_bytes(4);

        // Mask data (RFC 6455: client -> server must be masked).
        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= chr(ord($data[$i]) ^ ord($maskKey[$i % 4]));
        }

        $frame = chr(0x80 | ($opcode & 0x0F)); // FIN + opcode

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

class TcpTextClient
{
    private $socket;
    private string $buffer = '';

    public function __construct(string $url)
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '127.0.0.1';
        $port = $parts['port'] ?? 9000;

        $errno = 0;
        $errstr = '';
        $this->socket = @fsockopen($host, $port, $errno, $errstr, 5);
        if (!$this->socket) {
            throw new RuntimeException("Failed to connect: $errstr ($errno)");
        }
        stream_set_blocking($this->socket, true);
    }

    public function send(string $data): void
    {
        fwrite($this->socket, $data);
    }

    public function receive(?int $timeout = null): ?string
    {
        if (!is_resource($this->socket)) {
            return null;
        }

        $timeoutSec = $timeout ?? 0;
        stream_set_timeout($this->socket, $timeoutSec);
        $start = microtime(true);

        while (true) {
            $pos = strpos($this->buffer, '#');
            if ($pos !== false) {
                $packet = substr($this->buffer, 0, $pos + 1);
                $this->buffer = substr($this->buffer, $pos + 1);
                return trim($packet);
            }

            $chunk = fread($this->socket, 1024);
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->socket);
                if (($meta['timed_out'] ?? false) || ($timeout !== null && (microtime(true) - $start) >= $timeout)) {
                    return null;
                }
                if (feof($this->socket)) {
                    return null;
                }
                usleep(50000);
                continue;
            }

            $this->buffer .= $chunk;
        }
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
        $this->buffer = '';
    }

    public function __destruct()
    {
        $this->close();
    }
}

// --- Helper functions ---

function sendProtocolPacket(WsClient|TcpTextClient $ws, string $protocol, array|string $payload): void
{
    if ($protocol === 'vivistar-iw') {
        $line = is_string($payload) ? $payload : ($payload['raw'] ?? '');
        if (!is_string($line) || $line === '') {
            throw new RuntimeException('Vivistar payload must be a non-empty string line');
        }
        if ($ws instanceof WsClient) {
            $ws->send($line, 0x1);
        } else {
            $ws->send($line);
        }
        return;
    }

    if ($ws instanceof TcpTextClient) {
        throw new RuntimeException('Wonlex simulator requires WebSocket transport (ws://)');
    }

    if (!is_array($payload)) {
        throw new RuntimeException('Wonlex payload must be an array');
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $packet = pack("nn", 0xFCAF, strlen($json)) . $json;
    $ws->send($packet, 0x2);
}

function receiveProtocolPacket(WsClient|TcpTextClient $ws, string $protocol, ?int $timeout = null): ?array
{
    $raw = $ws->receive($timeout);
    if ($raw === null || $raw === '') {
        return null;
    }

    if ($protocol === 'vivistar-iw') {
        return parseVivistarLine($raw);
    }

    if (strlen($raw) < 4) {
        return null;
    }
    $header = unpack("nstart/nlength", substr($raw, 0, 4));
    if (($header['start'] ?? null) !== 0xFCAF) {
        return null;
    }

    $jsonStr = substr($raw, 4, $header['length']);
    $data = json_decode($jsonStr, true);
    return is_array($data) ? $data : null;
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

function parseVivistarLine(string $line): ?array
{
    $message = trim($line);
    if (!str_starts_with($message, 'IW') || !str_ends_with($message, '#')) {
        return null;
    }

    $body = substr($message, 2, -1);
    if (preg_match('/^(AP|BP)([A-Z0-9]{2})(?:,(.*))?$/', $body, $m) !== 1) {
        return null;
    }

    $prefix = $m[1];
    $code = $m[2];
    $csv = $m[3] ?? '';
    $fields = $csv === '' ? [] : explode(',', $csv);

    $imei = '';
    if (isset($fields[0]) && preg_match('/^\d{15}$/', $fields[0]) === 1) {
        $imei = $fields[0];
    }

    $ident = '';
    if (isset($fields[1]) && preg_match('/^\d{6,14}$/', $fields[1]) === 1) {
        $ident = $fields[1];
    } elseif (isset($fields[0]) && preg_match('/^\d{6,14}$/', $fields[0]) === 1) {
        $ident = $fields[0];
    }

    return [
        'raw' => $message,
        'prefix' => $prefix,
        'code' => $code,
        'type' => $prefix . $code,
        'ident' => $ident,
        'imei' => $imei,
        'data' => [
            'fields' => $fields,
            'csv' => $csv,
        ],
    ];
}

function buildVivistarUplink(string $type, mixed $data): string
{
    $type = strtoupper(trim($type));
    if (preg_match('/^AP[A-Z0-9]{2}$/', $type) !== 1) {
        throw new RuntimeException("Invalid VIVISTAR uplink type: $type");
    }

    $fields = [];

    if (is_array($data) && isset($data['fields']) && is_array($data['fields'])) {
        $fields = array_map(static fn ($v): string => (string)$v, $data['fields']);
    } elseif ($type === 'AP49') {
        $fields = [(string)($data['heartRate'] ?? 72)];
    } elseif ($type === 'APHT') {
        $fields = [
            (string)($data['heartRate'] ?? 72),
            (string)($data['systolic'] ?? 130),
            (string)($data['diastolic'] ?? 85),
        ];
    } elseif ($type === 'APHP') {
        $fields = [
            (string)($data['heartRate'] ?? 72),
            (string)($data['systolic'] ?? 130),
            (string)($data['diastolic'] ?? 85),
            (string)($data['spo2'] ?? 95),
            (string)($data['bloodSugar'] ?? 90),
        ];
    } elseif ($type === 'AP50') {
        $fields = [
            (string)($data['bodyTemperature'] ?? 36.5),
            (string)($data['batteryLevel'] ?? 80),
        ];
    } elseif (is_array($data) && !empty($data)) {
        foreach ($data as $value) {
            if (is_scalar($value) || $value === null) {
                $fields[] = (string)$value;
            }
        }
    }

    return empty($fields)
        ? "IW{$type}#"
        : "IW{$type}," . implode(',', $fields) . "#";
}

function isVivistarDownlinkCommand(array $msg): bool
{
    $fields = $msg['data']['fields'] ?? [];
    if (!is_array($fields) || count($fields) < 2) {
        return false;
    }

    $imei = (string)($fields[0] ?? '');
    $ident = (string)($fields[1] ?? '');

    return preg_match('/^\d{15}$/', $imei) === 1
        && preg_match('/^\d{6,14}$/', $ident) === 1;
}

// --- Connect ---

echo "=== Simulator: $model ($imei) ===\n";
echo "Server: $serverUrl\n";
echo "Protocol: $protocol\n";

try {
    $useNativeVivistarTcp = $protocol === 'vivistar-iw' && str_starts_with($serverUrl, 'tcp://');
    $ws = $useNativeVivistarTcp ? new TcpTextClient($serverUrl) : new WsClient($serverUrl);
    $transportLabel = $useNativeVivistarTcp ? 'native TCP server' : 'WebSocket server';
    echo "[OK] Connected to $transportLabel.\n";
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

if ($protocol === 'vivistar-iw') {
    sendProtocolPacket($ws, $protocol, "IWAP00{$imei}#");
} else {
    sendProtocolPacket($ws, $protocol, [
        'type' => 'login',
        'ident' => $ident,
        'ref' => 'w:update',
        'imei' => $imei,
        'data' => $loginData,
        'timestamp' => now(),
    ]);
}

echo "[login] Waiting for response...\n";
$response = receiveProtocolPacket($ws, $protocol, 5);

if (!$response) {
    echo "[ERROR] No response from server.\n";
    exit(1);
}

if ($protocol === 'vivistar-iw') {
    if (($response['type'] ?? '') !== 'BP00') {
        echo "[ERROR] Login rejected/unexpected response: " . json_encode($response, JSON_UNESCAPED_UNICODE) . "\n";
        exit(1);
    }

    echo "[OK] Login accepted (VIVISTAR BP00).\n";
    echo "     Capabilities from local profile:\n";
    echo "       Passive: " . count($caps->getPassive()) . " commands\n";
    echo "       Active:  " . count($caps->getActive()) . " commands\n";
} else {
    if (($response['type'] ?? '') === 'login_error') {
        echo "[ERROR] Login rejected: " . ($response['data']['error'] ?? 'unknown reason') . "\n";
        exit(1);
    }

    if (($response['type'] ?? '') === 'login_ok') {
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
        $serverMsg = receiveProtocolPacket($ws, $protocol, 0);
        if ($serverMsg) {
            if ($protocol === 'vivistar-iw') {
                $type = $serverMsg['type'] ?? '';
                if (
                    preg_match('/^BP([A-Z0-9]{2})$/', $type, $match) === 1
                    && $type !== 'BP00'
                    && isVivistarDownlinkCommand($serverMsg)
                ) {
                    $fields = $serverMsg['data']['fields'] ?? [];
                    echo "\n[COMMAND] {$type}: " . json_encode($fields) . "\n";
                    $replyFields = [];
                    $identFromDown = $serverMsg['ident'] ?? '';
                    if ($identFromDown !== '') {
                        $replyFields[] = $identFromDown;
                    }
                    $reply = empty($replyFields)
                        ? "IWAP{$match[1]}#"
                        : "IWAP{$match[1]}," . implode(',', $replyFields) . "#";
                    sendProtocolPacket($ws, $protocol, $reply);
                    echo "[reply] $reply\n> ";
                } else {
                    echo "\n[ACK] {$type}\n> ";
                }
            } else {
                $type = $serverMsg['type'] ?? '?';
                $ref = $serverMsg['ref'] ?? '?';
                if ($ref === 's:down') {
                    echo "\n[COMMAND] {$serverMsg['type']}: " . json_encode($serverMsg['data'] ?? []) . "\n";
                    sendProtocolPacket($ws, $protocol, [
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
        if ($protocol === 'vivistar-iw') {
            $line = buildVivistarUplink($cmdType, $cmdData);
            sendProtocolPacket($ws, $protocol, $line);
        } else {
            sendProtocolPacket($ws, $protocol, [
                'type' => $cmdType,
                'ident' => $cmdIdent,
                'ref' => 'w:update',
                'imei' => $imei,
                'data' => withToken($cmdData, $sessionToken),
                'timestamp' => now(),
            ]);
        }

        // Wait for confirmation.
        $ack = null;
        $start = time();
        while (!$ack && (time() - $start) < 5) {
            $ack = receiveProtocolPacket($ws, $protocol, 1);
            if ($ack) {
                if ($protocol === 'vivistar-iw') {
                    $expectedAck = 'BP' . substr($cmdType, 2);
                    if (($ack['type'] ?? '') === $expectedAck) {
                        echo "[OK] Confirmed ({$ack['type']})\n";
                        break;
                    }
                } else {
                    if (($ack['ident'] ?? '') === $cmdIdent) {
                        echo "[OK] Confirmed (ident=$cmdIdent)\n";
                        break;
                    } elseif (($ack['type'] ?? '') === 'error') {
                        echo "[ERROR] {$ack['data']['message']}\n";
                        break;
                    }
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
    if ($protocol === 'vivistar-iw') {
        $line = buildVivistarUplink($command, $cmdData);
        sendProtocolPacket($ws, $protocol, $line);
    } else {
        sendProtocolPacket($ws, $protocol, [
            'type' => $command,
            'ident' => $cmdIdent,
            'ref' => 'w:update',
            'imei' => $imei,
            'data' => withToken($cmdData, $sessionToken),
            'timestamp' => now(),
        ]);
    }

    echo "[$command] Sent (ident=$cmdIdent). Waiting for confirmation...\n";

    $ack = null;
    for ($i = 0; $i < 50; $i++) {
        $ack = receiveProtocolPacket($ws, $protocol, 1);
        if ($ack) {
            if ($protocol === 'vivistar-iw') {
                $expectedAck = 'BP' . substr($command, 2);
                if (($ack['type'] ?? '') === $expectedAck) {
                    echo "[OK] Confirmed by server ({$ack['type']}).\n";
                    break;
                }
            } else {
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
        $msg = receiveProtocolPacket($ws, $protocol, 5);
        if ($msg) {
            if ($protocol === 'vivistar-iw') {
                $type = $msg['type'] ?? '';
                if (
                    preg_match('/^BP([A-Z0-9]{2})$/', $type, $match) === 1
                    && $type !== 'BP00'
                    && isVivistarDownlinkCommand($msg)
                ) {
                    $fields = $msg['data']['fields'] ?? [];
                    echo "[COMMAND] {$type}\n";
                    echo "  Data: " . json_encode($fields) . "\n";

                    $replyFields = [];
                    $identFromDown = $msg['ident'] ?? '';
                    if ($identFromDown !== '') {
                        $replyFields[] = $identFromDown;
                    }

                    $reply = empty($replyFields)
                        ? "IWAP{$match[1]}#"
                        : "IWAP{$match[1]}," . implode(',', $replyFields) . "#";
                    sendProtocolPacket($ws, $protocol, $reply);
                    echo "[reply] {$reply}\n";
                } else {
                    echo "[ACK] {$type}\n";
                }
            } else {
                $ref = $msg['ref'] ?? '';
                if ($ref === 's:down') {
                    echo "[COMMAND] {$msg['type']}\n";
                    echo "  Data: " . json_encode($msg['data'] ?? []) . "\n";

                    sendProtocolPacket($ws, $protocol, [
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
