<?php

namespace App\WebSocket;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use App\Registry\Whitelist;
use App\Registry\DeviceCapabilities;
use App\Repository\EventRepository;
use App\Log\Logger;
use App\Redis\Client as RedisClient;
use App\Protocol\AdapterRegistry;
use App\Protocol\Adapter\DeviceAdapterInterface;

class WatchServer implements MessageComponentInterface
{
    private \SplObjectStorage $connections;
    private array $sessions;      // resourceId => session
    private array $deviceMap;     // imei => ConnectionInterface
    private array $deviceData;    // imei => latest health data
    private array $eventHistory;   // recent passive events
    private int $nextEventId;
    private Whitelist $whitelist;
    private ?EventRepository $eventsRepo;
    private ?RedisClient $redis;
    private AdapterRegistry $adapters;

    public function __construct(?\PDO $pdo = null, ?RedisClient $redis = null)
    {
        $this->eventsRepo = $pdo ? new EventRepository($pdo) : null;
        $this->redis = $redis;
        $this->connections = new \SplObjectStorage();
        $this->sessions = [];
        $this->deviceMap = [];
        $this->deviceData = [];
        $this->eventHistory = [];
        $this->nextEventId = 1;
        $this->whitelist = new Whitelist(pdo: $pdo);
        $this->adapters = new AdapterRegistry();

        if ($this->eventsRepo) {
            $this->loadDeviceDataFromDatabase();
        }
    }

    private function loadDeviceDataFromDatabase(): void
    {
        $this->deviceData = $this->eventsRepo->latestForAllImeis();
        if (!empty($this->deviceData)) {
            Logger::channel('watch')->info("Loaded " . count($this->deviceData)
                . " recent events from the database");
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
            'protocol' => null,
            'adapter' => null,
            'lastCommandType' => null,
            'lastCommandIdent' => null,
        ];
        Logger::channel('watch')->info("New connection: {$conn->resourceId}");
    }

    public function isRedisAvailable(): bool
    {
        return $this->redis !== null && $this->redis->isAvailable();
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $rid = $from->resourceId;
        $session = $this->sessions[$rid] ?? [];
        $raw = (string)$msg;

        $payload = null;
        $adapter = $session['adapter'] ?? null;
        if ($adapter instanceof DeviceAdapterInterface) {
            $payload = $adapter->decodeIncoming($raw, ['session' => $session]);
            if ($payload !== null) {
                $payload['_protocol'] = $session['protocol'] ?? $adapter->protocol();
            }
        }

        if ($payload === null) {
            $payload = $this->adapters->decodeAny($raw, ['session' => $session]);
        }

        if (!$payload || !isset($payload['type'])) {
            Logger::channel('watch')->warning('Invalid packet (unrecognized protocol payload)');
            return;
        }

        $type = $payload['type'];
        $detectedProtocol = $payload['_protocol'] ?? null;
        if ($detectedProtocol !== null && ($this->sessions[$rid]['protocol'] ?? null) === null) {
            $this->sessions[$rid]['protocol'] = $detectedProtocol;
            $this->sessions[$rid]['adapter'] = $this->adapters->get($detectedProtocol);
        }

        // Unauthenticated: only accepts "login".
        if (!($this->sessions[$rid]['authenticated'] ?? false)) {
            if ($type === 'login') {
                $this->handleLogin($from, $payload, $detectedProtocol);
            } elseif (in_array($type, ['login_error', 'login_ok'], true)) {
                return;
            } else {
                $this->sendError($from, $payload, 'authentication_required',
                    'You must send login first');
            }
            return;
        }

        // Authenticated: verify the session token.
        $session = $this->sessions[$rid];
        $caps = $session['caps'];
        $imei = $session['imei'];

        // Rate limiting via Redis
        if ($this->isRedisAvailable() && !$this->redis->rateLimitMessage($imei)) {
            $this->sendError($from, $payload, 'rate_limited',
                'Too many messages. Please wait a moment.');
            return;
        }

        if (($session['protocol'] ?? '') === 'wonlex-json') {
            $sentToken = $payload['data']['sessionToken'] ?? '';
            if ($sentToken !== $session['sessionToken']) {
                Logger::channel('watch')->warning("Invalid token for IMEI=$imei (expected={$session['sessionToken']}, received=$sentToken)");
                $this->sendError($from, $payload, 'invalid_session_token',
                    'Invalid session token');
                return;
            }
        }

        $ref = $payload['ref'] ?? '';
        $isReplyToServerCommand = $ref === 'w:reply'
            || $this->isVivistarCommandReply($session, $payload);
        $isPassiveUpdate = !$isReplyToServerCommand;

        if ($isPassiveUpdate && !$caps->supportsPassive($type)) {
            $this->sendError($from, $payload, 'capability_not_supported',
                "Model {$session['model']} does not support $type");
            return;
        }

        // Store the latest passive event received, regardless of native protocol.
        if ($isPassiveUpdate) {
            $this->storeDeviceEvent($imei, [
                'imei' => $imei,
                'nativeType' => $type,
                'feature' => $caps->featureForPassive($type),
                'nativePayload' => $this->sanitizePayload($payload['data'] ?? []),
                'receivedAt' => $this->now(),
            ]);
        }

        // If it is a reply to one of our commands (w:reply), always accept it.
        if ($isReplyToServerCommand) {
            if (($session['protocol'] ?? '') === 'vivistar-iw') {
                $this->sessions[$rid]['lastCommandType'] = null;
                $this->sessions[$rid]['lastCommandIdent'] = null;
            }
            Logger::channel('watch')->info("reply IMEI=$imei, type=$type");
            $this->sendPayload($from, $this->buildReply($payload, $payload['data'] ?? []));
            return;
        }

        // Generic command accepted (w:update).
        $this->routeCommand($from, $payload);
    }

