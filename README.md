# Relogios de Saude 4G — Plataforma Multi-Vendor

**Versao:** 3.0
**Objetivo:** Plataforma flexivel de integracao para relogios de saude com conectividade 4G/LTE de diferentes fabricantes, permitindo monitorizacao de sinais vitais, controlo remoto e gestao centralizada.

---

## Indice

- [Visao Geral](#visao-geral)
- [Arquitetura](#arquitetura)
- [Modelos Suportados](#modelos-suportados)
- [Sistema de Capacidades por Dispositivo](#sistema-de-capacidades-por-dispositivo)
- [Features Normalizadas](#features-normalizadas)
- [Whitelist e Autenticacao](#whitelist-e-autenticacao)
- [Protocolo de Comunicacao](#protocolo-de-comunicacao)
- [Fluxo de Handshake e Login](#fluxo-de-handshake-e-login)
- [API HTTP REST](#api-http-rest)
- [Configuracao do Ambiente Local](#configuracao-do-ambiente-local)
- [Implementacao do Servidor](#implementacao-do-servidor)
- [Simulador de Dispositivos](#simulador-de-dispositivos)
- [Workflow de Desenvolvimento Local](#workflow-de-desenvolvimento-local)
- [Referencia de Comandos](#referencia-de-comandos)
- [Seguranca e Boas Praticas](#seguranca-e-boas-praticas)

---

## Visao Geral

Este projecto implementa um servidor WebSocket + API HTTP em PHP que funciona como ponte entre relogios de saude 4G e aplicacoes clientes (web/mobile).

### Principais conceitos

| Conceito                     | Descricao                                                                                      |
| ---------------------------- | ---------------------------------------------------------------------------------------------- |
| **Multi-Vendor**             | O sistema suporta relogios de diferentes fabricantes com protocolos nativos distintos          |
| **Capacidades declarativas** | Cada modelo declara quais comandos passiveis (watch->server) e activos (server->watch) suporta |
| **Features normalizadas**    | Comandos nativos sao mapeados para features canonicas (ex: `heart_rate`, `location`)           |
| **Whitelist**                | Apenas dispositivos com IMEI autorizado podem estabelecer comunicacao                          |
| **Handshake obrigatorio**    | Ligacao so e considerada activa apos whitelist check + login + validacao de token de sessao    |
| **API REST**                 | Servidor HTTP para consulta de dispositivos, eventos e envio de comandos                       |
| **Simulador integrado**      | Ferramenta CLI para simular qualquer modelo sem hardware fisico                                |

---

## Arquitetura

```
  +---------------------+       +----------+       +-------------------+
  |                     |       |          |       |                   |
  |  RELOGIO 4G         |       |  NGINX   |       |  WEB / APP        |
  |  (Multi-vendor)     |       |  :80/443 |       |  (Dashboard)      |
  |                     |       |          |       |                   |
  |  +---------------+  | WS    | ip_hash  |       | +---------------+ |
  |  | WONLEX-PRO    |--+-------+-sticky---+-------| | Demo Web App  | |
  |  | WONLEX-HEALTH |  |       |    |     |       | | (SPA nativa)  | |
  |  | VIVISTAR-CARE |  |       |    |     |       | +---------------+ |
  |  | VIVISTAR-LITE |  |       |    |     |       |                   |
  |  +---------------+  |       |    |     |       | +---------------+ |
  |                     |       |  +-v---------+   | | API Client    | |
  |  WebSocket Client   |       |  | WS:8080   |   | | (curl / app)  | |
  |  FCAF + JSON        |       |  | API:8081  |   | +---------------+ |
  +---------+-----------+       +--+-----------+---+-------+-----------+
            |                       |          |
            |  WS (:8080)           |          | HTTP REST (:8081)
            |                       |          |
            v                       |          v
  +---------------------+           |  +----------------------+
  |                     |           |  |                      |
  |  WatchServer        |           |  | ApiServer            |
  |  (Ratchet WS)       |           |  | (ReactPHP HTTP)      |
  |                     |           |  |                      |
  |  Auth Layer         |           |  | /devices             |
  |  Whitelist          |           |  | /events/recent       |
  |  Capabilities       |           |  | /devices/{imei}/...  |
  |  Feature Router     |           |  | /demo/simulate       |
  |  Event History      |           |  | /docs (Swagger UI)   |
  |                     |           |  | /health              |
  |  Redis Stream       |           |  | /metrics             |
  |  (eventos passivos) |           |  +----------+-----------+
  +---------+-----------+           |             |
            |                       |             | Redis Stream
            |  XADD events          |             | (comandos)
            |                       |             |
            v                       |             v
  +---------------------+           |  +----------------------+
  |                     |           |  |                      |
  |  REDIS (7-alpine)   |<----------+  |  MYSQL (8.0)         |
  |                     |              |                      |
  |  Stream: events     |  +---------->|  devices             |
  |  Stream: cmd:stream |  |           |  device_events       |
  |  Hash: device:online|  |           |                      |
  |  Rate limit counters|  |           +----------------------+
  +---------+-----------+  |
            |              |
            |  XREADGROUP  |
            v              |
  +---------------------+  |
  |                     |  |
  |  Worker (PHP CLI)   |--+
  |  (bin/worker.php)   |
  |                     |
  |  Consumer Group     |
  |  events -> MySQL    |
  +---------------------+

  +-----------------------------------------------+
  |              CONFIGURACAO                     |
  |  config/server.json  |  config/whitelist.json |
  |  config/capabilities.json |  config/nginx.conf|
  |  config/schema.sql   |  Dockerfile            |
  |  docker-compose.yml  |  Makefile              |
  +-----------------------------------------------+
```

### Componentes do sistema

| Componente            | Funcao                                                                                                                                             |
| --------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Relogios 4G**       | Dispositivos fisicos de diferentes fabricantes. Protocolos: Wonlex JSON (WebSocket) ou VIVISTAR IW (TCP texto)                                     |
| **Nginx**             | Proxy reverso: balanceamento WS com `ip_hash` (sticky sessions), upstream API HTTP, rate limiting, TLS termination, security headers               |
| **WatchServer (PHP)** | Servidor WebSocket Ratchet que aceita ligacoes, autentica, valida tokens, armazena eventos e gere sessoes. Suporta Redis e MySQL                   |
| **ApiServer (PHP)**   | Servidor HTTP ReactPHP com REST API. Pode operar no mesmo processo (monolitico) ou separado (comandos via Redis Stream)                            |
| **Worker (PHP CLI)**  | Processo dedicado que consome eventos do Redis Stream (`XREADGROUP`) e persiste no MySQL                                                           |
| **Redis**             | Streams de eventos (buffer rapido), registo de dispositivos online (`device:online` hash), contadores de rate limiting, stream de comandos API->WS |
| **MySQL**             | Persistencia: tabela `devices` (whitelist), `device_events` (historico de eventos). Schema em `config/schema.sql`                                  |
| **Auth Layer**        | Valida whitelist, processa handshake, negoceia capacidades, verifica session token                                                                 |
| **Feature Router**    | Traduz comandos nativos (`upHeartRate`, `AP49`) para features canonicas (`heart_rate`)                                                             |
| **Simulator**         | Ferramenta CLI que emula qualquer modelo sem hardware                                                                                              |
| **Demo Web App**      | Interface web SPA (embutida no ApiServer) para visualizar dispositivos, simular eventos e ver dados em tempo real                                  |

---

## Modelos Suportados

| Modelo          | Fabricante | Protocolo     | Transporte     | Descricao                                          |
| --------------- | ---------- | ------------- | -------------- | -------------------------------------------------- |
| `WONLEX-PRO`    | Wonlex     | `wonlex-json` | WebSocket JSON | Completo: ECG, HRV, desportos, videochamada, batch |
| `WONLEX-HEALTH` | Wonlex     | `wonlex-json` | WebSocket JSON | Subset saude: sinais vitais, localizacao, OTA      |
| `VIVISTAR-CARE` | VIVISTAR   | `vivistar-iw` | TCP texto      | Completo: AP01-AP50, ECG remoto, audio             |
| `VIVISTAR-LITE` | VIVISTAR   | `vivistar-iw` | TCP texto      | Subset basico: localizacao, batimento, SOS         |

---

## Sistema de Capacidades por Dispositivo

Cada modelo tem um perfil em `config/capabilities.json` que declara comandos passiveis, activos e features normalizadas.

### Estrutura do perfil

```json
{
    "WONLEX-PRO": {
        "label": "Wonlex 4G Health Watch (Full protocol)",
        "supplier": "Wonlex",
        "protocol": "wonlex-json",
        "transport": "websocket-json",
        "source_doc": "docs/Wonlex.pdf",
        "passive": [
            "upHeartRate",
            "upECG",
            "upHRV",
            "upPPG",
            "upRR",
            "upKcal",
            "upBP",
            "upBO",
            "upBodyTemperature",
            "upBS",
            "upBF",
            "upUA",
            "upBatch",
            "upBreathe",
            "upSleep",
            "upTodayActivity",
            "upRun",
            "upWalk",
            "upRide",
            "upFree",
            "upRope",
            "upBadminton",
            "upTable",
            "upTennis",
            "upClimb",
            "upBasketball",
            "upVolleyball",
            "upDance",
            "upSpinningBike",
            "upYoga",
            "upJumpingJack",
            "upSitUps",
            "upFootball",
            "upWushu",
            "upATaekwondo",
            "upTaijiquanJumping",
            "upHulaHoop",
            "upLocation",
            "upBattery",
            "upWeather",
            "upShutdown",
            "upSMS",
            "upReset",
            "upPickup",
            "upSleepFind",
            "upGetOTA",
            "upGetDevConfig",
            "upSensorValue",
            "upSensorValues",
            "upCallLog",
            "upDeviceConfig",
            "upGetDevBindStatus",
            "upAppIdent",
            "upMakeFriend",
            "upSyncFriend",
            "upDelFriend",
            "upEphemeris",
            "upVideoCallWithAPP",
            "upWatchHangUp",
            "upVideoCallTime",
            "upGetVideoUserList",
            "upVideoNetStatusWithAPP",
            "upCustom"
        ],
        "active": [
            "dnDevBindStatus",
            "dnECGAnalysis",
            "dnHeartRate",
            "dnBP",
            "dnBO",
            "dnTemperature",
            "dnBreathe",
            "dnECG",
            "dnHRV",
            "dnPPG",
            "dnRR",
            "dnLocation",
            "locationInterval",
            "deviceConfig",
            "alarmClock",
            "familyNumber",
            "SOSNumber",
            "findPhoneBillOrFlow",
            "dnUpSleep",
            "dnWeather",
            "dnUpBreathe",
            "dnMedicationPlan",
            "find",
            "reset",
            "restart",
            "powerOff",
            "msgNotice",
            "OTA",
            "disturb",
            "disturb_two",
            "photography",
            "autoAnswer",
            "downMakeFriend",
            "downSyncFriend",
            "downDelFriend",
            "downPPmessage",
            "downSchoolTimeTable",
            "dnEphemeris",
            "downVideoCallWithAPPInfo",
            "downAPPHangUp",
            "downVideoCallWithWatch",
            "downGetVideoUserList",
            "downGetChatUserList",
            "downChatVoice",
            "dnCustom"
        ]
    }
}
```

### Campos do perfil

| Campo        | Descricao                                              |
| ------------ | ------------------------------------------------------ |
| `label`      | Nome legivel do modelo                                 |
| `supplier`   | Fabricante (Wonlex, VIVISTAR, etc.)                    |
| `protocol`   | Identificador do protocolo nativo                      |
| `transport`  | Tipo de transporte (websocket-json, tcp-text)          |
| `source_doc` | Documentacao de referencia do fabricante               |
| `passive`    | Comandos que o dispositivo envia (watch -> server)     |
| `active`     | Comandos que o servidor pode enviar (server -> watch)  |
| `features`   | Mapeamento de features canonicas para comandos nativos |

### Classe DeviceCapabilities

```php
$caps = DeviceCapabilities::forModel('WONLEX-PRO');
$caps->supportsPassive('upECG');            // true
$caps->supportsActive('find');               // true
$caps->getPassive();                          // array de comandos passiveis
$caps->getActive();                           // array de comandos activos
$caps->getSupplier();                         // 'Wonlex'
$caps->getProtocol();                         // 'wonlex-json'
$caps->getTransport();                        // 'websocket-json'
$caps->getFeatures();                         // array de features

$caps->supportsFeature('heart_rate');        // true
$caps->featureForPassive('upHeartRate');     // 'heart_rate'
$caps->resolveFeatureActiveCommand('heart_rate'); // 'dnHeartRate'

DeviceCapabilities::allModels();              // ['WONLEX-PRO', 'WONLEX-HEALTH', ...]
DeviceCapabilities::supportsAny('upECG');    // true
```

### Como adicionar um novo modelo

1. Adicionar entrada em `config/capabilities.json` com campos obrigatorios `passive` e `active`
2. Opcional: definir `features` para normalizacao de dados
3. Opcional: adicionar perfil de simulacao em `simulator/profiles/`
4. No `login`, o dispositivo envia `deviceModel` — o servidor procura o perfil
5. Se o modelo nao existir, o servidor rejeita a ligacao

---

## Features Normalizadas

Cada modelo mapeia os seus comandos nativos para features canonicas. Isto permite que a API REST e o dashboard funcionem de forma consistente entre fabricantes.

### Exemplo de mapeamento (WONLEX-PRO)

```json
{
    "heart_rate": {
        "passive": ["upHeartRate", "upBatch"],
        "active": ["dnHeartRate"]
    },
    "blood_pressure": {
        "passive": ["upBP", "upBatch"],
        "active": ["dnBP"]
    },
    "location": {
        "passive": ["upLocation"],
        "active": ["dnLocation", "locationInterval"]
    },
    "temperature": {
        "passive": ["upBodyTemperature"],
        "active": ["dnTemperature"]
    }
}
```

### Exemplo de mapeamento (VIVISTAR-CARE)

```json
{
    "heart_rate": {
        "passive": ["AP49", "APHT", "APHP"],
        "active": ["BPXL"]
    },
    "location": {
        "passive": ["AP01", "AP02", "AP10"],
        "active": ["BP16"]
    },
    "heartbeat": {
        "passive": ["AP03"],
        "active": []
    }
}
```

### Features canonicas disponiveis

| Feature                      | Descricao                                |
| ---------------------------- | ---------------------------------------- |
| `heart_rate`                 | Frequencia cardiaca                      |
| `blood_pressure`             | Pressao arterial                         |
| `blood_oxygen`               | SpO2                                     |
| `temperature`                | Temperatura corporal                     |
| `blood_sugar`                | Glicemia (Wonlex)                        |
| `blood_fat`                  | Gordura corporal (Wonlex)                |
| `uric_acid`                  | Acido urico (Wonlex)                     |
| `ecg`                        | Eletrocardiograma                        |
| `hrv`                        | Variabilidade cardiaca                   |
| `ppg`                        | Fotopletismografia                       |
| `rr_interval`                | Intervalo R-R                            |
| `respiration`                | Taxa respiratoria                        |
| `sleep`                      | Dados de sono                            |
| `activity`                   | Actividade diaria / desporto             |
| `location`                   | Localizacao GPS/LBS/WiFi                 |
| `battery`                    | Nivel de bateria                         |
| `weather`                    | Informacao meteorologica                 |
| `sos`                        | Botao SOS                                |
| `fall_detection`             | Deteccao de queda                        |
| `reminders`                  | Lembretes (medicacao, hidratacao)        |
| `device_config`              | Configuracoes do dispositivo             |
| `factory_reset`              | Restauro de fabrica                      |
| `restart`                    | Reinicio                                 |
| `power_off`                  | Desligar                                 |
| `find_device`                | Localizar dispositivo                    |
| `ota`                        | Actualizacao firmware                    |
| `messaging`                  | Comunicacao (chamadas, mensagens, video) |
| `contacts`                   | Gestao de contactos                      |
| `custom`                     | Comandos proprietarios (Wonlex)          |
| `ephemeris`                  | Efermerides (Wonlex)                     |
| `blood_pressure_calibration` | Calibracao tensao (VIVISTAR)             |

### Normalizacao de payloads

A API REST devolve dados normalizados para features conhecidas:

| Feature          | Campos normalizados                                           |
| ---------------- | ------------------------------------------------------------- |
| `heart_rate`     | `heartRateBpm`                                                |
| `blood_pressure` | `systolicMmHg`, `diastolicMmHg`, `pulseBpm`                   |
| `blood_oxygen`   | `spo2Percent`                                                 |
| `temperature`    | `bodyTemperatureC`, `skinTemperatureC`, `ambientTemperatureC` |
| `location`       | `latitude`, `longitude`, `altitudeMeters`, `satelliteCount`   |
| `battery`        | `batteryPercent`                                              |
| `heartbeat`      | `batteryPercent`, `steps`, `gsmSignal`, `workingMode`         |
| `activity`       | `steps`, `exerciseSeconds`, `caloriesKcal`, `distanceMeters`  |

---

## Whitelist e Autenticacao

Apenas IMEIs registados na whitelist podem comunicar com o servidor.

### config/whitelist.json

```json
{
    "865028000000306": {
        "model": "WONLEX-PRO",
        "label": "Relogio Joao (Wonlex Pro)",
        "enabled": true,
        "registered_at": "2025-01-15T10:00:00Z"
    },
    "865028000000307": {
        "model": "WONLEX-HEALTH",
        "label": "Relogio Maria (Wonlex Health)",
        "enabled": true,
        "registered_at": "2025-01-20T14:30:00Z"
    },
    "865028000000308": {
        "model": "VIVISTAR-CARE",
        "label": "Relogio Antonio (VIVISTAR Care)",
        "enabled": false,
        "registered_at": "2025-02-01T09:00:00Z"
    },
    "865028000000309": {
        "model": "VIVISTAR-LITE",
        "label": "Relogio Sofia (VIVISTAR Lite)",
        "enabled": true,
        "registered_at": "2025-03-10T11:00:00Z"
    }
}
```

### Fluxo de autenticacao

```
   RELOGIO                              SERVIDOR
      |                                     |
      |  1. WebSocket connect               |
      +------------------------------------>|
      |                                     |
      |  2. Envia login com IMEI + modelo   |
      +------------------------------------>|
      |                                     |
      |  3. Servidor verifica:              |
      |     - IMEI existe na whitelist?     |
      |     - IMEI esta enabled?            |
      |     - Modelo corresponde ao whitelist?|
      |     - Modelo tem perfil?             |
      |                                     |
      |     SE INVALIDO:                    |
      |     Envia "login_error" + fecha     |
      |<------------------------------------+
      |                                     |
      |     SE VALIDO:                      |
      |  4. Responde "login_ok"             |
      |     + sessionToken                  |
      |     + perfil de capacidades         |
      |<------------------------------------+
      |                                     |
      |  5. Ligaçao activa.                 |
      |     Todos os comandos incluem       |
      |     sessionToken no payload.        |
      |                                     |
```

---

## Protocolo de Comunicacao

O protocolo base e identico para todos os modelos. O que varia sao os `type` disponiveis (definidos no perfil de cada modelo).

### Estrutura do pacote TCP/IP

```
  +--------+--------+-----------------------------+
  | 0xFCAF | Length |        JSON Payload         |
  | 2 bytes | 2 bytes |        N bytes             |
  +--------+--------+-----------------------------+
```

- **0xFCAF** — unsigned short big-endian (start field)
- **Length** — unsigned short big-endian (tamanho do JSON)
- **Payload** — JSON codificado em UTF-8

### Codificacao/descodificacao em PHP

```php
// Codificar
$json = json_encode($payload);
$packet = pack("nn", 0xFCAF, strlen($json)) . $json;

// Descodificar
$header = unpack("nstart/nlength", substr($raw, 0, 4));
if ($header['start'] !== 0xFCAF) { /* invalido */ }
$payload = json_decode(substr($raw, 4, $header['length']), true);
```

### Campos comuns do JSON

| Campo       | Tipo   | Descricao                                                 |
| ----------- | ------ | --------------------------------------------------------- |
| `type`      | string | Nome do comando (ex: `login`, `upHeartRate`, `AP49`)      |
| `ident`     | string | 6 digitos aleatorios para emparelhar pedido-resposta      |
| `ref`       | string | Origem: `w:update`, `w:reply`, `s:down`, `s:reply`        |
| `imei`      | string | IMEI do dispositivo (15 digitos)                          |
| `data`      | object | Dados especificos do comando (pode conter `sessionToken`) |
| `timestamp` | int    | Unix timestamp em milissegundos                           |

### Regras de comunicacao

| Regra                     | Descricao                                                            |
| ------------------------- | -------------------------------------------------------------------- |
| **Handshake obrigatorio** | Nenhum comando alem de `login` e aceite antes do handshake completo  |
| **Session token**         | Apos login, todos os comandos devem incluir `sessionToken` no `data` |
| **Whitelist check**       | IMEI tem de estar registado e enabled                                |
| **Model check**           | Modelo declarado no login deve corresponder ao whitelist             |
| **Capability check**      | Comando passivo so e aceite se o modelo do dispositivo o suportar    |
| **Emparelhamento ident**  | Respostas usam o mesmo ident do pedido                               |
| **ref do relogio**        | `w:update` para dados novos, `w:reply` para respostas a comandos     |
| **ref do servidor**       | `s:reply` para confirmacoes, `s:down` para comandos activos          |
| **Event history**         | Eventos passiveis sao armazenados (max 200) e expostos via API       |

---

## Fluxo de Handshake e Login

```
  RELOGIO                                     SERVIDOR
  -------                                     --------

    |  1. Abre WebSocket                          |
    +-------------------------------------------->|
    |                                              |
    |  2. Login (obrigatorio, primeiro comando)    |
    |     type: "login"                            |
    |     ref: "w:update"                          |
    |     imei: "865028000000306"                  |
    |     data: {                                   |
    |       deviceModel: "WONLEX-PRO",              |
    |       firmware: "V3.2.1",                    |
    |       platform: "ASR Android",               |
    |       batteryLevel: 85,                       |
    |       cpuModel: "SC9832E"                     |
    |     }                                         |
    +-------------------------------------------->|
    |                                              |
    |  3. Servidor valida:                         |
    |     a) IMEI na whitelist e enabled?          |
    |     b) deviceModel confere com whitelist?    |
    |     c) deviceModel tem perfil?               |
    |                                              |
    |  --- Se invalido ---                         |
    |     type: "login_error"                      |
    |     ref: "s:reply"                            |
    |     data: { error: "IMEI not authorized" }    |
    |<--------------------------------------------+|
    |  Servidor fecha conexao                      |
    |                                              |
    |  --- Se valido ---                           |
    |  4. type: "login_ok"                         |
    |     ref: "s:reply"                            |
    |     data: {                                   |
    |       sessionToken: "a1b2c3d4e5f6",          |
    |       serverTime: 1715412340120,              |
    |       capabilities: {                         |
    |         passive: ["upHeartRate", ...],        |
    |         active: ["dnHeartRate", ...]          |
    |       }                                       |
    |     }                                         |
    |<--------------------------------------------+|
    |                                              |
    |  =========== LIGACAO ACTIVA ===========      |
    |                                              |
    |  5. Relogio envia dados biometricos          |
    |     type: "upHeartRate"                       |
    |     ref: "w:update"                           |
    |     data: { sessionToken: "...", value: 72 }  |
    +-------------------------------------------->|
    |                                              |
    |  6. Servidor confirma                        |
    |     type: "upHeartRate"                       |
    |     ref: "s:reply"                            |
    |<--------------------------------------------+|
    |                                              |
    |  7. Servidor envia comando                   |
    |     type: "dnSetConfig"                       |
    |     ref: "s:down"                             |
    |     data: { volume: 5 }                       |
    |<--------------------------------------------+|
    |                                              |
    |  8. Relogio responde                         |
    |     type: "dnSetConfig"                       |
    |     ref: "w:reply"                            |
    |     data: { sessionToken: "...", status: "ok" }|
    +-------------------------------------------->|
    |                                              |
```

### Mensagens

#### Login (relogio -> servidor) — Wonlex

```json
{
    "type": "login",
    "ident": "295781",
    "ref": "w:update",
    "imei": "865028000000306",
    "data": {
        "deviceModel": "WONLEX-PRO",
        "firmware": "V3.2.1",
        "platform": "ASR Android",
        "batteryLevel": 85,
        "cpuModel": "SC9832E",
        "storageTotal": "8192",
        "storageFree": "4096",
        "chips": ["ASR3603", "MT6739"]
    },
    "timestamp": 1715412340000
}
```

#### Login aceite (servidor -> relogio)

```json
{
  "type": "login_ok",
  "ident": "295781",
  "ref": "s:reply",
  "imei": "865028000000306",
  "data": {
    "sessionToken": "a1b2c3d4e5f6",
    "serverTime": 1715412340120,
    "capabilities": {
      "supplier": "Wonlex",
      "protocol": "wonlex-json",
      "transport": "websocket-json",
      "passive": ["upHeartRate", "upBP", ...],
      "active": ["dnHeartRate", "dnBP", ...],
      "features": {
        "heart_rate": {"passive": ["upHeartRate", "upBatch"], "active": ["dnHeartRate"]},
        "blood_pressure": {"passive": ["upBP", "upBatch"], "active": ["dnBP"]}
      }
    }
  },
  "timestamp": 1715412340120
}
```

> **Nota:** O `sessionToken` e obrigatorio em todos os comandos subsequentes. O servidor rejeita comandos com token invalido.

#### Comando rejeitado

```json
{
    "type": "error",
    "ident": "482103",
    "ref": "s:reply",
    "imei": "865028000000306",
    "data": {
        "error": "capability_not_supported",
        "command": "upECG",
        "message": "Modelo WONLEX-HEALTH nao suporta upECG"
    },
    "timestamp": 1715412400050
}
```

---

## API HTTP REST

O servidor inclui uma API REST (ReactPHP) na porta 8081.

### Endpoints

| Metodo | Path                                         | Descricao                                                        |
| ------ | -------------------------------------------- | ---------------------------------------------------------------- |
| `GET`  | `/devices`                                   | Listar todos os dispositivos da whitelist                        |
| `GET`  | `/events/recent?limit=50&after=12`           | Eventos passiveis recentes                                       |
| `GET`  | `/devices/{imei}/events/latest`              | Ultimo evento de um dispositivo                                  |
| `GET`  | `/devices/{imei}/features`                   | Features normalizadas do dispositivo                             |
| `POST` | `/devices/{imei}/command`                    | Enviar comando nativo (body: `{"type":"dnHeartRate","data":{}}`) |
| `POST` | `/devices/{imei}/features/{feature}/command` | Enviar comando por feature canonica                              |
| `POST` | `/demo/simulate`                             | Disparar simulador em background                                 |
| `GET`  | `/demo`                                      | Interface web SPA de demonstracao                                |
| `GET`  | `/openapi.json`                              | Especificacao OpenAPI 3.1                                        |
| `GET`  | `/docs`                                      | Swagger UI                                                       |

### Formato de resposta

Todas as respostas seguem uma estrutura consistente:

```json
{
    "data": [
        {
            "device": {
                "imei": "865028000000306",
                "label": "Relogio Joao (Wonlex Pro)",
                "model": {
                    "id": "WONLEX-PRO",
                    "label": "Wonlex 4G Health Watch (Full protocol)",
                    "supplier": "Wonlex",
                    "protocol": "wonlex-json",
                    "transport": "websocket-json"
                },
                "status": {
                    "enabled": true,
                    "online": true
                },
                "registeredAt": "2025-01-15T10:00:00Z"
            },
            "links": {
                "latestEvent": "/devices/865028000000306/events/latest",
                "features": "/devices/865028000000306/features",
                "command": "/devices/865028000000306/command"
            }
        }
    ],
    "meta": {
        "count": 4
    }
}
```

Erros:

```json
{
    "error": {
        "code": "device_not_found",
        "message": "Dispositivo nao encontrado ou desativado"
    }
}
```

---

## Configuracao do Ambiente Local

### Requisitos

| Componente     | Versao                                             | Notas                    |
| -------------- | -------------------------------------------------- | ------------------------ |
| PHP            | 8.1+                                               | CLI                      |
| Extensoes      | sockets, json, openssl, mbstring, pcntl, pdo_mysql | `docker-php-ext-install` |
| Composer       | 2.x                                                |                          |
| Docker         | 24+                                                | Opcional (recomendado)   |
| Docker Compose | 2.x                                                | Opcional (recomendado)   |

### Docker (recomendado)

```bash
# Construir e iniciar todos os servicos
make up

# Ou manualmente:
docker compose up -d

# Ver logs
make logs

# Parar
make down
```

Isto inicia 6 servicos:

| Servico  | Container     | Portas  | Funcao                           |
| -------- | ------------- | ------- | -------------------------------- |
| `mysql`  | health-mysql  | 3306    | Base de dados relacional         |
| `redis`  | health-redis  | 6379    | Streams, cache, rate limiting    |
| `ws`     | health-ws     | 8080    | WebSocket (dispositivos)         |
| `api`    | health-api    | 8081    | HTTP API REST                    |
| `worker` | health-worker | —       | Consumidor Redis Stream -> MySQL |
| `nginx`  | health-nginx  | 80, 443 | Proxy reverso, TLS               |

### Instalacao local (sem Docker)

```bash
composer install
```

### Variaveis de ambiente

As configuracoes podem ser sobrescritas por variaveis de ambiente (prioridade maxima):

| Variavel        | Default                | Descricao                            |
| --------------- | ---------------------- | ------------------------------------ |
| `DB_HOST`       | config `database.host` | Host MySQL                           |
| `DB_PORT`       | config `database.port` | Porta MySQL                          |
| `DB_NAME`       | config `database.name` | Nome da base de dados                |
| `DB_USER`       | config `database.user` | Utilizador MySQL                     |
| `DB_PASS`       | config `database.pass` | Password MySQL                       |
| `REDIS_HOST`    | config `redis.host`    | Host Redis                           |
| `REDIS_PORT`    | config `redis.port`    | Porta Redis                          |
| `WS_SERVER_URL` | config `public_ws_url` | URL publica do WS (para o simulador) |

### Estrutura de ficheiros

```
health-smartwatches-4g/
  README.md
  server.php                        # entrada monolitica (WebSocket + HTTP API)
  Dockerfile                        # imagem PHP 8.1-cli + extensoes
  docker-compose.yml                # orquestracao multi-servico
  docker-entrypoint.sh              # script de entrada (aguarda MySQL, migra)
  Makefile                          # atalhos docker compose
  composer.json
  composer.lock
  config/
    nginx.conf                      # configuracao Nginx (proxy, rate limit, headers)
    nginx-tls.conf                  # configuracao TLS opcional
    server.json                     # config do servidor (ip, porta, etc.)
    whitelist.json                  # IMEIS autorizados
    capabilities.json               # perfis de capacidades por modelo
    schema.sql                      # DDL MySQL (tabelas devices, device_events)
    ssl/                            # certificados TLS (auto-assinados para dev)
  bin/
    migrate.php                     # migracao MySQL + seed da whitelist
    worker.php                      # worker dedicado (consome Redis Stream)
    server-ws.php                   # servidor WebSocket separado
    server-api.php                  # servidor HTTP API separado
    purge-events.php                # limpeza de eventos antigos
    ssl-setup.sh                    # geracao de certificados auto-assinados
  src/
    Database/
      Database.php                  # PDO MySQL (devices, events, migrate, seed)
    Redis/
      Client.php                    # Redis (streams, device registry, rate limit, comandos)
    WebSocket/
      WatchServer.php               # servidor WebSocket principal
    Http/
      ApiServer.php                 # servidor HTTP REST API
      OpenApiSpec.php               # especificacao OpenAPI 3.1
    Registry/
      DeviceCapabilities.php        # gestao de capacidades por modelo
      Whitelist.php                 # gestao de whitelist (MySQL ou JSON)
  simulator/
    simulate.php                    # CLI para simular dispositivos
    profiles/
      wonlex-pro.json               # perfil de simulacao Wonlex PRO
      wonlex-health.json            # perfil de simulacao Wonlex Health
      vivistar-care.json            # perfil de simulacao VIVISTAR Care
      vivistar-lite.json            # perfil de simulacao VIVISTAR Lite
  docs/
    Wonlex.pdf                      # documentacao protocolo Wonlex
    VIVISTAR.docx                   # documentacao protocolo VIVISTAR
    4P-touch.md                     # notas protocolo 4P-touch
```

### config/server.json

```json
{
    "websocket": {
        "host": "0.0.0.0",
        "port": 8080
    },
    "api": {
        "host": "0.0.0.0",
        "port": 8081
    },
    "database": {
        "host": "127.0.0.1",
        "port": 3306,
        "name": "health_watches",
        "user": "root",
        "pass": ""
    },
    "redis": {
        "host": "127.0.0.1",
        "port": 6379,
        "database": 0
    },
    "public_ws_url": "ws://127.0.0.1:8080",
    "device_defaults": {
        "allow_unknown_models": false,
        "default_model": null
    },
    "logging": {
        "level": "info",
        "file": "var/log/server.log"
    }
}
```

---

## Implementacao do Servidor

### server.php (monolitico — dev)

O ficheiro `server.php` e o ponto de entrada monolitico que corre WebSocket e HTTP API no mesmo processo (ReactPHP event loop).

```bash
php server.php
```

Em producao, recomenda-se usar processos separados:

```bash
# Terminal 1: WebSocket
php bin/server-ws.php

# Terminal 2: HTTP API
php bin/server-api.php

# Terminal 3: Worker (persistencia Redis -> MySQL)
php bin/worker.php
```

Ou com Docker Compose:

```bash
make up
```

### server.php (codigo simplificado — consulte o ficheiro real)

```php
<?php

require __DIR__ . '/vendor/autoload.php';

$config = json_decode(file_get_contents(__DIR__ . '/config/server.json'), true);
$db = new Database($config['database']);           // MySQL (fallback JSON)
$redis = new RedisClient($config['redis']);          // Redis (fallback silencioso)
$loop = Loop::get();

$watchServer = new WatchServer($db, $redis);

$wsSocket = new Reactor("0.0.0.0:8080", $loop);
$wsServer = new IoServer(new HttpServer(new WsServer($watchServer)), $wsSocket, $loop);

$apiServer = new ApiServer($watchServer, $loop, 8081, '0.0.0.0', $db, $redis);

$loop->run();
```

O servidor usa o event loop do ReactPHP para correr o WebSocket (Ratchet) e o servidor HTTP (ReactPHP) no mesmo processo.

### bin/server-ws.php (separado — producao)

Servidor WebSocket apenas. Subscreve ao stream `cmd:stream` (Redis) para receber comandos do processo API.

```bash
php bin/server-ws.php
```

### bin/server-api.php (separado — producao)

Servidor HTTP API apenas. Envia comandos para dispositivos via Redis Stream (`cmd:stream`).

```bash
php bin/server-api.php
```

### bin/worker.php (separado — producao)

Worker dedicado que consome eventos do Redis Stream (`XREADGROUP`) e persiste em MySQL.

```bash
php bin/worker.php
```

### src/WebSocket/WatchServer.php

```php
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
    private array $sessions;        // resourceId => session
    private array $deviceMap;       // imei => ConnectionInterface
    private array $deviceData;      // imei => latest event
    private array $eventHistory;     // recent passive events (max 200)
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
        $this->whitelist = new Whitelist();
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

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        // 1. Parse FCAF header
        $header = unpack("nstart/nlength", substr($msg, 0, 4));
        if ($header['start'] !== 0xFCAF) { return; }
        $payload = json_decode(substr($msg, 4, $header['length']), true);
        if (!$payload || !isset($payload['type'])) { return; }

        $type = $payload['type'];
        $rid = $from->resourceId;

        // 2. Nao autenticado: apenas login
        if (!($this->sessions[$rid]['authenticated'] ?? false)) {
            if ($type === 'login') {
                $this->handleLogin($from, $payload);
            } else {
                $this->sendError($from, $payload, 'authentication_required');
            }
            return;
        }

        // 3. Verificar session token
        $session = $this->sessions[$rid];
        $caps = $session['caps'];
        $imei = $session['imei'];

        $sentToken = $payload['data']['sessionToken'] ?? '';
        if ($sentToken !== $session['sessionToken']) {
            $this->sendError($from, $payload, 'invalid_session_token');
            return;
        }

        // 4. Verificar capacidade para comandos passiveis
        $ref = $payload['ref'] ?? '';
        $isReply = $ref === 'w:reply';
        $isUpdate = !$isReply;

        if ($isUpdate && !$caps->supportsPassive($type)) {
            $this->sendError($from, $payload, 'capability_not_supported',
                "Modelo {$session['model']} nao suporta $type");
            return;
        }

        // 5. Armazenar evento passivo
        if ($isUpdate) {
            $this->storeDeviceEvent($imei, [
                'imei' => $imei,
                'model' => $session['model'],
                'nativeType' => $type,
                'feature' => $caps->featureForPassive($type),
                'nativePayload' => $this->sanitizePayload($payload['data'] ?? []),
                'receivedAt' => $this->now(),
            ]);
        }

        if ($isReply) {
            echo "[reply] IMEI=$imei, type=$type\n";
            $this->sendJson($from, $this->buildReply($payload, $payload['data'] ?? []));
            return;
        }

        $this->routeCommand($from, $payload);
    }

    private function handleLogin(ConnectionInterface $conn, array $payload): void
    {
        $imei = $payload['imei'] ?? '';
        $data = $payload['data'] ?? [];
        $model = $data['deviceModel'] ?? '';
        $ident = $payload['ident'] ?? '';

        if (!$this->whitelist->isAuthorized($imei)) {
            $this->sendLoginError($conn, $ident, $imei, 'IMEI not authorized or disabled');
            return;
        }

        $expectedModel = $this->whitelist->getModel($imei);
        if ($expectedModel && $expectedModel !== $model) {
            $this->sendLoginError($conn, $ident, $imei,
                "Model mismatch: expected $expectedModel, got $model");
            return;
        }

        $caps = DeviceCapabilities::forModel($model);
        if (!$caps) {
            $this->sendLoginError($conn, $ident, $imei,
                "Unknown device model: $model");
            return;
        }

        $rid = $conn->resourceId;
        $sessionToken = bin2hex(random_bytes(8));
        $this->sessions[$rid]['authenticated'] = true;
        $this->sessions[$rid]['imei'] = $imei;
        $this->sessions[$rid]['model'] = $model;
        $this->sessions[$rid]['caps'] = $caps;
        $this->sessions[$rid]['sessionToken'] = $sessionToken;
        $this->deviceMap[$imei] = $conn;

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
           . "Label={$this->whitelist->getLabel($imei)}, Session=$sessionToken\n";
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
    }

    public function sendCommand(string $imei, string $type, array $data = []): bool
    {
        if (!isset($this->deviceMap[$imei])) return false;

        $session = null;
        foreach ($this->sessions as $rid => $s) {
            if ($s['imei'] === $imei) {
                $session = $s; break;
            }
        }
        if (!$session || !$session['caps']->supportsActive($type)) return false;

        $ident = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $this->sendJson($this->deviceMap[$imei], [
            'type' => $type,
            'ident' => $ident,
            'ref' => 's:down',
            'imei' => $imei,
            'data' => $data,
            'timestamp' => $this->now(),
        ]);
        return true;
    }

    public function resolveFeatureCommand(string $imei, string $feature): ?string
    {
        $model = $this->whitelist->getModel($imei);
        $caps = $model ? DeviceCapabilities::forModel($model) : null;
        return $caps?->resolveFeatureActiveCommand($feature);
    }

    public function sendFeatureCommand(string $imei, string $feature, array $data = []): ?string
    {
        $type = $this->resolveFeatureCommand($imei, $feature);
        if (!$type || !$this->sendCommand($imei, $type, $data)) return null;
        return $type;
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $rid = $conn->resourceId;
        $imei = $this->sessions[$rid]['imei'] ?? 'desconhecido';
        unset($this->deviceMap[$imei]);
        unset($this->sessions[$rid]);
        $this->connections->offsetUnset($conn);
        echo "[-] Desconectado: resourceId=$rid, IMEI=$imei\n";
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

    private function sanitizePayload(array $payload): array
    {
        unset($payload['sessionToken'], $payload['encryptionCode'], $payload['EncryptionCode']);
        return $payload;
    }

    private function storeDeviceEvent(string $imei, array $event): void
    {
        $event['id'] = $this->nextEventId++;
        $this->deviceData[$imei] = $event;
        $this->eventHistory[] = $event;
        if (count($this->eventHistory) > 200) array_shift($this->eventHistory);
    }

    private function now(): int
    {
        return (int)round(microtime(true) * 1000);
    }

    // --- API Publica para o servidor HTTP ---

    public function getWhitelist(): Whitelist { return $this->whitelist; }
    public function getSessions(): array { return $this->sessions; }
    public function getDeviceData(string $imei): ?array { return $this->deviceData[$imei] ?? null; }
    public function getAllDeviceData(): array { return $this->deviceData; }
    public function isOnline(string $imei): bool { return isset($this->deviceMap[$imei]); }

    public function getRecentEvents(int $limit = 50, ?int $afterId = null): array
    {
        $events = $this->eventHistory;
        if ($afterId !== null) {
            $events = array_values(array_filter($events,
                static fn(array $e): bool => ($e['id'] ?? 0) > $afterId));
        }
        if ($limit > 0 && count($events) > $limit) {
            $events = array_slice($events, -$limit);
        }
        return array_reverse($events);
    }
}
```

### src/Registry/Whitelist.php

```php
<?php

namespace App\Registry;

class Whitelist
{
    private array $devices;
    private string $filePath;

    public function __construct(?string $filePath = null)
    {
        $this->filePath = $filePath ?? __DIR__ . '/../../config/whitelist.json';
        $this->load();
    }

    private function load(): void
    {
        if (!file_exists($this->filePath)) {
            $this->devices = []; return;
        }
        $this->devices = json_decode(file_get_contents($this->filePath), true) ?? [];
    }

    public function isAuthorized(string $imei): bool
    {
        return isset($this->devices[$imei]) && $this->devices[$imei]['enabled'] === true;
    }

    public function getModel(string $imei): ?string { return $this->devices[$imei]['model'] ?? null; }
    public function getLabel(string $imei): ?string { return $this->devices[$imei]['label'] ?? null; }
    public function all(): array { return $this->devices; }

    public function register(string $imei, string $model, string $label = ''): void
    {
        $this->devices[$imei] = [
            'model' => $model,
            'label' => $label ?: "Device $imei",
            'enabled' => true,
            'registered_at' => date('c'),
        ];
        $this->save();
    }

    public function unregister(string $imei): void { unset($this->devices[$imei]); $this->save(); }

    public function toggle(string $imei, bool $enabled): void
    {
        if (isset($this->devices[$imei])) {
            $this->devices[$imei]['enabled'] = $enabled;
            $this->save();
        }
    }

    private function save(): void
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents(
            $this->filePath,
            json_encode($this->devices, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }
}
```

### src/Registry/DeviceCapabilities.php

```php
<?php

namespace App\Registry;

class DeviceCapabilities
{
    private static ?array $profiles = null;
    private static ?string $profilesPath = null;

    public static function setProfilesPath(string $path): void
    {
        self::$profiles = null;
        self::$profilesPath = $path;
    }

    private static function load(): void
    {
        if (self::$profiles !== null) return;
        $path = self::$profilesPath ?? __DIR__ . '/../../config/capabilities.json';
        if (!file_exists($path)) {
            self::$profiles = []; return;
        }
        self::$profiles = json_decode(file_get_contents($path), true) ?? [];
    }

    public static function forModel(string $model): ?self
    {
        self::load();
        if (!isset(self::$profiles[$model])) return null;
        return new self($model, self::$profiles[$model]);
    }

    public static function allModels(): array { self::load(); return array_keys(self::$profiles); }
    public static function modelLabel(string $model): string
    { self::load(); return self::$profiles[$model]['label'] ?? $model; }

    public static function supportsAny(string $command): bool
    {
        self::load();
        foreach (self::$profiles as $profile) {
            if (in_array($command, $profile['passive'] ?? [], true)) return true;
            if (in_array($command, $profile['active'] ?? [], true)) return true;
        }
        return false;
    }

    public static function allKnownPassive(): array
    {
        self::load();
        $all = [];
        foreach (self::$profiles as $profile) $all = array_merge($all, $profile['passive'] ?? []);
        return array_values(array_unique($all));
    }

    public static function allKnownActive(): array
    {
        self::load();
        $all = [];
        foreach (self::$profiles as $profile) $all = array_merge($all, $profile['active'] ?? []);
        return array_values(array_unique($all));
    }

    // --- Instancia ---

    private string $model;
    private string $label;
    private ?string $supplier;
    private ?string $protocol;
    private ?string $transport;
    private ?string $sourceDoc;
    private array $passive;
    private array $active;
    private array $features;

    private function __construct(string $model, array $profile)
    {
        $this->model = $model;
        $this->label = $profile['label'] ?? $model;
        $this->supplier = $profile['supplier'] ?? null;
        $this->protocol = $profile['protocol'] ?? null;
        $this->transport = $profile['transport'] ?? null;
        $this->sourceDoc = $profile['source_doc'] ?? null;
        $this->passive = $profile['passive'] ?? [];
        $this->active = $profile['active'] ?? [];
        $this->features = $profile['features'] ?? [];
    }

    public function getModel(): string { return $this->model; }
    public function getLabel(): string { return $this->label; }
    public function getSupplier(): ?string { return $this->supplier; }
    public function getProtocol(): ?string { return $this->protocol; }
    public function getTransport(): ?string { return $this->transport; }
    public function getSourceDoc(): ?string { return $this->sourceDoc; }
    public function supportsPassive(string $type): bool { return in_array($type, $this->passive, true); }
    public function supportsActive(string $type): bool { return in_array($type, $this->active, true); }
    public function supportsFeature(string $feature): bool { return isset($this->features[$feature]); }
    public function getFeatures(): array { return $this->features; }
    public function getFeature(string $feature): ?array { return $this->features[$feature] ?? null; }
    public function getFeatureNames(): array { return array_keys($this->features); }
    public function getPassive(): array { return $this->passive; }
    public function getActive(): array { return $this->active; }

    public function featureForPassive(string $type): ?string
    {
        foreach ($this->features as $feature => $commands) {
            if (in_array($type, $commands['passive'] ?? [], true)) return $feature;
        }
        return null;
    }

    public function resolveFeatureActiveCommand(string $feature): ?string
    {
        $commands = $this->features[$feature]['active'] ?? [];
        foreach ($commands as $command) {
            if ($this->supportsActive($command)) return $command;
        }
        return null;
    }

    public function toArray(): array
    {
        return [
            'supplier' => $this->supplier,
            'protocol' => $this->protocol,
            'transport' => $this->transport,
            'source_doc' => $this->sourceDoc,
            'passive' => $this->passive,
            'active'  => $this->active,
            'features' => $this->features,
        ];
    }
}
```

### Executar o servidor

```bash
php server.php
```

Saida esperada:

```
============================================
  Servidor Multi-Vendor Relogios 4G
  WebSocket: ws://0.0.0.0:8080
  HTTP API:  http://0.0.0.0:8081
  Modelos:   WONLEX-PRO, WONLEX-HEALTH, VIVISTAR-CARE, VIVISTAR-LITE
============================================
[API] HTTP API em http://0.0.0.0:8081
```

---

## Simulador de Dispositivos

O simulador permite testar o servidor sem hardware fisico. Pode simular qualquer modelo definido nos perfis.

### Uso basico

```bash
# Listar modelos disponiveis
php simulator/simulate.php --list-models

# Mostrar capacidades de um modelo (incluindo features)
php simulator/simulate.php --model WONLEX-PRO --capabilities

# Simular modelo Wonlex PRO a enviar ritmo cardiaco
php simulator/simulate.php --model WONLEX-PRO --imei 865028000000306 \
    --command upHeartRate --data '{"date":"72","testType":0}'

# Simular modelo VIVISTAR LITE a enviar SOS com localizacao
php simulator/simulate.php --model VIVISTAR-LITE --imei 865028000000309 \
    --command AP10

# Modo interactivo (consola)
php simulator/simulate.php --model WONLEX-PRO --imei 865028000000306 --interactive

# Simular e ficar a ouvir comandos do servidor
php simulator/simulate.php --model WONLEX-PRO --imei 865028000000306 --listen

# Especificar servidor alternativo
php simulator/simulate.php --model VIVISTAR-CARE --imei 865028000000308 \
    --command AP03 --server ws://192.168.1.100:8080
```

### Modo interactivo

```
$ php simulator/simulate.php --model WONLEX-PRO --imei 865028000000306 --interactive

=== Simulador Interactivo ===
Comandos passiveis disponiveis:
  upHeartRate, upECG, upHRV, upPPG, upRR, ...

> upHeartRate {"date":"72","testType":0}
[OK] Confirmado (ident=482103)

> upECG {"date":"110.2,120.6,99.7","frequency":"500","collectionLogo":"87654321"}
[OK] Confirmado (ident=671234)

> quit
```

### Perfis de simulacao

Cada perfil em `simulator/profiles/` define dados de login e templates realistas:

**simulator/profiles/wonlex-pro.json:**

```json
{
    "model": "WONLEX-PRO",
    "login": {
        "deviceModel": "WONLEX-PRO",
        "firmware": "V3.2.1",
        "platform": "ASR Android",
        "batteryLevel": 85,
        "cpuModel": "SC9832E",
        "storageTotal": "8192",
        "storageFree": "4096",
        "chips": ["ASR3603", "MT6739"]
    },
    "dataTemplates": {
        "upHeartRate": { "date": "72", "testType": 0 },
        "upBP": { "date": "120/80/72", "testType": 0 },
        "upBO": { "date": "98", "testType": 0 },
        "upBodyTemperature": { "date": "36.5/31.2/28.0", "testType": 0 },
        "upLocation": {
            "baseStationType": 0,
            "positionDataType": "1",
            "gps": {
                "lon": "-9.1393",
                "lat": "38.7223",
                "height": 50,
                "satelliteNum": 10,
                "GSM": 100,
                "Type": 0
            },
            "baseStation": [
                { "mcc": 268, "mnc": 1, "lac": 1234, "ci": 5678, "rxlev": 51 }
            ],
            "wifi": [
                { "ssid": "HOME", "signal": "-52", "mac": "74-DE-2B-44-88-8C" }
            ]
        },
        "upBatch": { "heartRate": "100,98,97", "bp": "120/80/72", "bo": "98" }
    }
}
```

**simulator/profiles/vivistar-care.json:**

```json
{
    "model": "VIVISTAR-CARE",
    "login": {
        "deviceModel": "VIVISTAR-CARE",
        "firmware": "G4P_EMMC_HJ_V1.5",
        "platform": "4G LTE",
        "batteryLevel": 80,
        "deviceId": "3004627638"
    },
    "dataTemplates": {
        "AP01": {
            "gpsStatus": "A",
            "lat": "2232.9806N",
            "lng": "11404.9355E",
            "speed": "000.1",
            "gsmSignal": "060",
            "satellites": "009",
            "batteryLevel": "080",
            "lbs": { "mcc": 460, "mnc": 0, "lac": 9520, "cid": 3671 }
        },
        "AP03": {
            "gsmSignal": "060",
            "satellites": "009",
            "batteryLevel": "080",
            "fortification": "01",
            "workingMode": "02",
            "steps": 5555
        },
        "AP49": { "heartRate": 72 },
        "AP50": { "bodyTemperature": 36.5, "batteryLevel": 80 }
    }
}
```

---

## Workflow de Desenvolvimento Local

### A) Com Docker (recomendado)

```bash
# Iniciar todos os servicos (mysql, redis, ws, api, worker, nginx)
make up

# Verificar se esta tudo pronto
make ps

# Seguir logs
make logs
```

Acessos:

- **Dashboard web:** http://localhost/demo
- **API REST:** http://localhost/devices
- **Health check:** http://localhost/health
- **Metrics:** http://localhost/metrics
- **Swagger UI:** http://localhost/docs
- **WS devices:** ws://localhost

### A1. Executar comandos nos servicos

```bash
# Shell no WebSocket server
make shell

# Migrar whitelist JSON -> MySQL
make migrate

# Simular dispositivo
make simulate ARGS="--model WONLEX-PRO --imei 865028000000306 --interactive"

# Purge eventos antigos
make purge ARGS="--older-than 30 --dry-run"
```

### B) Local (PHP nativo, sem Docker)

```bash
# 1. Instalar dependencias
composer install

# 2. Iniciar servidor monolitico (WS + API)
php server.php
```

### C) Local com MySQL e Redis

```bash
# Iniciar apenas mysql e redis com docker
docker compose up -d mysql redis

# Iniciar servidor monolitico (usa env vars ou config)
php server.php
```

### D) Processos separados (modo producao)

```bash
# Terminal 1: WebSocket
php bin/server-ws.php

# Terminal 2: HTTP API
php bin/server-api.php

# Terminal 3: Worker (Redis -> MySQL)
php bin/worker.php
```

### 1. Simular dispositivo Wonlex PRO

```bash
php simulator/simulate.php --model WONLEX-PRO --imei 865028000000306 --interactive
```

### 2. Simular dispositivo VIVISTAR LITE

```bash
php simulator/simulate.php --model VIVISTAR-LITE --imei 865028000000309 \
    --command AP10
```

### 3. Abrir dashboard web

Abra `http://localhost/demo` (com Nginx) ou `http://localhost:8081/demo` (local) para ver dispositivos, simular eventos e visualizar dados em tempo real.

### 4. Consultar API REST

```bash
# Listar dispositivos
curl http://localhost/devices

# Ver ultimo evento de um dispositivo
curl http://localhost/devices/865028000000306/events/latest

# Ver features normalizadas
curl http://localhost/devices/865028000000306/features

# Enviar comando via API
curl -X POST http://localhost/devices/865028000000306/command \
    -H 'Content-Type: application/json' \
    -d '{"type":"find","data":{}}'

# Enviar comando por feature
curl -X POST http://localhost/devices/865028000000306/features/heart_rate/command \
    -H 'Content-Type: application/json' \
    -d '{"data":{}}'

# Disparar simulador via API
curl -X POST http://localhost/demo/simulate \
    -H 'Content-Type: application/json' \
    -d '{"imei":"865028000000306","model":"WONLEX-PRO","type":"upHeartRate"}'
```

### 5. Testar whitelist

```bash
# Tentar conectar com IMEI nao autorizado (deve falhar)
php simulator/simulate.php --model WONLEX-PRO --imei 865028000000999 --command upHeartRate
# Esperado: login_error - IMEI not authorized
```

### 6. Testar capacidades

```bash
# Tentar comando nao suportado pelo modelo VIVISTAR LITE
php simulator/simulate.php --model VIVISTAR-LITE --imei 865028000000309 \
    --command AP07
# Esperado: error - capability_not_supported
```

### 7. Swagger UI

Abra `http://localhost/docs` para a documentacao interativa da API.

---

## Referencia de Comandos

### Convencao de nomes

| Prefixo | Direcao             | Descricao                                 |
| ------- | ------------------- | ----------------------------------------- |
| `up*`   | Relogio -> Servidor | Dados enviados pelo dispositivo (passive) |
| `dn*`   | Servidor -> Relogio | Comandos enviados ao dispositivo (active) |

Nota: Os comandos VIVISTAR usam nomenclatura propria (`AP01`, `BP12`, etc.). Consulte `config/capabilities.json` para a lista completa.

### Categoria: Monitorizacao de Sinais Vitais

| Comando             | Direcao | Descricao               | Dados                                 |
| ------------------- | ------- | ----------------------- | ------------------------------------- |
| `upHeartRate`       | up      | Frequencia cardiaca     | `date` (BPM)                          |
| `upBP`              | up      | Pressao arterial        | `date` ("sist/diast/pulso")           |
| `upBO`              | up      | Oxigenio no sangue SpO2 | `date` (%)                            |
| `upBodyTemperature` | up      | Temperatura corporal    | `date` ("corpo/pele/ambiente")        |
| `upBS`              | up      | Glicemia                | `date` (mmol/L)                       |
| `upBF`              | up      | Gordura corporal        | `date`                                |
| `upUA`              | up      | Acido urico             | `data` (umol/L)                       |
| `upSleep`           | up      | Dados de sono           | `value` ("prof/leve/desp/AC")         |
| `upTodayActivity`   | up      | Resumo diario           | `step`, `exerciseTime`, `standTime`   |
| `upRun`             | up      | Corrida                 | `exerciseTime`, `consumed`, `mileage` |
| `upWalk`            | up      | Caminhada               | `exerciseTime`, `consumed`, `mileage` |
| `upKcal`            | up      | Calorias                | `date`                                |
| `upBreathe`         | up      | Respiraçao              | `value`                               |

### Categoria: Diagnostico Avancado (Wonlex)

| Comando          | Direcao | Descricao                                   |
| ---------------- | ------- | ------------------------------------------- |
| `upECG`          | up      | Eletrocardiograma (sinal eletrico cardiaco) |
| `upHRV`          | up      | Variabilidade cardiaca (intervalos R-R)     |
| `upPPG`          | up      | Fotopletismografia (sinal otico)            |
| `upRR`           | up      | Taxa respiratoria                           |
| `upBatch`        | up      | Lote multiplos registos                     |
| `upSensorValue`  | up      | Valores agregados de sensores               |
| `upSensorValues` | up      | Lista de valores de sensores                |

### Categoria: Localizacao

| Comando                                    | Descricao                 |
| ------------------------------------------ | ------------------------- |
| `upLocation` (Wonlex)                      | GPS + LBS + WiFi          |
| `AP01` / `AP02` / `AP10` (VIVISTAR)        | GPS + LBS + WiFi          |
| `AP03` (VIVISTAR)                          | Heartbeat com localizacao |
| `BP16` (VIVISTAR)                          | Pedido de localizacao     |
| `dnLocation` / `locationInterval` (Wonlex) | Pedido de localizacao     |

### Categoria: Seguranca e Alertas

| Comando                              | Descricao                      |
| ------------------------------------ | ------------------------------ |
| `AP10` (VIVISTAR) / `upSOS` (Wonlex) | Botao SOS                      |
| `upPickup` (Wonlex)                  | Dispositivo levantado do pulso |
| `SOSNumber` / `deviceConfig`         | Configurar numeros SOS         |

### Categoria: Comunicacao (Wonlex)

| Comando                    | Descricao                          |
| -------------------------- | ---------------------------------- |
| `upVideoCallWithAPP`       | Videochamada iniciada pelo relogio |
| `upWatchHangUp`            | Desligar chamada                   |
| `upCallLog`                | Registo de chamadas                |
| `upSMS`                    | Mensagem SMS                       |
| `downVideoCallWithAPPInfo` | Iniciar videochamada do servidor   |
| `downChatVoice`            | Mensagem de audio                  |
| `downPPmessage`            | Mensagem push                      |

### Categoria: Controlo Remoto

| Comando                   | Descricao                          |
| ------------------------- | ---------------------------------- |
| `reset` / `upReset`       | Reiniciar / notificacao de reset   |
| `restart`                 | Desligar e ligar                   |
| `powerOff` / `upShutdown` | Desligar / notificacao de shutdown |
| `find`                    | Emitir som no relogio (localizar)  |
| `photography`             | Capturar fotografia remotamente    |
| `OTA` / `upGetOTA`        | Actualizacao firmware              |
| `deviceConfig`            | Configuracoes gerais               |
| `alarmClock`              | Configurar alarme                  |
| `disturb` / `disturb_two` | Modo nao incomodar                 |
| `autoAnswer`              | Atendimento automatico             |
| `msgNotice`               | Notificacao push                   |

### Categoria: VIVISTAR

| Comando                  | Descricao                          |
| ------------------------ | ---------------------------------- |
| `AP07`                   | Mensagem audio                     |
| `AP49` / `APHT` / `APHP` | Batimento cardiaco / tensao / SpO2 |
| `AP50` / `APHD`          | Temperatura / ECG                  |
| `BP12`                   | Configurar SOS                     |
| `BP14` / `BP84`          | Lista de contactos                 |
| `BP28` / `BP40`          | Mensagens                          |
| `BP33` / `BP86`          | Configuracoes                      |
| `BP76` / `BP77`          | Deteccao de queda                  |
| `BP85`                   | Lembretes                          |
| `BPXL`                   | Pedido de batimento cardiaco       |
| `BPXY`                   | Pedido de tensao arterial          |
| `BPXT` / `BP87`          | Pedido de temperatura              |
| `BPXZ`                   | Pedido de SpO2                     |
| `BPJZ`                   | Calibracao de tensao               |

---

## Seguranca e Boas Praticas

### Autenticacao

- **Whitelist obrigatoria:** Apenas IMEIs registados e ativos podem comunicar
- **Modelo esperado:** O whitelist define qual o modelo esperado para cada IMEI
- **Disabled:** IMEIs desativados sao rejeitados ao nivel do handshake
- **Session Token:** Gerado no login_ok, obrigatorio em todos os comandos subsequentes
- **Session tokens sao efemeros:** Armazenados apenas em RAM (nao persistem em BD/Redis)

### Rede

- **TLS/SSL:** Usar WSS em producao. Configuracao incluida em `config/nginx-tls.conf`. Gerar certificados com `make ssl-setup`
- **Proxy reverso:** Nginx como terminador TLS e balanceador de carga
- **Firewall:** Restringir acesso ao servidor apenas aos IPs necessarios

### Validacao

- **Capability check:** Comandos nao declarados no perfil do modelo sao rejeitados
- **Session validation:** Token de sessao verificado em cada mensagem
- **Payload sanitization:** Tokens e chaves de encriptacao removidos do armazenamento de eventos
- **Rate limiting:** Por IMEI via Redis (contadores INCR + EXPIRE) e por IP via Nginx (limit_req, 30 req/s)
- **Logging:** Registar todas as tentativas de login (sucesso e falha)

### Persistencia

- **MySQL:** Tabelas `devices` (whitelist) e `device_events` (historico) com InnoDB e utf8mb4
- **Redis Streams:** Buffer rapido de eventos com MAXLEN 10000; worker dedicado persiste em MySQL
- **Graceful degradation:** Sem MySQL, usa ficheiros JSON + RAM. Sem Redis, funcionalidades Redis sao desativadas sem crash
- **Event purge:** Script `bin/purge-events.php` com `--older-than 30 --keep-per-device 1000`

### Infraestrutura

- **Nginx:** Seguranca (server_tokens off, headers de seguranca), rate limiting (30 req/s), TLS
- **Docker:** isolation de servicos, healthchecks, restart unless-stopped

---

## Proximos Passos

- [x] **Base de dados:** MySQL com tabelas `devices` e `device_events`
- [x] **Redis:** Streams de eventos, registo online, rate limiting, comando API->WS
- [x] **Worker:** Processo dedicado para persistencia Redis -> MySQL
- [x] **Separacao de processos:** WebSocket e HTTP API em processos independentes
- [x] **Load balancing:** Nginx com sticky sessions (ip_hash) e upstream HTTP
- [x] **Production hardening:** Health check, metrics, TLS, purge, security headers
- [ ] **Autenticacao de clientes API:** JWT ou API keys para o dashboard web
- [ ] **Notificacoes:** Alertas em tempo real (WebSocket push) para o dashboard
- [ ] **Multi-tenancy:** Suporte para multiplas organizacoes/empresas
- [ ] **Heartbeat timeout:** Fechar ligacoes inactivas automaticamente
- [ ] **Testes automatizados:** Cobertura de testes para handshake, protocolos e API

---

## Licenca

Documento interno para fins de integracao e desenvolvimento.
