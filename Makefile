# ---------------------------------------------------------------------------
# osTicket — atajos de desarrollo
#
#   make            ayuda
#   make lint       comprobación de sintaxis (rápida, sin dependencias)
#   make audit      análisis de seguridad (falla con hallazgos altos)
#   make test       suite de pruebas estáticas del proyecto
#   make ci         lint + audit + security (todo lo que se ejecuta antes de integrar)
# ---------------------------------------------------------------------------

PHP      ?= php
JOBS     ?= 4
BASELINE ?= tools/audit/baseline.json
ROOT     := $(patsubst %/,%,$(dir $(abspath $(lastword $(MAKEFILE_LIST)))))

.DEFAULT_GOAL := help
.PHONY: help lint audit audit-all audit-json baseline test security ci clean perms serve \
	docker-up docker-down docker-logs docker-shell docker-build docker-install \
	docker-reset docker-test docker-security docker-prod docker-composer docker-qa

help: ## Muestra esta ayuda
	@printf '\nUso: make <objetivo>\n\n'
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)
	@printf '\nVariables: PHP=$(PHP) JOBS=$(JOBS) BASELINE=$(BASELINE)\n\n'

lint: ## Comprueba la sintaxis de todos los archivos PHP
	@PHP_BIN=$(PHP) JOBS=$(JOBS) $(ROOT)/tools/lint.sh

audit: ## Análisis de seguridad; falla si hay hallazgos altos nuevos
	@$(PHP) $(ROOT)/tools/audit/scan.php --severity=high \
		--baseline=$(ROOT)/$(BASELINE)

audit-all: ## Informe completo (incluye severidad baja)
	@$(PHP) $(ROOT)/tools/audit/scan.php --severity=low --no-color

audit-json: ## Informe completo en JSON (para integrarlo en otras herramientas)
	@$(PHP) $(ROOT)/tools/audit/scan.php --severity=low --json

baseline: ## Registra los hallazgos actuales como línea base aceptada
	@$(PHP) $(ROOT)/tools/audit/scan.php \
		--write-baseline=$(ROOT)/$(BASELINE)

test: ## Ejecuta la suite de pruebas del proyecto
	@$(PHP) $(ROOT)/setup/test/run-tests.php

security: ## Comprueba la versión de PHP frente a php2shell (CVE-2026-109618/19)
	@$(PHP) $(ROOT)/tools/security/php-cve-check.php

ci: lint audit security ## Verificación completa para integración continua

clean: ## Elimina artefactos temporales de desarrollo
	@rm -rf $(ROOT)/vendor/.phpunit.result.cache
	@find $(ROOT)/tools -name '*.tmp' -delete 2>/dev/null || true

perms: ## Verifica los permisos recomendados de ost-config.php
	@if [ -f "$(ROOT)/include/ost-config.php" ]; then \
		stat -c '%a %n' "$(ROOT)/include/ost-config.php"; \
		mode=$$(stat -c '%a' "$(ROOT)/include/ost-config.php"); \
		case "$$mode" in \
			644|640|600) echo "✓ Permisos correctos";; \
			*) echo "⚠ Se recomienda: chmod 640 include/ost-config.php";; \
		esac; \
	else \
		echo "Aún no existe include/ost-config.php (instalación pendiente)"; \
	fi

serve: ## Servidor de desarrollo sin Docker (requiere PHP + BD local)
	@PHP_CLI_SERVER_WORKERS=4 $(PHP) -S 0.0.0.0:8080 -t $(ROOT)

# ---------------------------------------------------------------------------
# Docker — no requiere PHP ni MySQL en la máquina
# ---------------------------------------------------------------------------
ENV_FILE := $(ROOT)/.env

$(ENV_FILE):
	@cp $(ROOT)/.env.example $@
	@sed -i "s|^OST_SECRET_SALT=.*|OST_SECRET_SALT=$$(openssl rand -hex 32)|" $@ 2>/dev/null || \
	 sed -i '' "s|^OST_SECRET_SALT=.*|OST_SECRET_SALT=$$(openssl rand -hex 32)|" $@
	@echo "✓ .env creado con una SECRET_SALT aleatoria"

docker-up: $(ENV_FILE) ## Levanta el entorno (http://localhost:8080)
	@docker compose up -d --build
	@echo ""
	@echo "  Portal del cliente : http://localhost:8080"
	@echo "  Panel de agentes   : http://localhost:8080/scp/"
	@echo "  Instalador         : http://localhost:8080/setup/"
	@echo "  Correo de pruebas  : http://localhost:8025 (Mailpit)"
	@echo ""
	@echo "  Los logs: make docker-logs"

docker-down: ## Detiene los contenedores (conserva la base de datos)
	@docker compose down --remove-orphans

docker-reset: ## Detiene y BORRA la base de datos (destructivo)
	@docker compose down -v --remove-orphans
	@echo "✓ Volumen eliminado: la próxima vez empieza de cero"

docker-logs: ## Sigue los logs de nginx y php
	@docker compose logs -f --tail=100 nginx php

docker-shell: ## Shell interactivo en el contenedor de PHP
	@docker compose exec php sh

docker-build: ## Reconstruye la imagen de PHP
	@docker compose build --pull --no-cache php

docker-install: ## Prepara la base de datos y dependencias tras un volumen nuevo
	@docker compose exec php php /var/www/html/docker/php/setup-db.php
	@docker compose run --rm tools php tools/audit/scan.php --severity=high \
		--baseline=tools/audit/baseline.json

docker-test: ## Ejecuta lint + auditoría dentro del contenedor
	@docker compose run --rm tools make ci

docker-security: ## Comprueba php2shell y la postura de seguridad del contenedor
	@docker compose exec php php tools/security/php-cve-check.php

docker-composer: ## Instala las dependencias de desarrollo con Composer (en contenedor)
	@docker compose --profile tools run --rm composer install
	@echo "✓ vendor/ listo. Ejecuta 'make docker-qa' para el análisis estático"

docker-qa: ## PHPStan + PHP_CodeSniffer dentro del contenedor (requiere vendor/)
	@docker compose --profile tools run --rm tools php vendor/bin/phpstan analyse --no-progress
	@docker compose --profile tools run --rm tools php vendor/bin/phpcs

docker-prod: ## Construye la imagen de producción (sin Xdebug)
	@docker build --target prod \
		--build-arg PHP_VERSION=$(shell sed -n 's/^PHP_VERSION=//p' $(ROOT)/.env 2>/dev/null || echo 8.4.27) \
		-t osticket:prod .
