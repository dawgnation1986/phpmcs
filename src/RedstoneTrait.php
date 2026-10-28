<?php
declare(strict_types=1);

namespace PhpMc;

/**
 * 红石：邻居更新 + 红石粉(55) 的通电/断电。
 *
 * 现在支持的电源：
 *   152 红石块      —— 恒定 15
 *    76 红石火把(亮) —— 恒定 15
 *    69 拉杆        —— meta & 0x8 表示打开
 *    77 按钮        —— meta & 0x8 表示按下
 *
 * 规则（原版的大方向）：
 *   - 红石粉旁边有电源 → 15
 *   - 否则取相邻红石粉的最大值 - 1，最低 0
 *   - 迭代到稳定（最多 16 轮，红石最多传 15 格）
 *
 * 还没做的：火把的反相、中继器的延迟和方向、活塞、充能方块的强弱充能。
 */
trait RedstoneTrait
{
    /** 防止 changeBlock 和红石更新互相递归 */
    private bool $redstoneBusy = false;

    private function isRedstoneWire(int $id): bool
    {
        return $id === 55;
    }

    /** 这个方块作为「电源」能给导线多少电 */
    private function redstoneSourcePower(int $x, int $y, int $z): int
    {
        $state = $this->world->chunk($x >> 4, $z >> 4)->blockState($x & 15, $y, $z & 15);
        $id = $state >> 4;
        $meta = $state & 0x0F;

        if ($id === 152) {          // 红石块
            return 15;
        }
        if ($id === 76) {           // 红石火把（亮）
            return 15;
        }
        if ($id === 69 || $id === 77) {   // 拉杆 / 按钮
            return ($meta & 0x8) !== 0 ? 15 : 0;
        }

        return 0;
    }

    /** 某个红石粉格应该在的强度 */
    private function wirePowerAt(int $x, int $y, int $z): int
    {
        // 自己贴着电源就是 15
        foreach ([[1, 0, 0], [-1, 0, 0], [0, 1, 0], [0, -1, 0], [0, 0, 1], [0, 0, -1]] as [$dx, $dy, $dz]) {
            if ($this->redstoneSourcePower($x + $dx, $y + $dy, $z + $dz) > 0) {
                return 15;
            }
        }

        // 否则取相邻导线最大值 - 1
        $best = 0;
        foreach ([[1, 0, 0], [-1, 0, 0], [0, 1, 0], [0, -1, 0], [0, 0, 1], [0, 0, -1]] as [$dx, $dy, $dz]) {
            $nx = $x + $dx;
            $ny = $y + $dy;
            $nz = $z + $dz;
            if ($ny < 0 || $ny > 255) {
                continue;
            }
            $state = $this->world->chunk($nx >> 4, $nz >> 4)->blockState($nx & 15, $ny, $nz & 15);
            if (!$this->isRedstoneWire($state >> 4)) {
                continue;
            }
            $best = max($best, ($state & 0x0F) - 1);
        }

        return max(0, $best);
    }

    /**
     * 某个方块变了之后调用：把它周围连成一片的导线全部重算一遍。
     * 不是逐格触发，而是先 BFS 收集连通集合、再迭代到稳定 —— 这样
     * 断线、拐弯、多条路径汇合都能算对。
     */
    private function updateRedstoneAround(int $x, int $y, int $z): void
    {
        if ($this->redstoneBusy) {
            return;
        }
        $this->redstoneBusy = true;

        // ① 收集连通的红石粉
        $wires = [];
        $queue = [[$x, $y, $z]];
        $visited = [$x . ',' . $y . ',' . $z => true];

        while ($queue !== []) {
            [$cx, $cy, $cz] = array_shift($queue);
            foreach ([[1, 0, 0], [-1, 0, 0], [0, 1, 0], [0, -1, 0], [0, 0, 1], [0, 0, -1]] as [$dx, $dy, $dz]) {
                $nx = $cx + $dx;
                $ny = $cy + $dy;
                $nz = $cz + $dz;
                if ($ny < 0 || $ny > 255) {
                    continue;
                }
                $key = $nx . ',' . $ny . ',' . $nz;
                if (isset($visited[$key])) {
                    continue;
                }
                $state = $this->world->chunk($nx >> 4, $nz >> 4)->blockState($nx & 15, $ny, $nz & 15);
                if (!$this->isRedstoneWire($state >> 4)) {
                    continue;
                }
                $visited[$key] = true;
                $wires[$key] = [$nx, $ny, $nz];
                $queue[] = [$nx, $ny, $nz];
            }
        }

        if ($wires === []) {
            $this->redstoneBusy = false;

            return;
        }

        // ② 迭代到稳定
        for ($round = 0; $round < 16; $round++) {
            $changed = false;
            foreach ($wires as [$wx, $wy, $wz]) {
                $cur = $this->world->chunk($wx >> 4, $wz >> 4)->blockState($wx & 15, $wy, $wz & 15) & 0x0F;
                $want = $this->wirePowerAt($wx, $wy, $wz);
                if ($want !== $cur) {
                    $chunk = $this->world->chunk($wx >> 4, $wz >> 4);
                    $chunk->setBlock($wx & 15, $wy, $wz & 15, 55, $want);
                    $chunk->dirty = true;
                    $pos = $this->packPositionBytes($wx, $wy, $wz);
                    $state = (55 << 4) | $want;
                    foreach ($this->players() as $p) {
                        $p->send(self::C_BLOCK_CHANGE, (new Writer())->raw($pos)->varInt($state));
                    }
                    $changed = true;
                }
            }
            if (!$changed) {
                break;
            }
        }

        $this->redstoneBusy = false;
    }
}
