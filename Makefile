.PHONY: up down build rebuild logs shell migrate simulate worker worker-logs ws api nginx nginx-logs purge ssl-setup redis-cli ps

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
