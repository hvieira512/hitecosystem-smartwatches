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
use App\Repository\ModelRepository;
use App\Repository\SupplierRepository;
use App\Log\Logger;
use App\Redis\Client as RedisClient;
use App\Protocol\AdapterRegistry;

class ApiServer
{
    private ?WatchServer $watchServer;
    private ?Whitelist $whitelist;
    private ?\PDO $pdo;
    private ?DeviceRepository $deviceRepo;
    private ?EventRepository $eventsRepo;
    private ?ModelRepository $modelRepo;
    private ?SupplierRepository $supplierRepo;
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
        $this->modelRepo = $pdo ? new ModelRepository($pdo) : null;
        $this->supplierRepo = $pdo ? new SupplierRepository($pdo) : null;
        $this->redis = $redis;
        $this->whitelist = null;

        DeviceCapabilities::setDatabasePdo($pdo);
        DeviceCapabilities::setCacheTtl((int)(getenv('MODEL_CACHE_TTL_SECONDS') ?: 5));

        $envWsServerUrl = getenv('WS_SERVER_URL');
        $this->wsServerUrl = $wsServerUrl
            ?: (($envWsServerUrl !== false && $envWsServerUrl !== '')
                ? $envWsServerUrl
                : 'ws://127.0.0.1:8080');

        $this->http = new HttpServer($loop, \Closure::fromCallable([$this, 'handleRequest']));
        $this->socket = new SocketServer("$host:$port", [], $loop);
        $this->http->listen($this->socket);

