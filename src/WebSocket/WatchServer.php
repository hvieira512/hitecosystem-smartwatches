<?php

namespace App\WebSocket;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use App\Registry\Whitelist;
use App\Registry\DeviceCapabilities;
use App\Database\Database;
use App\Redis\Client as RedisClient;

class WatchServer implements MessageComponentInterface
{
    private \SplObjectStorage $connections;
    private array $sessions;      // resourceId => session
    private array $deviceMap;     // imei => ConnectionInterface
    private array $deviceData;    // imei => latest health data
    private array $eventHistory;   // recent passive events
    private int $nextEventId;
    private Whitelist $whitelist;
    private ?Database $db;
    private ?RedisClient $redis;

    public function __construct(?Database $db = null, ?RedisClient $redis = null)
    {
        $this->db = $db;
        $this->redis = $redis;
        $this->connections = new \SplObjectStorage();
        $this->sessions = [];
        $this->deviceMap = [];
        $this->deviceData = [];
        $this->eventHistory = [];
        $this->nextEventId = 1;
        $this->whitelist = new Whitelist(db: $db);

        if ($this->db) {
            $this->loadDeviceDataFromDatabase();
        }
    }

    private function loadDeviceDataFromDatabase(): void
    {
        $this->deviceData = $this->db->eventLatestForAllImeis();
        if (!empty($this->deviceData)) {
            echo "[WatchServer] Carregados " . count($this->deviceData)
                . " eventos recentes da base de dados.\n";
        }
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        $this->connections->offsetSet($conn, $conn->resourceId);
        $this->sessions[$conn->resourceId] = [
            'authenticated' => false,
            'imei' => null,
            'model' => null,
            'caps' => null,
            'sessionToken' => null,
        ];
        echo "[+] Nova conexao: {$conn->resourceId}\n";
    }

    public function isRedisAvailable(): bool
    {
        return $this->redis !== null && $this->redis->isAvailable();
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $header = unpack("nstart/nlength", substr($msg, 0, 4));
        if ($header['start'] !== 0xFCAF) {
            echo "[!] Pacote invalido (start field)\n";
            return;
        }

        $payload = json_decode(substr($msg, 4, $header['length']), true);
        if (!$payload || !isset($payload['type'])) {
            echo "[!] JSON invalido\n";
            return;
        }

        $type = $payload['type'];
        $rid = $from->resourceId;

        // Nao autenticado: aceita apenas "login"
        if (!($this->sessions[$rid]['authenticated'] ?? false)) {
            if ($type === 'login') {
                $this->handleLogin($from, $payload);
            } elseif (in_array($type, ['login_error', 'login_ok'], true)) {
                return;
            } else {
                $this->sendError($from, $payload, 'authentication_required',
                    'Deve enviar login primeiro');
            }
            return;
        }

        // Autenticado: verificar token de sessao
        $session = $this->sessions[$rid];
        $caps = $session['caps'];
        $imei = $session['imei'];

        // Rate limiting via Redis
        if ($this->isRedisAvailable() && !$this->redis->rateLimitMessage($imei)) {
            $this->sendError($from, $payload, 'rate_limited',
                'Demasiadas mensagens. Aguarde um momento.');
            return;
        }

        $sentToken = $payload['data']['sessionToken'] ?? '';
        if ($sentToken !== $session['sessionToken']) {
            echo "[!] Token invalido para IMEI=$imei (esperado={$session['sessionToken']}, recebido=$sentToken)\n";
            $this->sendError($from, $payload, 'invalid_session_token',
                'Token de sessao invalido');
            return;
        }

        $ref = $payload['ref'] ?? '';
        $isReplyToServerCommand = $ref === 'w:reply';
        $isPassiveUpdate = !$isReplyToServerCommand;

        if ($isPassiveUpdate && !$caps->supportsPassive($type)) {
            $this->sendError($from, $payload, 'capability_not_supported',
                "Modelo {$session['model']} nao suporta $type");
            return;
        }

        // Armazenar o ultimo evento passivo recebido, independentemente do protocolo nativo.
        if ($isPassiveUpdate) {
            $this->storeDeviceEvent($imei, [
                'imei' => $imei,
                'model' => $session['model'],
                'nativeType' => $type,
                'feature' => $caps->featureForPassive($type),
                'nativePayload' => $this->sanitizePayload($payload['data'] ?? []),
                'receivedAt' => $this->now(),
            ]);
        }

        // Se e resposta a um comando nosso (w:reply), aceitar sempre
        if ($isReplyToServerCommand) {
            echo "[reply] IMEI=$imei, type=$type\n";
            $this->sendJson($from, $this->buildReply($payload, $payload['data'] ?? []));
            return;
        }

        // Comando generico aceite (w:update)
        $this->routeCommand($from, $payload);
    }

