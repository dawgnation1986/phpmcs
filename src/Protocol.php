<?php
declare(strict_types=1);

namespace PhpMc;

/**
 * Minecraft 1.8.9 (protocol 47) 基础编解码。
 *
 * 1.8 的包 = VarInt 长度 + VarInt 包ID + 负载，
 * 所有多字节整数都是大端（big-endian），只有区块方块数据是 little-endian。
 */
final class VarInt
{
    /** 把 int 编成 VarInt 字节串（按 32 位补码处理负数）。 */
    public static function encode(int $value): string
    {
        $value &= 0xFFFFFFFF;
        $out = '';
        do {
            $byte = $value & 0x7F;
            $value = ($value >> 7) & 0x01FFFFFF;
            if ($value !== 0) {
                $byte |= 0x80;
            }
            $out .= chr($byte);
        } while ($value !== 0);

        return $out;
    }

    public static function decode(string $buf, int &$offset): int
    {
        $num = 0;
        $shift = 0;
        $len = strlen($buf);

        while (true) {
            if ($offset >= $len) {
                throw new \RuntimeException('VarInt: 数据不够，等下一个包');
            }
            $b = ord($buf[$offset++]);
            $num |= ($b & 0x7F) << $shift;
            if (($b & 0x80) === 0) {
                break;
            }
            $shift += 7;
            if ($shift > 35) {
                throw new \RuntimeException('VarInt: 超过 5 字节');
            }
        }

        // 转回有符号 32 位
        if ($num & 0x80000000) {
            $num -= 0x100000000;
        }

        return $num;
    }
}

/** 读包：所有 read* 都会推进内部游标。 */
final class Reader
{
    private int $pos = 0;
    private int $len;

    public function __construct(private string $data)
    {
        $this->len = strlen($data);
    }

    public function remaining(): int
    {
        return $this->len - $this->pos;
    }

    public function readVarInt(): int
    {
        return VarInt::decode($this->data, $this->pos);
    }

    public function readByte(): int
    {
        if ($this->pos >= $this->len) {
            throw new \RuntimeException('readByte: eof');
        }
        $v = ord($this->data[$this->pos++]);

        return $v >= 0x80 ? $v - 0x100 : $v;
    }

    public function readUnsignedByte(): int
    {
        if ($this->pos >= $this->len) {
            throw new \RuntimeException('readUnsignedByte: eof');
        }

        return ord($this->data[$this->pos++]);
    }

    public function readBool(): bool
    {
        return $this->readUnsignedByte() !== 0;
    }

    public function readShort(): int
    {
        $v = unpack('n', $this->take(2))[1];

        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    public function readUnsignedShort(): int
    {
        return unpack('n', $this->take(2))[1];
    }

    public function readInt(): int
    {
        $v = unpack('N', $this->take(4))[1];
        if ($v >= 0x80000000) {
            $v -= 0x100000000;
        }

        return $v;
    }

    public function readLong(): int
    {
        $v = unpack('J', $this->take(8))[1];

        return $v;
    }

    public function readFloat(): float
    {
        return unpack('G', $this->take(4))[1];
    }

    public function readDouble(): float
    {
        return unpack('E', $this->take(8))[1];
    }

    /** 1.8 的字符串：VarInt 长度 + UTF-8 字节。 */
    public function readString(int $max = 32767): string
    {
        $n = $this->readVarInt();
        if ($n < 0 || $n > $max * 4) {
            throw new \RuntimeException("readString: 长度非法 {$n}");
        }

        return $this->take($n);
    }

    public function take(int $n): string
    {
        if ($n < 0 || $this->pos + $n > $this->len) {
            throw new \RuntimeException('take: eof');
        }
        $s = substr($this->data, $this->pos, $n);
        $this->pos += $n;

        return $s;
    }

    public function rest(): string
    {
        return $this->take($this->remaining());
    }

    /** 记下当前位置，配合 sliceFrom() 原样取出后面一段（物品 NBT 不想解析时很有用）。 */
    public function mark(): int
    {
        return $this->pos;
    }

    public function sliceFrom(int $offset): string
    {
        if ($offset < 0 || $offset > $this->len) {
            return '';
        }

        return substr($this->data, $offset);
    }
}

/** 写包：链式调用，最后 toString() 拿字节。 */
final class Writer
{
    private string $buf = '';

    public function varInt(int $v): self
    {
        $this->buf .= VarInt::encode($v);

        return $this;
    }

    public function byte(int $v): self
    {
        $this->buf .= chr($v & 0xFF);

        return $this;
    }

    public function unsignedByte(int $v): self
    {
        return $this->byte($v);
    }

    public function bool(bool $v): self
    {
        return $this->byte($v ? 1 : 0);
    }

    public function short(int $v): self
    {
        $this->buf .= pack('n', $v & 0xFFFF);

        return $this;
    }

    public function int(int $v): self
    {
        $this->buf .= pack('N', $v & 0xFFFFFFFF);

        return $this;
    }

    public function long(int $v): self
    {
        $this->buf .= pack('J', $v);

        return $this;
    }

    public function float(float $v): self
    {
        $this->buf .= pack('G', $v);

        return $this;
    }

    public function double(float $v): self
    {
        $this->buf .= pack('E', $v);

        return $this;
    }

    public function string(string $s): self
    {
        if (strlen($s) > 32767) {
            throw new \RuntimeException('string 过长');
        }
        $this->buf .= VarInt::encode(strlen($s)) . $s;

        return $this;
    }

    public function raw(string $bytes): self
    {
        $this->buf .= $bytes;

        return $this;
    }

    public function length(): int
    {
        return strlen($this->buf);
    }

    public function toString(): string
    {
        return $this->buf;
    }
}

/** 分帧工具：把一个完整包（包ID+负载）套上长度前缀。 */
final class Frame
{
    public static function encode(string $packet): string
    {
        return VarInt::encode(strlen($packet)) . $packet;
    }

    /**
     * 从缓冲区里切出所有完整包。
     * 返回 [包ID, Reader|null]，Reader 为 null 表示负载为空。
     *
     * @return array<int, array{0:int,1:Reader}>
     */
    public static function drain(string &$buffer): array
    {
        $packets = [];
        $offset = 0;
        $total = strlen($buffer);

        while ($offset < $total) {
            $probe = $offset;
            try {
                $length = VarInt::decode($buffer, $probe);
            } catch (\RuntimeException) {
                break; // 长度字段还没收全
            }

            if ($length < 0 || $length > 2 * 1024 * 1024) {
                throw new \RuntimeException("包长度非法: {$length}");
            }
            if ($probe + $length > $total) {
                break; // 负载还没收全
            }

            $payload = substr($buffer, $probe, $length);
            $offset = $probe + $length;

            $pos = 0;
            $id = VarInt::decode($payload, $pos);
            $body = substr($payload, $pos);
            $packets[] = [$id, new Reader($body)];
        }

        $buffer = substr($buffer, $offset);

        return $packets;
    }
}
