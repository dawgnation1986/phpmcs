<?php
declare(strict_types=1);

namespace PhpMc;

/**
 * 生物：按群系生成、游荡、离远了消失。
 *
 * 只做「能看见、会动、会消失」这一层——攻击/寻路/AI 还没做。
 * 生成逻辑照 1.8 的思路：白天草系群系出被动生物，夜里出敌对生物，
 * 位置在玩家周围 8~24 格，找地面高度站立。
 */
trait MobTrait
{
    /** 类型 => [名字, 是否敌对] */
    private const MOB_TYPES = [
        50 => ['creeper', true],
        51 => ['skeleton', true],
        52 => ['spider', true],
        54 => ['zombie', true],
        57 => ['pigman', true],
        58 => ['enderman', true],
        65 => ['bat', false],
        90 => ['pig', false],
        91 => ['sheep', false],
        92 => ['cow', false],
        93 => ['chicken', false],
        95 => ['wolf', false],
        96 => ['mooshroom', false],
        98 => ['ocelot', false],
        100 => ['horse', false],
        101 => ['rabbit', false],
    ];

    /** 群系 id => 白天能出的被动生物 */
    private const BIOME_PASSIVE = [
        1 => [90, 91, 92, 93, 100],      // 平原
        129 => [90, 91, 92, 93, 100],    // 向日葵平原
        4 => [95, 91, 92, 93],           // 森林
        27 => [95, 91, 92, 93],          // 桦木森林
        28 => [95, 91, 92, 93],          // 黑森林
        3 => [95, 91, 92],               // 山地
        5 => [95, 91, 101],              // 针叶林
        19 => [95, 91, 101],             // 冷针叶林
        29 => [95, 91, 101],
        30 => [95, 91, 101],             // 巨型针叶林
        21 => [98, 93],                  // 丛林
        23 => [98, 93],
        22 => [98, 93],
        32 => [100, 92],                 // 热带草原
        33 => [100, 92],
        14 => [96],                      // 蘑菇岛
        2 => [101],                      // 沙漠（兔子）
        17 => [101],
    ];

    private const MOB_HOSTILE = [54, 51, 50, 52];

    /** @var array<int, array{type:int,x:float,y:float,z:float,at:float,life:float}> */
    private array $mobs = [];

    private float $lastMobSpawn = 0.0;
    private float $lastMobTick = 0.0;

    /** 在某处生成一只生物，广播出去，返回实体 id（失败返回 0）。 */
    private function spawnMob(int $type, float $x, float $y, float $z): int
    {
        if (!isset(self::MOB_TYPES[$type])) {
            return 0;
        }

        $eid = $this->nextEntityId++;
        $this->mobs[$eid] = [
            'type' => $type,
            'x' => $x,
            'y' => $y,
            'z' => $z,
            'at' => microtime(true),
            'life' => 0.0,
        ];
        foreach ($this->players() as $p) {
            $this->sendMobSpawn($p, $eid, $type, $x, $y, $z);
        }

        return $eid;
    }

    private function sendMobSpawn(Connection $p, int $eid, int $type, float $x, float $y, float $z): void
    {
        $p->send(self::C_SPAWN_MOB, (new Writer())
            ->varInt($eid)
            ->byte($type)
            ->int((int) ($x * 32))
            ->int((int) ($y * 32))
            ->int((int) ($z * 32))
            ->byte(0)->byte(0)->byte(0)      // yaw / pitch / headPitch
            ->short(0)->short(0)->short(0)   // 速度
            ->unsignedByte(0x7F));           // DataWatcher 结束符
    }

    /** 销毁一个生物并通知所有玩家。 */
    private function removeMob(int $eid): void
    {
        if (!isset($this->mobs[$eid])) {
            return;
        }
        unset($this->mobs[$eid]);
        foreach ($this->players() as $p) {
            $p->send(self::C_DESTROY_ENTITIES, (new Writer())->varInt(1)->varInt($eid));
        }
    }

