<?php
/**
 * docker/php/setup-db.php
 *
 * Prepara la base de datos de osTicket en el arranque del contenedor.
 *
 * Sólo es necesario cuando el volumen de MySQL ya existía (por ejemplo tras
 * `docker compose down -v` al revés, o al reutilizar un volumen de otro
 * proyecto): en ese caso /docker-entrypoint-initdb.d no se ejecuta y la base
 * de datos puede no existir todavía. También crea la cuenta de la aplicación
 * con los privilegios mínimos sobre esa base.
 *
 * Salidas:
 *   0  base de datos lista (creada o ya existente)
 *   1  configuración incompleta (faltan variables)
 *   2  no se pudo conectar con ninguna cuenta
 *   3  error al crear la base de datos o el usuario
 *
 * Uso: OST_DB_HOST=db OST_DB_NAME=osticket ... php docker/php/setup-db.php
 */

$host = getenv('OST_DB_HOST');
$port = (int) (getenv('OST_DB_PORT') ?: 3306);
$name = getenv('OST_DB_NAME');
$user = getenv('OST_DB_USER');
$pass = getenv('OST_DB_PASS');
$rootPass = getenv('OST_DB_ROOT_PASS')
    ?: getenv('MYSQL_ROOT_PASSWORD')
    ?: getenv('MARIADB_ROOT_PASSWORD');

if (!$host || !$name || !$user) {
    fwrite(STDERR, "setup-db: faltan OST_DB_HOST, OST_DB_NAME u OST_DB_USER\n");
    exit(1);
}

// escapeIdentifier se limita a nombres válidos para evitar inyección: no se
// pueden usar comillas invertidas en un nombre de base de datos/usuario.
function escapeIdentifier($value) {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $value))
        throw new InvalidArgumentException("Identificador no permitido: $value");
    return $value;
}

mysqli_report(MYSQLI_REPORT_OFF);

// 1. Intentar con la cuenta de la aplicación; si no existe, con root.
$conn = @new mysqli($host, $user, $pass, null, $port);
if ($conn->connect_errno) {
    $conn = @new mysqli($host, 'root', $rootPass, null, $port);
    if ($conn->connect_errno) {
        fwrite(STDERR, "setup-db: sin conexión ({$conn->connect_error})\n");
        exit(2);
    }
}

try {
    $db = escapeIdentifier($name);
    $account = escapeIdentifier($user);

    if (!$conn->query(sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $db))) {
        throw new RuntimeException($conn->error);
    }

    // La contraseña se escapa con real_escape_string, no se interpola.
    $quotedPass = $conn->real_escape_string($pass ?? '');
    $conn->query(sprintf(
        "CREATE USER IF NOT EXISTS '%s'@'%%' IDENTIFIED BY '%s'",
        $account, $quotedPass));
    $conn->query(sprintf(
        "ALTER USER '%s'@'%%' IDENTIFIED BY '%s'",
        $account, $quotedPass));
    $conn->query(sprintf(
        "GRANT ALL PRIVILEGES ON `%s`.* TO '%s'@'%%'",
        $db, $account));
    $conn->query('FLUSH PRIVILEGES');
}
catch (Throwable $e) {
    fwrite(STDERR, 'setup-db: ' . $e->getMessage() . "\n");
    exit(3);
}

echo "setup-db: base de datos '{$name}' lista\n";
exit(0);
