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
    private function startFluid(int $x, int $y, int $z, int $kind, int $level = 0): void
    {
        $this->fluidQueue[] = [$x, $y, $z, $kind, $level, 0];
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
            $this->changeBlock($nx, $y, $nz, $flowId, $next);
            $this->startFluid($nx, $y, $nz, $kind, $next, $depth + 1);
        }
    }
}
