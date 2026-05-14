<?php

namespace App\Http;

use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;
use React\EventLoop\LoopInterface;
use Psr\Http\Message\ServerRequestInterface;
use App\WebSocket\WatchServer;
use App\Registry\Whitelist;
use App\Registry\DeviceCapabilities;
use App\Repository\DeviceRepository;
use App\Repository\EventRepository;
use App\Log\Logger;
use App\Redis\Client as RedisClient;

class ApiServer
{
    private ?WatchServer $watchServer;
    private ?Whitelist $whitelist;
    private ?\PDO $pdo;
    private ?DeviceRepository $deviceRepo;
    private ?EventRepository $eventsRepo;
    private ?RedisClient $redis;
    private string $wsServerUrl;
    private array $demoListeners = [];
    private HttpServer $http;
    private SocketServer $socket;

    public function __construct(
        ?WatchServer $watchServer,
        LoopInterface $loop,
        int $port,
        string $host = '0.0.0.0',
        ?\PDO $pdo = null,
        ?RedisClient $redis = null,
        ?string $wsServerUrl = null,
    ) {
        $this->watchServer = $watchServer;
        $this->pdo = $pdo;
        $this->deviceRepo = $pdo ? new DeviceRepository($pdo) : null;
        $this->eventsRepo = $pdo ? new EventRepository($pdo) : null;
        $this->redis = $redis;
        $this->whitelist = null;
        $envWsServerUrl = getenv('WS_SERVER_URL');
        $this->wsServerUrl = $wsServerUrl
            ?: (($envWsServerUrl !== false && $envWsServerUrl !== '')
                ? $envWsServerUrl
                : 'ws://127.0.0.1:8080');

        $this->http = new HttpServer(
            $loop,
            \Closure::fromCallable([$this, 'handleRequest'])
        );

        $this->socket = new SocketServer("$host:$port", [], $loop);
        $this->http->listen($this->socket);

        Logger::channel('api')->info("HTTP API at http://$host:$port");
        Logger::channel('api')->info("WS server URL: {$this->wsServerUrl}");
        if ($watchServer === null) {
            Logger::channel('api')->info("Separate mode: commands are sent via Redis Stream");
        }
    }

    // --- WatchServer wrappers (fallback when WatchServer is unavailable) ---

    private function whitelist(): Whitelist
    {
        if ($this->watchServer !== null) {
            return $this->watchServer->getWhitelist();
        }
        if ($this->whitelist === null) {
            $this->whitelist = new Whitelist(pdo: $this->pdo);
        }
        return $this->whitelist;
    }

    private function deviceData(string $imei): ?array
    {
        if ($this->watchServer !== null) {
            return $this->watchServer->getDeviceData($imei);
        }
        if ($this->eventsRepo !== null) {
            return $this->eventsRepo->latestForImei($imei);
        }
        return null;
    }

    private function recentEventsFromServer(int $limit = 50, ?int $afterId = null): array
    {
        if ($this->watchServer !== null) {
            return $this->watchServer->getRecentEvents($limit, $afterId);
        }
        if ($this->eventsRepo !== null) {
            return $this->eventsRepo->findRecent($limit, $afterId);
        }
        return [];
    }

    private function deviceIsOnline(string $imei): bool
    {
        if ($this->watchServer !== null) {
            return $this->watchServer->isOnline($imei);
        }
        if ($this->redis !== null && $this->redis->isAvailable()) {
            return $this->redis->deviceGetNode($imei) !== null;
        }
        return false;
    }

    private function onlineDeviceCount(): int
    {
        if ($this->watchServer !== null) {
            return $this->watchServer->onlineDeviceCount();
        }
        if ($this->redis !== null && $this->redis->isAvailable()) {
            return count($this->redis->getAllOnlineDevices());
        }
        return 0;
    }

    private function deviceSupportsActiveCommand(string $imei, string $type): bool
    {
        $model = $this->whitelist()->getModel($imei);
        if (!$model) {
            return false;
        }
        $caps = DeviceCapabilities::forModel($model);
        return $caps?->supportsActive($type) ?? false;
    }