    /** 每 tick 调用：游荡 + 远离玩家就消失 + 定时自然生成。 */
    private function tickMobs(float $now): void
    {
        if ($now - $this->lastMobTick >= 2.0) {
            $this->lastMobTick = $now;
            foreach ($this->mobs as $eid => $m) {
                // 附近没玩家了 → 消失（原版是 128 格，这里用 64 更省）
                $near = $this->nearestPlayer($m['x'], $m['y'], $m['z'], 64.0);
                if ($near === null) {
                    $this->removeMob($eid);
                    continue;
                }

                // 随机走一小步
                $dx = random_int(-2, 2);
                $dz = random_int(-2, 2);
                if ($dx === 0 && $dz === 0) {
                    continue;
                }
                $nx = $m['x'] + $dx;
                $nz = $m['z'] + $dz;
                $ny = $this->groundY((int) floor($nx), (int) floor($nz), (int) $m['y']);

                $this->mobs[$eid]['x'] = $nx;
                $this->mobs[$eid]['y'] = $ny;
                $this->mobs[$eid]['z'] = $nz;
                $this->mobs[$eid]['life'] += 2.0;

                foreach ($this->players() as $p) {
                    $p->send(self::C_ENTITY_TELEPORT, (new Writer())
                        ->varInt($eid)
                        ->int((int) ($nx * 32))
                        ->int((int) ($ny * 32))
                        ->int((int) ($nz * 32))
                        ->byte(random_int(0, 255))
                        ->byte(0)
                        ->bool(true));
                }

                // 活够 5 分钟自然消失，避免越积越多
                if ($this->mobs[$eid]['life'] > 300) {
                    $this->removeMob($eid);
                }
            }
        }

        // 自然生成：每 5 秒给每个玩家附近放几只
        if ($now - $this->lastMobSpawn >= 5.0) {
            $this->lastMobSpawn = $now;
            foreach ($this->players() as $p) {
                if (count($this->mobs) >= 60) {
                    break;
                }
                $this->tryNaturalSpawn($p);
            }
        }
    }

    private function tryNaturalSpawn(Connection $p): void
    {
        $x = (int) floor($p->x) + random_int(-16, 16);
        $z = (int) floor($p->z) + random_int(-16, 16);
        $y = $this->groundY($x, $z, (int) floor($p->y) + 8);
        if ($y <= 0) {
            return;
        }

        // 别生成在玩家脸上
        if (abs($x - $p->x) < 4 && abs($z - $p->z) < 4) {
            return;
        }

        $biome = $this->biomeAt($x, $z);
        $night = $this->isNight();
        $types = $night
            ? self::MOB_HOSTILE
            : (self::BIOME_PASSIVE[$biome] ?? []);

        if ($types === []) {
            return;
        }
        $type = $types[random_int(0, count($types) - 1)];
        $this->spawnMob($type, $x + 0.5, (float) $y, $z + 0.5);
    }

    /** 从 startY 往下找第一个实心方块，返回它上面一层的 y。找不到返回 0。 */
    private function groundY(int $x, int $z, int $startY): int
    {
        $chunk = $this->world->chunk($x >> 4, $z >> 4);
        for ($y = min(255, $startY); $y > 1; $y--) {
            $id = $chunk->blockState($x & 15, $y, $z & 15) >> 4;
            if ($id !== 0 && !in_array($id, [8, 9, 31, 32, 37, 38, 175], true)) {
                return $y + 1;
            }
        }

        return 0;
    }

    private function biomeAt(int $x, int $z): int
    {
        $chunk = $this->world->chunk($x >> 4, $z >> 4);
        $biomes = $chunk->biomes();
        if ($biomes === null || strlen($biomes) !== 256) {
            return 1;
        }
        $i = ($z & 15) * 16 + ($x & 15);

        return ord($biomes[$i]);
    }

    /** 白天 = 世界时间 0~12000。拿不到时间就当白天。 */
    private function isNight(): bool
    {
        $time = $this->world->timeOfDay();

        return $time >= 13000 && $time <= 23000;
    }
}
