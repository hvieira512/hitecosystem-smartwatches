.PHONY: up down build rebuild logs shell migrate simulate simulate-vivistar-tcp listen-vivistar-tcp worker worker-logs ws api nginx nginx-logs purge ssl-setup redis-cli ps dev dev-ws dev-api

up:
	docker compose up -d

down:
	docker compose down

build:
	docker compose build

rebuild: down build up

logs:
	docker compose logs -f

shell:
	docker compose exec ws bash

api-shell:
	docker compose exec api bash

nginx-shell:
	docker compose exec nginx sh

migrate:
	docker compose exec ws php bin/migrate.php --seed 2>/dev/null || docker compose exec api php bin/migrate.php --seed

simulate:
	docker compose exec ws php simulator/simulate.php $(ARGS)

# Native Vivistar TCP simulation (defaults can be overridden).
# Example:
#   make simulate-vivistar-tcp IMEI=865028000000308 COMMAND=AP49 DATA='{"heartRate":68}'
simulate-vivistar-tcp:
	docker compose exec ws php simulator/simulate.php \
		--server tcp://127.0.0.1:9000 \
		--model $(or $(MODEL),VIVISTAR-CARE) \
		--imei $(or $(IMEI),865028000000308) \
		--command $(or $(COMMAND),AP49) \
		$(if $(DATA),--data '$(DATA)',)

# Native Vivistar TCP listen mode (for API -> device downlink tests).
# Example:
#   make listen-vivistar-tcp IMEI=865028000000308
listen-vivistar-tcp:
	docker compose exec ws php simulator/simulate.php \
		--server tcp://127.0.0.1:9000 \
		--model $(or $(MODEL),VIVISTAR-CARE) \
		--imei $(or $(IMEI),865028000000308) \
		--listen

worker:
	docker compose up -d worker

worker-logs:
	docker compose logs -f worker

ws:
	docker compose up -d ws

api:
	docker compose up -d api

nginx:
	docker compose up -d nginx

nginx-logs:
	docker compose logs -f nginx

purge:
	docker compose exec api php bin/purge-events.php $(ARGS)

purge-dry-run:
	docker compose exec api php bin/purge-events.php --dry-run $(ARGS)

ssl-setup:
	mkdir -p config/ssl && openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
		-keyout config/ssl/privkey.pem \
		-out config/ssl/fullchain.pem \
		-subj "/C=PT/O=Health Smartwatches/CN=localhost" && \
		echo "Certificados SSL criados em config/ssl/"

redis-cli:
	docker compose exec redis redis-cli

ps:
	docker compose ps

dev-ws:
	docker compose stop ws 2>/dev/null; \
	docker compose run --rm --service-ports --name health-ws-dev ws bin/dev.sh php bin/server-ws.php

dev-api:
	docker compose stop api 2>/dev/null; \
	docker compose run --rm --service-ports --name health-api-dev api bin/dev.sh php bin/server-api.php

dev: dev-ws dev-api
