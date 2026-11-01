<?php
declare(strict_types=1);

namespace PhpMc;

/**
 * 地狱门（A 方案：传送门 + 固定平台，不做真地形）。
 *
 * 做法：
 *   1. 打火石点黑曜石 → 在附近找 4×5 的黑曜石框，内部填传送门方块(90)
 *   2. 站在传送门里 2 秒 → 传送到「地狱平台」（同一世界、远坐标）
 *   3. 平台是现搭的：24×24 地狱岩地面 + 四周围栏 + 一个回程门
 *   4. 在地狱那边站门里 → 传回原来的位置
 *
 * 为什么不去改维度：
 *   1.8 切维度要发 0x07 并重建整个客户端世界，还要给 AnvilWorld 加 DIM-1 目录，
 *   一旦改错主世界也会崩。这里用「远坐标 + 地狱岩地面」模拟，
 *   客户端看起来就是地狱，风险为零。
 */
trait PortalTrait
{
    private const PORTAL_BLOCK = 90;
    private const OBSIDIAN = 49;
    private const NETHERRACK = 87;
    private const GLOWSTONE = 89;

    /** 地狱平台原点（远到不会碰到正常地形） */
    private const NETHER_X = 200000;
    private const NETHER_Y = 64;
    private const NETHER_Z = 200000;

    private bool $netherBuilt = false;
    private float $lastPortalTick = 0.0;

    /**
     * 从「火所在的那一格」向四周探，找出黑曜石框并填传送门。
     *
     * 原版允许 2×3（最小）到 21×21 的内部尺寸，所以不能写死尺寸，
     * 要顺着一个方向走到黑曜石边界，再验证四条边。
     */
    private function tryIgnitePortal(Connection $c, int $x, int $y, int $z): bool
    {
        foreach (['x', 'z'] as $axis) {
            $r = $this->probeFrame($x, $y, $z, $axis);
            if ($r === null) {
                continue;
            }
            [$x0, $y0, $z0, $w, $h] = $r;
            $meta = $axis === 'x' ? 1 : 2;
            for ($i = 0; $i < $w; $i++) {
                for ($j = 0; $j < $h; $j++) {
                    $this->changeBlock(
                        $axis === 'x' ? $x0 + $i : $x0,
                        $y0 + $j,
                        $axis === 'z' ? $z0 + $i : $z0,
                        self::PORTAL_BLOCK,
                        $meta
                    );
                }
            }
            $this->log("  {$c->username} 点燃地狱门 @ {$x0},{$y0},{$z0} 轴={$axis} 内部={$w}x{$h}");
            $this->sendChat($c, ['text' => '地狱门被点燃了。', 'color' => 'light_purple'], 1);

            return true;
        }

        return false;
    }

    /**
     * 以 (x,y,z) 为内部一格，沿 axis 方向探边界。
     * 返回 [x0,y0,z0,内宽,内高]，不合法返回 null。
     */
    private function probeFrame(int $x, int $y, int $z, string $axis): ?array
    {
        $at = function (int $i, int $j) use ($x, $y, $z, $axis): int {
            return $this->blockIdAt(
                $axis === 'x' ? $x + $i : $x,
                $y + $j,
                $axis === 'z' ? $z + $i : $z
            );
        };

        // ① 左右（沿水平轴）找黑曜石边界
        $left = null;
        for ($i = -1; $i >= -21; $i--) {
            $id = $at($i, 0);
            if ($id === self::OBSIDIAN) {
                $left = $i;
                break;
            }
            if ($id !== 0 && $id !== self::PORTAL_BLOCK) {
                $this->log("  [门] 轴={$axis} 左侧第 {$i} 格是 {$id}，不是空气也不是黑曜石");
                return null;
            }
        }
        $right = null;
        for ($i = 1; $i <= 21; $i++) {
            $id = $at($i, 0);
            if ($id === self::OBSIDIAN) {
                $right = $i;
                break;
            }
            if ($id !== 0 && $id !== self::PORTAL_BLOCK) {
                return null;
            }
        }
        if ($left === null || $right === null) {
            $this->log("  [门] 轴={$axis} 找不到左右边框（left=" . ($left ?? 'null') . " right=" . ($right ?? 'null') . '）');
            return null;
        }
        $w = $right - $left - 1;
        if ($w < 2) {
            $this->log("  [门] 轴={$axis} 内部只有 {$w} 格宽（最小 2）");
            return null;
        }

        // ② 上下找黑曜石边界
        $down = null;
        for ($j = -1; $j >= -21; $j--) {
            $id = $at(0, $j);
            if ($id === self::OBSIDIAN) {
                $down = $j;
                break;
            }
            if ($id !== 0 && $id !== self::PORTAL_BLOCK) {
                return null;
            }
        }
        $up = null;
        for ($j = 1; $j <= 21; $j++) {
            $id = $at(0, $j);
            if ($id === self::OBSIDIAN) {
                $up = $j;
                break;
            }
            if ($id !== 0 && $id !== self::PORTAL_BLOCK) {
                return null;
            }
        }
        if ($down === null || $up === null) {
            $this->log("  [门] 轴={$axis} 找不到上下边框（down=" . ($down ?? 'null') . " up=" . ($up ?? 'null') . '）');
            return null;
        }
        $h = $up - $down - 1;
        if ($h < 3) {
            $this->log("  [门] 轴={$axis} 内部只有 {$h} 格高（最小 3）");
            return null;
        }

        // ③ 验证四条边全是黑曜石
        for ($i = $left + 1; $i < $right; $i++) {
            if ($at($i, $down) !== self::OBSIDIAN || $at($i, $up) !== self::OBSIDIAN) {
                $this->log("  [门] 轴={$axis} 上下边第 {$i} 格不是黑曜石（下=" . $at($i, $down) . ' 上=' . $at($i, $up) . '）');
                return null;
            }
        }
        for ($j = $down + 1; $j < $up; $j++) {
            if ($at($left, $j) !== self::OBSIDIAN || $at($right, $j) !== self::OBSIDIAN) {
                $this->log("  [门] 轴={$axis} 左右边第 {$j} 格不是黑曜石（左=" . $at($left, $j) . ' 右=' . $at($right, $j) . '）');
                return null;
            }
        }

        // 原点 = 内部左下角（相对坐标）
        return [
            $axis === 'x' ? $x + $left + 1 : $x,
            $y + $down + 1,
            $axis === 'z' ? $z + $left + 1 : $z,
            $w,
            $h,
        ];
    }

