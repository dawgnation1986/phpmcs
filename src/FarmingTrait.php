<?php
declare(strict_types=1);

namespace PhpMc;

/**
 * 种植：作物随机生长 + 骨粉催熟。
 *
 * 1.8 的作物方块 id 和成熟度（meta）：
 *   59  小麦     meta 0~7
 *   141 胡萝卜   meta 0~7
 *   142 马铃薯   meta 0~7
 *   115 地狱疣   meta 0~3
 *
 * 生长不需要模拟原版的随机刻，这里每 2 秒给每株一个概率推进一级，
 * 观感上和原版接近（原版平均也要几十秒到几分钟）。
 */
trait FarmingTrait
{
    /** @var array<string,array{id:int,age:int}> "x,y,z" => 作物 */
    private array $crops = [];
    private float $lastCropTick = 0.0;

    /** 这些方块是作物，值 = 最高成熟度 */
    private const CROP_MAX_AGE = [
        59 => 7,    // 小麦
        141 => 7,   // 胡萝卜
        142 => 7,   // 马铃薯
        115 => 3,   // 地狱疣
    ];

    private function registerCrop(int $x, int $y, int $z, int $id, int $age): void
    {
        if (!isset(self::CROP_MAX_AGE[$id])) {
            return;
        }
        $this->crops[$x . ',' . $y . ',' . $z] = ['id' => $id, 'age' => $age];
    }

    private function unregisterCrop(int $x, int $y, int $z): void
    {
        unset($this->crops[$x . ',' . $y . ',' . $z]);
    }

    /**
     * 骨粉催熟。返回 true 表示用掉了（调用方负责扣物品）。
     */
    private function boneMeal(int $x, int $y, int $z): bool
    {
        $k = $x . ',' . $y . ',' . $z;
        if (!isset($this->crops[$k])) {
            return false;
        }
        $crop = &$this->crops[$k];
        $max = self::CROP_MAX_AGE[$crop['id']] ?? 7;
        if ($crop['age'] >= $max) {
            return false;   // 已经熟了
        }
        $crop['age'] = min($max, $crop['age'] + random_int(2, 4));
        $this->applyCrop($x, $y, $z, $crop['id'], $crop['age']);

        return true;
    }

    /** 把成熟度写进方块并广播 */
    private function applyCrop(int $x, int $y, int $z, int $id, int $age): void
    {
        $chunk = $this->world->chunk($x >> 4, $z >> 4);
        $cur = $chunk->blockState($x & 15, $y, $z & 15);
        if (($cur >> 4) !== $id) {
            return;   // 已经被挖掉了
        }
        if (($cur & 15) === $age) {
            return;
        }
        if ($chunk->setBlock($x & 15, $y, $z & 15, $id, $age)) {
            $chunk->dirty = true;
            foreach ($this->players() as $p) {
                $p->send(self::C_BLOCK_CHANGE, (new Writer())
                    ->raw($this->packPositionBytes($x, $y, $z))
                    ->varInt(($id << 4) | $age));
            }
        }
    }

    /** 每 2 秒推进一次 */
    private function tickCrops(float $now): void
    {
        if ($now - $this->lastCropTick < 2.0) {
            return;
        }
        $this->lastCropTick = $now;
        if ($this->crops === []) {
            return;
        }

        // 没有玩家在线就不长（省 CPU）
        $players = $this->players();
        if ($players === []) {
            return;
        }

        foreach ($this->crops as $k => &$crop) {
            $parts = explode(',', (string) $k);
            if (count($parts) !== 3) {
                unset($this->crops[$k]);
                continue;
            }
            [$x, $y, $z] = [(int) $parts[0], (int) $parts[1], (int) $parts[2]];
            $max = self::CROP_MAX_AGE[$crop['id']] ?? 7;
            if ($crop['age'] >= $max) {
                unset($this->crops[$k]);   // 熟了就不再占内存
                continue;
            }
            // 附近有玩家才长
            $near = false;
            foreach ($players as $p) {
                if (abs($p->x - $x) <= 64 && abs($p->z - $z) <= 64) {
                    $near = true;
                    break;
                }
            }
            if (!$near) {
                continue;
            }
            if (random_int(1, 6) !== 1) {
                continue;
            }
            $crop['age']++;
            $this->applyCrop($x, $y, $z, $crop['id'], $crop['age']);
        }
        unset($crop);
    }
}
