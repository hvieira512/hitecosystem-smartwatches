# MQTT Contract (Data Plane)

Status: architecture contract for external backend integrations. Some operational automation is planned and not fully implemented yet.

## Purpose

MQTT is the external data plane for:

- real-time telemetry delivery
- command result and status fanout
- operational stream consumption

MQTT is used by:

- client backend services only

MQTT is not the external command entrypoint. External command requests are submitted via REST.

## Access model

- Per-client service account credentials.
- One tenant namespace per client.
- ACLs restrict publish/subscribe by namespace and topic direction.

Recommended namespace:

- `tenant/{client_id}/...`

## Topic catalog

Recommended external topics:

- `tenant/{client_id}/device/{imei}/telemetry`
- `tenant/{client_id}/device/{imei}/status`
- `tenant/{client_id}/device/{imei}/command/result`
- `tenant/{client_id}/device/{imei}/errors`

Optional aggregate streams:

- `tenant/{client_id}/telemetry`
- `tenant/{client_id}/command/result`

Internal-only topics can exist separately and are not part of external contract.

## Publish/subscribe policy

External client backend:

- Subscribe: telemetry, status, command result, errors
- Publish: none for direct watch command dispatch

Platform services:

- Publish to tenant topics after validation and ownership checks
- Publish command result transitions from internal command state machine

## Payload contract

All MQTT payloads are JSON UTF-8.

Common envelope fields:

- `schemaVersion`
- `eventType`
- `eventId`
- `occurredAt`
- `clientId`
- `imei`
- `model`
- `supplier`
- `correlation` (optional)
- `data`

Canonical event types:

- `telemetry.received`
- `device.status.changed`
- `command.state.changed`
- `integration.error`

## Example payloads

Telemetry event example:

```json
{
  "schemaVersion": "1.0",
  "eventType": "telemetry.received",
  "eventId": "evt_01J...",
  "occurredAt": "2026-05-14T16:30:00Z",
  "clientId": 42,
  "imei": "865028000000306",
  "model": "WONLEX-PRO",
  "supplier": "Wonlex",
  "data": {
    "feature": "heart_rate",
    "nativeType": "upHeartRate",
    "normalized": {
      "heartRateBpm": 72
    }
  }
}
```

Command state change event example:

```json
{
  "schemaVersion": "1.0",
  "eventType": "command.state.changed",
  "eventId": "evt_01J...",
  "occurredAt": "2026-05-14T16:31:00Z",
  "clientId": 42,
  "imei": "865028000000306",
  "data": {
    "commandId": "cmd_01J...",
    "state": "ack",
    "native": {
      "requestType": "dnHeartRate",
      "responseType": "upHeartRate"
    }
  }
}
```

## QoS and retain policy

Recommended defaults:

- Telemetry topics: QoS 0 or QoS 1 depending SLA
- Command result topics: QoS 1
- Status topics: QoS 1 with retained last-known state when appropriate
- Errors topics: QoS 1

## Ordering and delivery notes

- Ordering is guaranteed only per topic partition semantics of the broker and publisher behavior.
- Consumers must treat messages as at-least-once when QoS 1 is used.
- Consumers must use `eventId` for idempotent processing.

## Security requirements

- TLS enabled in production.
- Per-client credential rotation policy.
- ACL deny-by-default.
- No wildcard ACL granting cross-tenant topic access.

## Compatibility policy

- Contract is additive-first.
- Breaking topic or payload changes require version bump and migration path.
- Native/vendor protocol changes must remain adapter-internal and not break external MQTT schema.

## Relationship to REST

- REST: command/control/history
- MQTT: real-time stream delivery
- External commands enter via REST and appear on MQTT as lifecycle events/results.
