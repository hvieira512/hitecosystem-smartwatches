# End-to-End Testing Runbook

This runbook verifies the full flow with 3 terminals:

- device login
- passive telemetry (device -> server)
- active command round-trip (API -> device -> server reply)

It uses real project processes and simulator protocols.

## Prerequisites

- Docker running
- Whitelist contains test devices:
  - `865028000000306` (`WONLEX-PRO`)
  - `865028000000308` (`VIVISTAR-CARE`)

## Terminal 1: Start Stack + Watch Logs

```bash
make rebuild
docker compose logs -f ws
```

What this does:

- starts MySQL, Redis, WS ingress, API, worker, nginx
- keeps WS logs open so you can see:
  - connection open/close
  - login accepted/rejected
  - passive event ingestion
  - command dispatch and reply

Expected startup log lines:

- `ws://0.0.0.0:8080`
- `tcp://0.0.0.0:9000 (Vivistar)`

## Terminal 2 + 3: Wonlex Flow (WebSocket)

### A) Passive action test (single command)

Terminal 2:

```bash
docker compose exec ws php simulator/simulate.php \
  --server ws://127.0.0.1:8080 \
  --model WONLEX-PRO \
  --imei 865028000000306 \
  --command upHeartRate \
  --data '{"heartRate":72}'
```

What this does:

- opens WS connection as a Wonlex watch
- performs login handshake
- sends passive telemetry `upHeartRate`
- waits for server acknowledgment

What to look for in Terminal 1 logs:

- `Login OK: IMEI=865028000000306`
- `data IMEI=865028000000306, type=upHeartRate`

### B) Active action round-trip (listen + API command)

Terminal 2:

```bash
docker compose exec ws php simulator/simulate.php \
  --server ws://127.0.0.1:8080 \
  --model WONLEX-PRO \
  --imei 865028000000306 \
  --listen
```

What this does:

- keeps simulated watch online
- waits for server downlink commands
- auto-replies as the device

Terminal 3:

```bash
curl -s -X POST http://127.0.0.1:8081/devices/865028000000306/features/heart_rate/command \
  -H 'Content-Type: application/json' \
  -d '{"data":{}}'
```

What this does:

- sends REST control-plane request
- API resolves feature -> native command
- WS server sends command to online watch
- watch replies back

What to look for:

- Terminal 2:
  - `[COMMAND] dnHeartRate`
  - `[reply]`
- Terminal 1:
  - `cmd IMEI=865028000000306, type=dnHeartRate`
  - `reply IMEI=865028000000306, type=dnHeartRate`

## Terminal 2 + 3: Vivistar Flow (Native TCP)

### A) Passive action test (single command)

Terminal 2:

```bash
make simulate-vivistar-tcp
```

Equivalent explicit command:

```bash
docker compose exec ws php simulator/simulate.php \
  --server tcp://127.0.0.1:9000 \
  --model VIVISTAR-CARE \
  --imei 865028000000308 \
  --command AP49
```

What this does:

- opens native TCP connection (not WebSocket)
- sends Vivistar login `AP00`
- sends passive packet `AP49`
- waits for `BP49`

What to look for in Terminal 1 logs:

- `Login OK: IMEI=865028000000308`
- `data IMEI=865028000000308, type=AP49`

### B) Active action round-trip (listen + API command)

Terminal 2:

```bash
make listen-vivistar-tcp
```

Terminal 3:

```bash
curl -s -X POST http://127.0.0.1:8081/devices/865028000000308/command \
  -H 'Content-Type: application/json' \
  -d '{"type":"BPXL","data":{}}'
```

What this does:

- API sends native Vivistar downlink `BPXL`
- native TCP watch receives it
- watch replies with `APXL`

What to look for:

- Terminal 2:
  - `[COMMAND] BPXL`
  - `[reply] IWAPXL,...#`
- Terminal 1:
  - `cmd IMEI=865028000000308, type=BPXL`
  - `reply IMEI=865028000000308, type=APXL`

## Quick Troubleshooting

If login fails:

- check `config/whitelist.json` IMEI and model match
- ensure `enabled: true`

If device offline in API command:

- confirm listen simulator is running in Terminal 2
- check WS logs for disconnects

If Vivistar TCP fails to connect:

- verify port mapping `9000:9000` is up
- check WS startup logs for `Vivistar TCP ingress`

## Why 3 terminals?

- Terminal 1 = observability (ground truth logs)
- Terminal 2 = device behavior
- Terminal 3 = control-plane/API actions

This separation makes debugging easy: you can see exactly where flow breaks (API, transport, adapter, or session/auth).
