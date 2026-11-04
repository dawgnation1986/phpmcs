<?php
declare(strict_types=1);

/**
 * 一次性补丁：加 wss://（WebSocket over TLS）监听。
 * 用 tcp:// + ssl context，TLS 握手放到 pump 里非阻塞推进，
 * 避免 accept 时卡住整个主循环。
 */

$path = __DIR__ . '/../src/Server.php';
$text = file_get_contents($path);
$EOL = str_contains($text, "\r\n") ? "\r\n" : "\n";
$text = str_replace("\r\n", "\n", $text);

$edits = [];

// 1) Connection 加 TLS 标记
$edits[] = [
    "    /** WS 解帧缓冲区（半个帧会留在这里等下一拍） */\n    public string \$wsBuf = '';",
    "    /** WS 解帧缓冲区（半个帧会留在这里等下一拍） */\n    public string \$wsBuf = '';\n    /** 这条连接是不是从 wss 端口进来的 */\n    public bool \$tls = false;\n    /** TLS 握手是否已完成 */\n    public bool \$tlsReady = true;",
];

// 2) 构造参数
$edits[] = [
    "        private int \$viewDistance = 3,\n    ) {",
    "        private int \$viewDistance = 3,\n        private int \$wssPort = 0,\n        private string \$wssCert = '',\n        private string \$wssKey = '',\n    ) {",
];

// 3) tlsMaster 属性：挂在 tlsMaster 字段附近（放 run 之前）
$edits[] = [
    "    public function run(): void\n    {\n        // 先把出生点周围生成好，再开端口。\n        // 顺序很重要：端口一开客户端就会连进来，那时还没地形可发。\n        \$this->preGenerateSpawn();",
    "    /** @var resource|null wss 监听句柄（没配 wss-port 就是 null） */\n    private \$tlsMaster = null;\n\n    public function run(): void\n    {\n        // 先把出生点周围生成好，再开端口。\n        // 顺序很重要：端口一开客户端就会连进来，那时还没地形可发。\n        \$this->preGenerateSpawn();",
];

// 4) 建 wss 监听
$edits[] = [
    "        stream_set_blocking(\$master, false);\n\n        \$this->log('PHP MC 服务端已启动  '",
    <<<'PHP'
        stream_set_blocking($master, false);

        // ---- 可选的 wss 监听 ----
        // 用 tcp:// + ssl context：TLS 握手不在 accept 里做，而是在 pump 里
        // 非阻塞地推进，否则一个慢客户端能把整个主循环卡死。
        if ($this->wssPort > 0 && is_file($this->wssCert) && is_file($this->wssKey)) {
            $tlsCtx = stream_context_create([
                'ssl' => [
                    'local_cert' => $this->wssCert,
                    'local_pk' => $this->wssKey,
                    'allow_self_signed' => true,
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);
            $tls = @stream_socket_server(
                "tcp://{$this->host}:{$this->wssPort}",
                $tlsErrno,
                $tlsErrstr,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
                $tlsCtx
            );
            if ($tls !== false) {
                stream_set_blocking($tls, false);
                $this->tlsMaster = $tls;
            } else {
                $this->log("wss 端口 {$this->wssPort} 监听失败: [{$tlsErrno}] {$tlsErrstr}");
            }
        }

        $this->log('PHP MC 服务端已启动  '
PHP,
];

// 5) read 数组加 tls master
$edits[] = [
    "            \$read = [\$master];\n            \$write = [];",
    "            \$read = [\$master];\n            if (\$this->tlsMaster !== null) {\n                \$read[] = \$this->tlsMaster;\n            }\n            \$write = [];",
];

// 6) accept 分发
$edits[] = [
    "                    if (\$sock === \$master) {\n                        \$this->accept(\$master);\n                        continue;\n                    }",
    "                    if (\$sock === \$master\n                        || (\$this->tlsMaster !== null && \$sock === \$this->tlsMaster)) {\n                        \$this->accept(\$sock);\n                        continue;\n                    }",
];

// 7) accept 里标记 tls
$edits[] = [
    "        \$c = new Connection(\$sock, (string) \$peer);\n        \$c->ws = 'unknown';",
    "        \$c = new Connection(\$sock, (string) \$peer);\n        \$c->ws = 'unknown';\n        $c->tls = (\$this->tlsMaster !== null && \$master === \$this->tlsMaster);\n        \$c->tlsReady = !\$c->tls;",
];

// 8) pump 里先做 TLS 握手
$edits[] = [
    "    private function pump(Connection \$c): void\n    {\n        \$chunk = @fread(\$c->socket, 65536);",
    <<<'PHP'
    private function pump(Connection $c): void
    {
        // wss 进来的连接，先把 TLS 握手推完才看得见明文。
        // 非阻塞模式下 enable_crypto 返回 0 表示「还缺数据」，下一拍再来。
        if (!$c->tlsReady) {
            $r = @stream_socket_enable_crypto(
                $c->socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_SERVER
            );
            if ($r === true) {
                $c->tlsReady = true;
            } elseif ($r === 0) {
                return;
            } else {
                $this->drop($c, 'TLS 握手失败');

                return;
            }
        }

        $chunk = @fread($c->socket, 65536);
PHP,
];

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
echo "Server.php 已加 wss 监听，" . count($edits) . " 处\n";
