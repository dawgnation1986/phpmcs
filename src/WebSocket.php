<?php
declare(strict_types=1);

namespace PhpMc;

/**
 * WebSocket 服务端（RFC 6455）的最小实现，只做我们需要的部分：
 *   - 识别客户端发来的 HTTP Upgrade 请求，回 101
 *   - 解客户端帧（必须带 mask），把 payload 拼成字节流
 *   - 把服务端的字节流包成 binary 帧
 *   - 回 pong
 *
 * 不做：分片重组的扩展协商、压缩扩展、子协议。
 * 网页版客户端（eaglercraft 那类）连上来就是一个普通的 MC 字节流，
 * 所以这里只是「在 socket 和协议层之间加一层拆包/打包」。
 */
final class WebSocket
{
    /** 首包像不像 HTTP 请求（用来区分普通 MC 客户端和网页客户端） */
    public static function looksLikeHttp(string $chunk): bool
    {
        return str_starts_with($chunk, 'GET ')
            || str_starts_with($chunk, 'POST ')
            || str_starts_with($chunk, 'HEAD ');
    }

    /** 从 HTTP 头算 101 响应；缺 Sec-WebSocket-Key 就返回 null */
    public static function handshakeResponse(string $header): ?string
    {
        if (!preg_match('/Sec-WebSocket-Key:\s*(\S+)/i', $header, $m)) {
            return null;
        }
        // RFC 6455 固定魔法串，拼上客户端的 key 做 SHA-1 再 base64
        $accept = base64_encode(sha1($m[1] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        return "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: {$accept}\r\n"
            . "\r\n";
    }

    /**
     * 从缓冲区里解出所有完整帧的 payload。
     *
     * @param string $buf 引用传入，解完的字节会被切掉，没解完的留在里面
     *
     * @return array{0:string,1:string[],2:bool} [payload, 要回的 pong 数据, 是否收到 close]
     */
    public static function decode(string &$buf): array
    {
        $payload = '';
        $pongs = [];
        $closed = false;

        while (true) {
            $len = strlen($buf);
            if ($len < 2) {
                break;
            }

            $b0 = ord($buf[0]);
            $b1 = ord($buf[1]);
            $opcode = $b0 & 0x0F;
            $masked = ($b1 & 0x80) !== 0;
            $plen = $b1 & 0x7F;
            $off = 2;

            if ($plen === 126) {
                if ($len < 4) {
                    break;
                }
                $plen = (ord($buf[2]) << 8) | ord($buf[3]);
                $off = 4;
            } elseif ($plen === 127) {
                if ($len < 10) {
                    break;
                }
                // 只取低 32 位：MC 的包不可能超过 4GB
                $plen = 0;
                for ($i = 4; $i < 8; $i++) {
                    $plen = ($plen << 8) | ord($buf[2 + $i]);
                }
                $off = 10;
            }

            $maskKey = '';
            if ($masked) {
                if ($len < $off + 4) {
                    break;
                }
                $maskKey = substr($buf, $off, 4);
                $off += 4;
            }

            if ($len < $off + $plen) {
                break;   // 帧还没收全
            }

            $data = substr($buf, $off, $plen);
            if ($masked && $plen > 0) {
                for ($i = 0; $i < $plen; $i++) {
                    $data[$i] = chr(ord($data[$i]) ^ ord($maskKey[$i & 3]));
                }
            }
            $buf = substr($buf, $off + $plen);

            if ($opcode === 0x8) {          // close
                $closed = true;
                break;
            }
            if ($opcode === 0x9) {          // ping
                $pongs[] = $data;
                continue;
            }
            if ($opcode === 0xA) {          // pong，忽略
                continue;
            }
            // 0x0 continuation / 0x1 text / 0x2 binary 一律当字节流拼起来
            $payload .= $data;
        }

        return [$payload, $pongs, $closed];
    }

    /** 把字节流包成一个 binary 帧（服务端发出的帧不加 mask） */
    public static function frame(string $data): string
    {
        return self::wrap(0x2, $data);
    }

    /** 回 pong（客户端心跳，不回会被判定超时断开） */
    public static function pong(string $data): string
    {
        return self::wrap(0xA, $data);
    }

    private static function wrap(int $opcode, string $data): string
    {
        $len = strlen($data);
        $head = chr(0x80 | $opcode);

        if ($len < 126) {
            $head .= chr($len);
        } elseif ($len < 65536) {
            $head .= chr(126) . pack('n', $len);
        } else {
            // 64 位长度：高 32 位固定 0，低 32 位大端
            $head .= chr(127) . pack('N2', 0, $len);
        }

        return $head . $data;
    }
}
