<?php
declare(strict_types=1);

namespace PhpMc;

/**
 * 刷怪笼（方块 52）。
 *
 * 数据存在区块 NBT 的 TileEntities 里（id = "MobSpawner"），
 * 运行时在内存里维护一份索引，tick 时按原版节奏刷怪。
 *
 * 原版规则（简化）：附近同类型怪 < 6 只、且玩家在 16 格内，
 * 每 200~800 tick 刷一只，刷在笼子周围 4 格范围内的地面上。
 */
trait SpawnerTrait
{
    /** "x,y,z" => ['mob' => 实体类型, 'delay' => 剩余 tick] */
    private array $spawners = [];
    private float $lastSpawnerTick = 0.0;

    /** 把一格登记成刷怪笼（区块扫到 / 玩家放置时调用） */
    public function registerSpawner(int $x, int $y, int $z, int $mob = 54): void
    {
        $k = $x . ',' . $y . ',' . $z;
        if (!isset($this->spawners[$k])) {
            $this->spawners[$k] = ['mob' => $mob, 'delay' => 200 + random_int(0, 600)];
        }
    }

    /** 供 AnvilWorld 写区块：刷怪笼的 TE */
    public function spawnerTilesForChunk(int $cx, int $cz): array
    {
        $out = [];
        foreach ($this->spawners as $k => $s) {
            $p = explode(',', (string) $k);
            if (count($p) !== 3) {
                continue;
            }
            [$x, $y, $z] = [(int) $p[0], (int) $p[1], (int) $p[2]];
            if (($x >> 4) !== $cx || ($z >> 4) !== $cz) {
                continue;
            }
            $out[] = \PhpMc\Nbt\Tag::compound([
                'id' => \PhpMc\Nbt\Tag::str('MobSpawner'),
                'x' => \PhpMc\Nbt\Tag::int($x),
                'y' => \PhpMc\Nbt\Tag::int($y),
                'z' => \PhpMc\Nbt\Tag::int($z),
                'EntityId' => \PhpMc\Nbt\Tag::str($this->spawnerMobName($s['mob'])),
                'Delay' => \PhpMc\Nbt\Tag::short((int) $s['delay']),
            ]);
        }

        return $out;
    }

    private function spawnerMobName(int $type): string
    {
        return match ($type) {
            50 => 'Creeper',
            51 => 'Skeleton',
            52 => 'Spider',
            54 => 'Zombie',
            default => 'Zombie',
        };
    }

    /** 每 2 秒推进一次；玩家在 16 格内才刷，附近同类型怪少于 6 只才刷 */
    private function tickSpawners(float $now): void
    {
        if ($now - $this->lastSpawnerTick < 2.0) {
            return;
        }
        $this->lastSpawnerTick = $now;
        if ($this->spawners === []) {
            return;
        }

        foreach ($this->spawners as $k => &$s) {
            $p = explode(',', (string) $k);
            [$x, $y, $z] = [(int) $p[0], (int) $p[1], (int) $p[2]];

            // 有玩家靠近才动
            $near = false;
            foreach ($this->players() as $pl) {
                if (abs($pl->x - $x) <= 16 && abs($pl->z - $z) <= 16 && abs($pl->y - $y) <= 16) {
                    $near = true;
                    break;
                }
            }
            if (!$near) {
                continue;
            }

            $s['delay'] -= 40;   // 2 秒 = 40 tick
            if ($s['delay'] > 0) {
                continue;
            }
            $s['delay'] = 200 + random_int(0, 600);

            // 附近同类型怪太多就不刷
            $count = 0;
            foreach ($this->mobs ?? [] as $m) {
                if (($m['type'] ?? 0) === $s['mob'] && abs($m['x'] - $x) <= 8 && abs($m['z'] - $z) <= 8) {
                    $count++;
                }
            }
            if ($count >= 6) {
                continue;
            }

            // 在笼子周围找一个能站的地面
            $chunk = $this->world->chunk($x >> 4, $z >> 4);
            for ($try = 0; $try < 8; $try++) {
                $ox = $x + random_int(-4, 4);
                $oz = $z + random_int(-4, 4);
                $oy = $y + random_int(-2, 1);
                $id = $chunk->blockState($ox & 15, $oy - 1, $oz & 15) >> 4;
                $here = $chunk->blockState($ox & 15, $oy, $oz & 15) >> 4;
                if ($id !== 0 && $here === 0) {
                    $this->spawnMob($s['mob'], $ox + 0.5, (float) $oy, $oz + 0.5);
                    break;
                }
            }
        }
        unset($s);
    }
}
