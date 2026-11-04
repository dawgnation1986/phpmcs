<?php
declare(strict_types=1);

/**
 * 一次性补丁：给 Server 接上 WebSocket（网页版客户端走 ws://）。
 */

$path = __DIR__ . '/../src/Server.php';
$text = file_get_contents($path);
if ($text === false) {
    fwrite(STDERR, "读不到 $path\n");
    exit(1);
}
$EOL = str_contains($text, "\r\n") ? "\r\n" : "\n";
$text = str_replace("\r\n", "\n", $text);

$edits = [];

// 1) Connection 字段
$edits[] = [
    "    public string \$remote = '';\n    public bool \$closed = false;",
    "    public string \$remote = '';\n    public bool \$closed = false;\n    /** WebSocket 状态：unknown=还没判 / ''=普通 TCP / handshake=收到 HTTP 头 / open=已握手 */\n    public string \$ws = '';\n    /** WS 解帧缓冲区（半个帧会留在这里等下一拍） */\n    public string \$wsBuf = '';",
];

// 2) send 里按 ws 包帧
$edits[] = [
    "    public function send(int \$packetId, Writer \$payload): void\n    {\n        \$this->out .= Frame::encode(VarInt::encode(\$packetId) . \$payload->toString());\n    }",
    "    public function send(int \$packetId, Writer \$payload): void\n    {\n        \$data = Frame::encode(VarInt::encode(\$packetId) . \$payload->toString());\n        // 网页客户端要包成 WebSocket binary 帧；普通 TCP 直接写\n        \$this->out .= \$this->ws === 'open' ? WebSocket::frame(\$data) : \$data;\n    }",
];

// 3) accept 里标记待判定
$edits[] = [
    "        \$c = new Connection(\$sock, (string) \$peer);\n        \$this->conns[(int) \$sock] = \$c;\n        \$this->log(\"+ 连接 {\$peer}\");",
    "        \$c = new Connection(\$sock, (string) \$peer);\n        \$c->ws = 'unknown';\n        \$this->conns[(int) \$sock] = \$c;\n        \$this->log(\"+ 连接 {\$peer}\");",
];

// 4) pump 里插 WebSocket 分支
$oldPump = "        if (\$chunk === '') {\n            return;\n        }\n\n        \$c->in .= \$chunk;\n        \$c->lastRead = microtime(true);\n";
$newPump = <<<'PHP'
        if ($chunk === '') {
            return;
        }
        $c->lastRead = microtime(true);

        // ---- WebSocket：首包看是不是 HTTP Upgrade，之后所有数据都过一遍拆帧 ----
        if ($c->ws === 'unknown') {
            $c->ws = WebSocket::looksLikeHttp($chunk) ? 'handshake' : '';
            $this->log($c->ws === '' ? "  {$c->remote} 普通 TCP" : "  {$c->remote} WebSocket");
        }

        if ($c->ws === 'handshake') {
            $c->wsBuf .= $chunk;
            $end = strpos($c->wsBuf, "\r\n\r\n");
            if ($end === false) {
                if (strlen($c->wsBuf) > 8192) {
                    $this->drop($c, 'WS 握手头过长');
                }

                return;
            }
            $header = substr($c->wsBuf, 0, $end + 4);
            $c->wsBuf = substr($c->wsBuf, $end + 4);
            $resp = WebSocket::handshakeResponse($header);
            if ($resp === null) {
                $this->drop($c, 'WS 握手缺 Sec-WebSocket-Key');

                return;
            }
            $c->out .= $resp;
            $c->ws = 'open';
            $this->log("  {$c->remote} WebSocket 已握手");
            if ($c->wsBuf === '') {
                return;
            }
            // 握手的同一个包后面可能已经跟着第一帧 MC 数据
            $chunk = $c->wsBuf;
            $c->wsBuf = '';
        }

        if ($c->ws === 'open') {
            $c->wsBuf .= $chunk;
            [$data, $pongs, $closed] = WebSocket::decode($c->wsBuf);
            foreach ($pongs as $p) {
                $c->out .= WebSocket::pong($p);
            }
            if ($closed) {
                $this->drop($c, 'WS 关闭帧');

                return;
            }
            if ($data === '') {
                return;
            }
            $chunk = $data;
        }

        $c->in .= $chunk;
PHP;
$edits[] = [$oldPump, $newPump];

foreach ($edits as $i => [$old, $new]) {
    $n = substr_count($text, $old);
    if ($n !== 1) {
        fwrite(STDERR, "第 " . ($i + 1) . " 处命中 $n 次，中止\n");
        exit(1);
    }
    $text = str_replace($old, $new, $text);
}

$text = str_replace("\n", $EOL, $text);
file_put_contents($path, $text);
echo "Server.php 已接上 WebSocket，" . count($edits) . " 处\n";