    private function sendCommandToDevice(string $imei, string $type, array $data = []): bool
    {
        if ($this->watchServer !== null) {
            return $this->watchServer->sendCommand($imei, $type, $data);
        }
        if ($this->redis !== null && $this->redis->isAvailable()) {
            $requestId = bin2hex(random_bytes(8));
            $this->redis->commandPublish([
                'imei' => $imei,
                'type' => $type,
                'data' => $data,
                'requestId' => $requestId,
                'feature' => '',
                'source' => 'api',
            ]);
            Logger::channel('api')->info("Command published via Redis: IMEI=$imei type=$type requestId=$requestId");
            return true;
        }
        return false;
    }

    private function resolveFeatureCommand(string $imei, string $feature): ?string
    {
        if ($this->watchServer !== null) {
            return $this->watchServer->resolveFeatureCommand($imei, $feature);
        }
        $model = $this->whitelist()->getModel($imei);
        if (!$model) return null;
        $caps = DeviceCapabilities::forModel($model);
        return $caps?->resolveFeatureActiveCommand($feature);
    }

    private function sendFeatureCommandToDevice(string $imei, string $feature, array $data = []): ?string
    {
        if ($this->watchServer !== null) {
            return $this->watchServer->sendFeatureCommand($imei, $feature, $data);
        }
        $type = $this->resolveFeatureCommand($imei, $feature);
        if ($type === null) return null;
        $sent = $this->sendCommandToDevice($imei, $type, $data);
        return $sent ? $type : null;
    }

    // --- Request Handling ---

    private function handleRequest(ServerRequestInterface $request): Response
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        $path = rtrim($path, '/');

        if ($method === 'OPTIONS') {
            return new Response(204, [
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type',
                'Access-Control-Max-Age' => '86400',
            ]);
        }

