.PHONY: up down sh composer console test stan cs-fix migrate

up:
	docker compose up -d --build

down:
	docker compose down

sh:
	docker compose exec php sh

composer:
	docker compose exec php composer $(filter-out $@,$(MAKECMDGOALS))

console:
	docker compose exec php php bin/console $(filter-out $@,$(MAKECMDGOALS))

test:
	docker compose exec -e APP_ENV=test php php bin/phpunit

stan:
	docker compose exec php vendor/bin/phpstan analyse

cs-fix:
	docker compose exec php vendor/bin/php-cs-fixer fix

migrate:
	docker compose exec php php bin/console doctrine:migrations:migrate -n

%:
	@:
