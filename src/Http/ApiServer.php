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

        Logger::channel('api')->info("HTTP API em http://$host:$port");
        Logger::channel('api')->info("WS server URL: {$this->wsServerUrl}");
        if ($watchServer === null) {
            Logger::channel('api')->info("Modo separado: comandos enviados via Redis Stream");
        }
    }

    // --- WatchServer wrappers (fallback quando WatchServer nao esta disponivel) ---

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
            Logger::channel('api')->info("Comando publicado via Redis: IMEI=$imei type=$type requestId=$requestId");
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
                $method === 'GET' && $path === '/openapi.json' => $this->openApiSpec(),
                $method === 'GET' && $path === '/docs' => $this->swaggerUi(),
                default => $this->errorResponse('not_found', 'Endpoint nao encontrado', 404),
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
                'Dispositivo nao encontrado ou desativado',
                404
            );
        }

        $data = $this->deviceData($imei);

        if (!$data && !$this->deviceIsOnline($imei)) {
            return $this->errorResponse(
                'no_data',
                'Nenhum evento disponivel para este dispositivo',
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
                'Dispositivo nao encontrado ou desativado',
                404
            );
        }

        $body = json_decode((string)$request->getBody(), true);
        if (!$body || !isset($body['type'])) {
            return $this->errorResponse(
                'invalid_request',
                'Campo "type" obrigatorio no JSON',
                400
            );
        }

        $type = $body['type'];
        $data = $body['data'] ?? [];

        if (!$this->deviceSupportsActiveCommand($imei, $type)) {
            return $this->errorResponse(
                'command_not_supported',
                "Dispositivo nao suporta o comando $type",
                400
            );
        }

        if (!$this->deviceIsOnline($imei)) {
            return $this->errorResponse(
                'device_offline',
                'Dispositivo offline ou nao encaminhavel neste momento',
                409
            );
        }

        $sent = $this->sendCommandToDevice($imei, $type, $data);
        if (!$sent) {
            return $this->errorResponse(
                'device_offline',
                'Dispositivo offline ou nao encaminhavel neste momento',
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
                'Dispositivo nao encontrado ou desativado',
                404
            );
        }

        $model = $whitelist->getModel($imei);
        $caps = $model ? DeviceCapabilities::forModel($model) : null;
        if (!$caps) {
            return $this->errorResponse(
                'model_not_found',
                'Modelo do dispositivo nao encontrado',
                404
            );
        }

        return $this->jsonResponse([
            'device' => $this->deviceResource($imei),
            'features' => $this->featureResources($caps->getFeatures()),
        ]);
    }

    private function sendFeatureCommand(string $imei, string $feature, ServerRequestInterface $request): Response
    {
        $whitelist = $this->whitelist();
        if (!$whitelist->isAuthorized($imei)) {
            return $this->errorResponse(
                'device_not_found',
                'Dispositivo nao encontrado ou desativado',
                404
            );
        }

        $model = $whitelist->getModel($imei);
        $caps = $model ? DeviceCapabilities::forModel($model) : null;
        if (!$caps || !$caps->supportsFeature($feature)) {
            return $this->errorResponse(
                'feature_not_supported',
                "Modelo $model nao suporta a feature $feature",
                400
            );
        }

        $type = $caps->resolveFeatureActiveCommand($feature);
        if (!$type) {
            return $this->errorResponse(
                'feature_has_no_active_command',
                "Feature $feature nao tem comando activo para o modelo $model",
                400
            );
        }

        $body = json_decode((string)$request->getBody(), true) ?: [];
        $data = $body['data'] ?? [];

        if (!$this->deviceIsOnline($imei)) {
            return $this->errorResponse(
                'device_offline',
                'Dispositivo offline ou nao encaminhavel neste momento',
                409
            );
        }

        $sentType = $this->sendFeatureCommandToDevice($imei, $feature, $data);
        if (!$sentType) {
            return $this->errorResponse(
                'device_offline',
                'Dispositivo offline ou nao encaminhavel neste momento',
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
                'Campos "imei" e "type" sao obrigatorios',
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
                "Modelo $model nao encontrado",
                404
            );
        }

        if (!$caps->supportsPassive($type)) {
            return $this->errorResponse(
                'capability_not_supported',
                "Modelo $model nao suporta o evento passivo $type",
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

    private function deviceResource(string $imei, ?array $info = null): array
    {
        $whitelist = $this->whitelist();
        $info = $info ?? ($whitelist->all()[$imei] ?? []);
        $model = $info['model'] ?? $whitelist->getModel($imei);
        $caps = $model ? DeviceCapabilities::forModel($model) : null;

        return [
            'imei' => $imei,
            'label' => $info['label'] ?? $whitelist->getLabel($imei),
            'model' => [
                'id' => $model,
                'label' => $caps?->getLabel() ?? $model,
                'supplier' => $caps?->getSupplier(),
                'protocol' => $caps?->getProtocol(),
                'transport' => $caps?->getTransport(),
            ],
            'status' => [
                'online' => $this->deviceIsOnline($imei),
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
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smartwatches 4G Demo</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f6f7f9;
            --panel: #ffffff;
            --line: #d8dde6;
            --text: #17202c;
            --muted: #667085;
            --blue: #2f6fed;
            --green: #16845b;
            --amber: #b56b12;
            --red: #b42318;
            --ink: #111827;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--text);
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            letter-spacing: 0;
        }

        header {
            min-height: 88px;
            padding: 20px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            border-bottom: 1px solid var(--line);
            background: #ffffff;
        }

        h1 {
            margin: 0;
            font-size: 24px;
            line-height: 1.15;
            font-weight: 720;
        }

        .statusbar {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--muted);
            font-size: 13px;
            white-space: nowrap;
        }

        .dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: var(--green);
            display: inline-block;
        }

        main {
            display: grid;
            grid-template-columns: minmax(260px, 320px) minmax(420px, 1fr) minmax(320px, 440px);
            gap: 16px;
            padding: 16px;
            height: calc(100vh - 88px);
        }

        section {
            min-height: 0;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .section-head {
            padding: 14px 16px;
            border-bottom: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .section-head h2 {
            margin: 0;
            font-size: 14px;
            line-height: 1.2;
            font-weight: 700;
        }

        .content {
            padding: 12px;
            overflow: auto;
            min-height: 0;
        }

        .device-list {
            display: grid;
            gap: 8px;
        }

        .device-button {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--line);
            border-radius: 6px;
            background: #ffffff;
            text-align: left;
            cursor: pointer;
            color: var(--text);
        }

        .device-button.active {
            border-color: var(--blue);
            box-shadow: inset 3px 0 0 var(--blue);
        }

        .device-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            font-weight: 650;
            font-size: 13px;
        }

        .device-meta {
            margin-top: 6px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.35;
            overflow-wrap: anywhere;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            min-height: 22px;
            padding: 2px 7px;
            border-radius: 999px;
            border: 1px solid var(--line);
            color: var(--muted);
            font-size: 12px;
            font-weight: 650;
            background: #fff;
        }

        .badge.online { color: var(--green); border-color: #9fd8c2; background: #effaf5; }
        .badge.offline { color: var(--amber); border-color: #efd0a4; background: #fff7ed; }
        .badge.disabled { color: var(--red); border-color: #f2b8b5; background: #fff1f0; }
        .badge.new { color: #175cd3; border-color: #b2ccff; background: #eff4ff; }

        .button-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(126px, 1fr));
            gap: 8px;
        }

        button.action {
            min-height: 42px;
            border: 1px solid #b9c7dc;
            border-radius: 6px;
            background: #f8fbff;
            color: #173b78;
            font-weight: 700;
            cursor: pointer;
        }

        button.action:hover { border-color: var(--blue); background: #eef5ff; }
        button.action:disabled { cursor: not-allowed; color: #98a2b3; background: #f2f4f7; border-color: var(--line); }

        .feature-row {
            margin-top: 12px;
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .feature-chip {
            padding: 5px 8px;
            border-radius: 999px;
            background: #f2f4f7;
            color: #344054;
            font-size: 12px;
            border: 1px solid #e4e7ec;
        }

        .event-list {
            display: grid;
            gap: 8px;
        }

        .event {
            border: 1px solid var(--line);
            border-radius: 6px;
            padding: 10px;
            background: #ffffff;
            transition: border-color 180ms ease, box-shadow 180ms ease, background-color 180ms ease;
        }

        .event.new-event {
            animation: event-arrived 1200ms ease-out;
            border-color: #7aa7ff;
            box-shadow: 0 0 0 3px rgba(47, 111, 237, 0.12);
        }

        @keyframes event-arrived {
            0% {
                transform: translateY(-6px);
                background: #eaf2ff;
                box-shadow: 0 0 0 4px rgba(47, 111, 237, 0.24);
            }
            45% {
                transform: translateY(0);
                background: #f4f8ff;
            }
            100% {
                transform: translateY(0);
                background: #ffffff;
                box-shadow: 0 0 0 0 rgba(47, 111, 237, 0);
            }
        }

        .event-main {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            font-size: 13px;
            font-weight: 700;
        }

        .event-sub {
            margin-top: 6px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.35;
        }

        pre {
            margin: 0;
            padding: 12px;
            overflow: auto;
            border-radius: 6px;
            background: #101828;
            color: #e6edf7;
            font-size: 12px;
            line-height: 1.45;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        #lastResponse { min-height: 180px; }
        .event pre { max-height: 140px; padding: 8px; }

        .split {
            display: grid;
            gap: 12px;
        }

        .muted {
            color: var(--muted);
            font-size: 12px;
        }

        .error {
            color: var(--red);
            font-weight: 700;
        }

        @media (max-width: 1100px) {
            main {
                height: auto;
                grid-template-columns: 1fr;
            }

            section {
                min-height: 320px;
            }
        }
    </style>
</head>
<body>
    <header>
        <div>
            <h1>Smartwatches 4G Demo</h1>
            <div class="muted">API HTTP, simulador WebSocket e eventos passivos recebidos pelo servidor</div>
        </div>
        <div class="statusbar"><span class="dot"></span><span id="connectionText">live polling</span></div>
    </header>

    <main>
        <section>
            <div class="section-head">
                <h2>Relogios</h2>
                <button class="action" id="refreshDevices" type="button">Atualizar</button>
            </div>
            <div class="content">
                <div id="devices" class="device-list"></div>
            </div>
        </section>

        <section>
            <div class="section-head">
                <h2>Simulacao</h2>
                <span class="badge" id="selectedBadge">sem selecao</span>
            </div>
            <div class="content split">
                <div class="button-grid" id="quickActions"></div>
                <div>
                    <div class="muted">Features normalizadas</div>
                    <div class="feature-row" id="features"></div>
                </div>
                <div>
                    <div class="muted">Ultima resposta HTTP</div>
                    <pre id="lastResponse">{}</pre>
                </div>
            </div>
        </section>

        <section>
            <div class="section-head">
                <h2>Eventos recebidos</h2>
                <span class="badge" id="eventCount">0</span>
            </div>
            <div class="content">
                <div id="events" class="event-list"></div>
            </div>
        </section>
    </main>

    <script>
        const state = {
            devices: [],
            selectedImei: null,
            features: {},
            events: [],
            seenEventIds: new Set(),
            newEventIds: new Set(),
            firstEventLoad: true,
        };

        const quickFeatures = [
            ['heart_rate', 'Ritmo cardiaco'],
            ['blood_pressure', 'Pressao arterial'],
            ['blood_oxygen', 'Oxigenio'],
            ['temperature', 'Temperatura'],
            ['location', 'Localizacao'],
            ['heartbeat', 'Heartbeat'],
            ['activity', 'Atividade'],
            ['battery', 'Bateria'],
            ['sleep', 'Sono'],
            ['ecg', 'ECG'],
        ];

        const $ = (id) => document.getElementById(id);

        async function api(path, options = {}) {
            const response = await fetch(path, {
                ...options,
                headers: {
                    'Content-Type': 'application/json',
                    ...(options.headers || {}),
                },
            });
            const text = await response.text();
            const data = text ? JSON.parse(text) : null;
            if (!response.ok) {
                throw data || { error: { code: 'http_error', message: response.statusText } };
            }
            return data;
        }

        function pretty(value) {
            return JSON.stringify(value, null, 2);
        }

        function selectedDevice() {
            return state.devices.find((item) => item.device.imei === state.selectedImei)?.device || null;
        }

        async function loadDevices() {
            const payload = await api('/devices');
            state.devices = payload.data || [];
            if (!state.selectedImei && state.devices.length) {
                state.selectedImei = state.devices[0].device.imei;
            }
            renderDevices();
            await loadFeatures();
        }

        async function loadFeatures() {
            const device = selectedDevice();
            if (!device) {
                state.features = {};
                renderActions();
                return;
            }

            try {
                const payload = await api(`/devices/${device.imei}/features`);
                state.features = payload.features || {};
            } catch (error) {
                state.features = {};
                $('lastResponse').textContent = pretty(error);
            }

            renderActions();
        }

        async function loadEvents() {
            try {
                const payload = await api('/events/recent?limit=40');
                const incoming = payload.data || [];
                const incomingIds = incoming
                    .map(({ event }) => event?.id)
                    .filter((id) => id !== null && id !== undefined);

                state.newEventIds = new Set(
                    state.firstEventLoad
                        ? []
                        : incomingIds.filter((id) => !state.seenEventIds.has(id))
                );
                incomingIds.forEach((id) => state.seenEventIds.add(id));
                state.firstEventLoad = false;
                state.events = incoming;
                renderEvents();
                $('connectionText').textContent = `live polling - ${new Date().toLocaleTimeString()}`;
            } catch (error) {
                $('connectionText').innerHTML = '<span class="error">sem ligacao</span>';
            }
        }

        function renderDevices() {
            $('devices').innerHTML = state.devices.map(({ device }) => {
                const active = device.imei === state.selectedImei ? ' active' : '';
                const statusClass = !device.status.enabled ? 'disabled' : (device.status.online ? 'online' : 'offline');
                const statusText = !device.status.enabled ? 'disabled' : (device.status.online ? 'online' : 'offline');
                return `
                    <button class="device-button${active}" type="button" data-imei="${device.imei}">
                        <div class="device-title">
                            <span>${escapeHtml(device.label)}</span>
                            <span class="badge ${statusClass}">${statusText}</span>
                        </div>
                        <div class="device-meta">
                            ${device.imei}<br>
                            ${escapeHtml(device.model.id)} · ${escapeHtml(device.model.protocol || '')}
                        </div>
                    </button>
                `;
            }).join('');

            document.querySelectorAll('.device-button').forEach((button) => {
                button.addEventListener('click', async () => {
                    state.selectedImei = button.dataset.imei;
                    renderDevices();
                    await loadFeatures();
                });
            });
        }

        function renderActions() {
            const device = selectedDevice();
            $('selectedBadge').textContent = device ? device.model.id : 'sem selecao';

            $('features').innerHTML = Object.keys(state.features).map((feature) => (
                `<span class="feature-chip">${escapeHtml(feature)}</span>`
            )).join('');

            $('quickActions').innerHTML = quickFeatures.map(([feature, label]) => {
                const passiveTypes = state.features[feature]?.passiveTypes || [];
                const nativeType = passiveTypes[0] || '';
                const disabled = !device || !nativeType || !device.status.enabled;
                return `
                    <button class="action" type="button" data-type="${escapeHtml(nativeType)}" ${disabled ? 'disabled' : ''}>
                        ${escapeHtml(label)}
                    </button>
                `;
            }).join('');

            document.querySelectorAll('#quickActions button').forEach((button) => {
                button.addEventListener('click', () => simulate(button.dataset.type));
            });
        }

        function renderEvents() {
            $('eventCount').textContent = String(state.events.length);
            if (!state.events.length) {
                $('events').innerHTML = '<div class="muted">Sem eventos ainda.</div>';
                return;
            }

            $('events').innerHTML = state.events.map(({ device, event }) => {
                const normalized = Object.keys(event.normalized || {}).length
                    ? pretty(event.normalized)
                    : pretty(event.nativePayload || {});
                const isNew = state.newEventIds.has(event.id);
                return `
                    <div class="event${isNew ? ' new-event' : ''}" data-event-id="${escapeHtml(event.id || '')}">
                        <div class="event-main">
                            <span>${escapeHtml(event.feature || event.nativeType || 'evento')}</span>
                            <span class="badge ${isNew ? 'new' : ''}">${escapeHtml(isNew ? 'novo' : (event.nativeType || ''))}</span>
                        </div>
                        <div class="event-sub">
                            ${escapeHtml(device.label)} · ${escapeHtml(device.imei)}<br>
                            ${new Date(event.receivedAt).toLocaleTimeString()}
                        </div>
                        <div class="event-sub"><pre>${escapeHtml(normalized)}</pre></div>
                    </div>
                `;
            }).join('');
        }

        async function simulate(nativeType) {
            const device = selectedDevice();
            if (!device || !nativeType) return;

            const body = {
                imei: device.imei,
                model: device.model.id,
                type: nativeType,
            };

            try {
                const response = await api('/demo/simulate', {
                    method: 'POST',
                    body: JSON.stringify(body),
                });
                $('lastResponse').textContent = pretty(response);
                setTimeout(loadEvents, 450);
                setTimeout(loadDevices, 900);
            } catch (error) {
                $('lastResponse').textContent = pretty(error);
            }
        }

        function escapeHtml(value) {
            return String(value ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        }

        $('refreshDevices').addEventListener('click', loadDevices);

        loadDevices();
        loadEvents();
        setInterval(loadEvents, 1000);
        setInterval(loadDevices, 5000);
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
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Multi-Vendor Relogios 4G</title>
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
