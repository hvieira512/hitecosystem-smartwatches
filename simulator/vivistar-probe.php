<?php

/**
 * Raw Vivistar protocol probe over WebSocket transport.
 *
 * Usage:
 *   php simulator/vivistar-probe.php --imei 865028000000308
 *   php simulator/vivistar-probe.php --imei 865028000000308 --command AP49 --fields 68
 *   php simulator/vivistar-probe.php --imei 865028000000308 --before-login AP49 --before-fields 72
 */

require __DIR__ . '/../vendor/autoload.php';

$args = parseArgs($argv);
$imei = (string)($args['imei'] ?? '');
$server = (string)($args['server'] ?? 'ws://127.0.0.1:8090');
$command = (string)($args['command'] ?? 'AP49');
$fields = (string)($args['fields'] ?? '68');
$beforeLogin = (string)($args['before-login'] ?? '');
$beforeFields = (string)($args['before-fields'] ?? '');

if ($imei === '') {
    echo "Usage: php simulator/vivistar-probe.php --imei IMEI [--server ws://127.0.0.1:8090]\n";
    echo "Optional: --command AP49 --fields 68 --before-login AP49 --before-fields 72\n";
    exit(1);
}

[$host, $port, $path] = parseServer($server);
$socket = @fsockopen($host, $port, $errno, $errstr, 5);
if (!$socket) {
    throw new RuntimeException("Failed to connect to $host:$port ($errstr/$errno)");
}

$key = base64_encode(random_bytes(16));
$upgrade = "GET $path HTTP/1.1\r\n"
    . "Host: $host:$port\r\n"
    . "Upgrade: websocket\r\n"
    . "Connection: Upgrade\r\n"
    . "Sec-WebSocket-Key: $key\r\n"
    . "Sec-WebSocket-Version: 13\r\n"
    . "\r\n";
fwrite($socket, $upgrade);

$response = '';
while (!feof($socket)) {
    $line = fgets($socket);
    if ($line === false) {
        break;
    }
    $response .= $line;
    if ($line === "\r\n") {
        break;
    }
}
if (!str_contains($response, '101 Switching Protocols')) {
    throw new RuntimeException("WebSocket handshake failed:\n$response");
}
echo "[ok] handshake: $server\n";

if ($beforeLogin !== '') {
    $probe = buildVivistarLine($beforeLogin, $beforeFields);
    sendTextFrame($socket, $probe);
    echo "-> $probe\n";
    $reply = receiveFramePayload($socket, 5);
    echo "<- " . ($reply ?? '[null]') . "\n";
}

$login = "IWAP00{$imei}#";
sendTextFrame($socket, $login);
echo "-> $login\n";
$loginReply = receiveFramePayload($socket, 5);
echo "<- " . ($loginReply ?? '[null]') . "\n";

$line = buildVivistarLine($command, $fields);
sendTextFrame($socket, $line);
echo "-> $line\n";
$cmdReply = receiveFramePayload($socket, 5);
echo "<- " . ($cmdReply ?? '[null]') . "\n";

@fwrite($socket, chr(0x88) . chr(0x00));
fclose($socket);

function parseArgs(array $argv): array
{
    $result = [];
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $key = substr($arg, 2);
        if (isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '--')) {
            $result[$key] = $argv[++$i];
            continue;
        }
        $result[$key] = true;
    }
    return $result;
}

function parseServer(string $server): array
{
    $parts = parse_url($server);
    $host = $parts['host'] ?? '127.0.0.1';
    $port = (int)($parts['port'] ?? 8090);
    $path = (string)($parts['path'] ?? '/');
    return [$host, $port, $path];
}

function buildVivistarLine(string $command, string $fieldsCsv): string
{
    $cmd = strtoupper(trim($command));
    if (!preg_match('/^AP[A-Z0-9]{2}$/', $cmd)) {
        throw new InvalidArgumentException("Invalid Vivistar AP command: $command");
    }
    $fieldsCsv = trim($fieldsCsv);
    if ($fieldsCsv === '') {
        return "IW{$cmd}#";
    }
    return "IW{$cmd},{$fieldsCsv}#";
}

function sendTextFrame($socket, string $payload): void
{
    $len = strlen($payload);
    $mask = random_bytes(4);
    $maskedPayload = '';
    for ($i = 0; $i < $len; $i++) {
        $maskedPayload .= chr(ord($payload[$i]) ^ ord($mask[$i % 4]));
    }

    $frame = chr(0x81); // FIN + text
    if ($len < 126) {
        $frame .= chr(0x80 | $len);
    } elseif ($len < 65536) {
        $frame .= chr(0x80 | 126) . pack('n', $len);
    } else {
        $frame .= chr(0x80 | 127) . pack('J', $len);
    }

    fwrite($socket, $frame . $mask . $maskedPayload);
}

function receiveFramePayload($socket, int $timeoutSec = 5): ?string
{
    stream_set_timeout($socket, $timeoutSec);
    $header = fread($socket, 2);
    if ($header === false || strlen($header) < 2) {
        return null;
    }

    $opcode = ord($header[0]) & 0x0F;
    $masked = (ord($header[1]) & 0x80) !== 0;
    $len = ord($header[1]) & 0x7F;

    if ($len === 126) {
        $ext = fread($socket, 2);
        $len = unpack('n', $ext)[1];
    } elseif ($len === 127) {
        $ext = fread($socket, 8);
        $len = unpack('J', $ext)[1];
    }

    $mask = '';
    if ($masked) {
        $mask = fread($socket, 4);
    }

    $payload = $len > 0 ? fread($socket, $len) : '';
    if ($payload === false) {
        return null;
    }

    if ($masked) {
        $unmasked = '';
        for ($i = 0; $i < strlen($payload); $i++) {
            $unmasked .= chr(ord($payload[$i]) ^ ord($mask[$i % 4]));
        }
        $payload = $unmasked;
    }

    if ($opcode === 8) {
        return null;
    }

    return $payload;
}

