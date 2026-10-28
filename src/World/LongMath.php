<?php
declare(strict_types=1);

namespace PhpMc\World;

/**
 * 64 位有符号整数的「回绕」乘法/加法（等价 Java 的 long 溢出行为）。
 *
 * 为什么需要：原版群系层的 LCG 是
 *     seed = seed * (seed * 6364136223846793005 + 1442695040888963407) + base
 * 全是远超 64 位的中间结果。PHP 的 int 溢出会静默转成 float，位模式就废了。
 * 这里用 16 位分肢手算，只保留低 64 位，再解释成有符号数。
 */
final class LongMath
{
    /** 低 64 位乘法（结果按有符号长整型解释） */
    public static function mul(int $a, int $b): int
    {
        $al = [$a & 0xFFFF, ($a >> 16) & 0xFFFF, ($a >> 32) & 0xFFFF, ($a >> 48) & 0xFFFF];
        $bl = [$b & 0xFFFF, ($b >> 16) & 0xFFFF, ($b >> 32) & 0xFFFF, ($b >> 48) & 0xFFFF];

        $r = [0, 0, 0, 0];
        for ($i = 0; $i < 4; $i++) {
            $carry = 0;
            for ($j = 0; $i + $j < 4; $j++) {
                $cur = $r[$i + $j] + $al[$i] * $bl[$j] + $carry;
                $r[$i + $j] = $cur & 0xFFFF;
                $carry = $cur >> 16;
            }
        }

        $lo = ($r[3] << 48) | ($r[2] << 32) | ($r[1] << 16) | $r[0];

        return $lo;
    }

    /** 64 位回绕加法 */
    public static function add(int $a, int $b): int
    {
        $sum = $a + $b;
        // PHP 溢出会变 float，这种情况用分肢重算
        if (is_float($sum)) {
            $al = [$a & 0xFFFF, ($a >> 16) & 0xFFFF, ($a >> 32) & 0xFFFF, ($a >> 48) & 0xFFFF];
            $bl = [$b & 0xFFFF, ($b >> 16) & 0xFFFF, ($b >> 32) & 0xFFFF, ($b >> 48) & 0xFFFF];
            $r = [0, 0, 0, 0];
            $carry = 0;
            for ($i = 0; $i < 4; $i++) {
                $cur = $al[$i] + $bl[$i] + $carry;
                $r[$i] = $cur & 0xFFFF;
                $carry = $cur >> 16;
            }

            return ($r[3] << 48) | ($r[2] << 32) | ($r[1] << 16) | $r[0];
        }

        return $sum;
    }
    /**
     * Java 的 long 除法：向零截断（PHP 的 intdiv 行为一致）。
     * 只有 MIN / -1 这一种会溢出，Java 回绕成 MIN，这里照做。
     */
    public static function div(int $a, int $b): int
    {
        if ($b === -1) {
            return $a === PHP_INT_MIN ? PHP_INT_MIN : -$a;
        }

        return intdiv($a, $b);
    }

    /** Java 的 long 取余：符号跟被除数（PHP 的 % 行为一致） */
    public static function mod(int $a, int $b): int
    {
        if ($b === -1) {
            return 0;
        }

        return $a % $b;
    }
}