    private function handleLogin(ConnectionInterface $conn, array $payload): void
    {
        $imei = $payload['imei'] ?? '';
        $data = $payload['data'] ?? [];
        $model = $data['deviceModel'] ?? '';
        $ident = $payload['ident'] ?? '';

        // 1. Verificar whitelist
        if (!$this->whitelist->isAuthorized($imei)) {
            $this->sendLoginError($conn, $ident, $imei, 'IMEI not authorized or disabled');
            return;
        }

        // 2. Verificar modelo esperado
        $expectedModel = $this->whitelist->getModel($imei);
        if ($expectedModel && $expectedModel !== $model) {
            $this->sendLoginError($conn, $ident, $imei,
                "Model mismatch: expected $expectedModel, got $model");
            return;
        }

        // 3. Carregar capacidades
        $caps = DeviceCapabilities::forModel($model);
        if (!$caps) {
            $this->sendLoginError($conn, $ident, $imei,
                "Unknown device model: $model");
            return;
        }

        // 4. Aceitar login
        $rid = $conn->resourceId;
        $sessionToken = bin2hex(random_bytes(8));
        $this->sessions[$rid]['authenticated'] = true;
        $this->sessions[$rid]['imei'] = $imei;
        $this->sessions[$rid]['model'] = $model;
        $this->sessions[$rid]['caps'] = $caps;
        $this->sessions[$rid]['sessionToken'] = $sessionToken;

        $previousConn = $this->deviceMap[$imei] ?? null;
        $this->deviceMap[$imei] = $conn;

        if ($this->isRedisAvailable()) {
            $this->redis->deviceSetOnline($imei);
        }

        $this->sendJson($conn, [
            'type' => 'login_ok',
            'ident' => $ident,
            'ref' => 's:reply',
            'imei' => $imei,
            'data' => [
                'sessionToken' => $sessionToken,
                'serverTime' => $this->now(),
                'capabilities' => $caps->toArray(),
            ],
            'timestamp' => $this->now(),
        ]);

        echo "[+] Login OK: IMEI=$imei, Modelo=$model, "
           . "Label={$this->whitelist->getLabel($imei)}, "
           . "Session=$sessionToken\n";

        if ($previousConn !== null && $previousConn !== $conn) {
            echo "[!] Login duplicado para IMEI=$imei; nova conexao assumiu o encaminhamento.\n";
        }
    }

    private function sendLoginError(ConnectionInterface $conn, string $ident, string $imei, string $msg): void
    {
        $this->sendJson($conn, [
            'type' => 'login_error',
            'ident' => $ident,
            'ref' => 's:reply',
            'imei' => $imei,
            'data' => ['error' => $msg],
            'timestamp' => $this->now(),
        ]);
        echo "[-] Login rejeitado: IMEI=$imei ($msg)\n";
    }

    private function routeCommand(ConnectionInterface $conn, array $payload): void
    {
        $type = $payload['type'];
        $imei = $payload['imei'] ?? '';

        echo "[data] IMEI=$imei, type=$type\n";

        $this->sendJson($conn, $this->buildReply($payload));
    }

    public function sendCommand(string $imei, string $type, array $data = []): bool
    {
        if (!isset($this->deviceMap[$imei])) {
            echo "[!] sendCommand: IMEI=$imei offline (nao esta neste node)\n";
            if ($this->isRedisAvailable()) {
                $node = $this->redis->deviceGetNode($imei);
                if ($node === null) {
                    echo "[!] sendCommand: IMEI=$imei nao encontrado no Redis\n";
                } elseif ($node !== $this->redis->getNodeId()) {
                    echo "[!] sendCommand: IMEI=$imei esta no node $node (futuro: reencaminhar via Pub/Sub)\n";
                }
            }
            return false;
        }

        $conn = $this->deviceMap[$imei];
        $session = $this->sessions[$conn->resourceId] ?? null;

        if (!$session || !$session['caps']->supportsActive($type)) {
            echo "[!] sendCommand: $type nao suportado para $imei\n";
            return false;
        }

        $ident = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $this->sendJson($conn, [
            'type' => $type,
            'ident' => $ident,
            'ref' => 's:down',
            'imei' => $imei,
            'data' => $data,
            'timestamp' => $this->now(),
        ]);

        echo "[cmd] IMEI=$imei, type=$type, ident=$ident\n";
        return true;
    }

    public function resolveFeatureCommand(string $imei, string $feature): ?string
    {
        $model = $this->whitelist->getModel($imei);
        if (!$model) {
            return null;
        }

        $caps = DeviceCapabilities::forModel($model);
        return $caps?->resolveFeatureActiveCommand($feature);
    }

