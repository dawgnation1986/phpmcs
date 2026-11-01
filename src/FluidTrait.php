<?php
declare(strict_types=1);

namespace PhpMc;

/**
 * 流体：逐格重算版（对齐 1.8.9 的 BlockDynamicLiquid 思路）。
 *
 * 核心变化：
 *   1. 不再用「半径 8 扫描 + 删除孤立水」这种区域清理 —— 那会把整条河删光
 *   2. 每个格子只重算自己：看邻居决定自己应该是几级水，变了就改、再通知邻居
 *   3. 水源（静止水 9 / 静止岩浆 11，meta=0）不参与降级
 *   4. 无限水：水平相邻 ≥2 个水源，且下方是固体（或同种 meta=0 水）
 *
 * ID 语义（已用 tools/audit_fluids.php 审计存档确认）：
 *   8  = flowing_water   9  = water
 *   10 = flowing_lava    11 = lava
 */
trait FluidTrait
{
    private const FLUID_REACH = [0 => 7, 1 => 3];      // 水 7 格 / 岩浆 3 格
    private const FLUID_TICK = 0.25;                    // 5 tick

    /** @var array 待更新的流体格子 [x, y, z, kind] */
    private array $fluidQueue = [];
    /** @var array 去重 "x,y,z" => true */
    private array $fluidQueued = [];
    private float $lastFluidTick = 0.0;

    private function fluidSourceId(int $kind): int
    {
        return $kind === 0 ? 9 : 11;
    }

    private function fluidFlowingId(int $kind): int
    {
        return $kind === 0 ? 8 : 10;
    }

    private function fluidKindOf(int $id): int
    {
        return ($id === 10 || $id === 11) ? 1 : 0;
    }

    private function isFluidId(int $id): bool
    {
        return $id === 8 || $id === 9 || $id === 10 || $id === 11;
    }

    /** 流水能不能挤掉这个方块 */
    private function fluidCanReplace(int $id): bool
    {
        if ($id === 0) {
            return true;
        }
        if ($this->isFluidId($id)) {
            return true;
        }

        // 原版可被水冲掉的非固体方块
        return in_array($id, [31, 32, 37, 38, 39, 40, 50, 51, 78, 106, 111, 132, 175], true);
    }

    private function blockStateAt(int $x, int $y, int $z): int
    {
        if ($y < 0 || $y > 255) {
            return -1;
        }

        return $this->world->chunk($x >> 4, $z >> 4)->blockState($x & 15, $y, $z & 15);
    }

    /** 放进队列（同一个格子只排一次） */
    private function startFluid(int $x, int $y, int $z, int $kind, int $level = 0, int $depth = 0): void
    {
        $key = $x . ',' . $y . ',' . $z;
        if (isset($this->fluidQueued[$key])) {
            return;
        }
        $this->fluidQueued[$key] = true;
        $this->fluidQueue[] = [$x, $y, $z, $kind];
    }

    /** 水桶放水：原版放的是流动水，稳定后才变静止水 */
    private function placeFluid(int $x, int $y, int $z, int $kind): void
    {
        $this->changeBlock($x, $y, $z, $this->fluidFlowingId($kind), 0);
        $this->startFluid($x, $y, $z, $kind);
        $this->notifyFluidNeighbors($x, $y, $z, $kind);
    }

