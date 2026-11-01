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

    /** 打火石点黑曜石：试着点燃一个门 */
    private function tryIgnitePortal(Connection $c, int $x, int $y, int $z): bool
    {
        // 点的那一格本身是黑曜石，框有可能在它四周
        foreach (['x', 'z'] as $axis) {
            for ($w = 2; $w <= 2; $w++) {          // 原版内部 2 格宽
                for ($h = 3; $h <= 3; $h++) {      // 3 格高
                    for ($ox = -2; $ox <= 2; $ox++) {
                        for ($oy = -3; $oy <= 1; $oy++) {
                            if ($this->frameAt($x, $y, $z, $axis, $w, $h, $ox, $oy)) {
                                $this->fillPortal($c, $x, $y, $z, $axis, $w, $h, $ox, $oy);

                                return true;
                            }
                        }
                    }
                }
            }
        }

        return false;
    }

    /** 以 (x,y,z) 为参考，检查某个偏移下是不是合法黑曜石框 */
    private function frameAt(int $x, int $y, int $z, string $axis, int $w, int $h, int $ox, int $oy): bool
    {
        $x0 = $x + ($axis === 'x' ? $ox : 0);
        $y0 = $y + $oy;
        $z0 = $z + ($axis === 'z' ? $ox : 0);

        // 内部必须是空气（或已经是传送门）
        for ($i = 0; $i < $w; $i++) {
            for ($j = 0; $j < $h; $j++) {
                $id = $this->blockIdAt(
                    $axis === 'x' ? $x0 + $i : $x0,
                    $y0 + $j,
                    $axis === 'z' ? $z0 + $i : $z0
                );
                if ($id !== 0 && $id !== self::PORTAL_BLOCK) {
                    return false;
                }
            }
        }

        // 四条边必须是黑曜石
        for ($i = -1; $i <= $w; $i++) {
            foreach ([-1, $h] as $j) {
                $id = $this->blockIdAt(
                    $axis === 'x' ? $x0 + $i : $x0,
                    $y0 + $j,
                    $axis === 'z' ? $z0 + $i : $z0
                );
                if ($id !== self::OBSIDIAN) {
                    return false;
                }
            }
        }
        for ($j = 0; $j < $h; $j++) {
            foreach ([-1, $w] as $i) {
                $id = $this->blockIdAt(
                    $axis === 'x' ? $x0 + $i : $x0,
                    $y0 + $j,
                    $axis === 'z' ? $z0 + $i : $z0
                );
                if ($id !== self::OBSIDIAN) {
                    return false;
                }
            }
        }

        return true;
    }

    private function fillPortal(Connection $c, int $x, int $y, int $z, string $axis, int $w, int $h, int $ox, int $oy): void
    {
        $x0 = $x + ($axis === 'x' ? $ox : 0);
        $y0 = $y + $oy;
        $z0 = $z + ($axis === 'z' ? $ox : 0);
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
        $this->log("  {$c->username} 点燃地狱门 @ {$x0},{$y0},{$z0} 轴={$axis}");
        $this->sendChat($c, ['text' => '地狱门被点燃了。', 'color' => 'light_purple'], 1);
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
