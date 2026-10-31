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
        // 除了四种作物，还有甘蔗(83)、南瓜茎(104)/西瓜茎(105)、可可豆(127)
        if (!isset(self::CROP_MAX_AGE[$id]) && !in_array($id, [83, 104, 105, 127], true)) {
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

    /** 甘蔗往上长一格（最高 3 格） */
    private function growSugarCane(int $x, int $y, int $z): void
    {
        $chunk = $this->world->chunk($x >> 4, $z >> 4);
        // 数一下这摞甘蔗多高
        $h = 1;
        for ($yy = $y - 1; $yy >= $y - 3; $yy--) {
            if (($chunk->blockState($x & 15, $yy, $z & 15) >> 4) === 83) {
                $h++;
            } else {
                break;
            }
        }
        if ($h >= 3) {
            return;
        }
        if (($chunk->blockState($x & 15, $y + 1, $z & 15)) !== 0) {
            return;
        }
        if (random_int(1, 8) !== 1) {
            return;
        }
        if ($chunk->setBlock($x & 15, $y + 1, $z & 15, 83, 0)) {
            $chunk->dirty = true;
            $this->registerCrop($x, $y + 1, $z, 83, 0);
            foreach ($this->players() as $p) {
                $p->send(self::C_BLOCK_CHANGE, (new Writer())
                    ->raw($this->packPositionBytes($x, $y + 1, $z))
                    ->varInt(83 << 4));
            }
        }
    }

    /** 茎结瓜：在四周找一格能长的地方 */
    private function placeFruit(int $x, int $y, int $z, int $fruitId): void
    {
        $dirs = [[1, 0], [-1, 0], [0, 1], [0, -1]];
        shuffle($dirs);
        foreach ($dirs as [$dx, $dz]) {
            $fx = $x + $dx;
            $fz = $z + $dz;
            $chunk = $this->world->chunk($fx >> 4, $fz >> 4);
            if (($chunk->blockState($fx & 15, $y, $fz & 15)) !== 0) {
                continue;
            }
            $below = $chunk->blockState($fx & 15, $y - 1, $fz & 15) >> 4;
            if (!in_array($below, [2, 3, 60], true)) {
                continue;
            }
            if ($chunk->setBlock($fx & 15, $y, $fz & 15, $fruitId, 0)) {
                $chunk->dirty = true;
                foreach ($this->players() as $p) {
                    $p->send(self::C_BLOCK_CHANGE, (new Writer())
                        ->raw($this->packPositionBytes($fx, $y, $fz))
                        ->varInt($fruitId << 4));
                }
            }

            return;
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
            // 甘蔗：往上长，最高 3 格，不按 age
            if ($crop['id'] === 83) {
                $this->growSugarCane($x, $y, $z);
                continue;
            }
            // 可可豆：meta 低两位是成熟度（0~2），高两位是朝向
            if ($crop['id'] === 127) {
                $ageC = $crop['age'] & 3;
                if ($ageC >= 2) {
                    unset($this->crops[$k]);
                    continue;
                }
                if (random_int(1, 6) === 1) {
                    $crop['age'] = ($crop['age'] & 0x0C) | ($ageC + 1);
                    $this->applyCrop($x, $y, $z, 127, $crop['age']);
                }
                continue;
            }
            // 南瓜/西瓜茎：长到 7 后尝试在相邻格结果
            if ($crop['id'] === 104 || $crop['id'] === 105) {
                if ($crop['age'] < 7) {
                    if (random_int(1, 6) === 1) {
                        $crop['age']++;
                        $this->applyCrop($x, $y, $z, $crop['id'], $crop['age']);
                    }
                } elseif (random_int(1, 10) === 1) {
                    $this->placeFruit($x, $y, $z, $crop['id'] === 104 ? 86 : 103);
                }
                continue;
            }

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
