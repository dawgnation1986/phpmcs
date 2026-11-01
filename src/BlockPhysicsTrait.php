<?php
declare(strict_types=1);

namespace PhpMc;

/**
 * 方块物理：支撑检查 + 邻居更新 + 重力方块。
 *
 * 对应 1.8.9 的 Block.canBlockStay / onNeighborBlockChange / BlockFalling。
 * 所有写入都走 changeBlock()，这里只在「写入完成后」做两件事：
 *   1. 看自己和六个邻居是否还站得住，站不住就掉落
 *   2. 沙子/沙砾这类方块下面空了，就往下掉一格
 */
trait BlockPhysicsTrait
{
    /** @var array 待下落方块 "x,y,z" => 下次下落时间 */
    private array $falling = [];

    private float $lastFallTick = 0.0;

    /** 需要支撑的方块（下方必须是特定方块） */
    private const NEEDS_SUPPORT = [
        6,   // 树苗
        37,  // 蒲公英
        38,  // 玫瑰
        39,  // 棕蘑菇
        40,  // 红蘑菇
        50,  // 火把
        59,  // 作物
        75,  // 红石火把（墙上）
        76,  // 红石火把（地面）
        78,  // 雪层
        81,  // 仙人掌
        83,  // 甘蔗
        115, // 地狱疣
        127, // 可可豆
        132, // 绊线
        140, // 花盆
        141, // 胡萝卜
        142, // 马铃薯
        143, // 按钮（地面）
        175, // 双生花
    ];

    /** 会下落的重力方块 */
    private const FALLING_BLOCKS = [
        12,  // 沙子
        13,  // 沙砾
        122, // 龙蛋
        145, // 铁砧
    ];

    private function blockIdAt(int $x, int $y, int $z): int
    {
        if ($y < 0 || $y > 255) {
            return -1;
        }
        $chunk = $this->world->chunk($x >> 4, $z >> 4);

        return $chunk->blockState($x & 15, $y, $z & 15) >> 4;
    }

    private function blockMetaAt(int $x, int $y, int $z): int
    {
        if ($y < 0 || $y > 255) {
            return 0;
        }
        $chunk = $this->world->chunk($x >> 4, $z >> 4);

        return $chunk->blockState($x & 15, $y, $z & 15) & 15;
    }

    /** 重力方块能不能落进这个方块（原版 canFallInto：空气/火/水/岩浆） */
    private function canFallInto(int $id): bool
    {
        return $id === 0 || $id === 51 || $id === 8 || $id === 9 || $id === 10 || $id === 11;
    }

    /** 这个方块现在站得住吗（对应 canBlockStay） */
    private function canStay(int $id, int $x, int $y, int $z): bool
    {
        $below = $this->blockIdAt($x, $y - 1, $z);

        switch ($id) {
            case 83:   // 甘蔗：下面可以是甘蔗；否则地基必须是草/土/沙，且四邻有水
                if ($below === 83) {
                    return true;
                }
                if (!in_array($below, [2, 3, 12], true)) {
                    return false;
                }
                foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$dx, $dz]) {
                    $side = $this->blockIdAt($x + $dx, $y - 1, $z + $dz);
                    if ($side === 8 || $side === 9) {
                        return true;
                    }
                }

                return false;

