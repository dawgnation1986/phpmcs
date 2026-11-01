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
    /** 本拍刚被删掉的格子：同一拍里不许被邻居重新填回来，否则会「越退越深」 */
    private array $fluidTomb = [];
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
        $this->fluidTomb = [];

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

        // 静止水但 meta > 0：原版收到邻居更新后会「唤醒」成动态水，保留原等级。
        // 之前这里直接 return，导致稳定下来的流水永远不参与重算 ——
        // 填掉一格以后周围的水就卡住不动了。
        if ($id === $source && $meta > 0) {
            $this->changeBlock($x, $y, $z, $flowing, $meta);
            $id = $flowing;
        }

        if ($id !== $flowing) {
            return false;
        }

        // 动态 meta 0 = 源级：原版会把它转成静止水源（9, meta 0），然后往外扩
        if ($meta === 0) {
            $this->changeBlock($x, $y, $z, $source, 0);
            $this->spreadFrom($x, $y, $z, $kind, 0);

            return true;
        }

        $want = $this->computeLevel($x, $y, $z, $kind, $meta);

        if ($want === null) {
            // 没有来源支撑 → 干掉这一格，并让邻居重算（逐格退潮）
            $this->fluidTomb[$x . ',' . $y . ',' . $z] = true;
            $this->changeBlock($x, $y, $z, 0, 0);
            $this->notifyFluidNeighbors($x, $y, $z, $kind);

            return true;
        }

        if ($want !== $meta) {
            $this->changeBlock($x, $y, $z, $flowing, $want);
        }

        // 先看这一格能不能升级成水源（两格水夹一格就是这条）
        if ($this->tryCreateSource($x, $y, $z, $kind)) {
            return true;
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

        // 水桶放下来的流动水就是 meta 0（源级）。原版里 flowing_water 的 0 级
        // 等价于水源，不参与降级判断 —— 少了这条，放下去的水会立刻被当成
        // 「没有来源」删掉（就是你看到的「倒出水，方块马上消失」）。
        if ($current === 0) {
            return 0;
        }

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

            // 只有同种液体才参与计算
            if ($nid !== $source && $nid !== $flowing) {
                continue;
            }
            // 只有 meta 0 才是真水源（ID 8 和 9 的 meta 0 都算）
            if ($nm === 0) {
                $best = 0;

                continue;
            }
            // meta 8~15 是下落水，参与等级计算时按 0 处理
            $eff = $nm >= 8 ? 0 : $nm;

            // ★ 关键：只认「比当前更浅」的邻居。
            // 原版 BlockDynamicLiquid 计算水位时，只有 level < 当前 的邻居才算来源。
            // 之前把平级/更深的邻居也算进来，于是 meta 1 的水看到旁边 meta 2 的水，
            // 会算出「我该是 3」永远不会消失 —— 填掉水源后周围的水就退不掉。
            if ($eff < $current && $eff < $reach) {
                $best = $best === null ? $eff : min($best, $eff);
            }
        }

        if ($best === null) {
            return null;
        }
        $want = $best + 1;

        return $want > $reach ? null : $want;
    }

    /**
     * 某一格能不能升级成水源（原版：水平 ≥2 个 meta=0 的同种液体，
     * 且下方是固体或同种 meta=0 液体）。
     * 注意要判断「这一格」本身，而不是旁边的水源 —— 之前写在 spreadFrom 里
     * 判断的是源头自己，所以永远升不了级。
     */
    private function tryCreateSource(int $x, int $y, int $z, int $kind): bool
    {
        if ($kind !== 0) {
            return false;
        }
        $source = $this->fluidSourceId(0);
        $flowing = $this->fluidFlowingId(0);

        $src = 0;
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dz]) {
            $nb = $this->blockStateAt($x + $dx, $y, $z + $dz);
            if ($nb < 0) {
                continue;
            }
            $nid = $nb >> 4;
            if (($nid === $source || $nid === $flowing) && ($nb & 15) === 0) {
                $src++;
            }
        }
        if ($src < 2) {
            return false;
        }

        $b = $this->blockStateAt($x, $y - 1, $z);
        $bid = $b >= 0 ? $b >> 4 : 0;
        $solidBelow = $bid !== 0 && !$this->isFluidId($bid);
        $waterBelow = $b >= 0 && ($bid === $source || $bid === $flowing) && ($b & 15) === 0;
        if (!$solidBelow && !$waterBelow) {
            return false;
        }

        $this->changeBlock($x, $y, $z, $source, 0);

        return true;
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
                    // 原版：下落水的 meta = 上方等级 + 8（0->8, 1->9, 7->15），
                    // 不是永远写 8。
                    $fallMeta = $meta >= 8 ? $meta : $meta + 8;
                    $this->changeBlock($x, $y - 1, $z, $flowing, $fallMeta);
                    $this->startFluid($x, $y - 1, $z, $kind);

                    return;          // 原版：能往下就不再横向扩
                }
            }
        }

        // ② 横向
        // 注意：meta 8~15 是「下落水」，不能按 >= reach 直接 return，
        // 否则瀑布落到地面之后永远不会横向铺开（之前就是这个 bug）。
        if ($meta < 8 && $meta >= $reach) {
            return;
        }
        $next = ($meta >= 8) ? 1 : $meta + 1;

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
            // 这一格刚在本拍被判定「该退」—— 不许同一拍被填回来
            if (isset($this->fluidTomb[$nx . ',' . $y . ',' . $nz])) {
                continue;
            }

            $this->changeBlock($nx, $y, $nz, $flowing, $next);
            $this->startFluid($nx, $y, $nz, $kind);
        }

    }
}
