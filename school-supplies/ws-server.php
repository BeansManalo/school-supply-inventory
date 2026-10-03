<?php
// The push server (see core/Push.php): holds the browsers' WebSocket connections and tells them "changed" when the app says so.
// The app starts it by itself when needed (and Setup.bat does). By hand, to watch it:   php ws-server.php
// It listens on 127.0.0.1 only; Apache passes /ws to it. It never reads the database and never sends anything but the word "p".
PHP_SAPI === 'cli' || exit('Run this from a terminal; the app starts it by itself.');
set_time_limit(0);
require __DIR__ . '/core/Push.php';

const MAX_CLIENTS = 150;    // select() on Windows copes with about 256 sockets; past this, pages keep polling
const AUTH_SECONDS = 10;    // a connection that has not said who it is by then is closed
const PING_SECONDS = 25;    // keeps Apache's proxy from closing an idle connection

($port = Push::port()) || exit("Push is not set up: config.local.php has no 'push' (run install.php).\n");
$server = @stream_socket_server("tcp://127.0.0.1:$port", $code, $error) or exit("Not started: $error. It may already be running.\n");
echo "Push server on 127.0.0.1:$port\n";
$clients = [];   // id => socket, in (bytes read so far), state (new, ws, open), topics, since, ping

function frame(string $payload, int $opcode = 0x1): string {
    $n = strlen($payload);
    return chr(0x80 | $opcode) . ($n < 126 ? chr($n) : chr(126) . pack('n', $n)) . $payload;
}

function drop(array &$clients, int $id): void {
    @fclose($clients[$id]['socket']);
    unset($clients[$id]);
}

/** The app's control line: POKE <topics> <signature> or QUIT <signature>. */
function control(string $line, array $clients): void {
    $p = explode(' ', $line);
    if ($p[0] === 'QUIT' && Push::verify('QUIT', $p[1] ?? '')) {
        echo "Stopped.\n";
        exit(0);
    }
    if ($p[0] === 'POKE' && count($p) === 3 && Push::verify($p[1], $p[2])) {
        $topics = explode(',', $p[1]);
        foreach ($clients as $c) {
            if ($c['state'] === 'open' && array_intersect($topics, $c['topics'])) {
                @fwrite($c['socket'], frame('p'));
            }
        }
    }
}

/** Reads what has arrived on one connection. Returns false to close it. */
function handle(array &$clients, int $id): bool {
    $c = &$clients[$id];
    if ($c['state'] === 'new') {
        if (strlen($c['in']) < 5) {
            return true;
        }
        if (str_starts_with($c['in'], 'POKE ') || str_starts_with($c['in'], 'QUIT ')) {
            if (str_contains($c['in'], "\n")) {
                control(trim($c['in']), $clients);
                return false;
            }
            return true;
        }
        if (!str_starts_with($c['in'], 'GET ')) {
            return false;
        }
        if (($end = strpos($c['in'], "\r\n\r\n")) === false) {
            return true;
        }
        $head = substr($c['in'], 0, $end);
        $c['in'] = substr($c['in'], $end + 4);
        if (!preg_match('/^Sec-WebSocket-Key:\s*(\S+)/mi', $head, $key) || !preg_match('/^Upgrade:\s*websocket/mi', $head)) {
            @fwrite($c['socket'], "HTTP/1.1 400 Bad Request\r\nConnection: close\r\n\r\n");
            return false;
        }
        @fwrite($c['socket'], "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: "
            . base64_encode(sha1($key[1] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)) . "\r\n\r\n");
        $c['state'] = 'ws';   // connected, but not yet said who it is
    }
    while (strlen($c['in']) >= 2) {   // WebSocket frames from the browser: always masked, never fragmented, tiny
        $first = ord($c['in'][0]);
        $second = ord($c['in'][1]);
        $opcode = $first & 0x0F;
        $length = $second & 0x7F;
        $at = 2;
        if ($length === 126) {
            if (strlen($c['in']) < 4) {
                return true;
            }
            $length = unpack('n', substr($c['in'], 2, 2))[1];
            $at = 4;
        }
        if (!($first & 0x80) || !($second & 0x80) || $length > 125 || (($second & 0x7F) === 127)) {
            return false;
        }
        if (strlen($c['in']) < $at + 4 + $length) {
            return true;
        }
        $mask = substr($c['in'], $at, 4);
        $payload = substr($c['in'], $at + 4, $length);
        for ($i = 0; $i < $length; $i++) {
            $payload[$i] = $payload[$i] ^ $mask[$i % 4];
        }
        $c['in'] = substr($c['in'], $at + 4 + $length);
        if ($opcode === 0x8) {   // close
            @fwrite($c['socket'], frame('', 0x8));
            return false;
        }
        if ($opcode === 0x9) {   // ping
            @fwrite($c['socket'], frame($payload, 0xA));
        } elseif ($opcode === 0x1 && $c['state'] === 'ws') {   // the first message is {"t": token}
            $topics = Push::topics((string) (json_decode($payload, true)['t'] ?? ''));
            if ($topics === null) {
                @fwrite($c['socket'], frame(pack('n', 1008), 0x8));
                return false;
            }
            $c['topics'] = $topics;
            $c['state'] = 'open';
            @fwrite($c['socket'], frame('ok'));
        } elseif ($opcode !== 0xA && $opcode !== 0x1) {
            return false;
        }
    }
    return true;
}

while (true) {
    $read = array_merge([$server], array_column($clients, 'socket'));
    $write = $except = null;
    if (@stream_select($read, $write, $except, 5) === false) {
        usleep(100000);
        continue;
    }
    foreach ($read as $socket) {
        if ($socket === $server) {
            if ($new = @stream_socket_accept($server, 0)) {
                if (count($clients) >= MAX_CLIENTS) {
                    fclose($new);
                    continue;
                }
                stream_set_blocking($new, false);
                $clients[(int) $new] = ['socket' => $new, 'in' => '', 'state' => 'new', 'topics' => [], 'since' => time(), 'ping' => time()];
            }
            continue;
        }
        $id = (int) $socket;
        $data = @fread($socket, 8192);
        if ($data === false || $data === '') {
            drop($clients, $id);
            continue;
        }
        $clients[$id]['in'] .= $data;
        if (strlen($clients[$id]['in']) > 8192 || !handle($clients, $id)) {
            drop($clients, $id);
        }
    }
    $now = time();
    foreach ($clients as $id => $c) {
        if ($c['state'] !== 'open' && $now - $c['since'] > AUTH_SECONDS) {
            drop($clients, $id);
        } elseif ($c['state'] === 'open' && $now - $c['ping'] >= PING_SECONDS) {
            $clients[$id]['ping'] = $now;
            @fwrite($c['socket'], frame('', 0x9));
        }
    }
}
