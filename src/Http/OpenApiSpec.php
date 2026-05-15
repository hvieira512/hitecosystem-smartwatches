<?php

namespace App\Http;

class OpenApiSpec
{
    public static function get(): array
    {
        $imeiParam = [
            'name' => 'imei',
            'in' => 'path',
            'required' => true,
            'description' => 'Device IMEI',
            'schema' => ['type' => 'string', 'example' => '865028000000306'],
        ];

        $supplierIdParam = [
            'name' => 'id',
            'in' => 'path',
            'required' => true,
            'description' => 'Supplier identifier',
            'schema' => ['type' => 'integer', 'example' => 3],
        ];

        $modelCodeParam = [
            'name' => 'code',
            'in' => 'path',
            'required' => true,
            'description' => 'Model code',
            'schema' => ['type' => 'string', 'example' => 'WONLEX-PRO'],
        ];

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Health Smartwatches API',
                'version' => '1.2.1',
                'description' => 'Devices, suppliers, models, events and command endpoints.',
            ],
            'servers' => [['url' => 'http://localhost:8081']],
            'tags' => [
                ['name' => 'Devices'],
                ['name' => 'Suppliers'],
                ['name' => 'Models'],
                ['name' => 'Events'],
                ['name' => 'Commands'],
                ['name' => 'System'],
            ],
            'paths' => [
                '/devices' => [
                    'get' => [
                        'tags' => ['Devices'],
                        'summary' => 'List devices',
                        'parameters' => [
                            ['name' => 'imei', 'in' => 'query', 'schema' => ['type' => 'string'], 'required' => false],
                            ['name' => 'model', 'in' => 'query', 'schema' => ['type' => 'string'], 'required' => false],
                            ['name' => 'enabled', 'in' => 'query', 'schema' => ['type' => 'boolean'], 'required' => false],
                            ['name' => 'online', 'in' => 'query', 'schema' => ['type' => 'boolean'], 'required' => false],
                            ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1], 'required' => false],
                            ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 25], 'required' => false],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Paginated device list',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DeviceListResponse']]],
                            ],
                        ],
                    ],
                    'post' => [
                        'tags' => ['Devices'],
                        'summary' => 'Register device',
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DeviceCreateRequest']]],
                        ],
                        'responses' => [
                            '201' => [
                                'description' => 'Device created',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DeviceSingleResponse']]],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                            '409' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/devices/{imei}' => [
                    'get' => [
                        'tags' => ['Devices'],
                        'summary' => 'Get device',
                        'parameters' => [$imeiParam],
                        'responses' => [
                            '200' => [
                                'description' => 'Device resource',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DeviceSingleResponse']]],
                            ],
                            '404' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                    'put' => [
                        'tags' => ['Devices'],
                        'summary' => 'Update device',
                        'parameters' => [$imeiParam],
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DeviceUpdateRequest']]],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Device updated',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DeviceSingleResponse']]],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                            '404' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                    'delete' => [
                        'tags' => ['Devices'],
                        'summary' => 'Delete device',
                        'parameters' => [$imeiParam],
                        'responses' => [
                            '200' => [
                                'description' => 'Device deleted',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DeviceDeleteResponse']]],
                            ],
                            '404' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/suppliers' => [
                    'get' => [
                        'tags' => ['Suppliers'],
                        'summary' => 'List suppliers',
                        'parameters' => [
                            ['name' => 'name', 'in' => 'query', 'schema' => ['type' => 'string'], 'required' => false],
                            ['name' => 'enabled', 'in' => 'query', 'schema' => ['type' => 'boolean'], 'required' => false],
                            ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1], 'required' => false],
                            ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 25], 'required' => false],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Paginated supplier list',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/SupplierListResponse']]],
                            ],
                        ],
                    ],
                    'post' => [
                        'tags' => ['Suppliers'],
                        'summary' => 'Create supplier',
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/SupplierCreateRequest']]],
                        ],
                        'responses' => [
                            '201' => [
                                'description' => 'Supplier created',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/SupplierSingleResponse']]],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                            '409' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/suppliers/{id}' => [
                    'get' => [
                        'tags' => ['Suppliers'],
                        'summary' => 'Get supplier',
                        'parameters' => [$supplierIdParam],
                        'responses' => [
                            '200' => [
                                'description' => 'Supplier resource',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/SupplierSingleResponse']]],
                            ],
                            '404' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                    'put' => [
                        'tags' => ['Suppliers'],
                        'summary' => 'Update supplier',
                        'parameters' => [$supplierIdParam],
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/SupplierUpdateRequest']]],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Supplier updated',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/SupplierSingleResponse']]],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                            '404' => ['$ref' => '#/components/responses/Error'],
                            '409' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                    'delete' => [
                        'tags' => ['Suppliers'],
                        'summary' => 'Delete supplier',
                        'parameters' => [$supplierIdParam],
                        'responses' => [
                            '200' => [
                                'description' => 'Supplier deleted',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/SupplierDeleteResponse']]],
                            ],
                            '404' => ['$ref' => '#/components/responses/Error'],
                            '409' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/models' => [
                    'get' => [
                        'tags' => ['Models'],
                        'summary' => 'List models',
                        'parameters' => [
                            ['name' => 'code', 'in' => 'query', 'schema' => ['type' => 'string'], 'required' => false],
                            ['name' => 'name', 'in' => 'query', 'schema' => ['type' => 'string'], 'required' => false],
                            ['name' => 'supplierId', 'in' => 'query', 'schema' => ['type' => 'integer'], 'required' => false],
                            ['name' => 'supplier', 'in' => 'query', 'schema' => ['type' => 'string'], 'required' => false],
                            ['name' => 'protocol', 'in' => 'query', 'schema' => ['type' => 'string'], 'required' => false],
                            ['name' => 'transport', 'in' => 'query', 'schema' => ['type' => 'string'], 'required' => false],
                            ['name' => 'enabled', 'in' => 'query', 'schema' => ['type' => 'boolean'], 'required' => false],
                            ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1], 'required' => false],
                            ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 25], 'required' => false],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Paginated model list',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ModelListResponse']]],
                            ],
                        ],
                    ],
                    'post' => [
                        'tags' => ['Models'],
                        'summary' => 'Create model',
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ModelCreateRequest']]],
                        ],
                        'responses' => [
                            '201' => [
                                'description' => 'Model created',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ModelSingleResponse']]],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                            '404' => ['$ref' => '#/components/responses/Error'],
                            '409' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/models/{code}' => [
                    'get' => [
                        'tags' => ['Models'],
                        'summary' => 'Get model',
                        'parameters' => [$modelCodeParam],
                        'responses' => [
                            '200' => [
                                'description' => 'Model resource',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ModelSingleResponse']]],
                            ],
                            '404' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                    'put' => [
                        'tags' => ['Models'],
                        'summary' => 'Update model',
                        'parameters' => [$modelCodeParam],
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ModelUpdateRequest']]],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Model updated',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ModelSingleResponse']]],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                            '404' => ['$ref' => '#/components/responses/Error'],
                            '409' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                    'delete' => [
                        'tags' => ['Models'],
                        'summary' => 'Delete model',
                        'parameters' => [$modelCodeParam],
                        'responses' => [
                            '200' => [
                                'description' => 'Model deleted',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ModelDeleteResponse']]],
                            ],
                            '404' => ['$ref' => '#/components/responses/Error'],
                            '409' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/events/recent' => [
                    'get' => [
                        'tags' => ['Events'],
                        'summary' => 'Recent events',
                        'parameters' => [
                            ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50], 'required' => false],
                            ['name' => 'after', 'in' => 'query', 'schema' => ['type' => 'integer'], 'required' => false],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Recent events',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/RecentEventsResponse']]],
                            ],
                        ],
                    ],
                ],
                '/devices/{imei}/events/latest' => [
                    'get' => [
                        'tags' => ['Events'],
                        'summary' => 'Latest event for device',
                        'parameters' => [$imeiParam],
                        'responses' => [
                            '200' => [
                                'description' => 'Latest event payload',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/LatestEventResponse']]],
                            ],
                            '404' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/devices/{imei}/features' => [
                    'get' => [
                        'tags' => ['Devices'],
                        'summary' => 'Device features',
                        'parameters' => [$imeiParam],
                        'responses' => [
                            '200' => [
                                'description' => 'Device features',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DeviceFeaturesResponse']]],
                            ],
                            '404' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/devices/{imei}/command' => [
                    'post' => [
                        'tags' => ['Commands'],
                        'summary' => 'Send native command',
                        'parameters' => [$imeiParam],
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/NativeCommandRequest']]],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Command sent',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/CommandResponse']]],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                            '404' => ['$ref' => '#/components/responses/Error'],
                            '409' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/devices/{imei}/features/{feature}/command' => [
                    'post' => [
                        'tags' => ['Commands'],
                        'summary' => 'Send feature command',
                        'parameters' => [
                            $imeiParam,
                            [
                                'name' => 'feature',
                                'in' => 'path',
                                'required' => true,
                                'schema' => ['type' => 'string', 'example' => 'heart_rate'],
                            ],
                        ],
                        'requestBody' => [
                            'required' => false,
                            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/FeatureCommandRequest']]],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Command sent',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/CommandResponse']]],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                            '404' => ['$ref' => '#/components/responses/Error'],
                            '409' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/health' => [
                    'get' => [
                        'tags' => ['System'],
                        'summary' => 'Health check',
                        'responses' => ['200' => ['description' => 'OK']],
                    ],
                ],
                '/metrics' => [
                    'get' => [
                        'tags' => ['System'],
                        'summary' => 'Metrics snapshot',
                        'responses' => ['200' => ['description' => 'OK']],
                    ],
                ],
                '/openapi.json' => [
                    'get' => [
                        'tags' => ['System'],
                        'summary' => 'OpenAPI spec',
                        'responses' => ['200' => ['description' => 'OpenAPI document']],
                    ],
                ],
                '/docs' => [
                    'get' => [
                        'tags' => ['System'],
                        'summary' => 'Swagger UI',
                        'responses' => ['200' => ['description' => 'Swagger UI page']],
                    ],
                ],
            ],
            'components' => [
                'responses' => [
                    'Error' => [
                        'description' => 'Error response',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/ErrorResponse'],
                            ],
                        ],
                    ],
                ],
                'schemas' => [
                    'Pagination' => [
                        'type' => 'object',
                        'required' => ['page', 'limit', 'total', 'totalPages'],
                        'properties' => [
                            'page' => ['type' => 'integer', 'example' => 1],
                            'limit' => ['type' => 'integer', 'example' => 25],
                            'total' => ['type' => 'integer', 'example' => 4],
                            'totalPages' => ['type' => 'integer', 'example' => 1],
                        ],
                    ],
                    'SupplierRef' => [
                        'type' => 'object',
                        'required' => ['id', 'name'],
                        'properties' => [
                            'id' => ['type' => 'integer', 'example' => 3],
                            'name' => ['type' => 'string', 'example' => 'Wonlex'],
                        ],
                    ],
                    'Device' => [
                        'type' => 'object',
                        'required' => ['imei', 'model', 'online', 'enabled'],
                        'properties' => [
                            'imei' => ['type' => 'string', 'example' => '865028000000306'],
                            'model' => ['type' => 'string', 'example' => 'WONLEX-PRO'],
                            'supplier' => ['type' => 'string', 'nullable' => true, 'example' => 'Wonlex'],
                            'protocol' => ['type' => 'string', 'nullable' => true, 'example' => 'wonlex-json'],
                            'transport' => ['type' => 'string', 'nullable' => true, 'example' => 'websocket-json'],
                            'online' => ['type' => 'boolean', 'example' => false],
                            'enabled' => ['type' => 'boolean', 'example' => true],
                            'registeredAt' => ['type' => 'string', 'nullable' => true, 'example' => '2025-01-15 10:00:00'],
                        ],
                    ],
                    'DeviceCreateRequest' => [
                        'type' => 'object',
                        'required' => ['imei', 'model'],
                        'properties' => [
                            'imei' => ['type' => 'string', 'example' => '865028000000306'],
                            'model' => ['type' => 'string', 'example' => 'WONLEX-PRO'],
                            'enabled' => ['type' => 'boolean', 'example' => true],
                        ],
                    ],
                    'DeviceUpdateRequest' => [
                        'type' => 'object',
                        'properties' => [
                            'model' => ['type' => 'string', 'example' => 'WONLEX-HEALTH'],
                            'enabled' => ['type' => 'boolean', 'example' => true],
                        ],
                    ],
                    'DeviceSingleResponse' => [
                        'type' => 'object',
                        'required' => ['data'],
                        'properties' => [
                            'data' => ['$ref' => '#/components/schemas/Device'],
                        ],
                    ],
                    'DeviceDeleteResponse' => [
                        'type' => 'object',
                        'required' => ['status', 'imei'],
                        'properties' => [
                            'status' => ['type' => 'string', 'example' => 'deleted'],
                            'imei' => ['type' => 'string', 'example' => '865028000000306'],
                        ],
                    ],
                    'DeviceFilters' => [
                        'type' => 'object',
                        'properties' => [
                            'imei' => ['type' => 'string', 'nullable' => true],
                            'model' => ['type' => 'string', 'nullable' => true],
                            'enabled' => ['type' => 'boolean', 'nullable' => true],
                            'online' => ['type' => 'boolean', 'nullable' => true],
                        ],
                    ],
                    'DeviceListResponse' => [
                        'type' => 'object',
                        'required' => ['data', 'pagination', 'filters'],
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Device']],
                            'pagination' => ['$ref' => '#/components/schemas/Pagination'],
                            'filters' => ['$ref' => '#/components/schemas/DeviceFilters'],
                        ],
                    ],
                    'Supplier' => [
                        'type' => 'object',
                        'required' => ['id', 'name', 'enabled'],
                        'properties' => [
                            'id' => ['type' => 'integer', 'example' => 3],
                            'name' => ['type' => 'string', 'example' => 'Wonlex'],
                            'enabled' => ['type' => 'boolean', 'example' => true],
                            'createdAt' => ['type' => 'string', 'nullable' => true, 'example' => '2026-05-15 14:37:38'],
                            'updatedAt' => ['type' => 'string', 'nullable' => true, 'example' => '2026-05-15 14:37:38'],
                        ],
                    ],
                    'SupplierCreateRequest' => [
                        'type' => 'object',
                        'required' => ['name'],
                        'properties' => [
                            'name' => ['type' => 'string', 'maxLength' => 50, 'example' => 'Wonlex'],
                            'enabled' => ['type' => 'boolean', 'example' => true],
                        ],
                    ],
                    'SupplierUpdateRequest' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string', 'maxLength' => 50, 'example' => 'VIVISTAR'],
                            'enabled' => ['type' => 'boolean', 'example' => true],
                        ],
                    ],
                    'SupplierSingleResponse' => [
                        'type' => 'object',
                        'required' => ['data'],
                        'properties' => [
                            'data' => ['$ref' => '#/components/schemas/Supplier'],
                        ],
                    ],
                    'SupplierDeleteResponse' => [
                        'type' => 'object',
                        'required' => ['status', 'data'],
                        'properties' => [
                            'status' => ['type' => 'string', 'example' => 'deleted'],
                            'data' => ['$ref' => '#/components/schemas/Supplier'],
                        ],
                    ],
                    'SupplierFilters' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string', 'nullable' => true],
                            'enabled' => ['type' => 'boolean', 'nullable' => true],
                        ],
                    ],
                    'SupplierListResponse' => [
                        'type' => 'object',
                        'required' => ['data', 'pagination', 'filters'],
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Supplier']],
                            'pagination' => ['$ref' => '#/components/schemas/Pagination'],
                            'filters' => ['$ref' => '#/components/schemas/SupplierFilters'],
                        ],
                    ],
                    'Model' => [
                        'type' => 'object',
                        'required' => ['id', 'code', 'name', 'supplier', 'protocol', 'transport', 'enabled', 'passive', 'active', 'features'],
                        'properties' => [
                            'id' => ['type' => 'integer', 'example' => 1],
                            'code' => ['type' => 'string', 'example' => 'WONLEX-PRO'],
                            'name' => ['type' => 'string', 'example' => 'Wonlex 4G Health Watch (Full protocol)'],
                            'supplier' => ['$ref' => '#/components/schemas/SupplierRef'],
                            'protocol' => ['type' => 'string', 'example' => 'wonlex-json'],
                            'transport' => ['type' => 'string', 'example' => 'websocket-json'],
                            'sourceDoc' => ['type' => 'string', 'nullable' => true, 'example' => 'docs/Wonlex.pdf'],
                            'enabled' => ['type' => 'boolean', 'example' => true],
                            'passive' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'active' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'features' => [
                                'type' => 'object',
                                'additionalProperties' => ['$ref' => '#/components/schemas/FeatureCommandMap'],
                            ],
                            'createdAt' => ['type' => 'string', 'nullable' => true],
                            'updatedAt' => ['type' => 'string', 'nullable' => true],
                        ],
                    ],
                    'FeatureCommandMap' => [
                        'type' => 'object',
                        'required' => ['passive', 'active'],
                        'properties' => [
                            'passive' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'active' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                    ],
                    'ModelCreateRequest' => [
                        'type' => 'object',
                        'required' => ['code', 'name', 'supplierId', 'protocol', 'transport', 'passive', 'active', 'features'],
                        'properties' => [
                            'code' => ['type' => 'string', 'example' => 'WONLEX-PRO'],
                            'name' => ['type' => 'string', 'example' => 'Wonlex 4G Health Watch (Full protocol)'],
                            'supplierId' => ['type' => 'integer', 'example' => 3],
                            'protocol' => ['type' => 'string', 'example' => 'wonlex-json'],
                            'transport' => ['type' => 'string', 'example' => 'websocket-json'],
                            'sourceDoc' => ['type' => 'string', 'nullable' => true, 'example' => 'docs/Wonlex.pdf'],
                            'enabled' => ['type' => 'boolean', 'example' => true],
                            'passive' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'active' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'features' => [
                                'type' => 'object',
                                'additionalProperties' => ['$ref' => '#/components/schemas/FeatureCommandMap'],
                            ],
                        ],
                    ],
                    'ModelUpdateRequest' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'supplierId' => ['type' => 'integer'],
                            'protocol' => ['type' => 'string'],
                            'transport' => ['type' => 'string'],
                            'sourceDoc' => ['type' => 'string', 'nullable' => true],
                            'enabled' => ['type' => 'boolean'],
                            'passive' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'active' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'features' => [
                                'type' => 'object',
                                'additionalProperties' => ['$ref' => '#/components/schemas/FeatureCommandMap'],
                            ],
                        ],
                    ],
                    'ModelSingleResponse' => [
                        'type' => 'object',
                        'required' => ['data'],
                        'properties' => [
                            'data' => ['$ref' => '#/components/schemas/Model'],
                        ],
                    ],
                    'ModelDeleteResponse' => [
                        'type' => 'object',
                        'required' => ['status', 'data'],
                        'properties' => [
                            'status' => ['type' => 'string', 'example' => 'deleted'],
                            'data' => ['$ref' => '#/components/schemas/Model'],
                        ],
                    ],
                    'ModelFilters' => [
                        'type' => 'object',
                        'properties' => [
                            'code' => ['type' => 'string', 'nullable' => true],
                            'name' => ['type' => 'string', 'nullable' => true],
                            'supplierId' => ['type' => 'integer', 'nullable' => true],
                            'supplierName' => ['type' => 'string', 'nullable' => true],
                            'protocol' => ['type' => 'string', 'nullable' => true],
                            'transport' => ['type' => 'string', 'nullable' => true],
                            'enabled' => ['type' => 'boolean', 'nullable' => true],
                        ],
                    ],
                    'ModelListResponse' => [
                        'type' => 'object',
                        'required' => ['data', 'pagination', 'filters'],
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Model']],
                            'pagination' => ['$ref' => '#/components/schemas/Pagination'],
                            'filters' => ['$ref' => '#/components/schemas/ModelFilters'],
                        ],
                    ],
                    'Event' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer', 'nullable' => true],
                            'direction' => ['type' => 'string', 'example' => 'watch_to_server'],
                            'feature' => ['type' => 'string', 'nullable' => true],
                            'nativeType' => ['type' => 'string', 'nullable' => true],
                            'receivedAt' => ['type' => 'integer', 'nullable' => true],
                            'nativePayload' => ['type' => 'object', 'additionalProperties' => true],
                            'normalized' => ['type' => 'object', 'additionalProperties' => true],
                        ],
                    ],
                    'RecentEventItem' => [
                        'type' => 'object',
                        'properties' => [
                            'device' => ['$ref' => '#/components/schemas/Device'],
                            'event' => ['$ref' => '#/components/schemas/Event'],
                        ],
                    ],
                    'RecentEventsResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/RecentEventItem']],
                            'meta' => [
                                'type' => 'object',
                                'properties' => [
                                    'count' => ['type' => 'integer'],
                                    'limit' => ['type' => 'integer'],
                                ],
                            ],
                        ],
                    ],
                    'LatestEventResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'device' => ['$ref' => '#/components/schemas/Device'],
                            'event' => ['$ref' => '#/components/schemas/Event'],
                        ],
                    ],
                    'DeviceFeatureItem' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'passive' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'active' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                    ],
                    'DeviceFeaturesResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'device' => ['$ref' => '#/components/schemas/Device'],
                            'features' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/DeviceFeatureItem']],
                            'nativeCommands' => [
                                'type' => 'object',
                                'properties' => [
                                    'passive' => ['type' => 'array', 'items' => ['type' => 'string']],
                                    'active' => ['type' => 'array', 'items' => ['type' => 'string']],
                                ],
                            ],
                        ],
                    ],
                    'NativeCommandRequest' => [
                        'type' => 'object',
                        'required' => ['type'],
                        'properties' => [
                            'type' => ['type' => 'string', 'example' => 'dnHeartRate'],
                            'data' => ['type' => 'object', 'additionalProperties' => true],
                        ],
                    ],
                    'FeatureCommandRequest' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'object', 'additionalProperties' => true],
                        ],
                    ],
                    'CommandResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'status' => ['type' => 'string', 'example' => 'sent'],
                            'device' => ['$ref' => '#/components/schemas/Device'],
                            'command' => [
                                'type' => 'object',
                                'properties' => [
                                    'feature' => ['type' => 'string', 'nullable' => true],
                                    'nativeType' => ['type' => 'string'],
                                    'payload' => ['type' => 'object', 'additionalProperties' => true],
                                ],
                            ],
                        ],
                    ],
                    'ErrorResponse' => [
                        'type' => 'object',
                        'required' => ['error'],
                        'properties' => [
                            'error' => [
                                'type' => 'object',
                                'required' => ['code', 'message'],
                                'properties' => [
                                    'code' => ['type' => 'string', 'example' => 'invalid_request'],
                                    'message' => ['type' => 'string', 'example' => 'Validation failed'],
                                    'details' => ['type' => 'object', 'additionalProperties' => true],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