        Logger::channel('api')->info("HTTP API at http://$host:$port");
        Logger::channel('api')->info("WS server URL: {$this->wsServerUrl}");
        if ($watchServer === null) {
            Logger::channel('api')->info('Separate mode: commands are sent via Redis Stream');
        }
    }

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
        if (!$model) {
            return null;
        }
        $caps = DeviceCapabilities::forModel($model);
        return $caps?->resolveFeatureActiveCommand($feature);
    }

    private function sendFeatureCommandToDevice(string $imei, string $feature, array $data = []): ?string
    {
        if ($this->watchServer !== null) {
            return $this->watchServer->sendFeatureCommand($imei, $feature, $data);
        }
        $type = $this->resolveFeatureCommand($imei, $feature);
        if ($type === null) {
            return null;
        }
        return $this->sendCommandToDevice($imei, $type, $data) ? $type : null;
    }

    private function handleRequest(ServerRequestInterface $request): Response
    {
        $method = $request->getMethod();
        $path = rtrim($request->getUri()->getPath(), '/');

        if ($method === 'OPTIONS') {
            return new Response(204, [
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type',
                'Access-Control-Max-Age' => '86400',
            ]);
        }

        try {
            return match (true) {
                $method === 'GET' && $path === '/devices' => $this->listDevices($request),
                $method === 'POST' && $path === '/devices' => $this->createDevice($request),
                $method === 'GET' && preg_match('#^/devices/([^/]+)$#', $path, $m) === 1 => $this->getDevice($m[1]),
                $method === 'PUT' && preg_match('#^/devices/([^/]+)$#', $path, $m) === 1 => $this->updateDevice($m[1], $request),
                $method === 'DELETE' && preg_match('#^/devices/([^/]+)$#', $path, $m) === 1 => $this->deleteDevice($m[1]),

                $method === 'GET' && $path === '/suppliers' => $this->listSuppliers($request),
                $method === 'POST' && $path === '/suppliers' => $this->createSupplier($request),
                $method === 'GET' && preg_match('#^/suppliers/(\d+)$#', $path, $m) === 1 => $this->getSupplier((int)$m[1]),
                $method === 'PUT' && preg_match('#^/suppliers/(\d+)$#', $path, $m) === 1 => $this->updateSupplier((int)$m[1], $request),
                $method === 'DELETE' && preg_match('#^/suppliers/(\d+)$#', $path, $m) === 1 => $this->deleteSupplier((int)$m[1]),

                $method === 'GET' && $path === '/models' => $this->listModels($request),
                $method === 'POST' && $path === '/models' => $this->createModel($request),
                $method === 'GET' && preg_match('#^/models/([^/]+)$#', $path, $m) === 1 => $this->getModel($m[1]),
                $method === 'PUT' && preg_match('#^/models/([^/]+)$#', $path, $m) === 1 => $this->updateModel($m[1], $request),
                $method === 'DELETE' && preg_match('#^/models/([^/]+)$#', $path, $m) === 1 => $this->deleteModel($m[1]),

                $method === 'GET' && $path === '/events/recent' => $this->recentEvents($request),
                $method === 'GET' && preg_match('#^/devices/([^/]+)/events/latest$#', $path, $m) === 1 => $this->latestDeviceEvent($m[1]),
                $method === 'GET' && preg_match('#^/devices/([^/]+)/features$#', $path, $m) === 1 => $this->deviceFeatures($m[1]),
                $method === 'POST' && preg_match('#^/devices/([^/]+)/command$#', $path, $m) === 1 => $this->sendCommand($m[1], $request),
                $method === 'POST' && preg_match('#^/devices/([^/]+)/features/([^/]+)/command$#', $path, $m) === 1 => $this->sendFeatureCommand($m[1], $m[2], $request),

                $method === 'GET' && $path === '/health' => $this->healthCheck(),
                $method === 'GET' && $path === '/metrics' => $this->metricsEndpoint(),
                $method === 'GET' && $path === '/demo' => $this->demoPage(),
                $method === 'POST' && $path === '/demo/simulate' => $this->simulateDeviceEvent($request),
                $method === 'POST' && $path === '/demo/listener' => $this->startDemoListener($request),
                $method === 'GET' && $path === '/demo/listeners' => $this->demoListeners(),
                $method === 'DELETE' && preg_match('#^/demo/listener/([^/]+)$#', $path, $m) === 1 => $this->stopDemoListener($m[1]),
                $method === 'GET' && $path === '/openapi.json' => $this->openApiSpec(),
                $method === 'GET' && $path === '/docs' => $this->swaggerUi(),
                default => $this->errorResponse('not_found', 'Endpoint not found', 404),
            };
        } catch (\Throwable $e) {
            return $this->errorResponse('internal_error', $e->getMessage(), 500);
        }
    }

    private function listDevices(ServerRequestInterface $request): Response
    {
        $query = $request->getQueryParams();
        $page = $this->parsePage($query['page'] ?? null);
        $limit = $this->parseLimit($query['limit'] ?? null);
        $filters = [
            'imei' => isset($query['imei']) ? trim((string)$query['imei']) : null,
            'model' => isset($query['model']) ? trim((string)$query['model']) : null,
            'enabled' => $this->parseNullableBool($query['enabled'] ?? null),
            'online' => $this->parseNullableBool($query['online'] ?? null),
        ];

        $devices = [];
        if ($this->deviceRepo !== null) {
            $devices = $this->deviceResourcesFromRows($this->deviceRepo->all());
        } else {
            foreach ($this->whitelist()->all() as $imei => $info) {
                $devices[] = $this->deviceResource($imei, is_array($info) ? $info : ['model' => $info]);
            }
        }

        $devices = $this->applyDeviceFilters($devices, $filters);
        $total = count($devices);
        $devices = array_slice($devices, ($page - 1) * $limit, $limit);

        return $this->jsonResponse([
            'data' => $devices,
            'pagination' => $this->paginationResource($page, $limit, $total),
            'filters' => $filters,
        ]);
    }

    private function getDevice(string $imei): Response
    {
        $info = $this->whitelist()->all()[$imei] ?? null;
        if ($info === null) {
            return $this->errorResponse('device_not_found', 'Device not found', 404);
        }

        return $this->jsonResponse(['data' => $this->deviceResource($imei, (array)$info)]);
    }

    private function createDevice(ServerRequestInterface $request): Response
    {
        $body = json_decode((string)$request->getBody(), true);
        if (!is_array($body) || !isset($body['imei']) || !isset($body['model'])) {
            return $this->errorResponse('invalid_request', 'Fields "imei" and "model" are required', 400);
        }

        $imei = trim((string)$body['imei']);
        $model = trim((string)$body['model']);
        $enabled = isset($body['enabled']) ? (bool)$body['enabled'] : true;

        if ($imei === '' || $model === '') {
            return $this->errorResponse('invalid_request', 'Fields "imei" and "model" must be non-empty', 400);
        }

        if ($this->whitelist()->isAuthorized($imei)) {
            return $this->errorResponse('device_already_exists', "Device $imei is already registered", 409);
        }

        $modelError = $this->validateModelForDeviceAssignment($model);
        if ($modelError !== null) {
            return $modelError;
        }

        $this->whitelist()->register($imei, $model, $enabled);
        Logger::channel('api')->info("Device registered: IMEI=$imei model=$model");

        return $this->jsonResponse(['data' => $this->deviceResource($imei)], 201);
    }

    private function updateDevice(string $imei, ServerRequestInterface $request): Response
    {
        $all = $this->whitelist()->all();
        if (!isset($all[$imei])) {
            return $this->errorResponse('device_not_found', 'Device not found', 404);
        }

        $body = json_decode((string)$request->getBody(), true);
        if (!is_array($body)) {
            return $this->errorResponse('invalid_request', 'Request body is required', 400);
        }

        $data = [];
        if (isset($body['model'])) {
            $model = trim((string)$body['model']);
            $modelError = $this->validateModelForDeviceAssignment($model);
            if ($modelError !== null) {
                return $modelError;
            }
            $data['model'] = $model;
        }
        if (array_key_exists('enabled', $body)) {
            $data['enabled'] = (bool)$body['enabled'];
        }

        if ($data === []) {
            return $this->errorResponse('invalid_request', 'At least one field (model, enabled) must be provided', 400);
        }

        $this->whitelist()->update($imei, $data);
        Logger::channel('api')->info("Device updated: IMEI=$imei data=" . json_encode($data));

        return $this->jsonResponse(['data' => $this->deviceResource($imei)]);
    }

    private function deleteDevice(string $imei): Response
    {
        $all = $this->whitelist()->all();
        if (!isset($all[$imei])) {
            return $this->errorResponse('device_not_found', 'Device not found', 404);
        }

        $this->whitelist()->unregister($imei);
        return $this->jsonResponse(['status' => 'deleted', 'imei' => $imei]);
    }

    private function listSuppliers(ServerRequestInterface $request): Response
    {
        if ($this->supplierRepo === null) {
            return $this->errorResponse('mysql_unavailable', 'MySQL is not available', 503);
        }

        $query = $request->getQueryParams();
        $page = $this->parsePage($query['page'] ?? null);
        $limit = $this->parseLimit($query['limit'] ?? null);
        $filters = [
            'name' => isset($query['name']) ? trim((string)$query['name']) : null,
            'enabled' => $this->parseNullableBool($query['enabled'] ?? null),
        ];

        $rows = $this->supplierRepo->list($filters, $page, $limit);
        $total = $this->supplierRepo->countFiltered($filters);

        return $this->jsonResponse([
            'data' => array_map(fn(array $row): array => $this->supplierResource($row), $rows),
            'pagination' => $this->paginationResource($page, $limit, $total),
            'filters' => $filters,
        ]);
    }

    private function getSupplier(int $id): Response
    {
        if ($this->supplierRepo === null) {
            return $this->errorResponse('mysql_unavailable', 'MySQL is not available', 503);
        }

        $row = $this->supplierRepo->find($id);
        if (!$row) {
            return $this->errorResponse('supplier_not_found', 'Supplier not found', 404);
        }

        return $this->jsonResponse(['data' => $this->supplierResource($row)]);
    }

    private function createSupplier(ServerRequestInterface $request): Response
    {
        if ($this->supplierRepo === null) {
            return $this->errorResponse('mysql_unavailable', 'MySQL is not available', 503);
        }

        $body = json_decode((string)$request->getBody(), true);
        if (!is_array($body) || !isset($body['name'])) {
            return $this->errorResponse('invalid_request', 'Field "name" is required', 400);
        }

        $name = mb_substr(trim((string)$body['name']), 0, 50);
        if ($name === '') {
            return $this->errorResponse('invalid_request', 'Field "name" must be a non-empty string', 400);
        }

        if ($this->supplierRepo->findByName($name)) {
            return $this->errorResponse('supplier_already_exists', "Supplier $name already exists", 409);
        }

        $id = $this->supplierRepo->insert([
            'name' => $name,
            'enabled' => isset($body['enabled']) ? (bool)$body['enabled'] : true,
        ]);

        return $this->jsonResponse(['data' => $this->supplierResource($this->supplierRepo->find($id) ?: ['id' => $id, 'name' => $name, 'enabled' => true])], 201);
    }

    private function updateSupplier(int $id, ServerRequestInterface $request): Response
    {
        if ($this->supplierRepo === null) {
            return $this->errorResponse('mysql_unavailable', 'MySQL is not available', 503);
        }

        $existing = $this->supplierRepo->find($id);
        if (!$existing) {
            return $this->errorResponse('supplier_not_found', 'Supplier not found', 404);
        }

        $body = json_decode((string)$request->getBody(), true);
        if (!is_array($body)) {
            return $this->errorResponse('invalid_request', 'Request body must be valid JSON', 400);
        }

        $data = [];
        if (array_key_exists('name', $body)) {
            $name = mb_substr(trim((string)$body['name']), 0, 50);
            if ($name === '') {
                return $this->errorResponse('invalid_request', 'Field "name" must be non-empty', 400);
            }
            $other = $this->supplierRepo->findByName($name);
            if ($other && (int)$other['id'] !== $id) {
                return $this->errorResponse('supplier_already_exists', "Supplier $name already exists", 409);
            }
            $data['name'] = $name;
        }
        if (array_key_exists('enabled', $body)) {
            if (!is_bool($body['enabled'])) {
                return $this->errorResponse('invalid_request', 'Field "enabled" must be boolean', 400);
            }
            $data['enabled'] = $body['enabled'];
        }

        if ($data === []) {
            return $this->errorResponse('invalid_request', 'At least one field (name, enabled) must be provided', 400);
        }

        $this->supplierRepo->update($id, $data);
        return $this->jsonResponse(['data' => $this->supplierResource($this->supplierRepo->find($id) ?: $existing)]);
    }

    private function deleteSupplier(int $id): Response
    {
        if ($this->supplierRepo === null) {
            return $this->errorResponse('mysql_unavailable', 'MySQL is not available', 503);
        }

        $existing = $this->supplierRepo->find($id);
        if (!$existing) {
            return $this->errorResponse('supplier_not_found', 'Supplier not found', 404);
        }

        $modelsCount = $this->supplierRepo->countModelsUsingSupplier($id);
        if ($modelsCount > 0) {
            return $this->errorResponse('supplier_in_use', 'Supplier is in use by models', 409, ['modelsCount' => $modelsCount]);
        }

        $this->supplierRepo->delete($id);
        return $this->jsonResponse(['status' => 'deleted', 'data' => $this->supplierResource($existing)]);
    }

    private function supplierResource(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'name' => (string)($row['name'] ?? ''),
            'enabled' => (bool)($row['enabled'] ?? true),
            'createdAt' => $row['created_at'] ?? null,
            'updatedAt' => $row['updated_at'] ?? null,
        ];
    }

    private function listModels(ServerRequestInterface $request): Response
    {
        if ($this->modelRepo === null) {
            return $this->errorResponse('mysql_unavailable', 'MySQL is not available', 503);
        }

        $query = $request->getQueryParams();
        $page = $this->parsePage($query['page'] ?? null);
        $limit = $this->parseLimit($query['limit'] ?? null);
        $filters = [
            'code' => isset($query['code']) ? trim((string)$query['code']) : null,
            'name' => isset($query['name']) ? trim((string)$query['name']) : null,
            'supplierId' => isset($query['supplierId']) && $query['supplierId'] !== '' ? (int)$query['supplierId'] : null,
            'supplierName' => isset($query['supplier']) ? trim((string)$query['supplier']) : null,
            'protocol' => isset($query['protocol']) ? trim((string)$query['protocol']) : null,
            'transport' => isset($query['transport']) ? trim((string)$query['transport']) : null,
            'enabled' => $this->parseNullableBool($query['enabled'] ?? null),
        ];

        $rows = $this->modelRepo->list($filters, $page, $limit);
        $total = $this->modelRepo->countFiltered($filters);

        return $this->jsonResponse([
            'data' => array_map(fn(array $row): array => $this->modelResource($row), $rows),
            'pagination' => $this->paginationResource($page, $limit, $total),
            'filters' => $filters,
        ]);
    }

    private function getModel(string $code): Response
    {
        if ($this->modelRepo === null) {
            return $this->errorResponse('mysql_unavailable', 'MySQL is not available', 503);
        }

        $row = $this->modelRepo->findByCode($code);
        if (!$row) {
            return $this->errorResponse('model_not_found', 'Model not found', 404);
        }

        return $this->jsonResponse(['data' => $this->modelResource($row)]);
    }

    private function createModel(ServerRequestInterface $request): Response
    {
        if ($this->modelRepo === null || $this->supplierRepo === null) {
            return $this->errorResponse('mysql_unavailable', 'MySQL is not available', 503);
        }

        $body = json_decode((string)$request->getBody(), true);
        if (!is_array($body)) {
            return $this->errorResponse('invalid_request', 'Request body must be valid JSON', 400);
        }

        foreach (['code', 'name', 'supplierId', 'protocol', 'transport', 'passive', 'active', 'features'] as $required) {
            if (!array_key_exists($required, $body)) {
                return $this->errorResponse('invalid_request', "Field \"$required\" is required", 400);
            }
        }

        $code = trim((string)$body['code']);
        if ($code === '') {
            return $this->errorResponse('invalid_request', 'Field "code" must be a non-empty string', 400);
        }

        if ($this->modelRepo->existsCode($code)) {
            return $this->errorResponse('model_already_exists', "Model $code already exists", 409);
        }

        $normalized = $this->normalizeModelPayload($body, true);
        if (isset($normalized['error'])) {
            return $this->errorResponse('invalid_request', $normalized['error'], 400, $normalized['details'] ?? []);
        }

        $supplier = $this->supplierRepo->find((int)$normalized['data']['supplier_id']);
        if (!$supplier) {
            return $this->errorResponse('supplier_not_found', 'Supplier not found', 404);
        }

        $data = $normalized['data'];
        $data['code'] = $code;
        $this->modelRepo->insert($data);

        return $this->jsonResponse(['data' => $this->modelResource($this->modelRepo->findByCode($code) ?: $data)], 201);
    }

    private function updateModel(string $code, ServerRequestInterface $request): Response
    {
        if ($this->modelRepo === null || $this->supplierRepo === null) {
            return $this->errorResponse('mysql_unavailable', 'MySQL is not available', 503);
        }

        $existing = $this->modelRepo->findByCode($code);
        if (!$existing) {
            return $this->errorResponse('model_not_found', 'Model not found', 404);
        }

        $body = json_decode((string)$request->getBody(), true);
        if (!is_array($body)) {
            return $this->errorResponse('invalid_request', 'Request body must be valid JSON', 400);
        }

        if (array_key_exists('code', $body)) {
            return $this->errorResponse('invalid_request', 'Field "code" is immutable', 400);
        }

        $normalized = $this->normalizeModelPayload($body, false, $existing);
        if (isset($normalized['error'])) {
            return $this->errorResponse('invalid_request', $normalized['error'], 400, $normalized['details'] ?? []);
        }

        $data = $normalized['data'];
        if ($data === []) {
            return $this->errorResponse('invalid_request', 'At least one updatable field must be provided', 400);
        }

        if (isset($data['supplier_id']) && !$this->supplierRepo->find((int)$data['supplier_id'])) {
            return $this->errorResponse('supplier_not_found', 'Supplier not found', 404);
        }

        $this->modelRepo->updateByCode($code, $data);
        return $this->jsonResponse(['data' => $this->modelResource($this->modelRepo->findByCode($code) ?: array_merge($existing, $data))]);
    }

    private function deleteModel(string $code): Response
    {
        if ($this->modelRepo === null) {
            return $this->errorResponse('mysql_unavailable', 'MySQL is not available', 503);
        }

        $existing = $this->modelRepo->findByCode($code);
        if (!$existing) {
            return $this->errorResponse('model_not_found', 'Model not found', 404);
        }

        $devicesCount = $this->modelRepo->countDevicesUsingModelCode($code);
        if ($devicesCount > 0) {
            return $this->errorResponse('model_in_use', "Model $code is in use by registered devices", 409, ['devicesCount' => $devicesCount]);
        }

        $this->modelRepo->deleteByCode($code);
        return $this->jsonResponse(['status' => 'deleted', 'data' => $this->modelResource($existing)]);
    }

    private function modelResource(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'code' => $row['code'] ?? null,
            'name' => $row['name'] ?? null,
            'supplier' => [
                'id' => (int)($row['supplier_id'] ?? 0),
                'name' => $row['supplier_name'] ?? null,
            ],
            'protocol' => $row['protocol'] ?? null,
            'transport' => $row['transport'] ?? null,
            'sourceDoc' => $row['source_doc'] ?? null,
            'enabled' => (bool)($row['enabled'] ?? true),
            'passive' => array_values($row['passive'] ?? []),
            'active' => array_values($row['active'] ?? []),
            'features' => $row['features'] ?? [],
            'createdAt' => $row['created_at'] ?? null,
            'updatedAt' => $row['updated_at'] ?? null,
        ];
    }

    private function recentEvents(ServerRequestInterface $request): Response
    {
        $query = $request->getQueryParams();
        $limit = isset($query['limit']) ? max(1, min(200, (int)$query['limit'])) : 50;
        $afterId = isset($query['after']) ? (int)$query['after'] : null;

        $events = array_map(function (array $event): array {
            $imei = $event['imei'] ?? '';
            return ['device' => $this->deviceResource($imei), 'event' => $this->eventResource($event)];
        }, $this->recentEventsFromServer($limit, $afterId));

        return $this->jsonResponse([
            'data' => $events,
            'meta' => ['count' => count($events), 'limit' => $limit],
        ]);
    }

    private function latestDeviceEvent(string $imei): Response
    {
        if (!$this->whitelist()->isAuthorized($imei)) {
            return $this->errorResponse('device_not_found', 'Device not found or disabled', 404);
        }

        $data = $this->deviceData($imei);
        if (!$data && !$this->deviceIsOnline($imei)) {
            return $this->errorResponse('no_data', 'No event available for this device', 404);
        }

        return $this->jsonResponse([
            'device' => $this->deviceResource($imei),
            'event' => $data ? $this->eventResource($data) : null,
        ]);
    }

    private function sendCommand(string $imei, ServerRequestInterface $request): Response
    {
        if (!$this->whitelist()->isAuthorized($imei)) {
            return $this->errorResponse('device_not_found', 'Device not found or disabled', 404);
        }

        $body = json_decode((string)$request->getBody(), true);
        if (!is_array($body) || !isset($body['type'])) {
            return $this->errorResponse('invalid_request', 'The "type" field is required in the JSON body', 400);
        }

        $type = (string)$body['type'];
        $data = isset($body['data']) && is_array($body['data']) ? $body['data'] : [];

        if (!$this->deviceSupportsActiveCommand($imei, $type)) {
            return $this->errorResponse('command_not_supported', "Device does not support command $type", 400);
        }

        if (!$this->deviceIsOnline($imei)) {
            return $this->errorResponse('device_offline', 'Device is offline or cannot be routed right now', 409);
        }

        if (!$this->sendCommandToDevice($imei, $type, $data)) {
            return $this->errorResponse('device_offline', 'Device is offline or cannot be routed right now', 409);
        }

        return $this->jsonResponse([
            'status' => 'sent',
            'device' => $this->deviceResource($imei),
            'command' => ['feature' => null, 'nativeType' => $type, 'payload' => $data],
        ]);
    }

    private function deviceFeatures(string $imei): Response
    {
        if (!$this->whitelist()->isAuthorized($imei)) {
            return $this->errorResponse('device_not_found', 'Device not found or disabled', 404);
        }

        $model = $this->whitelist()->getModel($imei);
        $caps = $model ? DeviceCapabilities::forModel($model) : null;
        if (!$caps) {
            return $this->errorResponse('model_not_found', 'Device model not found', 404);
        }

        return $this->jsonResponse([
            'device' => $this->deviceResource($imei),
            'features' => $this->featureResources($caps->getFeatures()),
            'nativeCommands' => ['passive' => $caps->getPassive(), 'active' => $caps->getActive()],
        ]);
    }

    private function sendFeatureCommand(string $imei, string $feature, ServerRequestInterface $request): Response
    {
        if (!$this->whitelist()->isAuthorized($imei)) {
            return $this->errorResponse('device_not_found', 'Device not found or disabled', 404);
        }

        $model = $this->whitelist()->getModel($imei);
        $caps = $model ? DeviceCapabilities::forModel($model) : null;
        if (!$caps || !$caps->supportsFeature($feature)) {
            return $this->errorResponse('feature_not_supported', "Model $model does not support feature $feature", 400);
        }

        $type = $caps->resolveFeatureActiveCommand($feature);
        if (!$type) {
            return $this->errorResponse('feature_has_no_active_command', "Feature $feature has no active command for model $model", 400);
        }

        $body = json_decode((string)$request->getBody(), true) ?: [];
        $data = isset($body['data']) && is_array($body['data']) ? $body['data'] : [];

        if (!$this->deviceIsOnline($imei)) {
            return $this->errorResponse('device_offline', 'Device is offline or cannot be routed right now', 409);
        }

        $sentType = $this->sendFeatureCommandToDevice($imei, $feature, $data);
        if (!$sentType) {
            return $this->errorResponse('device_offline', 'Device is offline or cannot be routed right now', 409);
        }

        return $this->jsonResponse([
            'status' => 'sent',
            'device' => $this->deviceResource($imei),
            'command' => ['feature' => $feature, 'nativeType' => $sentType, 'payload' => $data],
        ]);
    }

    private function simulateDeviceEvent(ServerRequestInterface $request): Response
    {
        $body = json_decode((string)$request->getBody(), true) ?: [];
        $imei = (string)($body['imei'] ?? '');
        $type = (string)($body['type'] ?? '');
        $data = isset($body['data']) && is_array($body['data']) ? $body['data'] : null;

        if ($imei === '' || $type === '') {
            return $this->errorResponse('invalid_request', 'Fields "imei" and "type" are required', 400);
        }

        $whitelist = $this->whitelist();
        $deviceInfo = $whitelist->all()[$imei] ?? null;
        $model = (string)($body['model'] ?? ($deviceInfo['model'] ?? ''));
        $caps = $model !== '' ? DeviceCapabilities::forModel($model) : null;

        if (!$caps) {
            return $this->errorResponse('model_not_found', "Model $model not found", 404);
        }

        if (!$caps->supportsPassive($type)) {
            return $this->errorResponse(
                'capability_not_supported',
                "Model $model does not support passive event $type",
                400,
                ['supportedPassiveTypes' => $caps->getPassive()]
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
            return $this->errorResponse('invalid_request', 'Field "imei" is required', 400);
        }

        $whitelist = $this->whitelist();
        if (!$whitelist->isAuthorized($imei)) {
            return $this->errorResponse('device_not_found', 'Device not found or disabled', 404);
        }

        $model = $whitelist->getModel($imei);
        if (!$model || !DeviceCapabilities::forModel($model)) {
            return $this->errorResponse('model_not_found', 'Device model not found', 404);
        }

        $this->pruneDemoListeners();
        if (isset($this->demoListeners[$imei])) {
            return $this->jsonResponse(['status' => 'already_running', 'listener' => $this->listenerResource($this->demoListeners[$imei])]);
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
            return $this->errorResponse('listener_start_failed', 'Failed to start demo watch listener', 500, ['logPath' => $logPath]);
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

        return $this->jsonResponse(['status' => 'started', 'listener' => $this->listenerResource($listener)], 201);
    }

    private function stopDemoListener(string $imei): Response
    {
        $this->pruneDemoListeners();
        if (!isset($this->demoListeners[$imei])) {
            return $this->errorResponse('listener_not_found', 'No managed demo watch listener found for this IMEI', 404);
        }

        $listener = $this->demoListeners[$imei];
        $pid = (int)$listener['pid'];
        if ($pid > 0 && $this->processIsRunning($pid)) {
            exec('kill ' . $pid);
        }

        unset($this->demoListeners[$imei]);
        return $this->jsonResponse(['status' => 'stopped', 'listener' => $this->listenerResource($listener, false)]);
    }

    private function demoListeners(): Response
    {
        $this->pruneDemoListeners();
        $listeners = array_map(fn(array $listener): array => $this->listenerResource($listener), array_values($this->demoListeners));
        return $this->jsonResponse(['data' => $listeners, 'meta' => ['count' => count($listeners)]]);
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
        $modelCode = $info['model'] ?? $whitelist->getModel($imei);
        $caps = $modelCode ? DeviceCapabilities::forModel($modelCode) : null;

        return [
            'imei' => $imei,
            'model' => $modelCode,
            'supplier' => $caps?->getSupplier(),
            'protocol' => $caps?->getProtocol(),
            'transport' => $caps?->getTransport(),
            'online' => $this->deviceIsOnline($imei),
            'enabled' => (bool)($info['enabled'] ?? $whitelist->isAuthorized($imei)),
            'registeredAt' => $info['registered_at'] ?? null,
        ];
    }

    private function deviceResourcesFromRows(array $rows): array
    {
        return array_map(function (array $row): array {
            $info = [
                'model' => $row['model_code'] ?? null,
                'enabled' => $row['enabled'] ?? true,
                'registered_at' => $row['registered_at'] ?? null,
            ];
            return $this->deviceResource((string)$row['imei'], $info);
        }, $rows);
    }

    private function applyDeviceFilters(array $devices, array $filters): array
    {
        return array_values(array_filter($devices, function (array $device) use ($filters): bool {
            if (($filters['imei'] ?? null) !== null && $filters['imei'] !== '' && !str_contains($device['imei'], $filters['imei'])) {
                return false;
            }
            if (($filters['model'] ?? null) !== null && $filters['model'] !== '' && strtolower((string)$device['model']) !== strtolower($filters['model'])) {
                return false;
            }
            if (($filters['enabled'] ?? null) !== null && (bool)$device['enabled'] !== (bool)$filters['enabled']) {
                return false;
            }
            if (($filters['online'] ?? null) !== null && (bool)$device['online'] !== (bool)$filters['online']) {
                return false;
            }
            return true;
        }));
    }

    private function validateModelForDeviceAssignment(string $model): ?Response
    {
        if ($model === '') {
            return $this->errorResponse('model_not_found', 'Model is required', 400);
        }

        if ($this->modelRepo !== null) {
            $record = $this->modelRepo->findByCode($model);
            if (!$record) {
                return $this->errorResponse('model_not_found', "Unknown device model: $model", 400, ['availableModels' => DeviceCapabilities::allModels()]);
            }
            if (!(bool)$record['enabled']) {
                return $this->errorResponse('model_disabled', "Model $model is disabled", 400);
            }
            return null;
        }

        $caps = DeviceCapabilities::forModel($model);
        if (!$caps) {
            return $this->errorResponse('model_not_found', "Unknown device model: $model", 400, ['availableModels' => DeviceCapabilities::allModels()]);
        }

        return null;
    }

    private function parsePage(mixed $value): int
    {
        $page = (int)$value;
        return $page > 0 ? $page : 1;
    }

    private function parseLimit(mixed $value): int
    {
        $limit = (int)$value;
        if ($limit <= 0) {
            return 25;
        }
        return min(200, $limit);
    }

    private function parseNullableBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string)$value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return null;
    }

    private function normalizeModelPayload(array $body, bool $create, ?array $existing = null): array
    {
        $data = [];
        $merged = $existing ?? [];

        if (array_key_exists('supplierId', $body)) {
            $supplierId = (int)$body['supplierId'];
            if ($supplierId <= 0) {
                return ['error' => 'Field "supplierId" must be a positive integer'];
            }
            $data['supplier_id'] = $supplierId;
            $merged['supplier_id'] = $supplierId;
        } elseif (array_key_exists('supplier_id', $body)) {
            $supplierId = (int)$body['supplier_id'];
            if ($supplierId <= 0) {
                return ['error' => 'Field "supplier_id" must be a positive integer'];
            }
            $data['supplier_id'] = $supplierId;
            $merged['supplier_id'] = $supplierId;
        } elseif ($create) {
            return ['error' => 'Field "supplierId" is required'];
        }

        foreach (['name', 'protocol', 'transport'] as $field) {
            if (array_key_exists($field, $body)) {
                $value = trim((string)$body[$field]);
                if ($value === '') {
                    return ['error' => "Field \"$field\" must be a non-empty string"];
                }
                $data[$field] = $value;
                $merged[$field] = $value;
            } elseif ($create) {
                return ['error' => "Field \"$field\" is required"];
            }
        }

        if (array_key_exists('sourceDoc', $body)) {
            if ($body['sourceDoc'] !== null && !is_string($body['sourceDoc'])) {
                return ['error' => 'Field "sourceDoc" must be string or null'];
            }
            $data['source_doc'] = $body['sourceDoc'];
            $merged['source_doc'] = $body['sourceDoc'];
        } elseif (array_key_exists('source_doc', $body)) {
            if ($body['source_doc'] !== null && !is_string($body['source_doc'])) {
                return ['error' => 'Field "source_doc" must be string or null'];
            }
            $data['source_doc'] = $body['source_doc'];
            $merged['source_doc'] = $body['source_doc'];
        } elseif ($create) {
            $data['source_doc'] = null;
            $merged['source_doc'] = null;
        }

        if (array_key_exists('enabled', $body)) {
            if (!is_bool($body['enabled'])) {
                return ['error' => 'Field "enabled" must be boolean'];
            }
            $data['enabled'] = $body['enabled'];
            $merged['enabled'] = $body['enabled'];
        } elseif ($create) {
            $data['enabled'] = true;
            $merged['enabled'] = true;
        }

        foreach (['passive', 'active'] as $field) {
            if (array_key_exists($field, $body)) {
                if (!is_array($body[$field])) {
                    return ['error' => "Field \"$field\" must be an array of unique strings"];
                }
                $normalized = [];
                foreach ($body[$field] as $item) {
                    if (!is_string($item) || trim($item) === '') {
                        return ['error' => "Field \"$field\" must contain only non-empty strings"];
                    }
                    $normalized[] = trim($item);
                }
                $normalized = array_values(array_unique($normalized));
                $data[$field] = $normalized;
                $merged[$field] = $normalized;
            } elseif ($create) {
                return ['error' => "Field \"$field\" is required"];
            }
        }

        if (array_key_exists('features', $body)) {
            if (!is_array($body['features'])) {
                return ['error' => 'Field "features" must be an object'];
            }
            try {
                $data['features'] = $this->normalizeFeaturesObject($body['features']);
            } catch (\InvalidArgumentException $e) {
                return ['error' => $e->getMessage()];
            }
            $merged['features'] = $data['features'];
        } elseif ($create) {
            return ['error' => 'Field "features" is required'];
        }

        if (($merged['protocol'] ?? '') !== '' && !$this->isSupportedProtocol((string)$merged['protocol'])) {
            return [
                'error' => "Protocol {$merged['protocol']} is not supported by registered adapters",
                'details' => ['supportedProtocols' => $this->supportedProtocols()],
            ];
        }

        $passiveSet = array_fill_keys($merged['passive'] ?? [], true);
        $activeSet = array_fill_keys($merged['active'] ?? [], true);
        $features = $merged['features'] ?? [];
        foreach ($features as $feature => $mapping) {
            foreach (($mapping['passive'] ?? []) as $type) {
                if (!isset($passiveSet[$type])) {
                    return ['error' => "Feature \"$feature\" references passive \"$type\" not present in top-level passive list"];
                }
            }
            foreach (($mapping['active'] ?? []) as $type) {
                if (!isset($activeSet[$type])) {
                    return ['error' => "Feature \"$feature\" references active \"$type\" not present in top-level active list"];
                }
            }
        }

        return ['data' => $data];
    }

    private function normalizeFeaturesObject(array $features): array
    {
        $normalized = [];

        foreach ($features as $feature => $mapping) {
            if (!is_string($feature) || trim($feature) === '') {
                throw new \InvalidArgumentException('Feature names must be non-empty strings');
            }
            if (!is_array($mapping)) {
                throw new \InvalidArgumentException("Feature \"$feature\" mapping must be an object");
            }

            $passive = $mapping['passive'] ?? [];
            $active = $mapping['active'] ?? [];
            if (!is_array($passive) || !is_array($active)) {
                throw new \InvalidArgumentException("Feature \"$feature\" passive/active must be arrays");
            }

            $normalizedPassive = [];
            foreach ($passive as $type) {
                if (!is_string($type) || trim($type) === '') {
                    throw new \InvalidArgumentException("Feature \"$feature\" has invalid passive type");
                }
                $normalizedPassive[] = trim($type);
            }

            $normalizedActive = [];
            foreach ($active as $type) {
                if (!is_string($type) || trim($type) === '') {
                    throw new \InvalidArgumentException("Feature \"$feature\" has invalid active type");
                }
                $normalizedActive[] = trim($type);
            }

            $normalized[$feature] = [
                'passive' => array_values(array_unique($normalizedPassive)),
                'active' => array_values(array_unique($normalizedActive)),
            ];
        }

        return $normalized;
    }

    private function supportedProtocols(): array
    {
        $registry = new AdapterRegistry();
        return $registry->protocols();
    }

    private function isSupportedProtocol(string $protocol): bool
    {
        return in_array($protocol, $this->supportedProtocols(), true);
    }

    private function paginationResource(int $page, int $limit, int $total): array
    {
        return [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => max(1, (int)ceil($total / $limit)),
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

    private function normalizeBattery(array $payload): array
    {
        $value = $this->firstScalar($payload, ['battery', 'power', 'value']);
        return $value === null ? [] : ['batteryPercent' => (int)$value];
    }

    private function normalizeHeartbeat(array $payload): array
    {
        return array_filter([
            'beats' => isset($payload['beats']) ? (int)$payload['beats'] : null,
            'intervalMs' => isset($payload['interval']) ? (int)$payload['interval'] : null,
        ], static fn($value) => $value !== null);
    }

    private function normalizeActivity(array $payload): array
    {
        return array_filter([
            'steps' => isset($payload['steps']) ? (int)$payload['steps'] : null,
            'distanceMeters' => isset($payload['distance']) ? (float)$payload['distance'] : null,
            'caloriesKcal' => isset($payload['calories']) ? (float)$payload['calories'] : null,
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
        return null;
    }

    private function featureResources(array $features): array
    {
        $resources = [];
        foreach ($features as $feature => $commands) {
            $resources[] = [
                'name' => $feature,
                'passive' => array_values($commands['passive'] ?? []),
                'active' => array_values($commands['active'] ?? []),
            ];
        }

        usort($resources, fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $resources;
    }

    private function healthCheck(): Response
    {
        $dbOk = $this->pdo !== null;
        $redisOk = $this->redis !== null && $this->redis->isAvailable();

        return $this->jsonResponse([
            'status' => ($dbOk ? 'ok' : 'degraded'),
            'services' => [
                'mysql' => $dbOk,
                'redis' => $redisOk,
                'watchServerAttached' => $this->watchServer !== null,
            ],
            'onlineDevices' => $this->onlineDeviceCount(),
            'time' => time(),
        ]);
    }

    private function metricsEndpoint(): Response
    {
        $payload = [
            'onlineDevices' => $this->onlineDeviceCount(),
            'knownModels' => DeviceCapabilities::allModels(),
            'totalDevices' => count($this->whitelist()->all()),
            'time' => time(),
        ];

        return $this->jsonResponse($payload);
    }

    private function demoPage(): Response
    {
        $path = __DIR__ . '/demo.html';
        if (!file_exists($path)) {
            return $this->errorResponse('not_found', 'Demo page not found', 404);
        }

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], file_get_contents($path));
    }

    private function openApiSpec(): Response
    {
        return $this->jsonResponse(OpenApiSpec::get());
    }

    private function swaggerUi(): Response
    {
        $html = '<!doctype html><html><head><meta charset="utf-8"><title>API Docs</title>'
            . '<link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">'
            . '</head><body><div id="swagger-ui"></div>'
            . '<script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>'
            . '<script>window.ui=SwaggerUIBundle({url:"/openapi.json",dom_id:"#swagger-ui"});</script>'
            . '</body></html>';

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    private function jsonResponse(array $payload, int $status = 200): Response
    {
        return new Response($status, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Access-Control-Allow-Origin' => '*',
        ], json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function errorResponse(string $code, string $message, int $status, array $details = []): Response
    {
        $payload = ['error' => ['code' => $code, 'message' => $message]];
        if ($details !== []) {
            $payload['error']['details'] = $details;
        }
        return $this->jsonResponse($payload, $status);
    }
}
