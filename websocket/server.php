<?php
// websocket/server.php
//
// Entry standalone del servidor WebSocket (Workerman).
// Se puede invocar directamente:
//   php websocket/server.php start          (foreground)
//   php websocket/server.php start -d       (daemon, solo Linux)
//   php websocket/server.php stop
//   php websocket/server.php status
//   php websocket/server.php restart
//
// Para una UX más integrada usa el comando `php apollo realtime:start`
// (que también levanta el pid file y se integra con el resto del framework).
//
// Compatible con Windows y Linux. En Windows, `start -d` se ignora (Workerman
// no soporta daemon en Windows) y el proceso corre en foreground.

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Carga .env si existe (mismo patrón que apollo)
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

$basePath = dirname(__DIR__);

// Workerman necesita el script como argv[0] para parseCommand.
global $argv;
if (!isset($argv) || empty($argv)) {
    $argv = [__FILE__];
} else {
    $argv[0] = __FILE__;
}

// Daemonize en Linux si se pasa -d.
if (PHP_OS_FAMILY !== 'Windows' && in_array('-d', $argv, true)) {
    \Workerman\Worker::$daemonize = true;
}

// Pid file en runtime/ (gitignored) para stop/status/inspect.
$pidFile = $basePath . '/runtime/realtime.pid';
\Workerman\Worker::$pidFile = $pidFile;
\Workerman\Worker::$logFile = $basePath . '/runtime/workerman.log';

// Workerman >= 5 acepta stop/status/restart/reload/connections via argv.
// En este entry dejamos que Workerman los gestione nativamente (stop/status).
// El comando `php apollo realtime:stop` de Apollo ofrece una envoltura
// cross-platform (taskkill en Windows) sobre este mismo pid file.

$config = is_file($basePath . '/config/realtime.php')
    ? require $basePath . '/config/realtime.php'
    : [];

if (!($config['enabled'] ?? true)) {
    fwrite(STDERR, "WebSocket deshabilitado (WEBSOCKET_ENABLED=false). Saliendo.\n");
    exit(0);
}

$server = new \Apollo\Core\Realtime\WebSocket\WebSocketServer($basePath, new \Apollo\Core\Realtime\Support\RealtimeConfig($config));
$server->start();