        try {
            return match (true) {
                $method === 'GET' && $path === '/devices' => $this->listDevices(),
                $method === 'GET' && $path === '/events/recent' => $this->recentEvents($request),
                $method === 'GET' && preg_match('#^/devices/([^/]+)/events/latest$#', $path, $m) === 1
                => $this->latestDeviceEvent($m[1]),
                $method === 'GET' && preg_match('#^/devices/([^/]+)/features$#', $path, $m) === 1
                => $this->deviceFeatures($m[1]),
                $method === 'POST' && preg_match('#^/devices/([^/]+)/command$#', $path, $m) === 1
                => $this->sendCommand($m[1], $request),
                $method === 'POST' && preg_match('#^/devices/([^/]+)/features/([^/]+)/command$#', $path, $m) === 1
                => $this->sendFeatureCommand($m[1], $m[2], $request),
                $method === 'GET' && $path === '/health' => $this->healthCheck(),
                $method === 'GET' && $path === '/metrics' => $this->metricsEndpoint(),
                $method === 'GET' && $path === '/demo' => $this->demoPage(),
                $method === 'POST' && $path === '/demo/simulate' => $this->simulateDeviceEvent($request),
                $method === 'POST' && $path === '/demo/listener' => $this->startDemoListener($request),
                $method === 'GET' && $path === '/demo/listeners' => $this->demoListeners(),
                $method === 'DELETE' && preg_match('#^/demo/listener/([^/]+)$#', $path, $m) === 1
                => $this->stopDemoListener($m[1]),
                $method === 'GET' && $path === '/openapi.json' => $this->openApiSpec(),
                $method === 'GET' && $path === '/docs' => $this->swaggerUi(),
                default => $this->errorResponse('not_found', 'Endpoint not found', 404),
            };
        } catch (\Throwable $e) {
            return $this->errorResponse('internal_error', $e->getMessage(), 500);
        }
    }

    private function listDevices(): Response
    {
        $whitelist = $this->whitelist();
        $devices = [];
        foreach ($whitelist->all() as $imei => $info) {
            $devices[] = [
                'device' => $this->deviceResource($imei, $info),
                'links' => $this->deviceLinks($imei),
            ];
        }

        return $this->jsonResponse([
            'data' => $devices,
            'meta' => ['count' => count($devices)],
        ]);
    }

    private function recentEvents(ServerRequestInterface $request): Response
    {
        $query = $request->getQueryParams();
        $limit = isset($query['limit']) ? max(1, min(200, (int)$query['limit'])) : 50;
        $afterId = isset($query['after']) ? (int)$query['after'] : null;

        $events = array_map(function (array $event): array {
            $imei = $event['imei'] ?? '';

            return [
                'device' => $this->deviceResource($imei),
                'event' => $this->eventResource($event),
            ];
        }, $this->recentEventsFromServer($limit, $afterId));

        return $this->jsonResponse([
            'data' => $events,
            'meta' => [
                'count' => count($events),
                'limit' => $limit,
            ],
        ]);
    }

    private function latestDeviceEvent(string $imei): Response
    {
        $whitelist = $this->whitelist();
        if (!$whitelist->isAuthorized($imei)) {
            return $this->errorResponse(
                'device_not_found',
                'Device not found or disabled',
                404
            );
        }

        $data = $this->deviceData($imei);

        if (!$data && !$this->deviceIsOnline($imei)) {
            return $this->errorResponse(
                'no_data',
                'No event available for this device',
                404
            );
        }

        return $this->jsonResponse([
            'device' => $this->deviceResource($imei),
            'event' => $data ? $this->eventResource($data) : null,
        ]);
    }

    private function sendCommand(string $imei, ServerRequestInterface $request): Response
    {
        $whitelist = $this->whitelist();
        if (!$whitelist->isAuthorized($imei)) {
            return $this->errorResponse(
                'device_not_found',
                'Device not found or disabled',
                404
            );
        }

        $body = json_decode((string)$request->getBody(), true);
        if (!$body || !isset($body['type'])) {
            return $this->errorResponse(
                'invalid_request',
                'The "type" field is required in the JSON body',
                400
            );
        }

        $type = $body['type'];
        $data = $body['data'] ?? [];

        if (!$this->deviceSupportsActiveCommand($imei, $type)) {
            return $this->errorResponse(
                'command_not_supported',
                "Device does not support command $type",
                400
            );
        }

        if (!$this->deviceIsOnline($imei)) {
            return $this->errorResponse(
                'device_offline',
                'Device is offline or cannot be routed right now',
                409
            );
        }

        $sent = $this->sendCommandToDevice($imei, $type, $data);
        if (!$sent) {
            return $this->errorResponse(
                'device_offline',
                'Device is offline or cannot be routed right now',
                409
            );
        }

        return $this->jsonResponse([
            'status' => 'sent',
            'device' => $this->deviceResource($imei),
            'command' => [
                'feature' => null,
                'nativeType' => $type,
                'payload' => $data,
            ],
        ]);
    }

    private function deviceFeatures(string $imei): Response
    {
        $whitelist = $this->whitelist();
        if (!$whitelist->isAuthorized($imei)) {
            return $this->errorResponse(
                'device_not_found',
                'Device not found or disabled',
                404
            );
        }

        $model = $whitelist->getModel($imei);
        $caps = $model ? DeviceCapabilities::forModel($model) : null;
        if (!$caps) {
            return $this->errorResponse(
                'model_not_found',
                'Device model not found',
                404
            );
        }

        return $this->jsonResponse([
            'device' => $this->deviceResource($imei),
            'features' => $this->featureResources($caps->getFeatures()),
            'nativeCommands' => [
                'passive' => $caps->getPassive(),
                'active' => $caps->getActive(),
            ],
        ]);
    }

    private function sendFeatureCommand(string $imei, string $feature, ServerRequestInterface $request): Response
    {
        $whitelist = $this->whitelist();
        if (!$whitelist->isAuthorized($imei)) {
            return $this->errorResponse(
                'device_not_found',
                'Device not found or disabled',
                404
            );
        }

        $model = $whitelist->getModel($imei);
        $caps = $model ? DeviceCapabilities::forModel($model) : null;
        if (!$caps || !$caps->supportsFeature($feature)) {
            return $this->errorResponse(
                'feature_not_supported',
                "Model $model does not support feature $feature",
                400
            );
        }

        $type = $caps->resolveFeatureActiveCommand($feature);
        if (!$type) {
            return $this->errorResponse(
                'feature_has_no_active_command',
                "Feature $feature has no active command for model $model",
                400
            );
        }

        $body = json_decode((string)$request->getBody(), true) ?: [];
        $data = $body['data'] ?? [];

        if (!$this->deviceIsOnline($imei)) {
            return $this->errorResponse(
                'device_offline',
                'Device is offline or cannot be routed right now',
                409
            );
        }

        $sentType = $this->sendFeatureCommandToDevice($imei, $feature, $data);
        if (!$sentType) {
            return $this->errorResponse(
                'device_offline',
                'Device is offline or cannot be routed right now',
                409
            );
        }

        return $this->jsonResponse([
            'status' => 'sent',
            'device' => $this->deviceResource($imei),
            'command' => [
                'feature' => $feature,
                'nativeType' => $sentType,
                'payload' => $data,
            ],
        ]);
    }

    private function simulateDeviceEvent(ServerRequestInterface $request): Response
    {
        $body = json_decode((string)$request->getBody(), true) ?: [];
        $imei = (string)($body['imei'] ?? '');
        $type = (string)($body['type'] ?? '');
        $data = isset($body['data']) && is_array($body['data']) ? $body['data'] : null;

        if ($imei === '' || $type === '') {
            return $this->errorResponse(
                'invalid_request',
                'Fields "imei" and "type" are required',
                400
            );
        }

        $whitelist = $this->whitelist();
        $deviceInfo = $whitelist->all()[$imei] ?? null;
        $model = (string)($body['model'] ?? ($deviceInfo['model'] ?? ''));
        $caps = $model !== '' ? DeviceCapabilities::forModel($model) : null;

        if (!$caps) {
            return $this->errorResponse(
                'model_not_found',
                "Model $model not found",
                404
            );
        }

        if (!$caps->supportsPassive($type)) {
            return $this->errorResponse(
                'capability_not_supported',
                "Model $model does not support passive event $type",
                400,
                [
                    'supportedPassiveTypes' => $caps->getPassive(),
                ]
            );
        }

        $root = dirname(__DIR__, 2);
        $runId = bin2hex(random_bytes(6));
        $logPath = sys_get_temp_dir() . "/health-smartwatches-demo-$runId.log";
        $command = sprintf(
            'php -d error_reporting=E_ALL %s --model %s --imei %s --command %s --server %s',
            escapeshellarg($root . '/simulator/simulate.php'),
            escapeshellarg($model),
            escapeshellarg($imei),
            escapeshellarg($type),
            escapeshellarg($this->wsServerUrl)
        );

        if ($data !== null) {
            $command .= ' --data ' . escapeshellarg(json_encode($data, JSON_UNESCAPED_UNICODE));
        }

        exec($command . ' > ' . escapeshellarg($logPath) . ' 2>&1 &');

        return $this->jsonResponse([
            'status' => 'queued',
            'simulation' => [
                'id' => $runId,
                'imei' => $imei,
                'model' => $model,
                'nativeType' => $type,
                'payload' => $data,
            ],
            'device' => $this->deviceResource($imei, $deviceInfo),
        ], 202);
    }

    private function startDemoListener(ServerRequestInterface $request): Response
    {
        $body = json_decode((string)$request->getBody(), true) ?: [];
        $imei = (string)($body['imei'] ?? '');

        if ($imei === '') {
            return $this->errorResponse(
                'invalid_request',
                'Field "imei" is required',
                400
            );
        }

        $whitelist = $this->whitelist();
        if (!$whitelist->isAuthorized($imei)) {
            return $this->errorResponse(
                'device_not_found',
                'Device not found or disabled',
                404
            );
        }

        $model = $whitelist->getModel($imei);
        if (!$model || !DeviceCapabilities::forModel($model)) {
            return $this->errorResponse(
                'model_not_found',
                'Device model not found',
                404
            );
        }

        $this->pruneDemoListeners();
        if (isset($this->demoListeners[$imei])) {
            return $this->jsonResponse([
                'status' => 'already_running',
                'listener' => $this->listenerResource($this->demoListeners[$imei]),
            ]);
        }

        $root = dirname(__DIR__, 2);
        $id = bin2hex(random_bytes(6));
        $logPath = sys_get_temp_dir() . "/health-smartwatches-listener-$id.log";
        $command = sprintf(
            'php -d error_reporting=E_ALL %s --model %s --imei %s --listen --server %s',
            escapeshellarg($root . '/simulator/simulate.php'),
            escapeshellarg($model),
            escapeshellarg($imei),
            escapeshellarg($this->wsServerUrl)
        );

        $output = [];
        $exitCode = 0;
        exec($command . ' > ' . escapeshellarg($logPath) . ' 2>&1 & echo $!', $output, $exitCode);
        $pid = isset($output[0]) ? (int)$output[0] : 0;

        if ($exitCode !== 0 || $pid <= 0) {
            return $this->errorResponse(
                'listener_start_failed',
                'Failed to start demo watch listener',
                500,
                ['logPath' => $logPath]
            );
        }

        $listener = [
            'id' => $id,
            'imei' => $imei,
            'model' => $model,
            'pid' => $pid,
            'logPath' => $logPath,
            'startedAt' => time(),
        ];
        $this->demoListeners[$imei] = $listener;

        Logger::channel('api')->info("Started demo watch listener: IMEI=$imei model=$model pid=$pid");

        return $this->jsonResponse([
            'status' => 'started',
            'listener' => $this->listenerResource($listener),
        ], 201);
    }

    private function stopDemoListener(string $imei): Response
    {
        $this->pruneDemoListeners();
        if (!isset($this->demoListeners[$imei])) {
            return $this->errorResponse(
                'listener_not_found',
                'No managed demo watch listener found for this IMEI',
                404
            );
        }

        $listener = $this->demoListeners[$imei];
        $pid = (int)$listener['pid'];
        if ($pid > 0 && $this->processIsRunning($pid)) {
            exec('kill ' . $pid);
        }

        unset($this->demoListeners[$imei]);
        Logger::channel('api')->info("Stopped demo watch listener: IMEI=$imei pid=$pid");

        return $this->jsonResponse([
            'status' => 'stopped',
            'listener' => $this->listenerResource($listener, false),
        ]);
    }

    private function demoListeners(): Response
    {
        $this->pruneDemoListeners();
        $listeners = array_map(
            fn(array $listener): array => $this->listenerResource($listener),
            array_values($this->demoListeners)
        );

        return $this->jsonResponse([
            'data' => $listeners,
            'meta' => ['count' => count($listeners)],
        ]);
    }

    private function pruneDemoListeners(): void
    {
        foreach ($this->demoListeners as $imei => $listener) {
            if (!$this->processIsRunning((int)$listener['pid'])) {
                unset($this->demoListeners[$imei]);
            }
        }
    }

    private function listenerResource(array $listener, ?bool $running = null): array
    {
        $imei = $listener['imei'];
        return [
            'id' => $listener['id'],
            'imei' => $imei,
            'model' => $listener['model'],
            'pid' => (int)$listener['pid'],
            'logPath' => $listener['logPath'],
            'running' => $running ?? $this->processIsRunning((int)$listener['pid']),
            'online' => $this->deviceIsOnline($imei),
            'startedAt' => $listener['startedAt'],
        ];
    }

    private function processIsRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }
        exec('kill -0 ' . $pid . ' 2>/dev/null', $output, $exitCode);
        return $exitCode === 0;
    }

    private function deviceResource(string $imei, ?array $info = null): array
    {
        $whitelist = $this->whitelist();
        $info = $info ?? ($whitelist->all()[$imei] ?? []);
        $model = $info['model'] ?? $whitelist->getModel($imei);
        $caps = $model ? DeviceCapabilities::forModel($model) : null;

        return [
            'imei' => $imei,
            'model' => [
                'id' => $model,
                'supplier' => $caps?->getSupplier(),
                'protocol' => $caps?->getProtocol(),
                'transport' => $caps?->getTransport(),
            ],
            'status' => [
                'online' => $this->deviceIsOnline($imei),
                'enabled' => (bool)($info['enabled'] ?? $whitelist->isAuthorized($imei)),
            ],
        ];
    }

    private function deviceLinks(string $imei): array
    {
        return [
            'latestEvent' => "/devices/$imei/events/latest",
            'features' => "/devices/$imei/features",
            'command' => "/devices/$imei/command",
        ];
    }

    private function eventResource(array $event): array
    {
        $nativePayload = $event['nativePayload'] ?? $event['data'] ?? [];
        $nativeType = $event['nativeType'] ?? $event['type'] ?? null;
        $feature = $event['feature'] ?? null;

        return [
            'id' => $event['id'] ?? null,
            'direction' => 'watch_to_server',
            'feature' => $feature,
            'nativeType' => $nativeType,
            'receivedAt' => $event['receivedAt'] ?? $event['timestamp'] ?? null,
            'nativePayload' => $nativePayload,
            'normalized' => $this->normalizedEventPayload($feature, $nativeType, $nativePayload),
        ];
    }

    private function normalizedEventPayload(?string $feature, ?string $nativeType, array $payload): array
    {
        return match ($feature) {
            'heart_rate' => $this->normalizeHeartRate($payload),
            'blood_pressure' => $this->normalizeBloodPressure($payload),
            'blood_oxygen' => $this->normalizeBloodOxygen($payload),
            'temperature' => $this->normalizeTemperature($payload),
            'location' => $this->normalizeLocation($payload),
            'battery' => $this->normalizeBattery($payload),
            'heartbeat' => $this->normalizeHeartbeat($payload),
            'activity' => $this->normalizeActivity($payload),
            default => [],
        };
    }

    private function normalizeHeartRate(array $payload): array
    {
        $value = $this->firstScalar($payload, ['heartRate', 'value', 'date', 'data']);
        return $value === null ? [] : ['heartRateBpm' => (int)$value];
    }

    private function normalizeBloodPressure(array $payload): array
    {
        if (isset($payload['date']) && is_string($payload['date'])) {
            $parts = array_map('intval', explode('/', $payload['date']));
            return array_filter([
                'systolicMmHg' => $parts[0] ?? null,
                'diastolicMmHg' => $parts[1] ?? null,
                'pulseBpm' => $parts[2] ?? null,
            ], static fn($value) => $value !== null);
        }

        return array_filter([
            'systolicMmHg' => isset($payload['systolic']) ? (int)$payload['systolic'] : null,
            'diastolicMmHg' => isset($payload['diastolic']) ? (int)$payload['diastolic'] : null,
            'pulseBpm' => isset($payload['pulse']) ? (int)$payload['pulse'] : (isset($payload['heartRate']) ? (int)$payload['heartRate'] : null),
        ], static fn($value) => $value !== null);
    }

    private function normalizeBloodOxygen(array $payload): array
    {
        $value = $this->firstScalar($payload, ['spo2', 'value', 'date', 'data']);
        return $value === null ? [] : ['spo2Percent' => (int)$value];
    }

    private function normalizeTemperature(array $payload): array
    {
        if (isset($payload['date']) && is_string($payload['date'])) {
            $parts = array_map('floatval', explode('/', $payload['date']));
            return ['bodyTemperatureC' => $parts[0] ?? null];
        }

        $value = $this->firstScalar($payload, ['bodyTemperature', 'temperature', 'value', 'data']);
        return $value === null ? [] : ['bodyTemperatureC' => (float)$value];
    }

    private function normalizeLocation(array $payload): array
    {
        $gps = $payload['gps'] ?? [];
        $lat = $payload['lat'] ?? $gps['lat'] ?? null;
        $lon = $payload['lng'] ?? $payload['lon'] ?? $gps['lon'] ?? null;

        return array_filter([
            'latitude' => $this->normalizeCoordinate($lat),
            'longitude' => $this->normalizeCoordinate($lon),
            'altitudeMeters' => isset($gps['height']) ? (int)$gps['height'] : ($payload['altitude'] ?? null),
            'satelliteCount' => isset($gps['satelliteNum']) ? (int)$gps['satelliteNum'] : (isset($payload['satellites']) ? (int)$payload['satellites'] : null),
        ], static fn($value) => $value !== null);
    }

    private function firstScalar(array $payload, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (isset($payload[$key]) && (is_scalar($payload[$key]) || $payload[$key] === null)) {
                return $payload[$key];
            }
        }

        foreach ($payload as $key => $value) {
            if (is_int($key) && is_scalar($value)) {
                return $value;
            }
        }

        return null;
    }

    private function normalizeCoordinate(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            return (float)$value;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = strtoupper(trim($value));
        if (!preg_match('/^(\d+)(\d{2}\.\d+)([NSEW])$/', $value, $m)) {
            return null;
        }

        $degrees = (int)$m[1];
        $minutes = (float)$m[2];
        $decimal = $degrees + ($minutes / 60);

        return in_array($m[3], ['S', 'W'], true) ? -$decimal : $decimal;
    }

    private function normalizeBattery(array $payload): array
    {
        $level = $payload['batteryLevel'] ?? $payload['level'] ?? null;
        return $level === null ? [] : ['batteryPercent' => (int)$level];
    }

    private function normalizeHeartbeat(array $payload): array
    {
        return array_filter([
            'batteryPercent' => isset($payload['batteryLevel']) ? (int)$payload['batteryLevel'] : null,
            'steps' => isset($payload['steps']) ? (int)$payload['steps'] : null,
            'gsmSignal' => $payload['gsmSignal'] ?? null,
            'workingMode' => $payload['workingMode'] ?? null,
        ], static fn($value) => $value !== null);
    }

    private function normalizeActivity(array $payload): array
    {
        return array_filter([
            'steps' => isset($payload['step']) ? (int)$payload['step'] : null,
            'exerciseSeconds' => isset($payload['exerciseTime']) ? (int)$payload['exerciseTime'] : null,
            'caloriesKcal' => isset($payload['consumed']) ? (int)$payload['consumed'] : (isset($payload['calories']) ? (int)$payload['calories'] : null),
            'distanceMeters' => isset($payload['distance']) ? (int)$payload['distance'] : null,
            'distanceKm' => isset($payload['mileage']) && is_numeric($payload['mileage']) ? (float)$payload['mileage'] : null,
        ], static fn($value) => $value !== null);
    }

    private function featureResources(array $features): array
    {
        $resources = [];
        foreach ($features as $feature => $commands) {
            $passive = $commands['passive'] ?? [];
            $active = $commands['active'] ?? [];
            $resources[$feature] = [
                'canReceive' => count($passive) > 0,
                'canRequest' => count($active) > 0,
                'passiveTypes' => $passive,
                'activeTypes' => $active,
            ];
        }

        return $resources;
    }

    private function healthCheck(): Response
    {
        $services = [
            'api' => ['status' => 'ok'],
        ];

        if ($this->deviceRepo !== null) {
            try {
                $this->deviceRepo->all();
                $services['mysql'] = ['status' => 'ok'];
            } catch (\Throwable $e) {
                $services['mysql'] = ['status' => 'error', 'message' => $e->getMessage()];
            }
        }

        if ($this->redis !== null && $this->redis->isAvailable()) {
            $onlineCount = count($this->redis->getAllOnlineDevices());
            $services['redis'] = ['status' => 'ok', 'devicesOnline' => $onlineCount];
        }

        $overall = 'ok';
        foreach ($services as $svc) {
            if (($svc['status'] ?? '') === 'error') {
                $overall = 'degraded';
                break;
            }
        }

        $httpStatus = $overall === 'ok' ? 200 : 503;
        return $this->jsonResponse([
            'status' => $overall,
            'uptime' => $this->uptime(),
            'services' => $services,
            'version' => '2.0.0',
        ], $httpStatus);
    }

    private function metricsEndpoint(): Response
    {
        $onlineCount = 0;
        $totalDevices = count($this->whitelist()->all());

        $onlineCount = $this->onlineDeviceCount();

        $metrics = [];
        $metrics[] = '# HELP health_devices_total Total authorized devices';
        $metrics[] = '# TYPE health_devices_total gauge';
        $metrics[] = "health_devices_total $totalDevices";
        $metrics[] = '';
        $metrics[] = '# HELP health_devices_online Currently connected devices';
        $metrics[] = '# TYPE health_devices_online gauge';
        $metrics[] = "health_devices_online $onlineCount";
        $metrics[] = '';
        $metrics[] = '# HELP health_uptime_seconds Server uptime in seconds';
        $metrics[] = '# TYPE health_uptime_seconds counter';
        $metrics[] = 'health_uptime_seconds ' . $this->uptime();
        $metrics[] = '';
        $metrics[] = '# HELP health_build_info Build metadata';
        $metrics[] = '# TYPE health_build_info gauge';
        $metrics[] = 'health_build_info{version="2.0.0"} 1';

        return new Response(
            200,
            [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Access-Control-Allow-Origin' => '*',
            ],
            implode("\n", $metrics) . "\n"
        );
    }

    private int $startTime = 0;

    private function uptime(): int
    {
        if ($this->startTime === 0) {
            $this->startTime = time();
        }
        return time() - $this->startTime;
    }

    private function demoPage(): Response
    {
        $path = __DIR__ . '/demo.html';
        $html = is_file($path)
            ? file_get_contents($path)
            : '<!DOCTYPE html><html lang="en"><body>Demo page unavailable.</body></html>';

        return new Response(
            200,
            [
                'Content-Type' => 'text/html; charset=utf-8',
                'Access-Control-Allow-Origin' => '*',
            ],
            $html
        );
    }

    private function openApiSpec(): Response
    {
        return new Response(
            200,
            [
                'Content-Type' => 'application/json',
                'Access-Control-Allow-Origin' => '*',
            ],
            json_encode(OpenApiSpec::get(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    private function swaggerUi(): Response
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Multi-Vendor 4G Smartwatch API</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css">
    <style>
        html { box-sizing: border-box; }
        *, *:before, *:after { box-sizing: inherit; }
        body { margin: 0; background: #fafafa; }
    </style>
</head>
<body>
    <div id="swagger-ui"></div>
    <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
    <script>
        SwaggerUIBundle({
            url: '/openapi.json',
            dom_id: '#swagger-ui',
            deepLinking: true,
            presets: [
                SwaggerUIBundle.presets.apis,
                SwaggerUIBundle.SwaggerUIStandalonePreset,
            ],
        });
    </script>
</body>
</html>
HTML;

        return new Response(
            200,
            [
                'Content-Type' => 'text/html; charset=utf-8',
                'Access-Control-Allow-Origin' => '*',
            ],
            $html
        );
    }

    private function jsonResponse(array $data, int $status = 200): Response
    {
        return new Response(
            $status,
            [
                'Content-Type' => 'application/json',
                'Access-Control-Allow-Origin' => '*',
            ],
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    private function errorResponse(string $code, string $message, int $status, array $details = []): Response
    {
        $payload = [
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];

        if ($details) {
            $payload['error']['details'] = $details;
        }

        return $this->jsonResponse($payload, $status);
    }
}
