<?php
declare(strict_types=1);

namespace PhpMc;

use PhpMc\World\AnvilWorld;
use PhpMc\World\EndGenerator;

/**
 * 末地相关：末影水晶、后面的龙和龙蛋都挂在这里。
 *
 * 末影水晶不是生物（没有 AI、没有寻路），是「方块类实体」——
 * 协议里用 Spawn Object type=51 表示。它只做三件事：
 *   1. 站在黑曜石柱顶上转
 *   2. 被打爆时炸一下
 *   3. 活着的时候给末影龙回血
 */
trait EndTrait
{
    /** @var array<int, array{x:float,y:float,z:float}> 水晶 eid => 坐标（相对末地中心的世界坐标） */
    private array $endCrystals = [];

    /** 是否已经生成过水晶 */
    private bool $endCrystalsSpawned = false;

    /**
     * 在 10 根柱顶生成水晶，并把实体发给现在就在末地的所有玩家。
     *
     * 只生成一次 —— 水晶被打爆之后就永远没了，重启服务端才会重来。
     */
    private function spawnEndCrystals(): void
    {
        if ($this->endCrystalsSpawned) {
            return;
        }
        $this->endCrystalsSpawned = true;

        $originX = AnvilWorld::END_CX * 16;
        $originZ = AnvilWorld::END_CZ * 16;

        foreach (EndGenerator::pillarTops() as [$dx, $y, $dz]) {
            $eid = $this->nextEntityId++;
            $x = $originX + $dx + 0.5;
            $yy = (float) $y;
            $z = $originZ + $dz + 0.5;

            $this->endCrystals[$eid] = ['x' => $x, 'y' => $yy, 'z' => $z];
        }

        $this->log('  末地水晶 x' . count($this->endCrystals) . ' 已生成');

        // 已经在末地的人补发一遍
        foreach ($this->players() as $p) {
            if ($p->portalDim === 1) {
                $this->sendEndCrystalsTo($p);
            }
        }
    }

    /** 把当前所有水晶的 Spawn Object 发给某个连接 */
    private function sendEndCrystalsTo(Connection $p): void
    {
        foreach ($this->endCrystals as $eid => $c) {
            $p->send(self::C_SPAWN_OBJECT, (new Writer())
                ->varInt($eid)
                ->byte(51)                       // 51 = ender crystal
                ->int((int) ($c['x'] * 32))
                ->int((int) ($c['y'] * 32))
                ->int((int) ($c['z'] * 32))
                ->byte(0)->byte(0)
                ->int(0));
        }
    }

    /**
     * 打爆一颗水晶。原版会炸掉周围方块并造成伤害，这里只做伤害和销毁。
     */
    private function destroyEndCrystal(int $eid): void
    {
        $crystal = $this->endCrystals[$eid] ?? null;
        if ($crystal === null) {
            return;
        }
        unset($this->endCrystals[$eid]);
        // 如果打爆的是召唤位上的水晶，那个位子要能重新放
        foreach ($this->summonPlaced as $k => $ce) {
            if ($ce === $eid) {
                unset($this->summonPlaced[$k]);
                break;
            }
        }

        foreach ($this->players() as $p) {
            $p->send(self::C_DESTROY_ENTITIES, (new Writer())
                ->varInt(1)->varInt($eid));
        }

        // 6 点基础伤害，距爆心越远越少，10 格外不受影响
        foreach ($this->players() as $p) {
            $dx = $p->x - $crystal['x'];
            $dy = $p->y - $crystal['y'];
            $dz = $p->z - $crystal['z'];
            $dist = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
            if ($dist < 10.0) {
                $dmg = (int) round(6.0 * (1.0 - $dist / 10.0));
                if ($dmg > 0) {
                    $this->damage($p, $dmg);
                }
            }
        }

        // 音效包先不发：0x29 在这个项目里被 Spawn Painting 占着，
        // 发出去客户端按绘画解析会报『3 bytes extra』直接断线。

        // 爆炸特效：半径 6，记录留空（不真的炸方块）
        foreach ($this->players() as $p) {
            $p->send(self::C_EXPLOSION, (new Writer())
                ->float($crystal['x'])->float($crystal['y'])->float($crystal['z'])
                ->float(6.0)
                ->int(0)
                ->float(0.0)->float(0.0)->float(0.0));
        }

        $this->log(sprintf('  末地水晶被打爆 eid=%d @ %.1f,%.1f,%.1f（还剩 %d 颗）',
            $eid, $crystal['x'], $crystal['y'], $crystal['z'], count($this->endCrystals)));
    }

