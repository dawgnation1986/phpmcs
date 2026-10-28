<?php
declare(strict_types=1);

namespace PhpMc\World;

/**
 * java.util.Random 的同款实现（Minecraft Java 版用的就是它）。
 *
 * 48 位线性同余：seed = (seed * 0x5DEECE66D + 0xB) & (2^48 - 1)
 *
 * 为什么不用原生乘法：PHP 的 int 是 64 位，48 位种子乘 37 位乘数会溢出成
 * float，结果就废了。这里拆成高低 24 位分别乘，全程不超过 2^61。
 */
final class JavaRandom
{
    private const MULT = 0x5DEECE66D;
    private const ADD = 0xB;
    private const MASK = 0xFFFFFFFFFFFF;   // 2^48 - 1

    private int $seed = 0;

    public function __construct(int $seed)
    {
        $this->setSeed($seed);
    }

    public function setSeed(int $seed): void
    {
        $this->seed = ($seed ^ self::MULT) & self::MASK;
    }

    /** 取高 $bits 位（带符号） */
    private function next(int $bits): int
    {
        $lo = $this->seed & 0xFFFFFF;
        $hi = ($this->seed >> 24) & 0xFFFFFF;

        // seed = hi*2^24 + lo
        // seed*A + B = (hi*A + q)*2^24 + r，其中 lo*A + B = q*2^24 + r
        // 所以进位是 >> 24，不是 >> 48（这里之前写错了，结果全偏）
        $t = $lo * self::MULT + self::ADD;
        $newLo = $t & 0xFFFFFF;
        $newHi = ($hi * self::MULT + ($t >> 24)) & 0xFFFFFF;

        $this->seed = ($newHi << 24) | $newLo;

        // Java 用的是无符号右移 >>>：先取出低 $bits 位，
        // 只有 bits=32 时才按 32 位有符号解释（可能为负）。
        $r = ($this->seed >> (48 - $bits)) & ((1 << $bits) - 1);
        if ($bits === 32 && $r >= 0x80000000) {
            $r -= 0x100000000;
        }

        return $r;
    }

    public function nextInt(): int
    {
        return $this->next(32);
    }

    /** 和 Java 一样：[0, bound) */
    public function nextIntBound(int $bound): int
    {
        if ($bound <= 0) {
            throw new \InvalidArgumentException('bound must be positive');
        }

        // 2 的幂：直接取高位
        if (($bound & ($bound - 1)) === 0) {
            return (int) (($bound * $this->next(31)) >> 31);
        }

        do {
            $bits = $this->next(31);
            $val = $bits % $bound;
            // Java 那句 while 靠 int 溢出判断；这里用等价的「超过 2^31-1」
        } while ($bits - $val + ($bound - 1) > 0x7FFFFFFF);

        return $val;
    }

    public function nextFloat(): float
    {
        return $this->next(24) / 16777216.0;   // 2^24
    }

    public function nextDouble(): float
    {
        return (($this->next(26) << 27) + $this->next(27)) / 9007199254740992.0;  // 2^53
    }

    public function nextLong(): int
    {
        $hi = $this->next(32);
        $lo = $this->next(32);

        // Java 的 ((long)hi << 32) + lo 会回绕，PHP 直接乘会变 float
        return LongMath::add(LongMath::mul($hi, 4294967296), $lo & 0xFFFFFFFF);
    }

    /** 当前 48 位种子（调试用） */
    public function state(): int
    {
        return $this->seed;
    }
}