    private function handleLogin(ConnectionInterface $conn, array $payload, ?string $detectedProtocol = null): void
    {
        $rid = $conn->resourceId;
        $protocol = $detectedProtocol ?? ($this->sessions[$rid]['protocol'] ?? null);
        $imei = $payload['imei'] ?? '';
        $data = $payload['data'] ?? [];
        $model = $data['deviceModel'] ?? '';
        $ident = $payload['ident'] ?? '';

        // 1. Check whitelist.
        if (!$this->whitelist->isAuthorized($imei)) {
            $this->sendLoginError($conn, $ident, $imei, 'IMEI not authorized or disabled');
            return;
        }

        // 2. Check expected model.
        $expectedModel = $this->whitelist->getModel($imei);
        if ($model === '' && $expectedModel) {
            $model = $expectedModel;
        }

        if ($expectedModel && $model !== '' && $expectedModel !== $model) {
            $this->sendLoginError($conn, $ident, $imei,
                "Model mismatch: expected $expectedModel, got $model");
            return;
        }

        // 3. Load capabilities.
        $caps = DeviceCapabilities::forModel($model);
        if (!$caps) {
            $this->sendLoginError($conn, $ident, $imei,
                "Unknown device model: $model");
            return;
        }

        $modelAdapter = $this->adapters->resolveForModel($model);
        if ($modelAdapter === null) {
            $this->sendLoginError($conn, $ident, $imei,
                "No protocol adapter configured for model: $model");
            return;
        }

        if ($protocol !== null && $modelAdapter->protocol() !== $protocol) {
            $this->sendLoginError(
                $conn,
                $ident,
                $imei,
                "Protocol mismatch: expected {$modelAdapter->protocol()}, got $protocol"
            );
            return;
        }

        // 4. Accept login.
        $sessionToken = bin2hex(random_bytes(8));
        $this->sessions[$rid]['authenticated'] = true;
        $this->sessions[$rid]['imei'] = $imei;
        $this->sessions[$rid]['model'] = $model;
        $this->sessions[$rid]['caps'] = $caps;
        $this->sessions[$rid]['sessionToken'] = $sessionToken;
        $this->sessions[$rid]['protocol'] = $modelAdapter->protocol();
        $this->sessions[$rid]['adapter'] = $modelAdapter;

        $previousConn = $this->deviceMap[$imei] ?? null;
        $this->deviceMap[$imei] = $conn;

        if ($this->isRedisAvailable()) {
            $this->redis->deviceSetOnline($imei);
        }

        $this->sendPayload($conn, [
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

        Logger::channel('watch')->info("Login OK: IMEI=$imei, model=$model, session=$sessionToken");

        if ($previousConn !== null && $previousConn !== $conn) {
            Logger::channel('watch')->warning("Duplicate login for IMEI=$imei; the new connection took over routing");
        }
    }

    private function sendLoginError(ConnectionInterface $conn, string $ident, string $imei, string $msg): void
    {
        $this->sendPayload($conn, [
            'type' => 'login_error',
            'ident' => $ident,
            'ref' => 's:reply',
            'imei' => $imei,
            'data' => ['error' => $msg],
            'timestamp' => $this->now(),
        ]);
        Logger::channel('watch')->warning("Login rejected: IMEI=$imei ($msg)");
    }

    private function routeCommand(ConnectionInterface $conn, array $payload): void
    {
        $type = $payload['type'];
        $imei = $payload['imei'] ?? '';

        Logger::channel('watch')->info("data IMEI=$imei, type=$type");

        $this->sendPayload($conn, $this->buildReply($payload));
    }

    public function sendCommand(string $imei, string $type, array $data = []): bool
    {
        if (!isset($this->deviceMap[$imei])) {
            Logger::channel('watch')->warning("sendCommand: IMEI=$imei offline (not on this node)");
            if ($this->isRedisAvailable()) {
                $node = $this->redis->deviceGetNode($imei);
                if ($node === null) {
                    Logger::channel('watch')->warning("sendCommand: IMEI=$imei not found in Redis");
                } elseif ($node !== $this->redis->getNodeId()) {
                    Logger::channel('watch')->warning("sendCommand: IMEI=$imei is on node $node (future: reroute via Pub/Sub)");
                }
            }
            return false;
        }

        $conn = $this->deviceMap[$imei];
        $session = $this->sessions[$conn->resourceId] ?? null;

        if (!$session || !$session['caps']->supportsActive($type)) {
            Logger::channel('watch')->warning("sendCommand: $type is not supported for $imei");
            return false;
        }

        $ident = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $this->sendPayload($conn, [
            'type' => $type,
            'ident' => $ident,
            'ref' => 's:down',
            'imei' => $imei,
            'data' => $data,
            'timestamp' => $this->now(),
        ]);

        Logger::channel('watch')->info("cmd IMEI=$imei, type=$type, ident=$ident");
        $this->sessions[$conn->resourceId]['lastCommandType'] = $type;
        $this->sessions[$conn->resourceId]['lastCommandIdent'] = $ident;
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
        $imei = $this->sessions[$rid]['imei'] ?? 'unknown';
        Logger::channel('watch')->info("Disconnected: resourceId=$rid, IMEI=$imei");

        if ($imei && isset($this->deviceMap[$imei]) && $this->deviceMap[$imei] === $conn) {
            $fallback = $this->findConnectionForImei($imei, $rid);
            if ($fallback !== null) {
                $this->deviceMap[$imei] = $fallback;
                Logger::channel('watch')->info("Routing restored: IMEI=$imei, resourceId={$fallback->resourceId}");
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
        Logger::channel('watch')->error($e->getMessage());
        $conn->close();
    }

    // --- Helpers ---

    private function sendPayload(ConnectionInterface $client, array $data): void
    {
        $rid = $client->resourceId;
        $session = $this->sessions[$rid] ?? [];
        $adapter = $session['adapter'] ?? null;

        if (!$adapter instanceof DeviceAdapterInterface) {
            $protocol = $session['protocol'] ?? 'wonlex-json';
            $adapter = $this->adapters->get($protocol) ?? $this->adapters->get('wonlex-json');
        }

        if (!$adapter instanceof DeviceAdapterInterface) {
            return;
        }

        $client->send($adapter->encodeOutgoing($data, ['session' => $session]));
    }

    private function sendError(ConnectionInterface $conn, array $original, string $error, string $msg = ''): void
    {
        $this->sendPayload($conn, [
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

    private function isVivistarCommandReply(array $session, array $payload): bool
    {
        if (($session['protocol'] ?? '') !== 'vivistar-iw') {
            return false;
        }

        $lastType = $session['lastCommandType'] ?? '';
        $lastIdent = $session['lastCommandIdent'] ?? '';
        $incomingType = $payload['type'] ?? '';
        $incomingIdent = $payload['ident'] ?? '';

        if ($lastType === '' || $lastIdent === '' || $incomingType === '' || $incomingIdent === '') {
            return false;
        }

        if ($incomingIdent !== $lastIdent) {
            return false;
        }

        if (preg_match('/^BP([A-Z0-9]{2})$/', $lastType, $downMatch) !== 1) {
            return false;
        }

        if (preg_match('/^AP([A-Z0-9]{2})$/', $incomingType, $upMatch) !== 1) {
            return false;
        }

        return $downMatch[1] === $upMatch[1];
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
        } elseif ($this->eventsRepo) {
            $event['id'] = $this->eventsRepo->insert($event);
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

    // --- Public API for the HTTP server ---

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
        if ($this->eventsRepo) {
            return $this->eventsRepo->findRecent($limit, $afterId);
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