    // ------------------------------------------------------------ 四水晶重召

    /** 四个召唤位（相对末地中心），正好在基座四条边的正中间 */
    private const SUMMON_SPOTS = [[3, 0], [-3, 0], [0, 3], [0, -3]];

    /** 已放水晶的位子："dx,dz" => 水晶 eid */
    private array $summonPlaced = [];

    /**
     * 右键基座边缘的基岩，放一颗末影水晶上去。
     *
     * 四个位子都放满就重新召唤末影龙 —— 这是原版 1.9+ 的机制，
     * 1.8 其实没有，但用户想要，做就做了，逻辑上没冲突。
     *
     * @return bool 是否吃掉了这次右键（true 就不再走放置方块的常规流程）
     */
    private function tryPlaceSummonCrystal(Connection $c, int $x, int $y, int $z): bool
    {
        $ox = AnvilWorld::END_CX * 16;
        $oz = AnvilWorld::END_CZ * 16;

        // 只认基座顶面那一层
        if ($y !== 64) {
            return false;
        }

        $dx = $x - $ox;
        $dz = $z - $oz;

        $key = null;
        foreach (self::SUMMON_SPOTS as [$sx, $sz]) {
            if ($dx === $sx && $dz === $sz) {
                $key = "{$sx},{$sz}";
                break;
            }
        }
        if ($key === null || isset($this->summonPlaced[$key])) {
            return false;
        }

        $slot = 36 + $c->heldSlot;
        $held = $c->inventory[$slot] ?? null;
        if ($held === null || (int) $held['id'] !== 426) {   // 426 = 末影水晶
            return false;
        }

        // 扣一个
        $held['count']--;
        if ($held['count'] <= 0) {
            unset($c->inventory[$slot]);
            $c->send(self::C_SET_SLOT, (new Writer())->byte(0)->short($slot)->short(-1));
        } else {
            $c->inventory[$slot] = $held;
            $this->sendSlot($c, $slot, $held);
        }

        $eid = $this->nextEntityId++;
        $cx = (float) $x + 0.5;
        $cy = 65.0;
        $cz = (float) $z + 0.5;
        $this->endCrystals[$eid] = ['x' => $cx, 'y' => $cy, 'z' => $cz];
        $this->summonPlaced[$key] = $eid;

        foreach ($this->players() as $p) {
            if ($p->portalDim !== 1) {
                continue;
            }
            $p->send(self::C_SPAWN_OBJECT, (new Writer())
                ->varInt($eid)
                ->byte(51)
                ->int((int) ($cx * 32))
                ->int((int) ($cy * 32))
                ->int((int) ($cz * 32))
                ->byte(0)->byte(0)
                ->int(0));
        }

        $this->log("  召唤水晶 {$key} eid={$eid}（" . count($this->summonPlaced) . '/4）');

        if (count($this->summonPlaced) === 4) {
            $this->summonPlaced = [];
            $this->sendChat($c, ['text' => '四颗水晶就位，末影龙要醒了……', 'color' => 'light_purple'], 1);
            if ($this->dragon === null) {
                $this->spawnDragon();
                foreach ($this->players() as $p) {
                    if ($p->portalDim === 1) {
                        $this->sendDragonSpawn($p);
                    }
                }
            }
        }

        return true;
    }

    // ------------------------------------------------------------ 末地传送门激活

    private const END_FRAME = 120;
    private const EYE_OF_ENDER = 381;
    private const END_PORTAL = 119;