            case 81:   // 仙人掌：下面是沙/仙人掌，且四邻不能是固体
                if ($below !== 12 && $below !== 81) {
                    return false;
                }
                foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$dx, $dz]) {
                    $side = $this->blockIdAt($x + $dx, $y, $z + $dz);
                    if ($side !== 0 && $side !== 8 && $side !== 9) {
                        return false;
                    }
                }

                return true;

            case 59:   // 作物：下面是耕地
            case 141:
            case 142:
                return $below === 60;

            case 115:  // 地狱疣：下面是灵魂沙
                return $below === 88;

            case 127:  // 可可豆：必须贴着丛林木侧面
                return $this->blockIdAt($x, $y - 1, $z) === 99
                    || $this->blockIdAt($x, $y, $z - 1) === 99
                    || $this->blockIdAt($x, $y, $z + 1) === 99;

            case 50:   // 火把：下面不能是空气
            case 76:
                return $below !== 0 && $below !== -1;

            case 78:   // 雪层：下面不能是空气
                return $below !== 0 && $below !== -1;

            default:   // 花/树苗/蘑菇：下面不能是空气
                return $below !== 0 && $below !== -1;
        }
    }

    /**
     * 邻居更新：自己 + 六个方向。
     * 站不住的方块掉落并置空；空出来的位置会继续触发上层（形成逐节清理）。
     */
    private function neighborUpdate(int $x, int $y, int $z): void
    {
        // 用队列而不是递归：之前靠 $inNeighborUpdate 挡递归，结果「掉一格触发
        // 下一格」也被挡掉了，甘蔗只剩最上面一节会掉。现在自己维护待检查队列。
        $queue = [[$x, $y, $z]];
        $seen = [];
        for ($i = 0; $i < 512 && $queue !== []; $i++) {
            [$tx, $ty, $tz] = array_shift($queue);
            $key = $tx . ',' . $ty . ',' . $tz;
            if (isset($seen[$key])) {
                continue;          // 同一格不重复处理，否则队列会 6 倍膨胀、提前撞上限
            }
            $seen[$key] = true;
            $id0 = $this->blockIdAt($tx, $ty, $tz);

            if ($id0 > 0 && in_array($id0, self::NEEDS_SUPPORT, true)
                && !$this->canStay($id0, $tx, $ty, $tz)) {
                [$dropId, $count] = self::dropOf($id0, $this->blockMetaAt($tx, $ty, $tz));
                if ($dropId > 0 && $count > 0) {
                    $this->spawnDrop($tx, $ty, $tz, $dropId, $count, 0, null);
                }
                $this->log("  支撑失效：方块 {$id0} @ {$tx},{$ty},{$tz} 已掉落");
                $this->changeBlock($tx, $ty, $tz, 0, 0);
                // 自己这一格、上面那格（甘蔗/仙人掌会往上串）都要重新检查
                $queue[] = [$tx, $ty, $tz];
                $queue[] = [$tx, $ty + 1, $tz];
            }

            // 重力方块：下面是空气就排队下落。
            // 【注意】这段以前被放在下面那个 `foreach ([] as ...)` 里（空数组），
            // 等于从来没执行过 —— 沙子永远不掉就是这个原因。
            if ($id0 > 0 && in_array($id0, self::FALLING_BLOCKS, true)
                && $this->canFallInto($this->blockIdAt($tx, $ty - 1, $tz))) {
                $this->falling[$tx . ',' . $ty . ',' . $tz] = microtime(true) + 0.12;
                $this->log("  排队下落：{$id0} @ {$tx},{$ty},{$tz}");
            }

            foreach ([[1, 0, 0], [-1, 0, 0], [0, 1, 0], [0, -1, 0], [0, 0, 1], [0, 0, -1]] as [$dx, $dy, $dz]) {
                $nx2 = $tx + $dx;
                $ny2 = $ty + $dy;
                $nz2 = $tz + $dz;
                // 空气不用排队（省得队列被空格子塞满，真正要检查的反而没轮到）
                if ($this->blockIdAt($nx2, $ny2, $nz2) === 0) {
                    continue;
                }
                $queue[] = [$nx2, $ny2, $nz2];
            }
        }

    }

    /** 下落：每 50ms 掉一格，掉到底或碰到实体方块就停下 */
    private function tickFalling(float $now): void
    {
        if ($this->falling === []) {
            return;
        }
        if ($now - $this->lastFallTick < 0.22) {
            return;
        }
        $this->lastFallTick = $now;

        foreach ($this->falling as $key => $due) {
            if ($now < $due) {
                continue;
            }
            unset($this->falling[$key]);
            [$fx, $fy, $fz] = array_map('intval', explode(',', $key));

            $id = $this->blockIdAt($fx, $fy, $fz);
            if (!in_array($id, self::FALLING_BLOCKS, true)) {
                continue;
            }
            $below = $this->blockIdAt($fx, $fy - 1, $fz);
            // 水和岩浆挡不住重力方块（原版会把它挤掉往下沉）
            if ($below === 8 || $below === 9 || $below === 10 || $below === 11) {
                $this->changeBlock($fx, $fy - 1, $fz, 0, 0);
                $below = 0;
            }
            if ($below !== 0 || $fy <= 0) {
                continue;    // 落地了
            }

            $meta = $this->blockMetaAt($fx, $fy, $fz);
            $this->changeBlock($fx, $fy, $fz, 0, 0);
            $this->changeBlock($fx, $fy - 1, $fz, $id, $meta);
            $this->falling[$fx . ',' . ($fy - 1) . ',' . $fz] = $now + 0.22;

            // 上面那格如果是同类重力方块，也跟着掉（整列一起落，不是只掉最上面一个）
            $above = $this->blockIdAt($fx, $fy + 1, $fz);
            if (in_array($above, self::FALLING_BLOCKS, true)) {
                $this->falling[$fx . ',' . ($fy + 1) . ',' . $fz] = $now + 0.22;
            }
        }
    }

    /**
     * 区块生成后清理悬空植物。
     * 只在「刚生成」的区块上调一次，不扫整块（16*16*256 在 PHP 里太慢）。
     */
    private function validatePlantsInChunk(int $cx, int $cz): void
    {
        $chunk = $this->world->chunk($cx, $cz);
        $removed = 0;
        foreach (self::NEEDS_SUPPORT as $id) {
            for ($y = 1; $y < 256; $y++) {
                for ($z = 0; $z < 16; $z++) {
                    for ($x = 0; $x < 16; $x++) {
                        if (($chunk->blockState($x, $y, $z) >> 4) !== $id) {
                            continue;
                        }
                        $wx = $cx * 16 + $x;
                        $wz = $cz * 16 + $z;
                        if ($this->canStay($id, $wx, $y, $wz)) {
                            continue;
                        }
                        $chunk->setBlock($x, $y, $z, 0, 0);
                        $removed++;
                    }
                }
            }
        }
        if ($removed > 0) {
            $this->log("  区块 {$cx},{$cz} 清理悬空植物 {$removed} 个");
        }
    }
}
