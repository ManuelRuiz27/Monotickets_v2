COMPOSE = docker compose

.PHONY: up down logs seed test

up:
$(COMPOSE) up -d --build

down:
$(COMPOSE) down --remove-orphans

logs:
$(COMPOSE) logs -f app queue scheduler

seed:
$(COMPOSE) run --rm app bash -lc "composer install && php artisan migrate --force && php artisan db:seed --force"

test:
$(COMPOSE) run --rm app bash -lc "composer install && php artisan test"