    /**
     * 右键末地传送门框架（120），手里有末影之眼就嵌一颗上去。
     * 12 个框架全部嵌满就在中间生成 3×3 的传送门方块（119）。
     *
     * @return bool 是否吃掉了这次右键
     */
    private function tryActivateEndPortal(Connection $c, int $x, int $y, int $z): bool
    {
        $chunk = $this->world->chunk($x >> 4, $z >> 4);
        $state = $chunk->blockState($x & 15, $y, $z & 15);
        if (($state >> 4) !== self::END_FRAME) {
            return false;
        }
        $this->log("  [门] 命中框架 {$x},{$y},{$z} state={$state}");

        $meta = $state & 15;
        if (($meta & 4) !== 0) {
            $this->log("  [门] {$x},{$y},{$z} 已经有眼睛了（meta={$meta}）");

            return false;   // 这颗已经有眼睛了
        }

        $slot = 36 + $c->heldSlot;
        $held = $c->inventory[$slot] ?? null;
        if ($held === null || (int) $held['id'] !== self::EYE_OF_ENDER) {
            return false;
        }

        // 扣一颗眼睛
        $held['count']--;
        if ($held['count'] <= 0) {
            unset($c->inventory[$slot]);
            $c->send(self::C_SET_SLOT, (new Writer())->byte(0)->short($slot)->short(-1));
        } else {
            $c->inventory[$slot] = $held;
            $this->sendSlot($c, $slot, $held);
        }

        // 框架 meta |= 4（有眼睛）
        $chunk->setBlock($x & 15, $y, $z & 15, self::END_FRAME, $meta | 4);
        foreach ($this->players() as $p) {
            $p->send(self::C_BLOCK_CHANGE, (new Writer())
                ->raw($this->packPositionBytes($x, $y, $z))
                ->varInt((self::END_FRAME << 4) | ($meta | 4)));
        }
        $this->log("  末地框架嵌眼 {$x},{$y},{$z}");

        $this->checkEndPortalRing($x, $y, $z);

        return true;
    }

    /** 扫 5×5 看是不是 12 个框架围成的环，是就把中间 3×3 点亮 */
    private function checkEndPortalRing(int $x, int $y, int $z): void
    {
        $offsets = [];
        // 11×11：12 个框架在外圈 5×5 上（四角空），最远两点跨 (4,4)。
        // 留一圈余量，免得玩家摆的时候偏一格就点不亮。
        for ($dx = -5; $dx <= 5; $dx++) {
            for ($dz = -5; $dz <= 5; $dz++) {
                if ($dx === 0 && $dz === 0) {
                    continue;   // 被点那格单独补，见下面
                }
                $bx = $x + $dx;
                $bz = $z + $dz;
                $s = $this->world->chunk($bx >> 4, $bz >> 4)->blockState($bx & 15, $y, $bz & 15);
                if (($s >> 4) === self::END_FRAME && (($s & 15) & 4) !== 0) {
                    $offsets[] = [$dx, $dz];
                }
            }
        }

        // 被点的那一格自己也是环上的一员（玩家往往点的就是第 12 个）。
        // 之前循环里把它排除又没补回来，所以永远只数到 11 —— 这就是门一直不开的原因。
        $offsets[] = [0, 0];

        if (count($offsets) < 12) {
            $list = [];
            foreach ($offsets as [$dx, $dz]) {
                $list[] = "({$dx},{$dz})";
            }
            $this->log(sprintf('  [门] %d,%d,%d 周围只扫到 %d 个嵌眼框架：%s',
                $x, $y, $z, count($offsets), implode(' ', $list)));

            return;
        }

        // 12 个环的平均偏移取反，就是环心相对被点框架的偏移
        $sumX = 0;
        $sumZ = 0;
        foreach ($offsets as [$dx, $dz]) {
            $sumX += $dx;
            $sumZ += $dz;
        }
        $n = count($offsets);
        // 12 个相对被点框架的偏移求和 = 12 × (环心 - 被点)，所以要「加」不是「减」。
        $centerX = $x + (int) round($sumX / $n);
        $centerZ = $z + (int) round($sumZ / $n);

        // 中间 3×3 铺传送门
        for ($dx = -1; $dx <= 1; $dx++) {
            for ($dz = -1; $dz <= 1; $dz++) {
                $bx = $centerX + $dx;
                $bz = $centerZ + $dz;
                $chunk = $this->world->chunk($bx >> 4, $bz >> 4);
                $chunk->setBlock($bx & 15, $y, $bz & 15, self::END_PORTAL, 0);
                foreach ($this->players() as $p) {
                    $p->send(self::C_BLOCK_CHANGE, (new Writer())
                        ->raw($this->packPositionBytes($bx, $y, $bz))
                        ->varInt(self::END_PORTAL << 4));
                }
            }
        }

        foreach ($this->players() as $p) {
            $this->sendChat($p, ['text' => '末地传送门开启了', 'color' => 'light_purple'], 1);
        }
        $this->log("  末地传送门已开启 @ {$centerX},{$y},{$centerZ}");
    }

