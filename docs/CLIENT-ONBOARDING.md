# Client Onboarding Guide

Status: integration guide for external client backends.

## Who this is for

This guide is for backend teams integrating with the platform.

Integration model:

- Use REST for control and command submission.
- Use MQTT for real-time event consumption.
- Do not connect mobile/web frontends directly to broker credentials.

## Prerequisites

Before onboarding, the platform team provides:

- client tenant record (`client_id`)
- approved supplier/model policy scope
- assigned IMEIs for that client
- REST credentials
- MQTT service account credentials and ACL scope

## Step 1: Confirm ownership and model policy

Validate with platform team:

- which IMEIs belong to your client
- which supplier/model combinations are approved globally
- whether each assigned device is enabled in whitelist

## Step 2: REST setup

Configure your backend with:

- base URL
- authentication credentials
- timeout and retry policy
- idempotency key strategy for command submission

Minimum REST usage pattern:

- query devices and features
- submit commands via REST
- query command/state history as needed

## Step 3: MQTT setup

Configure your backend MQTT consumer with:

- broker host and port
- TLS config (production)
- service account username/password (or cert)
- allowed topics under your tenant namespace

Subscribe to:

- telemetry topics
- status topics
- command result topics
- integration error topics

## Step 4: Data handling model

Recommended backend flow:

1. REST command request is submitted by your backend.
2. Platform validates ownership and model capability.
3. Platform dispatches internally to device adapter path.
4. Command lifecycle updates are published to MQTT.
5. Your backend stores/forwards events to product services.

## Step 5: Reliability checklist

- Use retries with backoff for REST transient failures.
- Use idempotency keys for command submission.
- Treat MQTT QoS 1 messages as at-least-once.
- Deduplicate events with `eventId`.
- Monitor command timeout and failed terminal states.

## Step 6: Security checklist

- Never expose MQTT credentials in frontend apps.
- Rotate credentials periodically.
- Restrict broker egress/ingress by IP/network policy where possible.
- Log access attempts and authorization failures.
- Ensure no cross-tenant topic subscriptions are permitted.

## Operational runbook basics

Monitor:

- REST error rates
- command lifecycle latency
- MQTT consumer lag and disconnect rates
- tenant authorization failures

Escalation data to include:

- `client_id`
- `imei`
- `commandId` and `eventId`
- timestamps and affected topics/endpoints

## Integration acceptance criteria

A client integration is considered ready when:

- backend can authenticate to REST and MQTT
- backend can list only its own devices
- backend can submit command and receive lifecycle updates
- telemetry stream is consumed and persisted reliably
- all security checks pass with no cross-tenant leakage

## Current-state note

Repository runtime is currently transitioning toward the target architecture. Core REST and internal messaging exist, while full external MQTT onboarding automation and adapter separation are roadmap items.
