<?php
declare(strict_types=1);

namespace PhpMc\Nbt;

final class Nbt
{
    private int $pos = 0;
    private int $len;

    private function __construct(private string $data)
    {
        $this->len = strlen($data);
    }

    // ---------------- 读 ----------------

    public static function parseFile(string $path): Tag
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("读不到 NBT 文件: {$path}");
        }

        return self::parse($raw);
    }

    /** 自动识别 gzip（level.dat）/ zlib（区块）/ 未压缩。 */
    public static function parse(string $raw): Tag
    {
        $raw = self::inflate($raw);
        $p = new self($raw);
        $type = $p->u8();
        if ($type !== Tag::COMPOUND) {
            throw new \RuntimeException("根标签不是 COMPOUND（type={$type}）");
        }
        $p->readString(); // 根名字，1.8 一般是空串

        return new Tag(Tag::COMPOUND, $p->payload(Tag::COMPOUND));
    }

    public static function inflate(string $raw): string
    {
        if (strlen($raw) > 2 && ord($raw[0]) === 0x1F && ord($raw[1]) === 0x8B) {
            $out = @gzdecode($raw);
            if ($out !== false) {
                return $out;
            }
        }
        $out = @zlib_decode($raw);
        if ($out !== false) {
            return $out;
        }

        return $raw;
    }

    private function payload(int $type): mixed
    {
        switch ($type) {
            case Tag::BYTE:
                $v = $this->u8();

                return $v >= 0x80 ? $v - 0x100 : $v;

            case Tag::SHORT:
                $v = unpack('n', $this->take(2))[1];

                return $v >= 0x8000 ? $v - 0x10000 : $v;

            case Tag::INT:
                $v = unpack('N', $this->take(4))[1];

                return $v >= 0x80000000 ? $v - 0x100000000 : $v;

            case Tag::LONG:
                return unpack('J', $this->take(8))[1];

            case Tag::FLOAT:
                return unpack('G', $this->take(4))[1];

            case Tag::DOUBLE:
                return unpack('E', $this->take(8))[1];

            case Tag::BYTE_ARRAY:
                $n = $this->i32();
                if ($n < 0 || $n > 64 * 1024 * 1024) {
                    throw new \RuntimeException("BYTE_ARRAY 长度非法: {$n}");
                }

                return $this->take($n);

            case Tag::STRING:
                return $this->readString();

            case Tag::LIST:
                $elem = $this->u8();
                $n = $this->i32();
                if ($n < 0 || $n > 4 * 1024 * 1024) {
                    throw new \RuntimeException("LIST 长度非法: {$n}");
                }
                $items = [];
                for ($i = 0; $i < $n; $i++) {
                    $items[] = new Tag($elem, $this->payload($elem));
                }

                return ['elem' => $elem, 'items' => $items];

            case Tag::COMPOUND:
                $map = [];
                while (true) {
                    $t = $this->u8();
                    if ($t === Tag::END) {
                        break;
                    }
                    $name = $this->readString();
                    $map[$name] = new Tag($t, $this->payload($t));
                }

                return $map;

            case Tag::INT_ARRAY:
                $n = $this->i32();
                if ($n < 0 || $n > 4 * 1024 * 1024) {
                    throw new \RuntimeException("INT_ARRAY 长度非法: {$n}");
                }
                $out = [];
                for ($i = 0; $i < $n; $i++) {
                    $out[] = $this->payload(Tag::INT);
                }

                return $out;

            case Tag::LONG_ARRAY:
                $n = $this->i32();
                if ($n < 0 || $n > 4 * 1024 * 1024) {
                    throw new \RuntimeException("LONG_ARRAY 长度非法: {$n}");
                }
                $out = [];
                for ($i = 0; $i < $n; $i++) {
                    $out[] = $this->payload(Tag::LONG);
                }

                return $out;
        }

        throw new \RuntimeException("未知 NBT 类型 {$type}");
    }

    private function take(int $n): string
    {
        if ($n < 0 || $this->pos + $n > $this->len) {
            throw new \RuntimeException('NBT 数据被截断');
        }
        $s = substr($this->data, $this->pos, $n);
        $this->pos += $n;

        return $s;
    }

    private function u8(): int
    {
        return ord($this->take(1));
    }

    private function i32(): int
    {
        $v = unpack('N', $this->take(4))[1];

        return $v >= 0x80000000 ? $v - 0x100000000 : $v;
    }

    private function readString(): string
    {
        $n = unpack('n', $this->take(2))[1];

        return $this->take($n);
    }

    // ---------------- 写 ----------------

    public static function write(Tag $root, string $name = ''): string
    {
        return chr($root->type) . pack('n', strlen($name)) . $name
            . self::payloadOut($root->type, $root->value);
    }

    public static function writeGzip(Tag $root, string $name = ''): string
    {
        $raw = self::write($root, $name);
        $gz = gzencode($raw, 6);
        if ($gz === false) {
            throw new \RuntimeException('gzip 压缩失败');
        }

        return $gz;
    }

    private static function payloadOut(int $type, mixed $v): string
    {
        switch ($type) {
            case Tag::BYTE:
                return chr(((int) $v) & 0xFF);

            case Tag::SHORT:
                return pack('n', ((int) $v) & 0xFFFF);

            case Tag::INT:
                return pack('N', ((int) $v) & 0xFFFFFFFF);

            case Tag::LONG:
                return pack('J', (int) $v);

            case Tag::FLOAT:
                return pack('G', (float) $v);

            case Tag::DOUBLE:
                return pack('E', (float) $v);

            case Tag::BYTE_ARRAY:
                return pack('N', strlen($v)) . $v;

            case Tag::STRING:
                return pack('n', strlen($v)) . $v;

            case Tag::LIST:
                $out = chr($v['elem']) . pack('N', count($v['items']));
                foreach ($v['items'] as $item) {
                    $out .= self::payloadOut($item->type, $item->value);
                }

                return $out;

            case Tag::COMPOUND:
                $out = '';
                foreach ($v as $name => $child) {
                    /** @var Tag $child */
                    $out .= chr($child->type) . pack('n', strlen($name)) . $name
                        . self::payloadOut($child->type, $child->value);
                }

                return $out . "\x00";

            case Tag::INT_ARRAY:
                $out = pack('N', count($v));
                foreach ($v as $i) {
                    $out .= pack('N', ((int) $i) & 0xFFFFFFFF);
                }

                return $out;

            case Tag::LONG_ARRAY:
                $out = pack('N', count($v));
                foreach ($v as $i) {
                    $out .= pack('J', (int) $i);
                }

                return $out;
        }

        throw new \RuntimeException("不能写 NBT 类型 {$type}");
    }

    // ---------------- 取值便利 ----------------

    public static function child(?Tag $node, string $name): ?Tag
    {
        if ($node === null || $node->type !== Tag::COMPOUND) {
            return null;
        }

        return $node->value[$name] ?? null;
    }

    public static function int(?Tag $node, string $name, int $def = 0): int
    {
        $c = self::child($node, $name);

        return $c === null ? $def : (int) $c->value;
    }

    public static function str(?Tag $node, string $name, string $def = ''): string
    {
        $c = self::child($node, $name);

        return $c === null ? $def : (string) $c->value;
    }

    public static function bytes(?Tag $node, string $name): ?string
    {
        $c = self::child($node, $name);
        if ($c === null || $c->type !== Tag::BYTE_ARRAY) {
            return null;
        }

        return $c->value;
    }

    /** @return Tag[] */
    public static function listItems(?Tag $node, string $name): array
    {
        $c = self::child($node, $name);
        if ($c === null || $c->type !== Tag::LIST) {
            return [];
        }

        return $c->value['items'];
    }
}
