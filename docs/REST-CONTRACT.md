# REST Contract (Control Plane)

Status: architecture contract for the target model. Some parts are planned and not fully implemented yet.

## Purpose

REST is the external control plane for:

- client and device governance
- command submission
- historical and state queries
- policy and authorization enforcement

REST is used by:

- client backends
- platform admins

REST is not intended for watch-native protocol traffic.

## Audience and trust model

- Client backend systems call REST.
- Mobile/web frontends should call client backend APIs, not platform admin endpoints directly.
- Every request is tenant-scoped (`client_id`) except explicit platform admin operations.

## Authentication and authorization

Target behavior:

- Each request is authenticated.
- The caller identity maps to exactly one client tenant unless admin-scoped.
- Resource access is authorized by ownership (`device.client_id`).

Authorization rule:

- For non-admin callers, access is allowed only when `requested_device.client_id == caller.client_id`.

## Resource model

Core resources:

- `client`
- `device`
- `event`
- `command`

Canonical principles:

- JSON only
- stable top-level shape (`data`, `meta`, `error`)
- canonical fields, vendor-native details as optional metadata

## Endpoint groups

## 1) Client governance

- `GET /clients`
- `POST /clients`
- `GET /clients/{id}`
- `PUT /clients/{id}`
- `DELETE /clients/{id}`
- `GET /clients/{id}/devices`

## 2) Device governance

- `GET /devices`
- `POST /devices`
- `PUT /devices/{imei}`
- `DELETE /devices/{imei}`

Required policy checks:

- global model allowlist validation
- whitelist binding consistency (`imei -> supplier/model -> client`)

## 3) Event queries

- `GET /events/recent`
- `GET /devices/{imei}/events/latest`

## 4) Command submission

- `POST /devices/{imei}/command`
- `POST /devices/{imei}/features/{feature}/command`

External command entrypoint is REST-only.

Target command response:

- returns a `commandId` and initial state (`accepted` or failure)
- lifecycle updates flow through MQTT and REST query endpoints

## 5) Operational endpoints

- `GET /health`
- `GET /metrics`
- `GET /openapi.json`
- `GET /docs`

## Command lifecycle contract

Minimum lifecycle states:

- `requested`
- `accepted`
- `dispatched`
- `ack`
- `timeout`
- `failed`

Lifecycle requirements:

- stable `commandId`
- correlation metadata for protocol adapters
- idempotency support for retries from client backend

## Error model

All errors return JSON with a machine-readable code.

Recommended fields:

- `error.code`
- `error.message`
- `error.details` (optional)
- `error.requestId` (optional)

Examples of domain errors:

- `device_not_found`
- `device_offline`
- `command_not_supported`
- `feature_not_supported`
- `model_not_allowed`
- `forbidden_client_scope`

## Compatibility policy

- REST contract is vendor-neutral.
- Adapter changes must not break REST schema.
- New fields should be additive and backward compatible.

## Current repository status

Implemented today:

- core device/client/event/command endpoints exist
- model validation against capability profiles exists

Planned:

- full tenant authz enforcement on every endpoint
- full command lifecycle persistence and query surface
- explicit model-allowlist governance API