    public function sendFeatureCommand(string $imei, string $feature, array $data = []): ?string
    {
        $type = $this->resolveFeatureCommand($imei, $feature);
        if (!$type || !$this->sendCommand($imei, $type, $data)) {
            return null;
        }

        return $type;
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $rid = $conn->resourceId;
        $imei = $this->sessions[$rid]['imei'] ?? 'desconhecido';
        echo "[-] Desconectado: resourceId=$rid, IMEI=$imei\n";

        if ($imei && isset($this->deviceMap[$imei]) && $this->deviceMap[$imei] === $conn) {
            $fallback = $this->findConnectionForImei($imei, $rid);
            if ($fallback !== null) {
                $this->deviceMap[$imei] = $fallback;
                echo "[+] Reencaminhamento restaurado: IMEI=$imei, resourceId={$fallback->resourceId}\n";
            } else {
                unset($this->deviceMap[$imei]);
                if ($this->isRedisAvailable()) {
                    $this->redis->deviceSetOffline($imei);
                }
            }
        }
        unset($this->sessions[$rid]);
        $this->connections->offsetUnset($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        echo "[!] Erro: {$e->getMessage()}\n";
        $conn->close();
    }

    // --- Helpers ---

    private function sendJson(ConnectionInterface $client, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $client->send(pack("nn", 0xFCAF, strlen($json)) . $json);
    }

    private function sendError(ConnectionInterface $conn, array $original, string $error, string $msg = ''): void
    {
        $this->sendJson($conn, [
            'type' => 'error',
            'ident' => $original['ident'] ?? '',
            'ref' => 's:reply',
            'imei' => $original['imei'] ?? '',
            'data' => [
                'error' => $error,
                'command' => $original['type'] ?? '',
                'message' => $msg ?: $error,
            ],
            'timestamp' => $this->now(),
        ]);
    }

    private function buildReply(array $payload, ?array $extraData = null): array
    {
        return [
            'type' => $payload['type'],
            'ident' => $payload['ident'] ?? '',
            'ref' => 's:reply',
            'imei' => $payload['imei'] ?? '',
            'data' => $extraData ?? new \stdClass(),
            'timestamp' => $this->now(),
        ];
    }

    private function now(): int
    {
        return (int)round(microtime(true) * 1000);
    }

    private function sanitizePayload(array $payload): array
    {
        unset($payload['sessionToken']);
        unset($payload['encryptionCode']);
        unset($payload['EncryptionCode']);

        return $payload;
    }

    private function findConnectionForImei(string $imei, int $excludeRid): ?ConnectionInterface
    {
        foreach ($this->connections as $conn) {
            $rid = $conn->resourceId;
            if ($rid === $excludeRid) {
                continue;
            }

            $session = $this->sessions[$rid] ?? null;
            if (($session['authenticated'] ?? false) && ($session['imei'] ?? null) === $imei) {
                return $conn;
            }
        }

        return null;
    }

    private function storeDeviceEvent(string $imei, array $event): void
    {
        if ($this->isRedisAvailable()) {
            $streamId = $this->redis->eventPush($event);
            $parts = explode('-', $streamId);
            $event['id'] = (int)$parts[0];
        } elseif ($this->db) {
            $event['id'] = $this->db->eventInsert($event);
        } else {
            $event['id'] = $this->nextEventId++;
        }

        $this->deviceData[$imei] = $event;
        $this->eventHistory[] = $event;

        if (count($this->eventHistory) > 200) {
            array_shift($this->eventHistory);
        }
    }

    public function ingestEvent(array $event, int $dbId): void
    {
        $event['id'] = $dbId;
        $imei = $event['imei'];
        $this->deviceData[$imei] = $event;
        $this->eventHistory[] = $event;
        if (count($this->eventHistory) > 200) {
            array_shift($this->eventHistory);
        }
    }

    // --- API Publica para o servidor HTTP ---

    public function getWhitelist(): Whitelist
    {
        return $this->whitelist;
    }

    public function getSessions(): array
    {
        return $this->sessions;
    }

    public function getDeviceData(string $imei): ?array
    {
        return $this->deviceData[$imei] ?? null;
    }

    public function getAllDeviceData(): array
    {
        return $this->deviceData;
    }

    public function getRecentEvents(int $limit = 50, ?int $afterId = null): array
    {
        if ($this->db) {
            return $this->db->eventFindRecent($limit, $afterId);
        }

        $events = $this->eventHistory;

        if ($afterId !== null) {
            $events = array_values(array_filter(
                $events,
                static fn (array $event): bool => ($event['id'] ?? 0) > $afterId
            ));
        }

        if ($limit > 0 && count($events) > $limit) {
            $events = array_slice($events, -$limit);
        }

        return array_reverse($events);
    }

    public function isOnline(string $imei): bool
    {
        if (isset($this->deviceMap[$imei])) {
            return true;
        }
        if ($this->isRedisAvailable()) {
            return $this->redis->deviceGetNode($imei) !== null;
        }
        return false;
    }

    public function onlineDeviceCount(): int
    {
        return count($this->deviceMap);
    }
}
