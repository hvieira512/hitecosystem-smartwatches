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
            'description' => 'IMEI do dispositivo (15 digitos)',
            'schema' => ['type' => 'string', 'example' => '865028000000306'],
        ];

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'API Multi-Vendor Relogios 4G',
                'version' => '1.1.0',
                'description' => 'API REST para consultar dispositivos, eventos recebidos e comandos server -> watch. As respostas usam recursos consistentes: `device`, `event`, `command` e `error`.',
            ],
            'servers' => [
                ['url' => 'http://localhost:8081', 'description' => 'Desenvolvimento local'],
            ],
            'paths' => [
                '/devices' => [
                    'get' => [
                        'summary' => 'Listar dispositivos',
                        'operationId' => 'listDevices',
                        'tags' => ['Dispositivos'],
                        'responses' => [
                            '200' => [
                                'description' => 'Lista de dispositivos registados',
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
                        'summary' => 'Listar eventos recentes',
                        'operationId' => 'recentEvents',
                        'tags' => ['Eventos'],
                        'parameters' => [
                            [
                                'name' => 'limit',
                                'in' => 'query',
                                'required' => false,
                                'description' => 'Numero maximo de eventos recentes',
                                'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50],
                            ],
                            [
                                'name' => 'after',
                                'in' => 'query',
                                'required' => false,
                                'description' => 'Devolve apenas eventos com id superior a este valor',
                                'schema' => ['type' => 'integer', 'example' => 12],
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Historico recente de eventos passivos',
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
                        'summary' => 'Obter ultimo evento recebido',
                        'operationId' => 'latestDeviceEvent',
                        'tags' => ['Eventos'],
                        'parameters' => [$imeiParam],
                        'responses' => [
                            '200' => [
                                'description' => 'Ultimo evento recebido do dispositivo',
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
                        'summary' => 'Listar features normalizadas',
                        'operationId' => 'deviceFeatures',
                        'tags' => ['Features'],
                        'parameters' => [$imeiParam],
                        'responses' => [
                            '200' => [
                                'description' => 'Features canonicas e comandos nativos que as implementam',
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
                        'summary' => 'Enviar comando nativo',
                        'description' => 'Envia um comando activo nativo do fornecedor. Para uma API mais portavel, prefira enviar por feature.',
                        'operationId' => 'sendCommand',
                        'tags' => ['Comandos'],
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
                                'description' => 'Comando enviado',
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
                        'summary' => 'Enviar comando por feature',
                        'description' => 'Traduz uma feature canonica para o comando activo nativo do modelo. Exemplo: `heart_rate` vira `dnHeartRate` em Wonlex e `BPXL` em VIVISTAR.',
                        'operationId' => 'sendFeatureCommand',
                        'tags' => ['Features'],
                        'parameters' => [
                            $imeiParam,
                            [
                                'name' => 'feature',
                                'in' => 'path',
                                'required' => true,
                                'description' => 'Feature canonica a executar',
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
                                'description' => 'Feature traduzida e comando enviado',
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
                        'summary' => 'Disparar simulador de relogio',
                        'description' => 'Endpoint auxiliar de demonstracao. Arranca o simulador em background para enviar um evento passivo real pelo WebSocket.',
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
                                'description' => 'Simulacao colocada em fila',
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
            ],
            'components' => [
                'responses' => [
                    'Error' => [
                        'description' => 'Erro normalizado',
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
                            'label' => ['type' => 'string', 'nullable' => true, 'example' => 'Relogio Joao (Wonlex Pro)'],
                            'model' => ['$ref' => '#/components/schemas/Model'],
                            'status' => ['$ref' => '#/components/schemas/DeviceStatus'],
                            'registeredAt' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                        ],
                    ],
                    'Model' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string', 'example' => 'WONLEX-PRO'],
                            'label' => ['type' => 'string', 'nullable' => true, 'example' => 'Wonlex 4G Health Watch (Full protocol)'],
                            'supplier' => ['type' => 'string', 'nullable' => true, 'example' => 'Wonlex'],
                            'protocol' => ['type' => 'string', 'nullable' => true, 'example' => 'wonlex-json'],
                            'transport' => ['type' => 'string', 'nullable' => true, 'example' => 'websocket-json'],
                        ],
                    ],
                    'DeviceStatus' => [
                        'type' => 'object',
                        'properties' => [
                            'enabled' => ['type' => 'boolean', 'example' => true],
                            'online' => ['type' => 'boolean', 'example' => false],
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
                                'description' => 'Payload limpo no formato nativo do fornecedor. Tokens de sessao nao sao expostos.',
                                'example' => ['date' => '72', 'testType' => 0],
                            ],
                            'normalized' => [
                                'type' => 'object',
                                'description' => 'Campos canonicos extraidos quando a feature e conhecida.',
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
                            'message' => ['type' => 'string', 'example' => 'Dispositivo offline ou nao encaminhavel neste momento'],
                            'details' => ['type' => 'object', 'nullable' => true],
                        ],
                    ],
                ],
            ],
        ];
    }
}