    /** 还有几颗水晶活着 */
    private function liveCrystalCount(): int
    {
        return count($this->endCrystals);
    }

    // ------------------------------------------------------------ 末影龙

    /** 死了但还没清场的龙：[eid => 该销毁的时间] */
    private array $deadDragons = [];
    private bool $dragonEnabled = true;
    /** @var array{eid:int,x:float,y:float,z:float,yaw:float,hp:float,angle:float}|null */
    private ?array $dragon = null;
    /** 上次给客户端推位置的时间 */
    private float $dragonLastMove = 0.0;
    /** 龙已经过了多少拍（用来算水晶回血的 10 tick 周期） */
    private int $dragonTicks = 0;
    /** 停驻结束的时刻（0 = 在飞） */
    private float $dragonPerchUntil = 0.0;

    /**
     * 生成末影龙。1.8 里龙是 Spawn Mob type=63（不是 Spawn Object），
     * 客户端自己会画出七个身体部件，服务端只维护一个球心。
     */
    private function spawnDragon(): void
    {
        // 【临时关闭】客户端收到这个 Spawn Mob 会 IndexOutOfBounds，正在查包格式。
        if (!$this->dragonEnabled) {
            return;
        }
        if ($this->dragon !== null) {
            return;
        }

        $ox = AnvilWorld::END_CX * 16 + 0.5;
        $oz = AnvilWorld::END_CZ * 16 + 0.5;

        $eid = $this->nextEntityId++;
        $this->dragonPerchUntil = 0.0;
        $this->dragon = [
            'eid' => $eid,
            'x' => $ox,
            'y' => 128.0,
            'z' => $oz,
            'yaw' => 0.0,
            'hp' => 200.0,
            'angle' => 0.0,
        ];

        foreach ($this->players() as $p) {
            if ($p->portalDim === 1) {
                $this->sendDragonSpawn($p);
            }
        }
        $this->log("  末影龙已生成 eid={$eid}");
    }

    private function sendDragonSpawn(Connection $p): void
    {
        $d = $this->dragon;
        if ($d === null) {
            return;
        }

        $p->send(self::C_SPAWN_MOB, (new Writer())
            ->varInt($d['eid'])
            ->byte(63)                                   // 63 = EnderDragon
            ->int((int) ($d['x'] * 32))
            ->int((int) ($d['y'] * 32))
            ->int((int) ($d['z'] * 32))
            ->byte(0)->byte(0)->byte(0)
            ->short(0)->short(0)->short(0)
            // DataWatcher 编码是 (type << 5) | index —— 1.8 和 1.7 是反的，别搞错。
            // 血量是 index 6 的 float，所以字节是 (3 << 5) | 6 = 0x66。
            // 别学 sendMobSpawn 那样再补一条 index 0 的 flags ——
            // 多这一条会让客户端在 metadata 末尾多读一个字段，直接 IndexOutOfBounds。
            ->unsignedByte(0x66)->float($d['hp'])
            ->unsignedByte(0x7F));
    }

