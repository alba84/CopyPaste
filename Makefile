.PHONY: up down sh composer console test stan cs-fix cs-check deptrac coverage qa migrate

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
	docker compose exec php php bin/console cache:warmup --env=dev
	docker compose exec php vendor/bin/phpstan analyse

cs-fix:
	docker compose exec php vendor/bin/php-cs-fixer fix

cs-check:
	docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff

deptrac:
	docker compose exec php vendor/bin/deptrac analyse

coverage:
	docker compose exec -e APP_ENV=test -e XDEBUG_MODE=off php php bin/phpunit --coverage-clover var/coverage/clover.xml
	docker compose exec php php tools/check-coverage.php

migrate:
	docker compose exec php php bin/console doctrine:migrations:migrate -n

qa: cs-check stan deptrac test

%:
	@:
