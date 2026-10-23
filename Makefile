# ---------------------------------------------------------------------------
# osTicket — atajos de desarrollo
#
#   make            ayuda
#   make lint       comprobación de sintaxis (rápida, sin dependencias)
#   make audit      análisis de seguridad (falla con hallazgos altos)
#   make test       suite de pruebas estáticas del proyecto
#   make theme      integridad del tema claro/oscuro (sin navegador)
#   make metrics    tamaño y deuda técnica del código propio
#   make ci         lint + audit + security + theme (antes de integrar)
# ---------------------------------------------------------------------------

# Si el equipo no tiene PHP, tools/php.sh lo ejecuta en el contenedor de
# herramientas: `make theme` funciona igual con Docker y sin instalar nada.
PHP      ?= $(ROOT)/tools/php.sh
JOBS     ?= 4
BASELINE ?= tools/audit/baseline.json
ROOT     := $(patsubst %/,%,$(dir $(abspath $(lastword $(MAKEFILE_LIST)))))

.DEFAULT_GOAL := help
.PHONY: help php-version lint audit audit-all audit-json baseline test test-legacy unit unit-list security theme metrics metrics-json ci clean perms serve browser-audit \
	dark-audit dark-audit-client contrast-audit contrast-audit-client docker-dark-audit \
	docker-up docker-down docker-logs docker-shell docker-build docker-install \
	docker-reset docker-test docker-security docker-prod docker-composer docker-qa \
	docker-bench docker-urls

help: ## Muestra esta ayuda
	@printf '\nUso: make <objetivo>\n\n'
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)
	@printf '\nVariables: PHP=$(PHP) JOBS=$(JOBS) BASELINE=$(BASELINE)\n'
	@printf 'Sin PHP en el equipo, los objetivos lo ejecutan en el contenedor (tools/php.sh).\n\n'

lint: ## Comprueba la sintaxis de todos los archivos PHP
	@if command -v php >/dev/null 2>&1 || [ -n "$$OST_PHP" ]; then \
		PHP_BIN=$(PHP) JOBS=$(JOBS) $(ROOT)/tools/lint.sh; \
	elif command -v docker >/dev/null 2>&1; then \
		echo "· php no está en el host: lint dentro del contenedor"; \
		docker compose run --rm --no-deps tools make lint; \
	else \
		echo "No hay PHP ni Docker: instala uno de los dos."; exit 127; \
	fi

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

unit: ## Pruebas unitarias sin base de datos (Format, Validator…)
	@if [ -x $(ROOT)/vendor/bin/phpunit ]; then \
		echo "· PHPUnit (vendor/bin/phpunit)"; \
		$(PHP) $(ROOT)/vendor/bin/phpunit --colors=never; \
	else \
		echo "· tests/run.php (sin Composer: mismo resultado, sin red)"; \
		$(PHP) $(ROOT)/tests/run.php; \
	fi

unit-list: ## Lista las pruebas unitarias
	@$(PHP) $(ROOT)/tests/run.php --list

test: unit ## Pruebas unitarias (alias de `unit`)

test-legacy: ## Suite del instalador (necesita base de datos configurada)
	@$(PHP) $(ROOT)/setup/test/run-tests.php

security: ## Comprueba la versión de PHP frente a php2shell (CVE-2026-109618/19)
	@$(PHP) $(ROOT)/tools/security/php-cve-check.php

theme: ## Revisa que el tema claro/oscuro siga completo y conectado
	@$(PHP) $(ROOT)/tools/ui/theme-check.php

inline-audit: ## Cuenta los onclick/onchange en línea (trabajo pendiente de la CSP)
	@$(PHP) $(ROOT)/tools/ui/inline-handlers.php

inline-audit-json: ## Lo mismo, en JSON, para comparar antes/después
	@$(PHP) $(ROOT)/tools/ui/inline-handlers.php --json

inline-budget: ## Falla si los onclick/onchange vuelven a subir del tope actual
	@$(PHP) $(ROOT)/tools/ui/inline-handlers.php --max=0

dark-audit: ## Zonas claras en el tema oscuro (navegador propio o contenedor)
	@$(ROOT)/tools/ui/browser-audit.sh dark "file://$(ROOT)/docs/theme-preview.html"

dark-audit-client: ## Lo mismo con la vista previa del portal
	@$(ROOT)/tools/ui/browser-audit.sh dark "file://$(ROOT)/docs/theme-preview-client.html"

contrast-audit: ## Contraste de texto en la vista previa del panel
	@$(ROOT)/tools/ui/browser-audit.sh contrast "file://$(ROOT)/docs/theme-preview.html"

contrast-audit-client: ## Contraste de texto en la vista previa del portal
	@$(ROOT)/tools/ui/browser-audit.sh contrast "file://$(ROOT)/docs/theme-preview-client.html"

docker-dark-audit: ## Lo mismo, dentro de la imagen de Playwright (no instala nada)
	@OST_FORCE_DOCKER=1 $(ROOT)/tools/ui/browser-audit.sh dark "file://$(ROOT)/docs/theme-preview.html"

icons: ## Hoja con los 155 iconos SVG para revisarlos a ojo (ost-iconos.html)
	@$(PHP) $(ROOT)/tools/ui/icon-sheet.php --out=$(ROOT)/ost-iconos.html
	@echo "  Abre $(ROOT)/ost-iconos.html en el navegador (no se versiona)."

