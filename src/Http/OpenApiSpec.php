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
            'description' => 'Device IMEI (15 digits)',
            'schema' => ['type' => 'string', 'example' => '865028000000306'],
        ];

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Multi-Vendor 4G Smartwatch API',
                'version' => '1.1.0',
                'description' => 'REST API for querying devices, received events, and server -> watch commands. Responses use consistent `device`, `event`, `command`, and `error` resources.',
            ],
            'servers' => [
                ['url' => 'http://localhost:8081', 'description' => 'Local development'],
            ],
            'paths' => [
                '/devices' => [
                    'get' => [
                        'summary' => 'List devices',
                        'operationId' => 'listDevices',
                        'tags' => ['Devices'],
                        'responses' => [
                            '200' => [
                                'description' => 'List of registered devices',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/DeviceListResponse'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                '/events/recent' => [
                    'get' => [
                        'summary' => 'List recent events',
                        'operationId' => 'recentEvents',
                        'tags' => ['Events'],
                        'parameters' => [
                            [
                                'name' => 'limit',
                                'in' => 'query',
                                'required' => false,
                                'description' => 'Maximum number of recent events',
                                'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50],
                            ],
                            [
                                'name' => 'after',
                                'in' => 'query',
                                'required' => false,
                                'description' => 'Return only events with an id greater than this value',
                                'schema' => ['type' => 'integer', 'example' => 12],
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Recent passive event history',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/EventListResponse'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                '/devices/{imei}/events/latest' => [
                    'get' => [
                        'summary' => 'Get the latest received event',
                        'operationId' => 'latestDeviceEvent',
                        'tags' => ['Events'],
                        'parameters' => [$imeiParam],
                        'responses' => [
                            '200' => [
                                'description' => 'Latest event received from the device',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/DeviceEventResponse'],
                                    ],
                                ],
                            ],
                            '404' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/devices/{imei}/features' => [
                    'get' => [
                        'summary' => 'List normalized features',
                        'operationId' => 'deviceFeatures',
                        'tags' => ['Features'],
                        'parameters' => [$imeiParam],
                        'responses' => [
                            '200' => [
                                'description' => 'Canonical features and the native commands that implement them',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/DeviceFeaturesResponse'],
                                    ],
                                ],
                            ],
                            '404' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/devices/{imei}/command' => [
                    'post' => [
                        'summary' => 'Send native command',
                        'description' => 'Sends a vendor-native active command. For a more portable API, prefer sending commands by feature.',
                        'operationId' => 'sendCommand',
                        'tags' => ['Commands'],
                        'parameters' => [$imeiParam],
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/CommandRequest'],
                                ],
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Command sent',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/CommandResponse'],
                                    ],
                                ],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                            '409' => ['$ref' => '#/components/responses/Error'],
                            '404' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/devices/{imei}/features/{feature}/command' => [
                    'post' => [
                        'summary' => 'Send command by feature',
                        'description' => 'Translates a canonical feature to the model native active command. Example: `heart_rate` becomes `dnHeartRate` on Wonlex and `BPXL` on VIVISTAR.',
                        'operationId' => 'sendFeatureCommand',
                        'tags' => ['Features'],
                        'parameters' => [
                            $imeiParam,
                            [
                                'name' => 'feature',
                                'in' => 'path',
                                'required' => true,
                                'description' => 'Canonical feature to execute',
                                'schema' => ['type' => 'string', 'example' => 'heart_rate'],
                            ],
                        ],
                        'requestBody' => [
                            'required' => false,
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/FeatureCommandRequest'],
                                ],
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Feature translated and command sent',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/CommandResponse'],
                                    ],
                                ],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                            '409' => ['$ref' => '#/components/responses/Error'],
                            '404' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/demo/simulate' => [
                    'post' => [
                        'summary' => 'Trigger passive watch event simulator',
                        'description' => 'Demo helper endpoint. Starts the simulator in the background to send one real passive watch -> server event through WebSocket.',
                        'operationId' => 'demoSimulate',
                        'tags' => ['Demo'],
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/SimulationRequest'],
                                ],
                            ],
                        ],
                        'responses' => [
                            '202' => [
                                'description' => 'Simulation queued',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/SimulationResponse'],
                                    ],
                                ],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                            '404' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/demo/listener' => [
                    'post' => [
                        'summary' => 'Start managed demo watch listener',
                        'description' => 'Starts a background simulator in listen mode so active server -> watch commands can be tested from the demo.',
                        'operationId' => 'startDemoListener',
                        'tags' => ['Demo'],
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/DemoListenerRequest'],
                                ],
                            ],
                        ],
                        'responses' => [
                            '201' => [
                                'description' => 'Listener started',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/DemoListenerResponse'],
                                    ],
                                ],
                            ],
                            '200' => [
                                'description' => 'Listener already running',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/DemoListenerResponse'],
                                    ],
                                ],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                            '404' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/demo/listeners' => [
                    'get' => [
                        'summary' => 'List managed demo watch listeners',
                        'operationId' => 'listDemoListeners',
                        'tags' => ['Demo'],
                        'responses' => [
                            '200' => [
                                'description' => 'Managed listeners',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/DemoListenerListResponse'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                '/demo/listener/{imei}' => [
                    'delete' => [
                        'summary' => 'Stop managed demo watch listener',
                        'operationId' => 'stopDemoListener',
                        'tags' => ['Demo'],
                        'parameters' => [$imeiParam],
                        'responses' => [
                            '200' => [
                                'description' => 'Listener stopped',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/DemoListenerResponse'],
                                    ],
                                ],
                            ],
                            '404' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
            ],
            'components' => [
                'responses' => [
                    'Error' => [
                        'description' => 'Normalized error',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/ErrorResponse'],
                            ],
                        ],
                    ],
                ],
                'schemas' => [
                    'DeviceListResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => [
                                'type' => 'array',
                                'items' => ['$ref' => '#/components/schemas/DeviceListItem'],
                            ],
                            'meta' => ['$ref' => '#/components/schemas/ListMeta'],
                        ],
                    ],
                    'DeviceListItem' => [
                        'type' => 'object',
                        'properties' => [
                            'device' => ['$ref' => '#/components/schemas/Device'],
                            'links' => ['$ref' => '#/components/schemas/DeviceLinks'],
                        ],
                    ],
                    'DeviceEventResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'device' => ['$ref' => '#/components/schemas/Device'],
                            'event' => [
                                'nullable' => true,
                                'allOf' => [['$ref' => '#/components/schemas/Event']],
                            ],
                        ],
                    ],
                    'EventListResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => [
                                'type' => 'array',
                                'items' => ['$ref' => '#/components/schemas/EventListItem'],
                            ],
                            'meta' => ['$ref' => '#/components/schemas/ListMeta'],
                        ],
                    ],
                    'EventListItem' => [
                        'type' => 'object',
                        'properties' => [
                            'device' => ['$ref' => '#/components/schemas/Device'],
                            'event' => ['$ref' => '#/components/schemas/Event'],
                        ],
                    ],
                    'DeviceFeaturesResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'device' => ['$ref' => '#/components/schemas/Device'],
                            'features' => ['$ref' => '#/components/schemas/FeatureMap'],
                            'nativeCommands' => ['$ref' => '#/components/schemas/NativeCommandSet'],
                        ],
                    ],
                    'CommandResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'status' => ['type' => 'string', 'example' => 'sent'],
                            'device' => ['$ref' => '#/components/schemas/Device'],
                            'command' => ['$ref' => '#/components/schemas/Command'],
                        ],
                    ],
                    'Device' => [
                        'type' => 'object',
                        'properties' => [
                            'imei' => ['type' => 'string', 'example' => '865028000000306'],
                            'model' => ['$ref' => '#/components/schemas/Model'],
                            'status' => ['$ref' => '#/components/schemas/DeviceStatus'],
                        ],
                    ],
                    'Model' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string', 'example' => 'WONLEX-PRO'],
                            'supplier' => ['type' => 'string', 'nullable' => true, 'example' => 'Wonlex'],
                            'protocol' => ['type' => 'string', 'nullable' => true, 'example' => 'wonlex-json'],
                            'transport' => ['type' => 'string', 'nullable' => true, 'example' => 'websocket-json'],
                        ],
                    ],
                    'DeviceStatus' => [
                        'type' => 'object',
                        'properties' => [
                            'online' => ['type' => 'boolean', 'example' => false],
                            'enabled' => ['type' => 'boolean', 'example' => true],
                        ],
                    ],
                    'DeviceLinks' => [
                        'type' => 'object',
                        'properties' => [
                            'latestEvent' => ['type' => 'string', 'example' => '/devices/865028000000306/events/latest'],
                            'features' => ['type' => 'string', 'example' => '/devices/865028000000306/features'],
                            'command' => ['type' => 'string', 'example' => '/devices/865028000000306/command'],
                        ],
                    ],
                    'Event' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer', 'example' => 1],
                            'direction' => ['type' => 'string', 'example' => 'watch_to_server'],
                            'feature' => ['type' => 'string', 'nullable' => true, 'example' => 'heart_rate'],
                            'nativeType' => ['type' => 'string', 'example' => 'upHeartRate'],
                            'receivedAt' => ['type' => 'integer', 'example' => 1778673778493],
                            'nativePayload' => [
                                'type' => 'object',
                                'description' => 'Sanitized payload in the vendor native format. Session tokens are not exposed.',
                                'example' => ['date' => '72', 'testType' => 0],
                            ],
                            'normalized' => [
                                'type' => 'object',
                                'description' => 'Canonical fields extracted when the feature is known.',
                                'example' => ['heartRateBpm' => 72],
                            ],
                        ],
                    ],
                    'FeatureMap' => [
                        'type' => 'object',
                        'additionalProperties' => ['$ref' => '#/components/schemas/Feature'],
                    ],
                    'Feature' => [
                        'type' => 'object',
                        'properties' => [
                            'canReceive' => ['type' => 'boolean', 'example' => true],
                            'canRequest' => ['type' => 'boolean', 'example' => true],
                            'passiveTypes' => ['type' => 'array', 'items' => ['type' => 'string'], 'example' => ['upHeartRate']],
                            'activeTypes' => ['type' => 'array', 'items' => ['type' => 'string'], 'example' => ['dnHeartRate']],
                        ],
                    ],
                    'NativeCommandSet' => [
                        'type' => 'object',
                        'properties' => [
                            'passive' => ['type' => 'array', 'items' => ['type' => 'string'], 'example' => ['upHeartRate']],
                            'active' => ['type' => 'array', 'items' => ['type' => 'string'], 'example' => ['dnHeartRate', 'find']],
                        ],
                    ],
                    'CommandRequest' => [
                        'type' => 'object',
                        'required' => ['type'],
                        'properties' => [
                            'type' => ['type' => 'string', 'example' => 'restart'],
                            'data' => ['type' => 'object', 'nullable' => true, 'example' => ['reason' => 'maintenance']],
                        ],
                    ],
                    'FeatureCommandRequest' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'object', 'nullable' => true, 'example' => ['collectionLogo' => '87654321']],
                        ],
                    ],
                    'Command' => [
                        'type' => 'object',
                        'properties' => [
                            'feature' => ['type' => 'string', 'nullable' => true, 'example' => 'heart_rate'],
                            'nativeType' => ['type' => 'string', 'example' => 'dnHeartRate'],
                            'payload' => ['type' => 'object', 'example' => ['collectionLogo' => '87654321']],
                        ],
                    ],
                    'SimulationRequest' => [
                        'type' => 'object',
                        'required' => ['imei', 'type'],
                        'properties' => [
                            'imei' => ['type' => 'string', 'example' => '865028000000306'],
                            'model' => ['type' => 'string', 'nullable' => true, 'example' => 'WONLEX-PRO'],
                            'type' => ['type' => 'string', 'example' => 'upHeartRate'],
                            'data' => ['type' => 'object', 'nullable' => true, 'example' => ['date' => '72', 'testType' => 0]],
                        ],
                    ],
                    'SimulationResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'status' => ['type' => 'string', 'example' => 'queued'],
                            'simulation' => [
                                'type' => 'object',
                                'properties' => [
                                    'id' => ['type' => 'string', 'example' => 'a1b2c3d4e5f6'],
                                    'imei' => ['type' => 'string', 'example' => '865028000000306'],
                                    'model' => ['type' => 'string', 'example' => 'WONLEX-PRO'],
                                    'nativeType' => ['type' => 'string', 'example' => 'upHeartRate'],
                                    'payload' => ['type' => 'object', 'nullable' => true],
                                ],
                            ],
                            'device' => ['$ref' => '#/components/schemas/Device'],
                        ],
                    ],
                    'DemoListenerRequest' => [
                        'type' => 'object',
                        'required' => ['imei'],
                        'properties' => [
                            'imei' => ['type' => 'string', 'example' => '865028000000306'],
                        ],
                    ],
                    'DemoListenerResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'status' => ['type' => 'string', 'example' => 'started'],
                            'listener' => ['$ref' => '#/components/schemas/DemoListener'],
                        ],
                    ],
                    'DemoListenerListResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => [
                                'type' => 'array',
                                'items' => ['$ref' => '#/components/schemas/DemoListener'],
                            ],
                            'meta' => ['$ref' => '#/components/schemas/ListMeta'],
                        ],
                    ],
                    'DemoListener' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string', 'example' => 'a1b2c3d4e5f6'],
                            'imei' => ['type' => 'string', 'example' => '865028000000306'],
                            'model' => ['type' => 'string', 'example' => 'WONLEX-PRO'],
                            'pid' => ['type' => 'integer', 'example' => 1234],
                            'logPath' => ['type' => 'string', 'example' => '/tmp/health-smartwatches-listener-a1b2c3d4e5f6.log'],
                            'running' => ['type' => 'boolean', 'example' => true],
                            'online' => ['type' => 'boolean', 'example' => true],
                            'startedAt' => ['type' => 'integer', 'example' => 1778748533],
                        ],
                    ],
                    'ListMeta' => [
                        'type' => 'object',
                        'properties' => [
                            'count' => ['type' => 'integer', 'example' => 4],
                            'limit' => ['type' => 'integer', 'nullable' => true, 'example' => 50],
                        ],
                    ],
                    'ErrorResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'error' => ['$ref' => '#/components/schemas/ErrorBody'],
                        ],
                    ],
                    'ErrorBody' => [
                        'type' => 'object',
                        'properties' => [
                            'code' => ['type' => 'string', 'example' => 'device_offline'],
                            'message' => ['type' => 'string', 'example' => 'Device is offline or cannot be routed right now'],
                            'details' => ['type' => 'object', 'nullable' => true],
                        ],
                    ],
                ],
            ],
        ];
    }
}