    /**
     * 龙绕着中央岛转圈。原版是一套复杂的飞行 AI（盘旋、俯冲、停在水晶上回血），
     * 这里先做最基础的一圈：半径 42、高度 95±12 上下起伏。
     */
    private function tickDragon(float $now): void
    {
        // 先把 10 秒前死掉的龙清场
        foreach ($this->deadDragons as $deadEid => $at) {
            if ($now < $at) {
                continue;
            }
            foreach ($this->players() as $p) {
                $p->send(self::C_DESTROY_ENTITIES, (new Writer())
                    ->varInt(1)->varInt($deadEid));
            }
            unset($this->deadDragons[$deadEid]);
            $this->log("  末影龙实体已清场 eid={$deadEid}");
        }

        if ($this->dragon === null || $now - $this->dragonLastMove < 0.1) {
            return;
        }

        $viewer = false;
        foreach ($this->players() as $p) {
            if ($p->portalDim === 1) {
                $viewer = true;
                break;
            }
        }
        if (!$viewer) {
            return;
        }

        $this->dragonLastMove = $now;
        $this->dragonTicks++;

        // 水晶回血：原版每 10 tick 回 1 点，只要还有任意一颗活着
        if ($this->dragonTicks % 10 === 0
            && $this->liveCrystalCount() > 0
            && $this->dragon['hp'] < 200.0) {
            $this->dragon['hp'] = min(200.0, $this->dragon['hp'] + 1.0);
            foreach ($this->players() as $p) {
                if ($p->portalDim !== 1) {
                    continue;
                }
                $p->send(self::C_ENTITY_METADATA, (new Writer())
                    ->varInt($this->dragon['eid'])
                    ->byte(0x66)->float($this->dragon['hp'])
                    ->unsignedByte(0x7F));
            }
        }

        $ox = AnvilWorld::END_CX * 16 + 0.5;
        $oz = AnvilWorld::END_CZ * 16 + 0.5;

        // 停驻中：原地不动，时间到了才重新起飞
        if ($this->dragonPerchUntil > 0.0) {
            if ($now < $this->dragonPerchUntil) {
                return;
            }
            $this->dragonPerchUntil = 0.0;
            $this->dragon['angle'] = 0.0;
            $this->log('  末影龙起飞');
        }

        // 0.02 弧度/0.1 秒 ≈ 31 秒一圈，之前 0.04 是 15 秒，太快了
        $this->dragon['angle'] += 0.02;
        $angle = $this->dragon['angle'];

        // 飞满两圈就回中央平台停 20~30 秒
        if ($angle >= 4 * M_PI) {
            $secs = random_int(20, 30);
            $this->dragonPerchUntil = $now + $secs;
            $this->dragon['x'] = $ox;
            $this->dragon['y'] = 72.0;
            $this->dragon['z'] = $oz;
            $this->dragon['angle'] = 0.0;

            foreach ($this->players() as $p) {
                if ($p->portalDim !== 1) {
                    continue;
                }
                $p->send(self::C_ENTITY_TELEPORT, (new Writer())
                    ->varInt($this->dragon['eid'])
                    ->int((int) ($ox * 32))
                    ->int((int) (72.0 * 32))
                    ->int((int) ($oz * 32))
                    ->byte(0)->byte(0)->byte(1));
            }
            $this->log("  末影龙回中央停驻 {$secs} 秒");

            return;
        }

        $this->dragon['x'] = $ox + cos($angle) * 42.0;
        $this->dragon['z'] = $oz + sin($angle) * 42.0;
        $this->dragon['y'] = 95.0 + sin($angle * 3.0) * 12.0;
        $this->dragon['yaw'] = fmod(rad2deg($angle) + 90.0, 360.0);

        $yawByte = ((int) ($this->dragon['yaw'] * 256.0 / 360.0)) & 0xFF;

        foreach ($this->players() as $p) {
            if ($p->portalDim !== 1) {
                continue;
            }
            $p->send(self::C_ENTITY_TELEPORT, (new Writer())
                ->varInt($this->dragon['eid'])
                ->int((int) ($this->dragon['x'] * 32))
                ->int((int) ($this->dragon['y'] * 32))
                ->int((int) ($this->dragon['z'] * 32))
                ->byte($yawByte)
                ->byte(0)
                ->byte(1));
        }
    }

    private function hurtDragon(int $dmg): void
    {
        if ($this->dragon === null) {
            return;
        }

        $this->dragon['hp'] -= $dmg;

        // Entity Status(2) = 受伤：客户端会把身体闪红并播 mob.enderdragon.hurt
        foreach ($this->players() as $p) {
            $p->send(self::C_ENTITY_STATUS, (new Writer())
                ->int($this->dragon['eid'])
                ->byte(2));
        }
        if ($this->dragon['hp'] <= 0.0) {
            $this->killDragon();

            return;
        }

        foreach ($this->players() as $p) {
            $p->send(self::C_ENTITY_METADATA, (new Writer())
                ->varInt($this->dragon['eid'])
                ->byte(0x66)->float($this->dragon['hp'])
                ->unsignedByte(0x7F));
        }
        $this->log(sprintf('  末影龙受击 %d，剩 %.1f', $dmg, $this->dragon['hp']));
    }

