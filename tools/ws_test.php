<?php
declare(strict_types=1);

/**
 * WebSocket 冒烟测试：模拟网页版客户端连上来做一次服务器列表 ping。
 *
 * 流程：
 *   1. TCP 连 25565
 *   2. 发 HTTP Upgrade（带 Sec-WebSocket-Key）
 *   3. 读 101
 *   4. 发 MC Handshake + Status Request（包在 binary 帧里）
 *   5. 读回 Status Response，解帧，看里面有没有 JSON
 *
 * 用法：php tools/ws_test.php [host] [port]
 */

$host = $argv[1] ?? '127.0.0.1';
$port = (int) ($argv[2] ?? 25565);
$tls = in_array('--tls', $argv, true) || in_array('-t', $argv, true);

$url = ($tls ? 'ssl://' : 'tcp://') . "{$host}:{$port}";
$ctx = stream_context_create([
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true,
    ],
]);
$sock = @stream_socket_client($url, $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx);
if ($sock === false) {
    fwrite(STDERR, "连不上 {$url} — {$errstr}\n");
    exit(1);
}
echo $tls ? "使用 TLS 连接\n" : "使用明文连接\n";
stream_set_timeout($sock, 5);

$key = base64_encode(random_bytes(16));
$req = "GET / HTTP/1.1\r\n"
    . "Host: {$host}:{$port}\r\n"
    . "Upgrade: websocket\r\n"
    . "Connection: Upgrade\r\n"
    . "Sec-WebSocket-Key: {$key}\r\n"
    . "Sec-WebSocket-Version: 13\r\n"
    . "\r\n";
fwrite($sock, $req);

// ---- 读 101 ----
$buf = '';
$deadline = microtime(true) + 5;
while (microtime(true) < $deadline) {
    $r = fread($sock, 4096);
    if ($r === false || $r === '') {
        usleep(10000);
        continue;
    }
    $buf .= $r;
    if (str_contains($buf, "\r\n\r\n")) {
        break;
    }
}
if (!str_contains($buf, '101')) {
    fwrite(STDERR, "握手失败，收到：\n{$buf}\n");
    exit(1);
}
$expect = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
if (!str_contains($buf, $expect)) {
    fwrite(STDERR, "Sec-WebSocket-Accept 不对\n期望 {$expect}\n实际：\n{$buf}\n");
    exit(1);
}
echo "✓ 101 握手通过，Accept 正确\n";
// 把 101 之后可能已经跟过来的帧留下
$rest = substr($buf, strpos($buf, "\r\n\r\n") + 4);

// ---- 构造 MC Handshake + Status Request ----
$varint = static function (int $v): string {
    $out = '';
    do {
        $b = $v & 0x7F;
        $v >>= 7;
        if ($v !== 0) {
            $b |= 0x80;
        }
        $out .= chr($b);
    } while ($v !== 0);

    return $out;
};
$str = static function (string $s) use ($varint): string {
    return $varint(strlen($s)) . $s;
};

$body = $varint(0)                     // packet id 0 = Handshake
    . $varint(47)                      // protocol 47
    . $str($host)
    . pack('n', $port)
    . $varint(1);                      // next state = status
$hs = $varint(strlen($body)) . $body;

$statusReq = $varint(1) . $varint(0);  // length 1, packet id 0

$mc = $hs . $statusReq;

// ---- 包成客户端 binary 帧（必须带 mask）----
$maskKey = random_bytes(4);
$masked = '';
for ($i = 0; $i < strlen($mc); $i++) {
    $masked .= chr(ord($mc[$i]) ^ ord($maskKey[$i & 3]));
}
$frame = chr(0x82) . chr(0x80 | strlen($mc)) . $maskKey . $masked;
fwrite($sock, $frame);

// ---- 读响应帧 ----
$buf = $rest;
$deadline = microtime(true) + 5;
$payload = '';
while (microtime(true) < $deadline) {
    // 尝试解帧
    while (strlen($buf) >= 2) {
        $b1 = ord($buf[1]);
        $plen = $b1 & 0x7F;
        $off = 2;
        if ($plen === 126) {
            if (strlen($buf) < 4) {
                break;
            }
            $plen = (ord($buf[2]) << 8) | ord($buf[3]);
            $off = 4;
        } elseif ($plen === 127) {
            if (strlen($buf) < 10) {
                break;
            }
            $plen = 0;
            for ($i = 4; $i < 8; $i++) {
                $plen = ($plen << 8) | ord($buf[2 + $i]);
            }
            $off = 10;
        }
        if (strlen($buf) < $off + $plen) {
            break;
        }
        $payload .= substr($buf, $off, $plen);
        $buf = substr($buf, $off + $plen);
    }
    if (str_contains($payload, '{')) {
        break;
    }
    $r = @fread($sock, 4096);
    if ($r === false || $r === '') {
        usleep(10000);
        continue;
    }
    $buf .= $r;
}

fclose($sock);

if ($payload === '') {
    fwrite(STDERR, "没收到任何回包\n");
    exit(1);
}
echo '✓ 收到 ' . strlen($payload) . " 字节\n";
echo '  原始（前 200 字节）：' . substr(bin2hex($payload), 0, 200) . "\n";

// 从 payload 里挖 JSON：包结构是 [len][id=0][strlen][json]
$jsonStart = strpos($payload, '{');
if ($jsonStart === false) {
    fwrite(STDERR, "回包里没有 JSON\n");
    exit(1);
}
$json = substr($payload, $jsonStart);
echo "  JSON：{$json}\n";

$data = json_decode($json, true);
if (!is_array($data)) {
    fwrite(STDERR, "JSON 解析失败\n");
    exit(1);
}
printf("✓ 状态响应有效：version=%s players=%d/%d motd=%s\n",
    $data['version']['name'] ?? '?',
    $data['players']['online'] ?? -1,
    $data['players']['max'] ?? -1,
    is_array($data['description'] ?? null)
        ? ($data['description']['text'] ?? '?')
        : (string) ($data['description'] ?? '?')
);
echo "\nWebSocket 通道全部通过。\n";
