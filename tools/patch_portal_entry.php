<?php
declare(strict_types=1);

/**
 * 一次性补丁：把「现搭 24×24 平台」换成「在真地狱地形里凿入口房间」。
 * 读一次 → 整段替换（带首尾锚点断言）→ 只写一次。
 */

$path = __DIR__ . '/../src/PortalTrait.php';
$text = file_get_contents($path);
if ($text === false) {
    fwrite(STDERR, "读不到 $path\n");
    exit(1);
}
$EOL = str_contains($text, "\r\n") ? "\r\n" : "\n";
$text = str_replace("\r\n", "\n", $text);   // 统一成 LF 处理，最后还原

// ---- 1) 字段：记下落点高度 ----
$oldField = "    private bool \$netherBuilt = false;\n    private float \$lastPortalTick = 0.0;";
$newField = "    private bool \$netherBuilt = false;\n    /** 地狱入口房间的地面高度（第一次传送时定下来） */\n    private int \$netherY = self::NETHER_Y;\n    private float \$lastPortalTick = 0.0;";
if (substr_count($text, $oldField) !== 1) {
    fwrite(STDERR, "字段锚点不唯一，中止\n");
    exit(1);
}
$text = str_replace($oldField, $newField, $text);

// ---- 2) 整段替换旧函数 ----
$startMark = "    /** 现搭一个 24×24 的地狱岩平台（只搭一次） */\n";
$start = strpos($text, $startMark);
if ($start === false) {
    fwrite(STDERR, "找不到旧函数起点\n");
    exit(1);
}
// 从起点往后找 trait 的最后一个 "}\n"（文件结尾）
$end = strrpos($text, "\n}\n");
if ($end === false || $end < $start) {
    fwrite(STDERR, "找不到 trait 结尾\n");
    exit(1);
}

$newFn = <<<'PHP'
    /**
     * 在地狱地形里凿出入口房间 + 嵌回程门，返回玩家该站的 y。
     *
     * 地形是 NetherGenerator 生成的，落点落在一处天然空腔里 —— 但空腔的形状
     * 靠不住（可能只有 3 格高、可能地板是岩浆），所以统一凿一个 7×7×5 的房间，
     * 地面铺地狱岩，北墙嵌门。只做一次，位置记在 $netherY。
     */
    private function ensureNetherEntry(): int
    {
        $ox = self::NETHER_X;
        $oz = self::NETHER_Z;

        // 先把落点周围 3×3 区块生成出来，后面查方块才查得到
        for ($dz = -1; $dz <= 1; $dz++) {
            for ($dx = -1; $dx <= 1; $dx++) {
                $this->world->chunk(($ox >> 4) + $dx, ($oz >> 4) + $dz);
            }
        }

        if ($this->netherBuilt) {
            return $this->netherY;
        }
        $this->netherBuilt = true;

        // 从上往下找第一段连续 6 格空气 —— 那是最高的一层空腔
        $standY = -1;
        for ($y = 108; $y >= 34; $y--) {
            $ok = true;
            for ($k = 0; $k < 6; $k++) {
                if ($this->blockIdAt($ox, $y + $k, $oz) !== 0) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $standY = $y;
                break;
            }
        }
        if ($standY < 0) {
            $standY = 58;   // 那一列整根是石头，硬凿
        }

        $set = function (int $x, int $y, int $z, int $id, int $meta = 0): void {
            $this->world->chunk($x >> 4, $z >> 4)->setBlock($x & 15, $y, $z & 15, $id, $meta);
        };

        $half = 3;
        $top = $standY + 4;
        for ($dx = -$half; $dx <= $half; $dx++) {
            for ($dz = -$half; $dz <= $half; $dz++) {
                $wx = $ox + $dx;
                $wz = $oz + $dz;
                $set($wx, $standY - 1, $wz, self::NETHERRACK);
                for ($y = $standY; $y <= $top; $y++) {
                    $set($wx, $y, $wz, 0);
                }
            }
        }

        // 房间顶上挂两块萤石：天然空腔可能一点光都没有
        $set($ox + 2, $top, $oz, self::GLOWSTONE);
        $set($ox - 2, $top, $oz, self::GLOWSTONE);

        // 回程门：嵌在北墙（z = oz-3），4 宽 × 5 高。
        // 门框整体比地面低一格，这样玩家站在地面上时脚下那格就是传送门方块
        // （tickPortals 查的是 floor(y) 和 floor(y)+1，浮点误差差一格也命中）。
        $px = $ox - 1;
        $pz = $oz - 3;
        $base = $standY - 1;
        for ($i = 0; $i < 4; $i++) {
            for ($j = 0; $j < 5; $j++) {
                $edge = $i === 0 || $i === 3 || $j === 0 || $j === 4;
                if ($edge) {
                    $set($px + $i, $base + $j, $pz, self::OBSIDIAN);
                } elseif ($j >= 1 && $j <= 3) {
                    $set($px + $i, $base + $j, $pz, self::PORTAL_BLOCK, 2);
                }
            }
        }

        $this->netherY = $standY;
        $this->log(sprintf('  地狱入口已就绪 @ %d,%d,%d', $ox, $standY, $oz));

        return $standY;
    }
PHP;

$text = substr($text, 0, $start) . $newFn . substr($text, $end);

// 还原行尾
$text = str_replace("\n", $EOL, $text);
file_put_contents($path, $text);
echo "PortalTrait.php 入口房间已替换\n";