    private function killDragon(): void
    {
        if ($this->dragon === null) {
            return;
        }
        $eid = $this->dragon['eid'];
        $x = $this->dragon['x'];
        $y = $this->dragon['y'];
        $z = $this->dragon['z'];
        $this->dragon = null;

        foreach ($this->players() as $p) {
            // 先发 Entity Status(3) 让它播死亡动画 —— 客户端的 EntityDragon
            // 在 onDeathUpdate 跑到第 200 tick 时会自己弹出终末之诗。
            // 这里不能马上发 Destroy Entities，那样实体被移除，动画和诗都没了。
            $p->send(self::C_ENTITY_STATUS, (new Writer())
                ->int($eid)
                ->byte(3));
            $this->sendChat($p, ['text' => '末影龙被击败了', 'color' => 'light_purple'], 1);
        }
        $this->deadDragons[$eid] = microtime(true) + 10.0;   // 10 秒后清场

        // 原版龙死掉后掉一大堆经验球
        for ($i = 0; $i < 12; $i++) {
            $this->spawnXpOrb($x + random_int(-3, 3), $y + random_int(-2, 2), $z + random_int(-3, 3), 60);
        }

        // 龙蛋：原版在死亡动画结束时出现在基座中心的柱顶（y=68）
        $eggX = AnvilWorld::END_CX * 16;
        $eggZ = AnvilWorld::END_CZ * 16;
        $chunk = $this->world->chunk($eggX >> 4, $eggZ >> 4);
        if ($chunk->setBlock($eggX & 15, 68, $eggZ & 15, 122, 0)) {
            foreach ($this->players() as $p) {
                $p->send(self::C_BLOCK_CHANGE, (new Writer())
                    ->raw($this->packPositionBytes($eggX, 68, $eggZ))
                    ->varInt(122 << 4));
            }
            $this->log('  龙蛋已生成');
        }

        $this->log('  末影龙死亡');
    }

    // ------------------------------------------------------------ 返回传送门

    private float $lastEndPortalCheck = 0.0;

    /**
     * 踩到末地传送门方块（119）就回主世界。
     *
     * 原版会把你送到主世界的出生点（或者床边），这里直接用世界出生点，
     * 维度切换交给 tickDimSync —— 位置一改，它下一拍就会发 0x07。
     */
    private function tickEndPortal(float $now): void
    {
        if ($now - $this->lastEndPortalCheck < 0.5) {
            return;
        }
        $this->lastEndPortalCheck = $now;

        foreach ($this->players() as $p) {
            // 刚切完维度那一两拍先别判定，不然人还站在原地的门里，
            // 会被立刻踢回去，来回弹。
            if ($now - $p->portalDimAt < 2.0) {
                continue;
            }

            $id = $this->blockIdAt((int) floor($p->x), (int) floor($p->y), (int) floor($p->z));
            if ($id !== 119) {
                continue;
            }

            if ($p->portalDim === 1) {
                // 末地 → 主世界
                [$sx, $sy, $sz] = $this->world->spawnPoint();
                $p->x = (float) $sx + 0.5;
                $p->y = (float) $sy;
                $p->z = (float) $sz + 0.5;
                $p->fallFrom = -1.0;
                $this->sendChat($p, ['text' => '你从末地回来了', 'color' => 'light_purple'], 1);
                $this->log("  {$p->username} 踩传送门回主世界");
            } else {
                // 主世界 → 末地中央岛的平台
                $p->x = AnvilWorld::END_CX * 16 + 0.5;
                $p->y = 65.0;
                $p->z = AnvilWorld::END_CZ * 16 + 8.5;
                $p->fallFrom = -1.0;
                $this->sendChat($p, ['text' => '你跳进了末地', 'color' => 'light_purple'], 1);
                $this->log("  {$p->username} 踩传送门去末地");
            }
        }
    }
}
