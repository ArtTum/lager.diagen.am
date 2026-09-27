<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Yerevan');

$root = dirname(__DIR__);
$settings = [];
foreach (file($root.'/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
    [$key, $value] = explode('=', $line, 2);
    $settings[trim($key)] = trim(trim($value), "\"'");
}

$host = $settings['WS_HOST'] ?? '127.0.0.1';
$port = (int) ($settings['WS_PORT'] ?? 8096);
if (!empty($settings['SESSION_NAME']) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $settings['SESSION_NAME'])) session_name($settings['SESSION_NAME']);
$server = stream_socket_server("tcp://{$host}:{$port}", $errorNumber, $errorMessage);
if (!$server) {
    fwrite(STDERR, "WebSocket server failed: {$errorMessage} ({$errorNumber})\n");
    exit(1);
}
stream_set_blocking($server, false);

$pdo = new PDO(
    'mysql:host='.($settings['DB_HOST'] ?? '127.0.0.1').';port='.($settings['DB_PORT'] ?? '3306').';dbname='.($settings['DB_DATABASE'] ?? 'diagen_lager').';charset=utf8mb4',
    $settings['DB_USERNAME'] ?? 'root',
    $settings['DB_PASSWORD'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
);

/** @var array<int, array{stream:resource,session:string,last_ping:int}> $clients */
$clients = [];
$nextClientId = 1;
$lastAuditId = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM audit_logs')->fetchColumn();
$nextAuditCheck = microtime(true) + 1.5;
$nextPing = time() + 25;

$frame = static function (string $payload, int $opcode = 1): string {
    $length = strlen($payload);
    $head = chr(0x80 | $opcode);
    if ($length < 126) return $head.chr($length).$payload;
    if ($length <= 0xffff) return $head.chr(126).pack('n', $length).$payload;
    return $head.chr(127).pack('J', $length).$payload;
};

$isAuthorized = static function (string $sessionId) use ($root): bool {
    if ($sessionId === '' || strlen($sessionId) > 128 || !preg_match('/^[A-Za-z0-9,-]+$/', $sessionId)) return false;
    session_save_path($root.'/storage/sessions');
    session_id($sessionId);
    if (!@session_start()) return false;
    $user = $_SESSION['user'] ?? null;
    // The socket only broadcasts an invalidation signal; each page reloads its own
    // data through the normal session and permission checks.
    $allowed = is_array($user) && !empty($user['id']);
    session_write_close();
    return $allowed;
};

fwrite(STDOUT, "Diagen notifications socket listening on {$host}:{$port}\n");

while (true) {
    $read = [$server];
    foreach ($clients as $client) $read[] = $client['stream'];
    $write = null;
    $except = null;
    @stream_select($read, $write, $except, 1);

    foreach ($read as $stream) {
        if ($stream === $server) {
            $peer = @stream_socket_accept($server, 0);
            if ($peer) {
                stream_set_blocking($peer, false);
                $id = $nextClientId++;
                $clients[$id] = ['stream' => $peer, 'session' => '', 'last_ping' => time()];
            }
            continue;
        }

        $id = null;
        foreach ($clients as $clientId => $client) {
            if ($client['stream'] === $stream) { $id = $clientId; break; }
        }
        if ($id === null) continue;

        $input = @fread($stream, 8192);
        if ($input === false || ($input === '' && feof($stream))) {
            fclose($stream);
            unset($clients[$id]);
            continue;
        }

        if ($clients[$id]['session'] === '') {
            if (!str_contains($input, "\r\n\r\n")) continue;
            $lines = explode("\r\n", $input);
            $headers = [];
            foreach (array_slice($lines, 1) as $line) {
                if (!str_contains($line, ':')) continue;
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
            $originHost = strtolower((string) parse_url($headers['origin'] ?? '', PHP_URL_HOST));
            $requestHost = strtolower(preg_replace('/:\\d+$/', '', $headers['host'] ?? ''));
            $cookieName = preg_quote(session_name(), '/');
            preg_match('/(?:^|;\s*)'.$cookieName.'=([^;]+)/', $headers['cookie'] ?? '', $match);
            $sessionId = $match[1] ?? '';
            $valid = str_starts_with($lines[0] ?? '', 'GET ')
                && isset($headers['sec-websocket-key'])
                && $originHost !== '' && $originHost === $requestHost
                && $isAuthorized($sessionId);
            if (!$valid) {
                @fwrite($stream, "HTTP/1.1 403 Forbidden\r\nConnection: close\r\nContent-Length: 0\r\n\r\n");
                fclose($stream);
                unset($clients[$id]);
                continue;
            }
            $accept = base64_encode(sha1($headers['sec-websocket-key'].'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
            @fwrite($stream, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n");
            $clients[$id]['session'] = $sessionId;
            continue;
        }

        // The client only receives events; consuming its pong/close frames keeps the socket healthy.
        if ($input !== '' && (ord($input[0]) & 0x0f) === 8) {
            fclose($stream);
            unset($clients[$id]);
        }
    }

    $now = microtime(true);
    if ($now >= $nextAuditCheck) {
        $nextAuditCheck = $now + 1.5;
        try {
            $changes = $pdo->prepare('SELECT id, actor_id, entity FROM audit_logs WHERE id > ? ORDER BY id LIMIT 100');
            $changes->execute([$lastAuditId]);
            foreach ($changes->fetchAll() as $change) {
                $lastAuditId = (int) $change['id'];
                // Authentication activity does not change any workspace data.
                if ($change['entity'] === 'auth') continue;
                $event = $frame(json_encode([
                    'type' => 'data.refresh',
                    'id' => $lastAuditId,
                    'actor' => (int) ($change['actor_id'] ?? 0),
                    'entity' => (string) $change['entity'],
                ], JSON_THROW_ON_ERROR));
                foreach ($clients as $id => $client) {
                    if ($client['session'] !== '' && !@fwrite($client['stream'], $event)) {
                        fclose($client['stream']);
                        unset($clients[$id]);
                    }
                }
            }
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Notification poll failed: '.$exception->getMessage()."\n");
        }
    }

    if (time() >= $nextPing) {
        $nextPing = time() + 25;
        foreach ($clients as $id => $client) {
            if ($client['session'] !== '' && !$isAuthorized($client['session'])) {
                @fwrite($client['stream'], $frame('', 8));
                fclose($client['stream']);
                unset($clients[$id]);
            } elseif ($client['session'] !== '' && !@fwrite($client['stream'], $frame('', 9))) {
                fclose($client['stream']);
                unset($clients[$id]);
            }
        }
    }
}
