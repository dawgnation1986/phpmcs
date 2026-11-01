<?php
declare(strict_types=1);

namespace PhpMc;

/**
 * 流体：水(8/9) 和岩浆(10/11) 的扩散。
 *
 * 规则照原版的大方向，但简化了两处：
 *  - 不处理「源头被拆掉之后回填」——只做往外流。
 *  - 不区分「流动方向衰减 1/2」的细节，只用层数。
 *
 * 方块 id：8 = 静止水，9 = 流动水，10 = 静止岩浆，11 = 流动岩浆。
 * meta：0 是源头，1~7 是流动等级（越大越远），8 表示「从上面落下来」。
 */
trait FluidTrait
{
    /** @var array<int, array{0:int,1:int,2:int,3:int,4:int,5:int}> [x,y,z,种类,等级,已走格数] */
    private array $fluidQueue = [];

    private float $lastFluidTick = 0.0;

    /** 水最远流 7 格、岩浆 3 格 */
    private const FLUID_REACH = [0 => 7, 1 => 3];

    /** 把一个流体方块丢进扩散队列。种类：0 水 / 1 岩浆 */
    /**
     * 入队一次流体更新。
     * 注意 $depth：之前签名里没有这个参数，调用方传的第 6 个参数被 PHP 直接丢弃，
     * 队列里永远写 0 —— 于是 FLUID_REACH（水 7 格 / 岩浆 3 格）从来没生效过。
     */
    private function startFluid(int $x, int $y, int $z, int $kind, int $level = 0, int $depth = 0): void
    {
        $this->fluidQueue[] = [$x, $y, $z, $kind, $level, $depth];
    }

    /** 放置流体方块时调用：先落地，再排队扩散 */
    private function placeFluid(int $x, int $y, int $z, int $kind): void
    {
        $sourceId = $kind === 0 ? 8 : 10;
        $this->changeBlock($x, $y, $z, $sourceId, 0);
        $this->startFluid($x, $y, $z, $kind, 0);
    }

    private function fluidSourceId(int $kind): int
    {
        return $kind === 0 ? 8 : 10;
    }

    private function fluidFlowingId(int $kind): int
    {
        return $kind === 0 ? 9 : 11;
    }

    /** 这个方块 id 是不是流体 */
    private function isFluidId(int $id): bool
    {
        return $id === 8 || $id === 9 || $id === 10 || $id === 11;
    }

    private function fluidKindOf(int $id): int
    {
        return ($id === 10 || $id === 11) ? 1 : 0;
    }

    /** 每 tick 处理一批，避免一次性刷爆网络 */
    private function tickFluids(float $now): void
    {
        if ($this->fluidQueue === [] || $now - $this->lastFluidTick < 0.25) {
            return;
        }
        $this->lastFluidTick = $now;

        $budget = 64;
        while ($budget-- > 0 && $this->fluidQueue !== []) {
            [$x, $y, $z, $kind, $level, $depth] = array_shift($this->fluidQueue);
            $this->flowFrom($x, $y, $z, $kind, $level, $depth);
        }
    }

