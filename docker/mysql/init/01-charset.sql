-- ---------------------------------------------------------------------------
-- Ajustes iniciales de la base de datos para osTicket
--
-- Se ejecuta sólo la primera vez que se crea el volumen.
-- La base de datos se crea con MARIADB_DATABASE (osticket por defecto).
-- ---------------------------------------------------------------------------

-- Zona horaria coherente con los contenedores (UTC por defecto).
SET GLOBAL time_zone = '+00:00';

-- Límite de paquetes para adjuntos grandes.
SET GLOBAL max_allowed_packet = 67108864;