    private function notifyFluidNeighbors(int $x, int $y, int $z, int $kind): void
    {
        $this->startFluid($x, $y + 1, $z, $kind);
        $this->startFluid($x, $y - 1, $z, $kind);
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dz]) {
            $this->startFluid($x + $dx, $y, $z + $dz, $kind);
        }
    }

    private function tickFluids(float $now): void
    {
        if ($this->fluidQueue === [] || $now - $this->lastFluidTick < self::FLUID_TICK) {
            return;
        }
        $this->lastFluidTick = $now;

        $budget = 200;
        while ($budget-- > 0 && $this->fluidQueue !== []) {
            [$x, $y, $z, $kind] = array_shift($this->fluidQueue);
            unset($this->fluidQueued[$x . ',' . $y . ',' . $z]);
            $this->updateFluidCell($x, $y, $z, $kind);
        }
    }

    /** 重算一个格子。返回是否发生了变化。 */
    private function updateFluidCell(int $x, int $y, int $z, int $kind): bool
    {
        if ($y < 1 || $y > 255) {
            return false;
        }
        $state = $this->blockStateAt($x, $y, $z);
        if ($state < 0) {
            return false;
        }
        $id = $state >> 4;
        $meta = $state & 15;

        $source = $this->fluidSourceId($kind);
        $flowing = $this->fluidFlowingId($kind);

        // 水源：永远不动，只负责往外扩散
        if ($id === $source && $meta === 0) {
            $this->spreadFrom($x, $y, $z, $kind, 0);

            return false;
        }

        // 不是流动水 → 不用管（别的方块自己会触发邻居更新）
        if ($id !== $flowing) {
            return false;
        }

        $want = $this->computeLevel($x, $y, $z, $kind, $meta);

        if ($want === null) {
            // 没有来源支撑 → 干掉这一格，并让邻居重算（逐格退潮）
            $this->changeBlock($x, $y, $z, 0, 0);
            $this->notifyFluidNeighbors($x, $y, $z, $kind);

            return true;
        }

        if ($want !== $meta) {
            $this->changeBlock($x, $y, $z, $flowing, $want);
        }

        $this->spreadFrom($x, $y, $z, $kind, $want);

        return $want !== $meta;
    }

    /**
     * 计算某格流动水应该是几级（meta）。返回 null 表示「没有来源」→ 该删。
     */
    private function computeLevel(int $x, int $y, int $z, int $kind, int $current): ?int
    {
        $source = $this->fluidSourceId($kind);
        $flowing = $this->fluidFlowingId($kind);
        $reach = self::FLUID_REACH[$kind];

        // ① 正上方是同种流体 → 这一格是「落下来的水」
        $above = $this->blockStateAt($x, $y + 1, $z);
        if ($above >= 0 && $this->isFluidId($above >> 4) && $this->fluidKindOf($above >> 4) === $kind) {
            $am = $above & 15;

            return min(15, $am >= 8 ? $am : $am + 8);
        }

        // ② 水平方向找最近来源
        $best = null;
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dz]) {
            $nb = $this->blockStateAt($x + $dx, $y, $z + $dz);
            if ($nb < 0) {
                continue;
            }
            $nid = $nb >> 4;
            $nm = $nb & 15;
            if ($nid === $source || ($nid === $flowing && $nm === 0)) {
                $best = 0;

                continue;
            }
            if ($nid === $flowing && $nm < $reach) {
                $best = $best === null ? $nm : min($best, $nm);
            }
        }

        if ($best === null) {
            return null;
        }
        $want = $best + 1;

        return $want > $reach ? null : $want;
    }

    /** 从一个格子往外扩散 */
    private function spreadFrom(int $x, int $y, int $z, int $kind, int $meta): void
    {
        $flowing = $this->fluidFlowingId($kind);
        $source = $this->fluidSourceId($kind);
        $reach = self::FLUID_REACH[$kind];

        // ① 往下：下面能替换就落下去（meta 8 = 从上方落下）
        if ($y > 1) {
            $below = $this->blockStateAt($x, $y - 1, $z);
            if ($below >= 0) {
                $bid = $below >> 4;
                $sameFluid = $this->isFluidId($bid) && $this->fluidKindOf($bid) === $kind;
                if (!$sameFluid && $this->fluidCanReplace($bid)) {
                    $this->changeBlock($x, $y - 1, $z, $flowing, 8);
                    $this->startFluid($x, $y - 1, $z, $kind);

                    return;          // 原版：能往下就不再横向扩
                }
            }
        }

        // ② 横向
        if ($meta >= $reach) {
            return;
        }
        $next = ($meta >= 8) ? 1 : $meta + 1;      // 落下来的水横向从头算

        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dz]) {
            $nx = $x + $dx;
            $nz = $z + $dz;
            $nb = $this->blockStateAt($nx, $y, $nz);
            if ($nb < 0) {
                continue;
            }
            $nid = $nb >> 4;
            $nm = $nb & 15;

            if ($this->isFluidId($nid) && $this->fluidKindOf($nid) === $kind) {
                // 已有的水：让它自己重算（可能该更浅 / 该退）
                if ($nid === $flowing && $nm > $next) {
                    $this->startFluid($nx, $y, $nz, $kind);
                }

                continue;
            }
            if (!$this->fluidCanReplace($nid)) {
                continue;
            }

            $this->changeBlock($nx, $y, $nz, $flowing, $next);
            $this->startFluid($nx, $y, $nz, $kind);
        }

        // ③ 无限水：相邻 ≥2 个水源，且下方是固体或同种水源
        if ($kind === 0) {
            $srcCount = 0;
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dz]) {
                $nb = $this->blockStateAt($x + $dx, $y, $z + $dz);
                if ($nb >= 0 && ($nb >> 4) === $source && ($nb & 15) === 0) {
                    $srcCount++;
                }
            }
            if ($srcCount >= 2) {
                $b = $this->blockStateAt($x, $y - 1, $z);
                $bid = $b >= 0 ? $b >> 4 : 0;
                $solidBelow = $bid !== 0 && !$this->isFluidId($bid);
                $waterBelow = $bid === $source && ($b & 15) === 0;
                if ($solidBelow || $waterBelow) {
                    $this->changeBlock($x, $y, $z, $source, 0);
                }
            }
        }
    }
}