    /**
     * 回流：水源被拿掉后，流出去的水要退回去。
     *
     * 判定很简单 —— 一格流动水只有在「紧挨着水源」或「紧挨着更近一级的流动水」
     * 时才站得住；否则就是孤立的，删掉。反复扫几轮，最远的水先退，形成逐格退潮。
     */
    private function recedeAround(int $x, int $y, int $z, int $kind): void
    {
        $source = $kind === 1 ? 10 : 8;
        $flowing = $kind === 1 ? 11 : 9;
        $R = 8;

        for ($pass = 0; $pass < 8; $pass++) {
            $changed = false;
            for ($dx = -$R; $dx <= $R; $dx++) {
                for ($dz = -$R; $dz <= $R; $dz++) {
                    for ($dy = -2; $dy <= 1; $dy++) {
                        $cx = $x + $dx;
                        $cy = $y + $dy;
                        $cz = $z + $dz;
                        if ($cy < 1 || $cy > 255) {
                            continue;
                        }
                        $chunk = $this->world->chunk($cx >> 4, $cz >> 4);
                        $state = $chunk->blockState($cx & 15, $cy, $cz & 15);
                        if (($state >> 4) !== $flowing) {
                            continue;
                        }
                        $myMeta = $state & 15;

                        // 紧挨着水源就站得住
                        $held = false;
                        foreach ([[1, 0, 0], [-1, 0, 0], [0, 1, 0], [0, 0, 1], [0, 0, -1]] as [$ax, $ay, $az]) {
                            $nb = $this->world->chunk(($cx + $ax) >> 4, ($cz + $az) >> 4)
                                ->blockState(($cx + $ax) & 15, $cy + $ay, ($cz + $az) & 15);
                            $nid = $nb >> 4;
                            $nmeta = $nb & 15;
                            if ($nid === $source) {
                                $held = true;
                                break;
                            }
                            if ($nid === $flowing && $nmeta < $myMeta) {
                                $held = true;
                                break;
                            }
                        }
                        if ($held) {
                            continue;
                        }

                        $this->changeBlock($cx, $cy, $cz, 0, 0);
                        $changed = true;
                    }
                }
            }
            if (!$changed) {
                return;
            }
        }
    }

    private function flowFrom(int $x, int $y, int $z, int $kind, int $level, int $depth): void
    {
        $max = self::FLUID_REACH[$kind];
        $flowId = $this->fluidFlowingId($kind);

        // ① 先看下面：能落就落，落下去等级重置为 0
        if ($y > 1) {
            $belowId = $this->world->chunk($x >> 4, $z >> 4)
                ->blockState($x & 15, $y - 1, $z & 15) >> 4;
            if ($belowId === 0) {
                $this->changeBlock($x, $y - 1, $z, $flowId, 8);   // meta 8 = 从上往下
                $this->startFluid($x, $y - 1, $z, $kind, 0, $depth + 1);

                return;
            }
            // 下面是同种流体就不重复处理，免得死循环
            if ($this->isFluidId($belowId) && $this->fluidKindOf($belowId) === $kind) {
                // 继续横向
            }
        }

        // ② 横向扩散
        if ($depth >= $max) {
            return;
        }
        $next = $level + 1;
        if ($next > 7) {
            return;
        }
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dz]) {
            $nx = $x + $dx;
            $nz = $z + $dz;
            $nid = $this->world->chunk($nx >> 4, $nz >> 4)
                ->blockState($nx & 15, $y, $nz & 15) >> 4;
            if ($nid !== 0) {
                continue;
            }
            // 已经是同等级的水流就别再写一遍，否则每拍都触发邻居更新/物理检查
            $same = $this->world->chunk($nx >> 4, $nz >> 4)
                ->blockState($nx & 15, $y, $nz & 15);
            if (($same >> 4) === $flowId && ($same & 15) === $next) {
                continue;
            }
            $this->changeBlock($nx, $y, $nz, $flowId, $next);
            $this->startFluid($nx, $y, $nz, $kind, $next, $depth + 1);

            // 原版规则：流动水如果水平方向紧挨着**两个水源**，自己就变成水源。
            // 少了这条，挖出来的水坑永远不会「填满成完整水面」。
            if ($kind === 0) {
                $src = 0;
                foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$ax, $az]) {
                    $nb = $this->world->chunk(($nx + $ax) >> 4, ($nz + $az) >> 4)
                        ->blockState(($nx + $ax) & 15, $y, ($nz + $az) & 15) >> 4;
                    if ($nb === 8) {
                        $src++;
                    }
                }
                if ($src >= 2) {
                    // 只升级成水源，**不要**再 startFluid —— 否则新水源又扩散一轮，
                    // 水流永远停不下来（就是你看到的「填上源头还在流」）。
                    $this->changeBlock($nx, $y, $nz, 8, 0);
                }
            }
        }
    }
}
