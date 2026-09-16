<?php

// Servidor SMTP falso para tests: AUTH LOGIN (334/334/235), MAIL/RCPT/DATA y QUIT (221).
// Uso: php fake_smtp_server.php <puerto>. Descarta conexiones que no empiecen con EHLO
// (permite sondas de disponibilidad sin consumir el handshake).

$port = (int) ($argv[1] ?? 0);
$server = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "no listen: {$errstr}\n");
    exit(1);
}

$conn = false;
$deadline = time() + 10;
while (time() < $deadline) {
    $c = @stream_socket_accept($server, 1);
    if ($c === false) {
        continue;
    }
    stream_set_timeout($c, 2);
    // El servidor saluda primero; las sondas de disponibilidad cierran sin
    // mandar EHLO y se descartan (fgets falla) para esperar a un cliente real.
    @fwrite($c, "220 fake.local SMTP ready\r\n");
    $first = fgets($c, 2048);
    if ($first === false || stripos(trim((string) $first), 'EHLO') !== 0) {
        fclose($c);
        continue;
    }
    $conn = $c;
    break;
}

if ($conn === false) {
    fclose($server);
    exit(1);
}

$out = static function (string $line) use ($conn): void {
    fwrite($conn, $line . "\r\n");
};
$read = static function () use ($conn) {
    return fgets($conn, 4096);
};

$out('250-fake.local');
$out('250 AUTH LOGIN');
$read();                  // AUTH LOGIN
$out('334 VXNlcm5hbWU6'); // Username:
$read();                  // usuario en base64
$out('334 UGFzc3dvcmQ6'); // Password:
$read();                  // contraseña en base64
$out('235 2.7.0 Authentication successful');
$read();                  // MAIL FROM
$out('250 OK');
$read();                  // RCPT TO
$out('250 OK');
$read();                  // DATA
$out('354 End data with <CR><LF>.<CR><LF>');
while (($line = $read()) !== false) {
    if (rtrim((string) $line, "\r\n") === '.') {
        break;
    }
}
$out('250 Queued');
$read();                  // QUIT
$out('221 Bye');

fclose($conn);
fclose($server);
