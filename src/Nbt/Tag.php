<?php
declare(strict_types=1);

namespace PhpMc\Nbt;

/**
 * 一个 NBT 标签。类型常量与官方一致。
 *
 * 用「类型 + 值」一个类装完所有标签，而不是给每种类型写一个类：
 *   BYTE/SHORT/INT/LONG/FLOAT/DOUBLE -> int|float
 *   STRING                            -> string
 *   BYTE_ARRAY                        -> 原始二进制 string（关键：要和 STRING 区分开）
 *   LIST                              -> ['elem' => 元素类型, 'items' => Tag[]]
 *   COMPOUND                          -> ['名字' => Tag]
 *   INT_ARRAY / LONG_ARRAY            -> int[]
 */
final class Tag
{
    public const END = 0;
    public const BYTE = 1;
    public const SHORT = 2;
    public const INT = 3;
    public const LONG = 4;
    public const FLOAT = 5;
    public const DOUBLE = 6;
    public const BYTE_ARRAY = 7;
    public const STRING = 8;
    public const LIST = 9;
    public const COMPOUND = 10;
    public const INT_ARRAY = 11;
    public const LONG_ARRAY = 12;

    public function __construct(public int $type, public mixed $value)
    {
    }

    public static function compound(array $map = []): self
    {
        return new self(self::COMPOUND, $map);
    }

    public static function byte(int $v): self
    {
        return new self(self::BYTE, $v);
    }

    public static function short(int $v): self
    {
        return new self(self::SHORT, $v);
    }

    public static function float(float $v): self
    {
        return new self(self::FLOAT, $v);
    }

    public static function double(float $v): self
    {
        return new self(self::DOUBLE, $v);
    }

    public static function int(int $v): self
    {
        return new self(self::INT, $v);
    }

    public static function long(int $v): self
    {
        return new self(self::LONG, $v);
    }

    public static function str(string $v): self
    {
        return new self(self::STRING, $v);
    }

    /** 二进制字节串，不是文本 */
    public static function byteArray(string $bytes): self
    {
        return new self(self::BYTE_ARRAY, $bytes);
    }

    public static function intArray(array $ints): self
    {
        return new self(self::INT_ARRAY, $ints);
    }

    /** @param Tag[] $items */
    public static function list(int $elemType, array $items): self
    {
        return new self(self::LIST, ['elem' => $elemType, 'items' => array_values($items)]);
    }

    public function isCompound(): bool
    {
        return $this->type === self::COMPOUND;
    }
}