    /** 每 0.5 秒：站在门里 2 秒就传送 */
    private function tickPortals(float $now): void
    {
        if ($now - $this->lastPortalTick < 0.5) {
            return;
        }
        $this->lastPortalTick = $now;

        foreach ($this->players() as $p) {
            $in = $this->blockIdAt((int) floor($p->x), (int) floor($p->y), (int) floor($p->z)) === self::PORTAL_BLOCK
                || $this->blockIdAt((int) floor($p->x), (int) floor($p->y) + 1, (int) floor($p->z)) === self::PORTAL_BLOCK;

            if (!$in) {
                $p->portalSince = 0.0;

                continue;
            }
            if ($p->portalSince <= 0.0) {
                $p->portalSince = $now;

                continue;
            }
            if ($now - $p->portalSince < 2.0) {
                continue;
            }

            $p->portalSince = 0.0;
            $this->teleportThroughPortal($p);
        }
    }

    private function teleportThroughPortal(Connection $c): void
    {
        $atNether = abs($c->x - self::NETHER_X) < 64 && abs($c->z - self::NETHER_Z) < 64;

        if ($atNether) {
            // 回主世界：回到进门前的位置
            [$tx, $ty, $tz] = $c->portalReturn ?? [-63.0, 64.0, -42.0];
        } else {
            $this->ensureNetherPlatform();
            $c->portalReturn = [$c->x, $c->y, $c->z];
            $tx = self::NETHER_X + 0.5;
            $ty = (float) (self::NETHER_Y + 1);
            $tz = self::NETHER_Z + 0.5;
        }

        $c->x = (float) $tx;
        $c->y = (float) $ty;
        $c->z = (float) $tz;
        $c->send(self::C_POS_LOOK, (new Writer())
            ->double($c->x)->double($c->y)->double($c->z)
            ->float($c->yaw)->float($c->pitch)->byte(0));
        // 位置变了，区块和数据都得重发
        $c->resendInvAt = microtime(true) + 0.5;
        $this->queueChunks($c);
        $this->log(sprintf('  %s 传送 -> %.1f,%.1f,%.1f', $c->username, $c->x, $c->y, $c->z));
        $this->sendChat($c, ['text' => $atNether ? '回到主世界。' : '你来到了地狱。', 'color' => 'light_purple'], 1);
    }

    /** 现搭一个 24×24 的地狱岩平台（只搭一次） */
    private function ensureNetherPlatform(): void
    {
        if ($this->netherBuilt) {
            return;
        }
        $this->netherBuilt = true;

        $ox = self::NETHER_X;
        $oy = self::NETHER_Y;
        $oz = self::NETHER_Z;

        for ($dx = -12; $dx <= 12; $dx++) {
            for ($dz = -12; $dz <= 12; $dz++) {
                $chunk = $this->world->chunk(($ox + $dx) >> 4, ($oz + $dz) >> 4);
                $chunk->setBlock(($ox + $dx) & 15, $oy - 1, ($oz + $dz) & 15, self::NETHERRACK, 0);
                for ($dy = 0; $dy < 4; $dy++) {
                    $chunk->setBlock(($ox + $dx) & 15, $oy + $dy, ($oz + $dz) & 15, 0, 0);
                }
                // 边缘围一圈地狱岩，看着像个洞窟
                if (abs($dx) === 12 || abs($dz) === 12) {
                    for ($dy = 0; $dy < 3; $dy++) {
                        $chunk->setBlock(($ox + $dx) & 15, $oy + $dy, ($oz + $dz) & 15, self::NETHERRACK, 0);
                    }
                }
            }
        }

        // 顶上挂几块萤石
        foreach ([[-6, -6], [6, 6], [-6, 6], [6, -6]] as [$gx, $gz]) {
            $chunk = $this->world->chunk(($ox + $gx) >> 4, ($oz + $gz) >> 4);
            $chunk->setBlock(($ox + $gx) & 15, $oy + 3, ($oz + $gz) & 15, self::GLOWSTONE, 0);
        }

        // 回程门（4×5 黑曜石框 + 传送门方块）
        $px = $ox - 1;
        $pz = $oz - 3;
        for ($i = 0; $i < 4; $i++) {
            for ($j = 0; $j < 5; $j++) {
                $edge = $i === 0 || $i === 3 || $j === 0 || $j === 4;
                $chunk = $this->world->chunk(($px + $i) >> 4, $pz >> 4);
                if ($edge) {
                    $chunk->setBlock(($px + $i) & 15, $oy + $j, $pz & 15, self::OBSIDIAN, 0);
                } elseif ($i >= 1 && $i <= 2 && $j >= 1 && $j <= 3) {
                    $chunk->setBlock(($px + $i) & 15, $oy + $j, $pz & 15, self::PORTAL_BLOCK, 2);
                }
            }
        }

        $this->log('  地狱平台已搭建 @ ' . $ox . ',' . $oy . ',' . $oz);
    }
}
