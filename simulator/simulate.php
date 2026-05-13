<?php

/**
 * Simulador Multi-Vendor de Relogios 4G
 *
 * Uso:
 *   php simulator/simulate.php --model WONLEX-PRO --imei 865028000000306 --command upHeartRate
 *   php simulator/simulate.php --model VIVISTAR-LITE --imei 865028000000309 --command AP49
 *   php simulator/simulate.php --list-models
 *   php simulator/simulate.php --model WONLEX-PRO --capabilities
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Registry\DeviceCapabilities;

// --- Parse de argumentos ---

$args = parseArgs($argv);
$command = $args['command'] ?? null;

// --- Listar modelos ---

if (isset($args['list-models'])) {
    echo "Modelos disponiveis:\n";
    foreach (DeviceCapabilities::allModels() as $model) {
        $label = DeviceCapabilities::modelLabel($model);
        echo "  - $model ($label)\n";
    }
    exit(0);
}

// --- Mostrar capacidades de um modelo ---

if (isset($args['capabilities'])) {
    $model = $args['model'] ?? '';
    $caps = DeviceCapabilities::forModel($model);
    if (!$caps) {
        echo "Erro: Modelo '$model' desconhecido.\n";
        exit(1);
    }
    echo "Modelo: $model (" . $caps->getLabel() . ")\n\n";
    echo "Comandos PASSIVOS (relogio -> servidor):\n";
    foreach ($caps->getPassive() as $c) {
        echo "  - $c\n";
    }
    echo "\nComandos ACTIVOS (servidor -> relogio):\n";
    foreach ($caps->getActive() as $c) {
        echo "  - $c\n";
    }
    echo "\nFEATURES NORMALIZADAS:\n";
    foreach ($caps->getFeatures() as $feature => $commands) {
        $passive = implode(', ', $commands['passive'] ?? []);
        $active = implode(', ', $commands['active'] ?? []);
        echo "  - $feature\n";
        echo "      passive: " . ($passive ?: '-') . "\n";
        echo "      active:  " . ($active ?: '-') . "\n";
    }
    exit(0);
}

// --- Variaveis obrigatorias ---

$model = $args['model'] ?? '';
$imei = $args['imei'] ?? '';

if (!$model || !$imei) {
    echo "Uso:\n";
    echo "  php simulator/simulate.php --model MODELO --imei IMEI [opcoes]\n\n";
    echo "Opcoes:\n";
    echo "  --command TYPE        Envia comando unico (ex: upHeartRate)\n";
    echo "  --data JSON           Dados do comando (ex: '{\"value\":75}')\n";
    echo "  --interactive         Modo interactivo (consola)\n";
    echo "  --listen              Manter ligacao e ouvir comandos\n";
    echo "  --server URL          URL do servidor WebSocket (def: ws://127.0.0.1:8080)\n";
    echo "  --list-models         Listar modelos disponiveis\n";
    echo "  --capabilities        Mostrar capacidades de um modelo\n";
    exit(1);
}

// --- Carregar capacidades ---

$caps = DeviceCapabilities::forModel($model);
if (!$caps) {
    echo "Erro: Modelo '$model' desconhecido.\n";
    exit(1);
}

// --- Carregar perfil de simulacao ---

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
            throw new RuntimeException("Falha ao conectar: $errstr ($errno)");
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
            throw new RuntimeException("Handshake falhou. Resposta:\n$response");
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

        // Ler cabeçalho do frame (2 bytes minimo)
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
            // ping - responder pong
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

        // Mascarar dados (RFC 6455: cliente -> servidor tem de ser masked)
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

// --- Funcoes auxiliares ---

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

// --- Conectar ---

echo "=== Simulador: $model ($imei) ===\n";
echo "Servidor: $serverUrl\n";

try {
    $ws = new WsClient($serverUrl);
    echo "[OK] Conectado ao servidor WebSocket.\n";
} catch (Exception $e) {
    echo "[ERRO] " . $e->getMessage() . "\n";
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

echo "[login] Aguardar resposta...\n";
$response = receivePacket($ws, 5);

if (!$response) {
    echo "[ERRO] Sem resposta do servidor.\n";
    exit(1);
}

if ($response['type'] === 'login_error') {
    echo "[ERRO] Login rejeitado: " . ($response['data']['error'] ?? 'motivo desconhecido') . "\n";
    exit(1);
}

if ($response['type'] === 'login_ok') {
    $sessionToken = $response['data']['sessionToken'] ?? '';
    $capsFromServer = $response['data']['capabilities'] ?? [];
    echo "[OK] Login aceite. Sesao: $sessionToken\n";
    echo "     Capacidades recebidas do servidor:\n";
    echo "       Passive: " . count($capsFromServer['passive'] ?? []) . " comandos\n";
    echo "       Active:  " . count($capsFromServer['active'] ?? []) . " comandos\n";
} else {
    echo "[?] Resposta inesperada: " . json_encode($response) . "\n";
    exit(1);
}

// --- Modo interactivo ---

$templates = $profile['dataTemplates'] ?? [];

if ($interactive) {
    echo "\n=== Modo Interactivo ===\n";
    echo "Comandos passiveis disponiveis:\n";
    foreach ($caps->getPassive() as $c) {
        echo "  $c\n";
    }
    echo "\nDigite 'quit' para sair.\n\n";

    stream_set_blocking(STDIN, false);

    while (true) {
        // Verificar mensagens do servidor (non-blocking)
        $serverMsg = receivePacket($ws, 0);
        if ($serverMsg) {
            $type = $serverMsg['type'] ?? '?';
            $ref = $serverMsg['ref'] ?? '?';
            if ($ref === 's:down') {
                echo "\n[COMANDO] {$serverMsg['type']}: " . json_encode($serverMsg['data'] ?? []) . "\n";
                sendPacket($ws, [
                    'type' => $type,
                    'ident' => $serverMsg['ident'] ?? '',
                    'ref' => 'w:reply',
                    'imei' => $imei,
                    'data' => withToken(['status' => 'ok'], $sessionToken),
                    'timestamp' => now(),
                ]);
                echo "[reply] Resposta enviada.\n> ";
            } elseif ($ref === 's:reply') {
                echo "\n[ACK] {$serverMsg['type']} (ident={$serverMsg['ident']})\n> ";
            }
        }

        // Ler input
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
            echo "[!] Comando '$cmdType' nao e passivo para este modelo.\n";
            continue;
        }

        if ($cmdData === null) {
            echo "[!] JSON invalido.\n";
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

        // Aguardar confirmacao
        $ack = null;
        $start = time();
        while (!$ack && (time() - $start) < 5) {
            $ack = receivePacket($ws, 1);
            if ($ack) {
                if (($ack['ident'] ?? '') === $cmdIdent) {
                    echo "[OK] Confirmado (ident=$cmdIdent)\n";
                } elseif (($ack['type'] ?? '') === 'error') {
                    echo "[ERRO] {$ack['data']['message']}\n";
                }
            }
        }
        if (!$ack) {
            echo "[!] Sem confirmacao (timeout)\n";
        }
    }

    $ws->close();
    echo "\nSimulador encerrado.\n";
    exit(0);
}

// --- Modo comando unico ---

if ($command) {
    if (!$caps->supportsPassive($command)) {
        echo "Erro: Comando '$command' nao e passivo para o modelo '$model'.\n";
        echo "Comandos passiveis: " . implode(', ', $caps->getPassive()) . "\n";
        $ws->close();
        exit(1);
    }

    $cmdData = $dataJson ? json_decode($dataJson, true) : ($templates[$command] ?? []);
    if ($cmdData === null) {
        echo "Erro: JSON invalido.\n";
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

    echo "[$command] Enviado (ident=$cmdIdent). Aguardar confirmacao...\n";

    $ack = null;
    for ($i = 0; $i < 50; $i++) {
        $ack = receivePacket($ws, 1);
        if ($ack) {
            if (($ack['ident'] ?? '') === $cmdIdent) {
                echo "[OK] Confirmado pelo servidor.\n";
                break;
            }
            if (($ack['type'] ?? '') === 'error') {
                echo "[ERRO] {$ack['data']['message']}\n";
                break;
            }
        }
    }

    if (!$ack) {
        echo "[!] Timeout: sem resposta do servidor.\n";
    }

    $ws->close();
    exit(0);
}

// --- Modo listen ---

if ($listen) {
    echo "\n=== Modo Listen ===\n";
    echo "A aguardar comandos do servidor... (Ctrl+C para sair)\n\n";

    while (true) {
        $msg = receivePacket($ws, 5);
        if ($msg) {
            $ref = $msg['ref'] ?? '';
            if ($ref === 's:down') {
                echo "[COMANDO] {$msg['type']}\n";
                echo "  Dados: " . json_encode($msg['data'] ?? []) . "\n";

                sendPacket($ws, [
                    'type' => $msg['type'],
                    'ident' => $msg['ident'] ?? '',
                    'ref' => 'w:reply',
                    'imei' => $imei,
                    'data' => withToken(['status' => 'ok', 'received' => true], $sessionToken),
                    'timestamp' => now(),
                ]);
                echo "[reply] Resposta enviada.\n";
            } elseif ($ref === 's:reply') {
                echo "[ACK] {$msg['type']}\n";
            }
        } else {
            echo ".";
        }
    }
}

// --- Funcao auxiliar ---

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