metrics: ## Radiografía del código propio (tamaño y deuda técnica)
	@$(PHP) $(ROOT)/tools/quality/metrics.php

metrics-json: ## Lo mismo, en JSON (para comparar antes/después)
	@$(PHP) $(ROOT)/tools/quality/metrics.php --json

ci: lint audit security theme unit ## Verificación completa para integración continua

clean: ## Elimina artefactos temporales de desarrollo
	@rm -rf $(ROOT)/vendor/.phpunit.result.cache
	@find $(ROOT)/tools -name '*.tmp' -delete 2>/dev/null || true

perms: ## Verifica y corrige los permisos de ost-config.php
	@if [ -f "$(ROOT)/include/ost-config.php" ]; then \
		mode=$$(stat -c '%a' "$(ROOT)/include/ost-config.php"); \
		case "$$mode" in \
			640|600) echo "✓ include/ost-config.php con permisos correctos ($$mode)";; \
			*) echo "→ Ajustando include/ost-config.php: $$mode → 640"; \
			   chmod 640 "$(ROOT)/include/ost-config.php";; \
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

# ---------------------------------------------------------------------------
# Puertos publicados.
#
# Se leen de .env AL EJECUTAR la receta (variables recursivas), no al leer
# este archivo: así funcionan aunque .env se cree en este mismo `make` y, sobre
# todo, cuando cambias HTTP_PORT porque 8080 está ocupado.
# El valor por defecto replica el de compose.yaml (`${HTTP_PORT:-8080}`).
# ---------------------------------------------------------------------------
read_env = $(shell sed -n 's/^$1=//p' $(ENV_FILE) 2>/dev/null | tail -1 | tr -d '\r"' | tr -d ' ')

HTTP_PORT       = $(or $(call read_env,HTTP_PORT),8080)
MAILPIT_UI_PORT = $(or $(call read_env,MAILPIT_UI_PORT),8025)
DB_PORT         = $(or $(call read_env,DB_PORT),3307)

# Bloque compartido por `docker-up` y `docker-urls`.
URLS = port=$(HTTP_PORT); mail=$(MAILPIT_UI_PORT); \
	printf '\n  Portal del cliente : http://localhost:%s\n' "$$port"; \
	printf '  Panel de agentes   : http://localhost:%s/scp/\n' "$$port"; \
	printf '  Instalador         : http://localhost:%s/setup/\n' "$$port"; \
	printf '  Correo de pruebas  : http://localhost:%s (Mailpit)\n' "$$mail"; \
	printf '  MariaDB desde el host: 127.0.0.1:%s\n\n' "$(DB_PORT)"

$(ENV_FILE):
	@cp $(ROOT)/.env.example $@
	@sed -i "s|^OST_SECRET_SALT=.*|OST_SECRET_SALT=$$(openssl rand -hex 32)|" $@ 2>/dev/null || \
	 sed -i '' "s|^OST_SECRET_SALT=.*|OST_SECRET_SALT=$$(openssl rand -hex 32)|" $@
	@echo "✓ .env creado con una SECRET_SALT aleatoria"

docker-up: $(ENV_FILE) ## Levanta el entorno (puertos tomados de .env)
	@docker compose up -d --build
	@$(URLS)
	@echo "  HTTP_PORT=$(HTTP_PORT) según .env. Logs: make docker-logs"

docker-urls: ## Muestra las URLs con los puertos configurados en .env
	@$(URLS)

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

docker-security: ## Comprueba php2shell, la postura y el acceso a /setup/
	@docker compose exec php php tools/security/php-cve-check.php
	@echo ""
	@echo "→ ¿Sigue accesible /setup/ desde la web?"
	@docker compose exec -T php php tools/security/setup-probe.php

docker-composer: ## Instala las dependencias de desarrollo con Composer (en contenedor)
	@docker compose --profile tools run --rm composer install
	@echo "✓ vendor/ listo. Ejecuta 'make docker-qa' para el análisis estático"

docker-qa: ## PHPStan + PHP_CodeSniffer dentro del contenedor (requiere vendor/)
	@docker compose --profile tools run --rm tools php vendor/bin/phpstan analyse --no-progress
	@docker compose --profile tools run --rm tools php vendor/bin/phpcs

docker-bench: ## Mide el rendimiento del stack (útil en Windows/WSL)
	@echo "→ Petición completa a /scp/ (3 intentos)"
	@for i in 1 2 3; do \
		curl -s -o /dev/null -w "  intento $$i: %{time_total}s\n" \
			http://localhost:$(HTTP_PORT)/scp/ || true; \
	done
	@echo "→ Carga de clases PHP dentro del contenedor"
	@docker compose exec -T php php -r '$$t = microtime(true); \
		for ($$i = 0; $$i < 200; $$i++) \
			require_once "/var/www/html/include/class.analytics.php"; \
		printf("  200 require: %.1f ms\n", (microtime(true) - $$t) * 1000);'
	@echo ""
	@echo "  Referencia: < 1 s la petición y < 50 ms los 200 require indican"
	@echo "  que el sistema de archivos ya no es el cuello de botella."
	@echo "  Detalle y qué hacer si no: docs/DOCKER.md § 11."

docker-prod: ## Construye la imagen de producción (sin Xdebug)
	@docker build --target prod \
		--build-arg PHP_VERSION=$(shell sed -n 's/^PHP_VERSION=//p' $(ROOT)/.env 2>/dev/null || echo 8.4.27) \
		-t osticket:prod .
