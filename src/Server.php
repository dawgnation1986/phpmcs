<?php
declare(strict_types=1);

namespace PhpMc;

use PhpMc\Nbt\Nbt;
use PhpMc\Nbt\Tag;
use PhpMc\Skin\Skins;
use PhpMc\World\AnvilWorld;

/** 一条玩家的 TCP 连接。状态机：0 握手 → 1 状态查询 / 2 登录 → 3 游戏内。 */
final class Connection
{
    public const STATE_HANDSHAKE = 0;
    public const STATE_STATUS = 1;
    public const STATE_LOGIN = 2;
    public const STATE_PLAY = 3;

    /** @var resource */
    public $socket;
    public string $in = '';
    public string $out = '';
    public int $state = self::STATE_HANDSHAKE;
    public int $protocol = 0;
    public string $remote = '';
    public bool $closed = false;
    public float $lastRead = 0.0;

    // 登录后
    public int $entityId = 0;
    public string $username = '';
    public string $uuid = '';
    /** @var array{value:string,signature:string}|null */
    public ?array $textures = null;
    /** @var array{x:int,y:int,z:int,size:int,partner:?array{0:int,1:int,2:int},slots:array}|null 打开着的箱子 */
    public ?array $chest = null;

    /** @var array{id:int,count:int,damage:int}|null 服务端记录的光标物品 */
    public ?array $cursor = null;
    /** @var array<string, array{0:int,1:int,2:int}> 进服时带箱子的区块段，稍后重发一次 */
    public array $chestSections = [];
    /** 段重发时间点（秒） */
    public array $chestSectionTimes = [];
    /** 箱子补发跑到第几轮了（0 起）：第一轮只造 TE，之后才发 0x23 */
    public int $chestFixRound = 0;
    /** 是否已经提示过「书架其实是箱子」 */
    public bool $chestHinted = false;
    /** 当前打开的熔炉坐标 */
    public ?array $furnace = null;
    /** @var array<int,array{id:int,count:int,damage:int}|null> 玩家 2x2 合成格 */
    public array $craftGrid = [null, null, null, null];
    /** @var array<int,array{id:int,count:int,damage:int}|null> 工作台 3x3 合成格 */
    public array $craft9 = [null, null, null, null, null, null, null, null, null];
    /** 是否打开了工作台窗口（windowId 2） */
    public bool $workbench = false;
    /** 拖动（mode 5）阶段：0 未开始，1 收集槽位，2 结束分发 */
    public int $dragPhase = 0;
    /** 拖动鼠标键：0 左，1 右，2 中 */
    public int $dragButton = 0;
    /** @var array<int,int> 拖动经过的容器槽位 */
    public array $dragSlots = [];
    /** 进服 1 秒后补发一次背包：有的客户端会忽略第一次 Window Items */
    public float $resendInvAt = 0.0;
    /** 创造模式第一次挖方块时提示一次「不掉落」，别每次都刷 */
    public bool $hintedCreativeDrop = false;
    /** 上次自然回血的时间 */
    public float $lastRegen = 0.0;
    /** 疲劳值：攒够 4.0 掉 1 点饱食度 */
    public float $exhaustion = 0.0;
    /** 下一次能吃的时间：一口 1.6 秒（原版进食时长） */
    public float $nextEatAt = 0.0;
    /** 上一次的位置，用来算跑动距离 */
    public float $lastX = 0.0;
    public float $lastY = 0.0;
    public float $lastZ = 0.0;
    public int $gamemode = 1;
    public bool $flying = false;
    /** 手上拿的是快捷栏第几格（0-8），客户端切物品时会告诉我们 */
    public int $heldSlot = 0;
    public float $health = 20.0;
    public int $food = 20;
    /** 开始下落时的高度，落地时用来算摔伤 */
    public float $fallFrom = -1.0;   // <0 表示没在下落
    /** @var array<string, bool> 已经发过区块的 "cx,cz" */
    public array $sentChunks = [];
    /** @var array<string, array{0:int,1:int}> 等着补发的区块 */
    public array $chunkQueue = [];
    /** @var array<int, array{id:int,count:int,damage:int}> 槽位 => 物品 */
    public array $inventory = [];

    // 位置（脚底）
    public float $x = 0.5;
    public float $y = 4.0;
    public float $z = 0.5;
    public float $yaw = 0.0;
    public float $pitch = 0.0;
    public bool $onGround = true;

    // Keep Alive
    public int $keepAliveId = 0;
    public float $keepAliveSentAt = 0.0;

    public function __construct($socket, string $remote)
    {
        $this->socket = $socket;
        $this->remote = $remote;
        $this->lastRead = microtime(true);
    }

    public function send(int $packetId, Writer $payload): void
    {
        $this->out .= Frame::encode(VarInt::encode($packetId) . $payload->toString());
    }

    public function flush(): bool
    {
        if ($this->out === '') {
            return true;
        }
        $written = @fwrite($this->socket, $this->out);
        if ($written === false) {
            return false;
        }
        if ($written > 0) {
            $this->out = substr($this->out, $written);
        }

        return true;
    }
}

final class Server
{
    use ChestTrait;
    use CraftingTrait;
    use MobTrait;
    use FurnaceTrait;

    public const PROTOCOL = 47;

    /** 段重发开关：先关着（见 flushSections 的注释） */
    private const ENABLE_SECTION_RESEND = false;
    public const VERSION = '1.8.9';

    // 客户端包 ID
    private const C_KEEP_ALIVE = 0x00;
    private const C_JOIN_GAME = 0x01;
    private const C_CHAT = 0x02;
    private const C_SPAWN_POSITION = 0x05;
    private const C_POS_LOOK = 0x08;
    private const C_SPAWN_PLAYER = 0x0C;
    private const C_DESTROY_ENTITIES = 0x13;
    private const C_SPAWN_MOB = 0x0F;
    private const C_TIME_UPDATE = 0x03;
    private const C_ENTITY_STATUS = 0x1A;
    private const C_ENTITY_LOOK = 0x16;
    private const C_ENTITY_TELEPORT = 0x18;
    private const C_HEAD_LOOK = 0x19;
    private const C_CHUNK_DATA = 0x21;
    /** 1.8 的瓦片实体包：箱子这类方块没有它客户端就不画 */
    private const C_UPDATE_TILE_ENTITY = 0x35;
    // 1.8：0x0B 是 Animation（挥手），0x0D 才是 Collect Item。
    // 写成 0x0B 的话客户端只会挥一下手，地上的东西永远不消失。
    private const C_ANIMATION = 0x0B;
    private const C_COLLECT_ITEM = 0x0D;
    private const C_SPAWN_OBJECT = 0x0E;
    private const C_ENTITY_VELOCITY = 0x12;
    private const C_ENTITY_METADATA = 0x1C;
    private const C_UPDATE_HEALTH = 0x06;
    private const C_OPEN_WINDOW = 0x2D;
    private const C_WINDOW_ITEMS = 0x30;
    private const C_CONFIRM_TRANSACTION = 0x32;
    private const C_BLOCK_CHANGE = 0x23;
    private const C_CHANGE_GAME_STATE = 0x2B;
    private const C_SET_SLOT = 0x2F;
    private const C_PLAYER_LIST = 0x38;
    private const C_ABILITIES = 0x39;
    private const C_DISCONNECT = 0x40;

    /** @var array<int, Connection> */
    private array $conns = [];

    private int $nextEntityId = 1;
    /** @var array<int, array{id:int,count:int,meta:int,x:float,y:float,z:float,at:float,expire:float}> */
    private array $drops = [];
    /** @var array<string, array{0:int,1:int,2:int}> 改了方块的 section，要重发给客户端 */
    private array $dirtySections = [];
    /** @var array<string, float> 上次补发段的时间，用来做节流 */
    private array $lastSectionPush = [];
    private float $startedAt;
    private float $lastKeepAlive = 0.0;
    private float $lastSave = 0.0;

    public function __construct(
        private string $host,
        private int $port,
        private string $motd,
        private AnvilWorld $world,
        private Skins $skins,
        private \PhpMc\World\ChestStore $chests,
        private int $maxPlayers = 20,
        private int $gamemode = 1,
        private int $viewDistance = 3,
    ) {
        $this->startedAt = microtime(true);
        $this->lastKeepAlive = microtime(true);
        $this->lastSave = microtime(true);
    }

    public function run(): void
    {
        $ctx = stream_context_create([
            'socket' => ['backlog' => 64, 'so_reuseaddr' => true],
        ]);
        $master = @stream_socket_server(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $ctx
        );

        if ($master === false) {
            fwrite(STDERR, "监听 {$this->host}:{$this->port} 失败: [{$errno}] {$errstr}\n");
            exit(1);
        }
        stream_set_blocking($master, false);

        $this->log('PHP MC 服务端已启动  ' . "{$this->host}:{$this->port}" . '  协议 ' . self::PROTOCOL);
        $this->log("MOTD: {$this->motd}");
        $this->log('存档: ' . ($this->world->created ? '新建' : '载入') . ' / 视距 '
            . $this->viewDistance . ' / 模式 ' . self::gameModeName($this->gamemode));

        while (true) {
            $read = [$master];
            $write = [];
            foreach ($this->conns as $c) {
                if ($c->closed) {
                    continue;
                }
                $read[] = $c->socket;
                if ($c->out !== '') {
                    $write[] = $c->socket;
                }
            }
            $except = null;

            $ready = @stream_select($read, $write, $except, 0, 20000);
            if ($ready === false) {
                $this->log('stream_select 出错，退出');
                break;
            }

            if ($ready > 0) {
                foreach ($read as $sock) {
                    if ($sock === $master) {
                        $this->accept($master);
                        continue;
                    }
                    $c = $this->find($sock);
                    if ($c !== null) {
                        $this->pump($c);
                    }
                }
                foreach ($write as $sock) {
                    $c = $this->find($sock);
                    if ($c !== null && !$c->flush()) {
                        $this->drop($c, '写失败');
                    }
                }
            }

            $this->tick();
        }
    }

    private function accept($master): void
    {
        $sock = @stream_socket_accept($master, 0, $peer);
        if ($sock === false) {
            return;
        }
        stream_set_blocking($sock, false);
        stream_set_read_buffer($sock, 0);
        stream_set_write_buffer($sock, 0);
        $c = new Connection($sock, (string) $peer);
        $this->conns[(int) $sock] = $c;
        $this->log("+ 连接 {$peer}");
    }

    private function find($sock): ?Connection
    {
        return $this->conns[(int) $sock] ?? null;
    }

    private function pump(Connection $c): void
    {
        $chunk = @fread($c->socket, 65536);
        if ($chunk === false || ($chunk === '' && feof($c->socket))) {
            $this->drop($c, '对端关闭');
            return;
        }
        if ($chunk === '') {
            return;
        }

        $c->in .= $chunk;
        $c->lastRead = microtime(true);

        try {
            foreach (Frame::drain($c->in) as [$id, $r]) {
                $this->handle($c, $id, $r);
                if ($c->closed) {
                    return;
                }
            }
        } catch (\Throwable $e) {
            $this->drop($c, '协议错误: ' . $e->getMessage());
        }
    }

    private function handle(Connection $c, int $id, Reader $r): void
    {
        switch ($c->state) {
            case Connection::STATE_HANDSHAKE:
                if ($id !== 0x00) {
                    throw new \RuntimeException("握手包ID异常: {$id}");
                }
                $c->protocol = $r->readVarInt();
                $host = $r->readString(255);
                $port = $r->readUnsignedShort();
                $next = $r->readVarInt();
                $c->state = $next === 1 ? Connection::STATE_STATUS : Connection::STATE_LOGIN;
                $this->log("  握手 proto={$c->protocol} host={$host}:{$port} next={$next}");

                return;

            case Connection::STATE_STATUS:
                if ($id === 0x00) {
                    $c->send(0x00, (new Writer())->string($this->statusJson()));
                } elseif ($id === 0x01) {
                    $c->send(0x01, (new Writer())->long($r->readLong()));
                }

                return;

            case Connection::STATE_LOGIN:
                if ($id === 0x00) {
                    $c->username = $r->readString(16);
                    if ($c->username === '') {
                        throw new \RuntimeException('空用户名');
                    }
                    foreach ($this->players() as $other) {
                        if (strcasecmp($other->username, $c->username) === 0) {
                            $c->send(0x00, (new Writer())->string(json_encode([
                                'text' => '同名玩家已在游戏中',
                                'color' => 'red',
                            ], JSON_UNESCAPED_UNICODE)));
                            $c->flush();
                            $this->drop($c, '重名');

                            return;
                        }
                    }
                    $this->log("  登录 user={$c->username}");
                    $this->join($c);
                }

                return;

            case Connection::STATE_PLAY:
                $this->handlePlay($c, $id, $r);

                return;
        }
    }

    // ---------------- 进服流程 ----------------

    private function join(Connection $c): void
    {
        $c->uuid = self::offlineUuid($c->username);
        $c->entityId = $this->nextEntityId++;
        $c->state = Connection::STATE_PLAY;
        // 用上次的模式，没有记录才用世界默认（/gm 换过之后重进保持）
        $c->gamemode = $this->world->playerGamemode($c->username) ?? $this->gamemode;

        // 饱食度和血量也要接着上次（不然重进永远满血满饱食度）
        $vitals = $this->world->vitals($c->username);
        $c->food = $vitals['food'];
        $c->health = $vitals['hp'];

        // 皮肤：只有拿着带签名的 textures 属性，别人的客户端才认
        $t0 = microtime(true);
        $c->textures = $this->skins->of($c->username);
        $ms = (microtime(true) - $t0) * 1000;
        $this->log(sprintf(
            '  皮肤 %s（%s，%.0f ms）',
            $c->textures === null ? '未取到' : '已取到',
            $c->username,
            $ms
        ));

        [$sx, $sy, $sz] = $this->world->spawnPoint();
        $c->x = $sx + 0.5;
        $c->y = (float) $sy;
        $c->z = $sz + 0.5;

        // 上次在哪儿下线就回哪儿，别每次都把人扔回出生点
        $last = $this->world->lastPosition($c->username);
        if ($last !== null && $last['y'] > 0 && $last['y'] < 256) {
            $c->x = $last['x'];
            $c->y = $last['y'];
            $c->z = $last['z'];
            $c->yaw = $last['yaw'] ?? 0.0;
            $c->pitch = $last['pitch'] ?? 0.0;
            $this->log("  回到上次位置 {$c->x},{$c->y},{$c->z}");
        }

        // 1) Login Success
        $c->send(0x02, (new Writer())->string($c->uuid)->string($c->username));

        // 2) Join Game
        $c->send(self::C_JOIN_GAME, (new Writer())
            ->int($c->entityId)
            ->unsignedByte($c->gamemode)
            ->byte(0)                       // 维度：主世界
            ->unsignedByte(0)               // 难度：和平
            ->unsignedByte($this->maxPlayers)
            ->string($this->world->generator())
            ->bool(false));                 // reduced debug info

        // 3) 能力：不发这个，创造模式等于没开（不能飞、不是无敌）
        $this->sendAbilities($c);

        // 血量：用上面读到的存档值，不再强制满血
        $this->sendHealth($c);

        // 上一次位置要先设成出生点，否则第一段移动会被算成「从 0,0,0 跑过来」，
        // 一口气掉好几格饱食度。
        $c->lastX = $c->x;
        $c->lastY = $c->y;
        $c->lastZ = $c->z;

        // 背包：不发这个，重连之后东西就「没了」（客户端从空背包开始）
        $c->inventory = $this->world->inventory($c->username);

        // 清洗：旧版本存进去的「没有物品形态的 id」要丢掉，
        // 否则每次进服发 Window Items 都会让客户端 NPE。
        $removed = 0;
        foreach ($c->inventory as $slot => $item) {
            if (!self::hasItem($item['id'])) {
                unset($c->inventory[$slot]);
                $removed++;
            }
        }
        if ($removed > 0) {
            $this->log("  背包清洗：丢掉 {$removed} 个无效物品");
        }
        $this->sendPlayerInventory($c);
        $c->resendInvAt = microtime(true) + 1.0;

        // 地上已有的掉落物要重新 Spawn 给这个连接。
        // 不补的话：客户端根本没有这些实体，东西「看不见但捡得到」——
        // 因为拾取是服务端按距离算的，不依赖客户端画没画出来。
        $resent = 0;
        foreach ($this->drops as $eid => $d) {
            $this->sendDropSpawn($c, $eid, $d);
            $resent++;
        }
        if ($resent > 0) {
            $this->log("  补发地上掉落物 {$resent} 个");
        }
        $this->log("  载入背包 " . count($c->inventory) . ' 格');

        // 4) 标签列表 / 互相看见
        foreach ($this->players() as $other) {
            $this->sendTabAdd($c, $other);
            if ($other !== $c) {
                $this->sendSpawnPlayer($c, $other);
                $this->sendTabAdd($other, $c);
                $this->sendSpawnPlayer($other, $c);
            }
        }

        // 5) 区块：排队慢慢发。
        // 以前是 49 个一口气推 3.7MB，客户端接不住（表现就是「进去全是石头，
        // 放一块方块才啪一下变正常」）。走进去的新区块是每拍 4 个发的，一直是好的。
        $this->queueChunks($c);

        // 6) 出生点 + 位置同步
        $c->send(self::C_SPAWN_POSITION, (new Writer())->raw($this->packPositionBytes($sx, $sy, $sz)));
        $c->send(self::C_POS_LOOK, (new Writer())
            ->double($c->x)->double($c->y)->double($c->z)
            ->float($c->yaw)->float($c->pitch)
            ->byte(0));

        $this->log("  进服 {$c->username}  eid={$c->entityId}  at {$c->x},{$c->y},{$c->z}");
        $this->broadcastChat('§e' . $c->username . ' 加入了游戏', 1);
        $this->sendChat($c, [
            'text' => '欢迎。存档：' . $this->world->levelName(),
            'color' => 'yellow',
        ], 1);
        $this->sendChat($c, ['text' => '试试 /gm 1 | /gm 0，或者 /help', 'color' => 'gray'], 1);
    }

    private function sendAbilities(Connection $c): void
    {
        $flags = 0;
        if ($c->gamemode === 1 || $c->gamemode === 3) {
            $flags |= 0x01 | 0x04 | 0x08;   // 无敌 + 允许飞 + 创造
        }
        if ($c->flying) {
            $flags |= 0x02;
        }
        $c->send(self::C_ABILITIES, (new Writer())
            ->unsignedByte($flags)
            ->float(0.05)                    // 飞行速度
            ->float(0.1));                   // 行走速度
    }

    /** @return Connection[] */
    private function players(): array
    {
        $out = [];
        foreach ($this->conns as $c) {
            if (!$c->closed && $c->state === Connection::STATE_PLAY) {
                $out[] = $c;
            }
        }

        return $out;
    }

    private function sendChunkTo(Connection $c, int $cx, int $cz): void
    {
        [$mask, $data] = $this->world->chunkPayload($cx, $cz);
        $c->send(self::C_CHUNK_DATA, (new Writer())
            ->int($cx)
            ->int($cz)
            ->bool(true)                // groundUp
            ->short($mask)
            ->varInt(strlen($data))
            ->raw($data));
        $c->sentChunks[$cx . ',' . $cz] = true;

        // 区块包只写方块字节，不建 TileEntity。发完立刻补 0x35，
        // 让客户端在渲染这段区块之前就能拿到箱子实体。
        // 注意：绝不能先发空气「破状态」—— air 会把区块里已有的箱子方块
        // 一起打掉，之后补方块也救不回来，玩家看到的就是箱子永久消失。
        $chunk = $this->world->chunk($cx, $cz);
        $chests = $chunk->blockPositions(54);
        foreach ($chests as [$x, $y, $z]) {
            $this->sendTileEntity($c, $x, $y, $z);
            // 客户端有时在区块还没标成 loaded 时就处理 0x35，TE 建不出来；
            // 过两秒把箱子所在的段整段重发一次，强制它重建渲染区块。
            $sy = $y >> 4;
            $c->chestSections[$cx . ',' . $cz . ',' . $sy] = [$cx, $cz, $sy];
        }
        if ($chests !== []) {
            $now = microtime(true);
            // 每 2 秒喂一轮，共 15 轮（30 秒）。客户端什么时候把区块真正加载好，
            // 下一轮就能把 TE 建出来；建好后多余的轮次是无害的空转。
            $c->chestSectionTimes = [];
            for ($i = 0; $i < 15; $i++) {
                $c->chestSectionTimes[] = $now + 0.4 + 1.5 * $i;
            }
        }
    }

    private function sendTileEntity(Connection $c, int $x, int $y, int $z): void
    {
        $nbt = Nbt::write(Tag::compound([
            'id' => Tag::str('Chest'),
            'x' => Tag::int($x),
            'y' => Tag::int($y),
            'z' => Tag::int($z),
        ]));
        // action 只是给少数实体做特殊处理（1 刷怪箱 / 2 命令方块 / 3 告示牌 /
        // 4 头颅 / 5 / 6），箱子没有特殊分支，填 0 即可。客户端会先按方块
        // 建出 TileEntityChest，再走这里。
        $c->send(self::C_UPDATE_TILE_ENTITY, (new Writer())
            ->raw($this->packPositionBytes($x, $y, $z))
            ->byte(0)
            ->raw($nbt));
    }

    private function broadcastTileEntity(int $x, int $y, int $z): void
    {
        foreach ($this->players() as $p) {
            $this->sendTileEntity($p, $x, $y, $z);
        }
    }

    private function sendChunks(Connection $c): void
    {
        $cx0 = ((int) floor($c->x)) >> 4;
        $cz0 = ((int) floor($c->z)) >> 4;
        $r = $this->viewDistance;
        $sent = 0;
        $t0 = microtime(true);
        $bytes = 0;

        for ($dx = -$r; $dx <= $r; $dx++) {
            for ($dz = -$r; $dz <= $r; $dz++) {
                $before = strlen($c->out);
                $this->sendChunkTo($c, $cx0 + $dx, $cz0 + $dz);
                $bytes += strlen($c->out) - $before;
                $sent++;
            }
        }

        $ms = (microtime(true) - $t0) * 1000;
        $this->log(sprintf('  发送区块 %d 个，%d KB，耗时 %.0f ms', $sent, round($bytes / 1024), $ms));
    }

    /** 走进新区块时，把视野里还没发过的区块排进队列。 */
    private function queueChunks(Connection $c): void
    {
        $cx0 = ((int) floor($c->x)) >> 4;
        $cz0 = ((int) floor($c->z)) >> 4;
        $r = $this->viewDistance;

        for ($dx = -$r; $dx <= $r; $dx++) {
            for ($dz = -$r; $dz <= $r; $dz++) {
                $cx = $cx0 + $dx;
                $cz = $cz0 + $dz;
                $key = $cx . ',' . $cz;
                if (!isset($c->sentChunks[$key]) && !isset($c->chunkQueue[$key])) {
                    $c->chunkQueue[$key] = [$cx, $cz];
                }
            }
        }
    }

    /** 每拍最多补 4 个，别一次推几百 KB 把线程堵住。 */
    private function flushChunkQueue(Connection $c): void
    {
        $n = 0;
        foreach ($c->chunkQueue as $key => [$cx, $cz]) {
            unset($c->chunkQueue[$key]);
            $this->sendChunkTo($c, $cx, $cz);
            if (++$n >= 8) {
                break;
            }
        }
    }

    private function sendTabAdd(Connection $to, Connection $who): void
    {
        $w = (new Writer())
            ->varInt(0)
            ->varInt(1)
            ->raw(hex2bin(str_replace('-', '', $who->uuid)))
            ->string($who->username);

        // 属性段：name, value, 是否有签名, 签名
        if ($who->textures !== null) {
            $w->varInt(1)
                ->string('textures')
                ->string($who->textures['value'])
                ->bool($who->textures['signature'] !== '');
            if ($who->textures['signature'] !== '') {
                $w->string($who->textures['signature']);
            }
        } else {
            $w->varInt(0);
        }

        $w->varInt($who->gamemode)->varInt(0)->bool(false);
        $to->send(self::C_PLAYER_LIST, $w);
    }

    private function sendTabRemove(Connection $to, Connection $who): void
    {
        $to->send(self::C_PLAYER_LIST, (new Writer())
            ->varInt(4)
            ->varInt(1)
            ->raw(hex2bin(str_replace('-', '', $who->uuid))));
    }

    private function sendSpawnPlayer(Connection $to, Connection $who): void
    {
        $to->send(self::C_SPAWN_PLAYER, (new Writer())
            ->varInt($who->entityId)
            ->raw(hex2bin(str_replace('-', '', $who->uuid)))
            ->int((int) floor($who->x * 32))
            ->int((int) floor($who->y * 32))
            ->int((int) floor($who->z * 32))
            ->byte(self::angle($who->yaw))
            ->byte(self::angle($who->pitch))
            ->short(0)
            ->unsignedByte(0x7F));
    }

    // ---------------- 游戏内 ----------------

    private function handlePlay(Connection $c, int $id, Reader $r): void
    {
        switch ($id) {
            case 0x00: // Keep Alive 回应
                $c->keepAliveId = 0;
                $r->readVarInt();
                break;

            case 0x01: // 聊天 / 命令
                $msg = $r->readString(100);
                if ($msg === '') {
                    break;
                }
                if ($msg[0] === '/') {
                    $this->command($c, $msg);
                } else {
                    $this->broadcastChat("<{$c->username}> {$msg}", 0);
                }
                break;

            case 0x03: // 只报 onGround
                $c->onGround = $r->readBool();
                break;

            case 0x04: // Player Position
                $x = $r->readDouble();
                $y = $r->readDouble();
                $z = $r->readDouble();
                $this->move($c, $x, $y, $z, null, null, $r->readBool());
                break;

            case 0x05: // Player Look
                $yaw = $r->readFloat();
                $pitch = $r->readFloat();
                $this->move($c, null, null, null, $yaw, $pitch, $r->readBool());
                break;

            case 0x06: // Player Position And Look
                $x = $r->readDouble();
                $y = $r->readDouble();
                $z = $r->readDouble();
                $yaw = $r->readFloat();
                $pitch = $r->readFloat();
                $this->move($c, $x, $y, $z, $yaw, $pitch, $r->readBool());
                break;

            case 0x07: // 挖方块 / 丢物品
                $status = $r->readUnsignedByte();
                [$x, $y, $z] = $this->unpackPosition($r->readLong());
                $r->readUnsignedByte(); // face
                if ($status === 3 || $status === 4) {
                    // Q 和 Ctrl+Q 在 1.8 里也是这个包，坐标是 0,0,0
                    $this->dropFromSlot($c, 36 + $c->heldSlot, $status === 3);
                    break;
                }
                $state = ($y >= 0 && $y <= 255)
                    ? $this->world->chunk($x >> 4, $z >> 4)->blockState($x & 15, $y, $z & 15)
                    : -1;
                $this->log("  挖 status={$status} @ {$x},{$y},{$z} 服务端方块={$state} 模式={$c->gamemode}");
                // 创造点一下就碎；生存里硬度 0 的方块（花、枯木、甘蔗、高草…）
                // 也是一碰就碎，不等 status=2。
                $instant = $status === 0
                    && in_array($state >> 4, self::INSTANT_BREAK, true);
                if (($c->gamemode === 1 && $status === 0)
                    || ($c->gamemode !== 1 && ($status === 2 || $instant))) {
                    if ($state === 0) {
                        // 客户端以为这里有方块，服务端这边是空气。
                        // 直接把这个「空气」打回去，客户端收到就会把幽灵方块擦掉。
                        $c->send(self::C_BLOCK_CHANGE, (new Writer())
                            ->raw($this->packPositionBytes($x, $y, $z))
                            ->varInt(0));
                    } else {
                        $this->breakBlock($c, $x, $y, $z);
                    }

                    // 不管碎没碎，都把「服务端认为这块是什么」打回去。
                    // 这样客户端要是画错了（比如它显示枯木、服务端其实是高草），
                    // 下一眼就会看到真身，而不是一直显示幽灵。
                    $now2 = $this->world->chunk($x >> 4, $z >> 4)
                        ->blockState($x & 15, $y, $z & 15);
                    $c->send(self::C_BLOCK_CHANGE, (new Writer())
                        ->raw($this->packPositionBytes($x, $y, $z))
                        ->varInt($now2));
                }
                break;

            case 0x08: // 放方块 / 使用方块
                [$x, $y, $z] = $this->unpackPosition($r->readLong());
                $face = $r->readUnsignedByte();

                // 坐标 (-1,-1) 表示「使用了手上的物品」（吃东西、喝药），
                // 不是对着方块操作。1.8 里吃食物就走这个包。
                if ($x === -1 && $z === -1) {
                    $useId = $r->readShort();
                    if ($useId > 0) {
                        $this->useItem($c, $useId);
                    }
                    break;
                }

                // 先看点到的是什么：箱子这类是「使用」，不是「放置」。
                // 必须放在「手上是不是方块」判断之前 —— 空手右键箱子时
                // itemId 是 -1，先判就永远走不到这里。
                $hitId = $this->world->chunk($x >> 4, $z >> 4)
                    ->blockState($x & 15, $y, $z & 15) >> 4;
                if ($hitId === 54) {
                    $this->openChest($c, $x, $y, $z);
                    break;
                }
                if ($hitId === 54 || ($hitId === 47 && $this->chests->has($x, $y, $z))) {
                    $this->openChest($c, $x, $y, $z);
                    break;
                }
                if ($hitId === 98) {
                    if ($this->hasFurnace($x, $y, $z)) {
                        $this->openFurnace($c, $x, $y, $z);
                        $this->log("  打开熔炉 {$x},{$y},{$z}");
                    } else {
                        $this->log("  石砖 @ {$x},{$y},{$z} 没有熔炉记录");
                        $this->sendChat($c, ['text' => '这块石砖没有熔炉记录（普通石砖）。放一个熔炉试试。', 'color' => 'gray'], 1);
                    }
                    break;
                }
                if ($hitId === 58) {
                    $this->openWorkbench($c);
                    break;
                }

                // 服务端这里是空气，客户端却对着它右键：说明客户端留了一块
                // 「幽灵方块」（放置被拒后客户端不会自己撤销预测）。如果旁边
                // 挨着真箱子，就把空气打回去擦掉幽灵，并打开那个真箱子 ——
                // 表现就是「上面的箱子点不开」。
                if ($hitId === 0 && $face <= 5) {
                    $near = $this->chestNextTo($x, $y, $z, $face);
                    if ($near !== null) {
                        $c->send(self::C_BLOCK_CHANGE, (new Writer())
                            ->raw($this->packPositionBytes($x, $y, $z))
                            ->varInt(0));
                        $this->log("  幽灵方块 @ {$x},{$y},{$z} -> 打开真箱子 {$near[0]},{$near[1]},{$near[2]}");
                        $this->openChest($c, $near[0], $near[1], $near[2]);
                        break;
                    }
                }

                $itemId = $r->readShort();
                if ($itemId === 383) {
                    // 刷怪蛋：damage 就是生物类型
                    $r->readByte();
                    $eggMeta = $r->readShort();
                    // 1.8 里刷怪蛋的 damage 直接就是生物 id（EntityList 的 id），
                    // 没有任何偏移。之前写成 -32，猪蛋(90) 就变成了末影人(58)。
                    $type = $eggMeta;
                    if (isset(self::MOB_TYPES[$type])) {
                        [$tx, $ty, $tz] = self::offsetByFace($x, $y, $z, $face);
                        $gy = $this->groundY($tx, $tz, $ty + 2);
                        $this->spawnMob($type, $tx + 0.5, (float) ($gy > 0 ? $gy : $ty), $tz + 0.5);
                        $this->log("  {$c->username} 用刷怪蛋生成了 " . self::MOB_TYPES[$type][0]);
                    }
                    break;
                }
                if ($itemId <= 0) {
                    break; // 手上不是方块
                }
                // 1.8 有一部分物品 id 和方块 id 不是同一个数（甘蔗、种子、作物…），
                // 直接拿物品 id 当方块 id 会让服务端拒绝放置。
                $blockId = $itemId <= 255 ? $itemId : (self::PLACEABLE_ITEM[$itemId] ?? 0);
                if ($blockId <= 0) {
                    break; // 这个物品不能当方块放
                }
                $r->readByte();               // count
                $damage = $r->readShort();     // 方块 meta 藏在 damage 里

                [$tx, $ty, $tz] = self::offsetByFace($x, $y, $z, $face);
                if (!$this->canPlaceOn($blockId, $tx, $ty, $tz)) {
                    // 客户端已经先画上去了，把服务端的真实状态打回去让它回弹
                    $now = $this->world->chunk($tx >> 4, $tz >> 4)
                        ->blockState($tx & 15, $ty, $tz & 15);
                    $c->send(self::C_BLOCK_CHANGE, (new Writer())
                        ->raw($this->packPositionBytes($tx, $ty, $tz))
                        ->varInt($now));
                    $this->log("  放 方块{$blockId} @ {$tx},{$ty},{$tz} 被拒：这个位置不能种");
                    break;
                }
                $this->log("  放 方块{$blockId} @ {$tx},{$ty},{$tz}（物品 {$itemId}，点的 {$x},{$y},{$z} 面 {$face}）");
                // 箱子(54)不再真的放进世界：客户端把它设成 BlockContainer 方块
                // 会在 1.8.9 上抛 NPE，方块状态永远落不了地。
                // 改成放书架(47) + 在 ChestStore 里留一条记录，右键照样开箱。
                if ($blockId === 61) {
                    $this->changeBlock($tx, $ty, $tz, 98, 0);
                    $this->ensureFurnace($tx, $ty, $tz);
                    $blockId = 98;
                } elseif ($blockId === 54) {
                    $this->changeBlock($tx, $ty, $tz, 47, 0);
                    $this->chests->ensure($tx, $ty, $tz);
                    $blockId = 47;
                } else {
                    $this->changeBlock($tx, $ty, $tz, $blockId, $this->placeMeta($c, $blockId, $damage & 0x0F));
                }

                // 放置成功要扣手里的方块。少了这一步：客户端本地把 13 减成 12，
                // 下一次整窗重发又推回服务端的 13 —— 玩家看到的就是
                // 「消耗完的东西又回来了，放的方块还不消失」。
                $placed = ($this->world->chunk($tx >> 4, $tz >> 4)
                    ->blockState($tx & 15, $ty, $tz & 15) >> 4) === $blockId;
                if ($placed && $c->gamemode !== 1) {
                    $heldSlot = 36 + $c->heldSlot;
                    $held = $c->inventory[$heldSlot] ?? null;
                    if ($held !== null && $held['id'] === $itemId) {
                        if ($held['count'] > 1) {
                            $held['count']--;
                            $c->inventory[$heldSlot] = $held;
                            $this->sendInventorySlot($c, $heldSlot, $held);
                        } else {
                            unset($c->inventory[$heldSlot]);
                            $this->sendInventorySlot($c, $heldSlot, null);
                        }
                    }
                }
                break;

            case 0x10: // 创造模式从背包拿东西
                $slot = $r->readShort();
                $start = $r->mark();
                $itemId = $r->readShort();     // -1 表示清空
                $count = $itemId >= 0 ? $r->readUnsignedByte() : 0;
                $damage = $itemId >= 0 ? $r->readShort() : 0;
                $raw = $r->sliceFrom($start);  // 连 NBT 一起原样回传
                if ($slot >= 0 && $slot <= 44) {
                    if ($itemId < 0) {
                        unset($c->inventory[$slot]);
                    } else {
                        $c->inventory[$slot] = ['id' => $itemId, 'count' => $count, 'damage' => $damage];
                    }
                    // 必须回一个 Set Slot，不然客户端刚拿到手又把方块收回去
                    $c->send(self::C_SET_SLOT, (new Writer())
                        ->byte(0)
                        ->short($slot)
                        ->raw($raw));
                }
                break;

            case 0x09: // 切换手上的快捷栏格
                $c->heldSlot = max(0, min(8, $r->readShort()));
                break;

            case 0x0A: // 挥手：广播给别人，不然对面看不到你在打
                $packet = (new Writer())
                    ->varInt($c->entityId)
                    ->byte(0);            // 0 = 挥主手
                foreach ($this->players() as $p) {
                    if ($p !== $c) {
                        $p->send(self::C_ANIMATION, $packet);
                    }
                }
                break;

            case 0x02: // Use Entity（攻击 / 交互）
                $targetEid = $r->readVarInt();
                $action = $r->readByte();
                if ($action === 1) {          // 1 = 攻击
                    // 先看是不是打到了生物
                    if ($this->damageMob($targetEid, 1, $c)) {
                        $this->addExhaustion($c, 0.3);
                        break;
                    }
                    foreach ($this->players() as $other) {
                        if ($other !== $c && $other->entityId === $targetEid) {
                            $this->damage($other, 1);            // 空手 1 点
                            $this->addExhaustion($c, 0.3);       // 原版：每次攻击 0.3
                            break;
                        }
                    }
                }
                break;

            case 0x0E: // Click Window
                // 1.8 顺序：windowId, slot, button, action(short), mode, 手上物品
                $windowId = $r->readByte();
                $slot = $r->readShort();
                $button = $r->readByte();
                $action = $r->readShort();
                $mode = $r->readByte();
                $cursor = $this->readSlotItem($r);
                if ($mode !== 5 && $c->dragPhase !== 0) {
                    $this->resetDrag($c);
                }
                if ($mode === 5) {
                    // 拖动开始/经过/结束，slot=-999 也要交给 handleDrag
                    $this->handleDrag($c, $windowId, $slot, $button);
                } elseif ($windowId === 2) {
                    $this->clickWorkbench($c, $slot, $button, $mode, $cursor);
                } elseif ($windowId === 0 && $mode === 4) {
                    if ($c->cursor !== null) {
                        $this->dropCursorItem($c, $button === 1);
                    } else {
                        $this->dropFromSlot($c, $slot, $button === 1);
                    }
                } elseif ($windowId === 0) {
                    $this->clickInventory($c, $slot, $button, $mode, $cursor);
                } elseif ($windowId === 3) {
                    $this->clickFurnace($c, $slot, $button, $mode, $cursor);
                } elseif ($windowId === 1) {
                    $this->clickChest($c, $slot, $button, $mode, $cursor);
                }
                // 每次真正改变槽位后整窗覆盖一次，客户端本地预测错了也会被纠正，
                // 不会出现「Shift 出一堆」「合成完又消失」这种分叉。
                if (($mode === 5 && ($button & 3) === 2) || $mode !== 5) {
                    $this->sendFullWindow($c);
                    $this->sendCursor($c);
                }
                $c->send(self::C_CONFIRM_TRANSACTION, (new Writer())
                    ->byte($windowId)->short($action)->bool(true));
                break;

            case 0x0D: // Close Window
                $windowId = $r->readByte();
                $this->resetDrag($c);
                if ($windowId === 3) {
                    $this->closeFurnace($c);
                } elseif ($windowId === 2) {
                    $this->closeWorkbench($c);
                } else {
                    $this->closeChest($c);
                    $this->returnCraftGrid($c);
                }
                $this->returnCursor($c);
                $this->sendPlayerInventory($c);
                break;

            case 0x13: // 客户端报能力（比如开始飞）
                $flags = $r->readUnsignedByte();
                $c->flying = ($flags & 0x02) !== 0;
                break;

            case 0x15: // Client Settings
                $r->readString(16);
                break;

            default:
                break;
        }
    }

    /**
     * 按玩家朝向算放置 meta（原版 onBlockPlacedBy 的逻辑）。
     * 之前一律用物品的 damage 当 meta，所以原木、楼梯、熔炉全朝一个方向。
     */
    private function placeMeta(Connection $c, int $blockId, int $itemMeta): int
    {
        // 原木：按视线定轴（竖着 0 / 沿 X 是 4 / 沿 Z 是 8）
        if ($blockId === 17) {
            if ($c->pitch > 45.0 || $c->pitch < -45.0) {
                return 0;
            }
            $yaw = deg2rad($c->yaw);

            return abs(sin($yaw)) > abs(cos($yaw)) ? 4 : 8;
        }

        // 朝向类方块：i = 0 南 / 1 西 / 2 北 / 3 东
        $i = ((int) floor(fmod($c->yaw, 360.0) / 90.0 + 0.5)) & 3;
        // 楼梯：面朝玩家（南3 西4 北2 东5 → 取反）
        $stairs = [2 => 5, 3 => 4, 4 => 2, 5 => 3];
        $stairIds = [53, 67, 108, 109, 114, 128, 134, 135, 136, 156, 163, 164, 180, 203];

        if (in_array($blockId, $stairIds, true)) {
            // 原版楼梯 meta = 朝向对面 + 是否倒置(0x4)
            $facing = [3, 4, 2, 5][$i];

            return ($stairs[$facing] ?? 2) & 0x3;
        }

        return $itemMeta;
    }

    private function changeBlock(int $x, int $y, int $z, int $id, int $meta): void
    {
        if ($y < 0 || $y > 255) {
            return;
        }
        $chunk = $this->world->chunk($x >> 4, $z >> 4);
        $existing = $chunk->blockState($x & 15, $y, $z & 15);
        if ($id !== 0 && $existing !== 0 && !self::isReplaceable($existing >> 4)) {
            // 客户端已经先把方块画上去了。这里必须把服务端的真实方块打回去，
            // 否则客户端会一直留一块幽灵方块，右键它只会继续发放置包。
            $this->broadcastBlockChange($x, $y, $z, $existing);
            $this->log("  放 方块{$id} @ {$x},{$y},{$z} 被拒：目标被方块" . ($existing >> 4) . ' 占着');

            return; // 目标被实心方块占着，放不进去
        }
        if ($existing !== 0) {
            $this->log("  替换方块 {$existing} @ {$x},{$y},{$z}");
        }
        if (!$chunk->setBlock($x & 15, $y, $z & 15, $id, $meta)) {
            $this->broadcastBlockChange($x, $y, $z, $existing);

            return;
        }

        // 双生花是两格高：放下半格时自动补上半格，跟原版一样
        if ($id === 175 && ($meta & 0x8) === 0 && $y < 255) {
            if ($chunk->setBlock($x & 15, $y + 1, $z & 15, 175, $meta | 0x8)) {
                $this->broadcastBlockChange($x, $y + 1, $z, (175 << 4) | ($meta | 0x8));
            }
        }

        $this->broadcastBlockChange($x, $y, $z, (($id & 0xFF) << 4) | ($meta & 0x0F));
        $this->markSection($x, $y, $z);

        if ($id === 54) {
            // 新放的箱子要立刻补瓦片实体，否则客户端只有方块、画不出箱子。
            $this->broadcastTileEntity($x, $y, $z);
            // 挨着的箱子也要重发一次，客户端才会重新算双箱子模型。
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dz]) {
                $nx = $x + $dx;
                $nz = $z + $dz;
                if (($this->world->chunk($nx >> 4, $nz >> 4)
                    ->blockState($nx & 15, $y, $nz & 15) >> 4) === 54) {
                    $this->broadcastTileEntity($nx, $y, $nz);
                }
            }
        }
    }

    /**
     * 原版的支撑检查：种植物只能种在对应的方块上。
     * 甘蔗：下面的方块是草/泥土/沙子，且四邻有水；已经在甘蔗上可以继续叠。
     * 地狱疣：灵魂沙。小麦/胡萝卜/马铃薯：耕地。
     */
    private function canPlaceOn(int $id, int $x, int $y, int $z): bool
    {
        if ($y <= 0 || $y > 255) {
            return false;
        }
        $below = $this->world->chunk($x >> 4, $z >> 4)
            ->blockState($x & 15, $y - 1, $z & 15) >> 4;

        if ($id === 83) {
            if ($below === 83) {
                return true;
            }
            if (!in_array($below, [2, 3, 12], true)) {
                return false;
            }
            foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$dx, $dz]) {
                $wx = $x + $dx;
                $wz = $z + $dz;
                $water = $this->world->chunk($wx >> 4, $wz >> 4)
                    ->blockState($wx & 15, $y - 1, $wz & 15) >> 4;
                if ($water === 8 || $water === 9) {
                    return true;
                }
            }

            return false;
        }
        if ($id === 115) {                 // 地狱疣 -> 灵魂沙
            return $below === 88;
        }
        if ($id === 59 || $id === 141 || $id === 142) {  // 作物 -> 耕地
            return $below === 60;
        }

        return true;
    }

    /** 草、花、雪层这些原版可以直接盖掉的方块 */
    private static function isReplaceable(int $id): bool
    {
        return in_array($id, [31, 32, 37, 38, 39, 40, 51, 78, 106, 111, 175], true);
    }

    /** 打碎方块：广播 + 掉东西 */
    private function breakBlock(Connection $c, int $x, int $y, int $z): void
    {
        if ($y < 0 || $y > 255) {
            $this->log("  挖掘被拒：y={$y} 越界");

            return;
        }
        $chunk = $this->world->chunk($x >> 4, $z >> 4);
        $state = $chunk->blockState($x & 15, $y, $z & 15);
        if ($state === 0) {
            $this->log("  挖掘被拒：({$x},{$y},{$z}) 服务端这边已经是空气");

            return;
        }
        $id = $state >> 4;
        $meta = $state & 0x0F;
        if ($id === 7) {
            return; // 基岩挖不动
        }

        if ($id === 175) {
            // 双生花是两格高：meta 0-7 是下半，8-15 是上半。
            // 打任意一半都要把另一半一起清掉，否则会剩半截悬空。
            $lower = ($meta & 0x8) !== 0 ? $y - 1 : $y;
            $upper = $lower + 1;
            foreach ([$lower, $upper] as $yy) {
                if ($chunk->blockState($x & 15, $yy, $z & 15) >> 4 === 175) {
                    $chunk->setBlock($x & 15, $yy, $z & 15, 0, 0);
                    $this->broadcastBlockChange($x, $yy, $z, 0);
                }
            }
            [$dropId, $count] = [175, 1];
            if ($c->gamemode !== 1) {
                $this->spawnDrop($x, $lower, $z, $dropId, $count, $meta & 0x7, $c);
            }

            return;
        }

        if ($id === 47 && $this->chests->has($x, $y, $z)) {
            // 书架容器：内容和箱子一样掉出来
            foreach ($this->chests->load($x, $y, $z) as $item) {
                if ($item !== null && $item['count'] > 0) {
                    $this->spawnDrop($x, $y, $z, $item['id'], $item['count'], $item['damage'], $c);
                }
            }
            $this->chests->remove($x, $y, $z);
        }

        if ($id === 54) {
            // 原版 BlockChest.breakBlock：内容全掉出来，再删掉 TileEntity。
            // 双箱子只掉被打掉的那半边，另一半保留。
            $this->breakChestBlock($c, $x, $y, $z);
        }

        $this->addExhaustion($c, 0.005);   // 挖一块 0.005（原版数值）

        // 工具磨损：不扣的话服务端那份永远是满耐久，
        // 一打开容器整窗覆盖，客户端就会「耐久忽然回满」。
        if ($c->gamemode !== 1) {
            $heldSlot = 36 + $c->heldSlot;
            $held = $c->inventory[$heldSlot] ?? null;
            if ($held !== null && isset(self::TOOL_MAX[$held['id']])) {
                $held['damage'] = ($held['damage'] ?? 0) + 1;
                if ($held['damage'] >= self::TOOL_MAX[$held['id']]) {
                    unset($c->inventory[$heldSlot]);
                    $this->sendInventorySlot($c, $heldSlot, null);
                } else {
                    $c->inventory[$heldSlot] = $held;
                    $this->sendInventorySlot($c, $heldSlot, $held);
                }
            }
        }

        if (!$chunk->setBlock($x & 15, $y, $z & 15, 0, 0)) {
            return;
        }
        $this->broadcastBlockChange($x, $y, $z, 0);
        $this->markSection($x, $y, $z);

        // 创造模式不掉落（原版行为）
        if ($c->gamemode === 1) {
            if (! $c->hintedCreativeDrop) {
                $c->hintedCreativeDrop = true;
                $this->sendChat($c, [
                    'text' => '创造模式不掉落（原版行为）。想测掉落用 /gm 0 切生存。',
                    'color' => 'gray',
                ], 1);
            }

            return;
        }

        [$dropId, $count] = self::dropOf($id, $meta);
        if ($dropId > 0 && $count > 0) {
            $this->spawnDrop($x, $y, $z, $dropId, $count, $dropId === $id ? $meta : 0, $c);
        }
        foreach (self::extraDrops($id, $meta) as [$extraId, $extraCount]) {
            $this->spawnDrop($x, $y, $z, $extraId, $extraCount, 0, $c);
        }
    }

    /**
     * 1.8 没有单独的光照包。方块一变，客户端那片光就停在旧数据上，
     * 于是挖开的洞是黑的。唯一的办法是把这一段重新发一遍（带新光照）。
     */
    private function markSection(int $x, int $y, int $z): void
    {
        // 什么都不做。发段更新会把我们这份「存档里的旧光照」盖到客户端上，
        // 客户端本来算对的地方反而被压暗 —— 玩家反馈「挖完反而变黑」就是它。
        // 光照要真修，得在服务端跑正儿八经的光照传播（见 README 的待办）。
    }

    /**
     * 把改过的段补发一次（groundUp=false）。
     * 1.8 没有单独的光照包，客户端也不会自己重算天空光，
     * 所以挖开的洞要么全黑、要么靠这一步把新光照送过去。
     */
    private function flushSections(): void
    {
        // 停用开关：这段代码现在没有任何调用点，留着是为了将来做真正的
        // 光照传播时接着用。谁要重新启用，先读 markSection 上面那段注释 ——
        // 拿存档里的旧光照覆盖客户端，会让玩家「挖完反而变黑」。
        if (!self::ENABLE_SECTION_RESEND) {
            return;
        }

        if ($this->dirtySections === []) {
            return;
        }
        $now = microtime(true);
        $sent = 0;
        foreach ($this->dirtySections as $key => [$cx, $cz, $sy]) {
            if (($this->lastSectionPush[$key] ?? 0.0) > $now - 0.12) {
                continue;
            }
            unset($this->dirtySections[$key]);
            $this->lastSectionPush[$key] = $now;

            $data = $this->world->chunk($cx, $cz)->sectionPayload($sy);
            if ($data === null) {
                continue;
            }
            $packet = (new Writer())
                ->int($cx)
                ->int($cz)
                ->bool(false)                // 只更新这一段
                ->short(1 << $sy)
                ->varInt(strlen($data))
                ->raw($data);
            foreach ($this->players() as $p) {
                $p->send(self::C_CHUNK_DATA, $packet);
            }
            if (++$sent >= 4) {
                break;
            }
        }
    }

    private function broadcastBlockChange(int $x, int $y, int $z, int $state): void
    {
        $packet = (new Writer())
            ->raw($this->packPositionBytes($x, $y, $z))
            ->varInt($state);
        foreach ($this->players() as $p) {
            $p->send(self::C_BLOCK_CHANGE, $packet);
        }
    }

    private function move(
        Connection $c,
        ?float $x,
        ?float $y,
        ?float $z,
        ?float $yaw,
        ?float $pitch,
        bool $onGround
    ): void {
        $lastChunkX = $c->x / 16;
        $lastChunkZ = $c->z / 16;
        $moved = false;
        if ($x !== null && ($x !== $c->x || $y !== $c->y || $z !== $c->z)) {
            $c->x = $x;
            $c->y = (float) $y;
            $c->z = $z;
            $moved = true;
        }
        if ($yaw !== null) {
            $c->yaw = $yaw;
            $c->pitch = (float) $pitch;
        }

        // 跑动和跳跃的饱食度消耗（原版：跑 0.1/格，跳 0.05，冲刺跳 0.2）
        if ($moved) {
            $dist = sqrt(
                ($c->x - $c->lastX) ** 2 + ($c->z - $c->lastZ) ** 2
            );
            if ($dist > 0.02) {
                $this->addExhaustion($c, $dist * 0.1);
            }
            if ($onGround === false && $c->y > $c->lastY + 0.15) {
                $this->addExhaustion($c, 0.05);
            }
            $c->lastX = $c->x;
            $c->lastY = $c->y;
            $c->lastZ = $c->z;
        }

        // 掉落伤害：离地时记住高度，落地时按 (高度差 - 3) 算。
        // 原版 EntityPlayer.fall() 是 ceil(距离 - 3)，创造模式不摔伤。
        if (! $onGround && $moved) {
            $c->fallFrom = $c->fallFrom < 0 ? $c->y : max($c->fallFrom, $c->y);
        } elseif ($onGround && $c->fallFrom >= 0) {
            $fall = $c->fallFrom - $c->y;
            $c->fallFrom = -1.0;

            // 落水里/岩浆里不摔伤（原版：水里、网里、草堆上都不算摔落）
            $chunk = $this->world->chunk(((int) floor($c->x)) >> 4, ((int) floor($c->z)) >> 4);
            $feet = $chunk->blockState(((int) floor($c->x)) & 15, (int) floor($c->y), ((int) floor($c->z)) & 15) >> 4;
            $inWater = in_array($feet, [8, 9, 10, 11], true);

            if ($fall > 3.0 && !$inWater) {
                $this->damage($c, (int) ceil($fall - 3.0));
            }
        }

        $c->onGround = $onGround;

        // 换了区块就把视野里缺的补上，不然走远了前面就是虚空
        $oldKey = ((int) floor($lastChunkX)) . ',' . ((int) floor($lastChunkZ));
        $newKey = (((int) floor($c->x)) >> 4) . ',' . (((int) floor($c->z)) >> 4);
        if ($oldKey !== $newKey) {
            $this->queueChunks($c);
        }

        foreach ($this->players() as $other) {
            if ($other === $c) {
                continue;
            }
            if ($moved) {
                $other->send(self::C_ENTITY_TELEPORT, (new Writer())
                    ->varInt($c->entityId)
                    ->int((int) floor($c->x * 32))
                    ->int((int) floor($c->y * 32))
                    ->int((int) floor($c->z * 32))
                    ->byte(self::angle($c->yaw))
                    ->byte(self::angle($c->pitch))
                    ->bool($c->onGround));
            } elseif ($yaw !== null) {
                $other->send(self::C_ENTITY_LOOK, (new Writer())
                    ->varInt($c->entityId)
                    ->byte(self::angle($c->yaw))
                    ->byte(self::angle($c->pitch))
                    ->bool($c->onGround));
            }
            if ($yaw !== null) {
                $other->send(self::C_HEAD_LOOK, (new Writer())
                    ->varInt($c->entityId)
                    ->byte(self::angle($c->yaw)));
            }
        }
    }

    // ---------------- 掉落物 ----------------

    /** 打碎方块掉什么（1.8 掉落表里常用的那些） */
    /**
     * 硬度 0 的方块：一碰就碎。
     * 原版客户端对这类方块是「按下即完成」，有时只发 status=0 不发 2，
     * 所以服务端不能死等 status=2 —— 收到 0 就直接碎。
     */
    private const INSTANT_BREAK = [
        6,   // 树苗
        31,  // 高草
        32,  // 枯木
        37,  // 蒲公英
        38,  // 玫瑰
        39,  // 棕蘑菇
        40,  // 红蘑菇
        50,  // 火把
        51,  // 火
        59,  // 作物
        78,  // 雪层
        83,  // 甘蔗
        106, // 藤蔓
        111, // 睡莲
        115, // 地狱疣
        141, // 胡萝卜
        142, // 马铃薯
        175, // 双生花
    ];

    /** 物品 id => 方块 id（1.8 里这几样物品 id 与方块 id 不同） */
    private const PLACEABLE_ITEM = [
        295 => 59,   // 小麦种子 -> 作物
        338 => 83,   // 甘蔗 -> 芦苇
        372 => 115,  // 地狱疣
        391 => 141,  // 胡萝卜
        392 => 142,  // 马铃薯
    ];

    /**
     * 方块 id => [掉落物 id, 数量]。没列出来的默认掉自己（大多数建筑方块如此）。
     * 概率类的（树叶、砂砾、枯木、高草）单独在下面处理。
     */
    private const DROPS = [
        1 => [4, 1],        // 石头 -> 圆石
        2 => [3, 1],        // 草方块 -> 土
        12 => [12, 1],      // 沙子
        13 => [13, 1],      // 砂砾（原版 10% 燧石，先按砂砾）
        14 => [14, 1],      // 金矿
        15 => [15, 1],      // 铁矿
        16 => [16, 1],      // 煤矿
        21 => [351, 4],     // 青金石矿 -> 4 个青金石
        24 => [24, 1],      // 砂岩
        30 => [287, 1],     // 蜘蛛网 -> 线
        60 => [3, 1],       // 耕地 -> 土
        73 => [331, 4],     // 红石矿 -> 4 个红石
        78 => [332, 1],     // 雪层 -> 雪球
        80 => [80, 1],      // 雪块
        82 => [337, 4],     // 黏土 -> 4 个黏土球
        83 => [338, 1],     // 甘蔗（方块 83，物品 338）
        86 => [86, 1],      // 南瓜
        89 => [89, 1],      // 萤石
        91 => [91, 1],      // 南瓜灯
        103 => [360, 4],    // 西瓜 -> 4 片
        110 => [3, 1],      // 菌丝 -> 土
        115 => [115, 1],    // 地狱疣
        121 => [121, 1],    // 末地石
        129 => [129, 1],    // 绿宝石矿
        155 => [155, 1],    // 石英矿
        159 => [159, 1],    // 染色黏土
        161 => [161, 1],    // 深色树叶（概率在下面）
        170 => [170, 1],    // 干草块
        172 => [172, 1],    // 硬化黏土
        174 => [174, 1],    // 浮冰
        179 => [179, 1],    // 红砂岩
    ];

    /** 打碎后什么都不掉的（水、岩浆、玻璃、冰、刷怪箱…） */
    private const NO_DROP = [7, 8, 9, 10, 11, 20, 26, 34, 36, 51, 52, 55, 63, 64, 68, 71,
        75, 79, 90, 93, 94, 95, 96, 102, 119, 120, 122, 132, 140, 144, 149, 150, 160,
        166, 167, 176, 177];

    /**
     * 1.8 里「有方块、但没有对应 Item」的 id。
     * 把这些 id 当物品发给客户端，Item.getItemById() 返回 null，
     * 客户端塞进背包就 NullPointerException（日志里成片出现）。
     */
    private const NO_ITEM = [
        26, 34, 36, 51, 55, 59, 63, 64, 68, 71, 75, 93, 94,
        104, 105, 132, 143, 144, 149, 150, 193, 194, 195, 196, 197,
    ];

    private static function hasItem(int $id): bool
    {
        return $id > 0 && $id <= 450 && !in_array($id, self::NO_ITEM, true);
    }

    /** 工具 id => 最大耐久。挖方块时扣 1，挖穿了就消失。 */
    private const TOOL_MAX = [
        // 木
        268 => 59, 269 => 59, 270 => 59, 271 => 59, 290 => 59,
        // 石
        272 => 131, 273 => 131, 274 => 131, 275 => 131, 291 => 131,
        // 铁
        256 => 250, 257 => 250, 258 => 250, 259 => 250, 292 => 250,
        // 钻石
        276 => 1561, 277 => 1561, 278 => 1561, 279 => 1561, 293 => 1561,
        // 金
        283 => 32, 284 => 32, 285 => 32, 286 => 32, 294 => 32,
    ];

    private static function dropOf(int $id, int $meta): array
    {
        // 作物：方块 id 和物品 id 不同，而且要看 meta（age）决定掉什么
        if ($id === 59) {
            // 小麦：成熟掉小麦，没成熟掉种子；成熟的种子由 extraDrops 补
            return $meta >= 7 ? [296, 1] : [295, 1];
        }
        if ($id === 115) {
            // 地狱疣：成熟 2~4 个，没成熟 1 个
            return [372, $meta >= 3 ? 2 + random_int(0, 2) : 1];
        }
        if ($id === 141) {
            return [391, 1];   // 胡萝卜
        }
        if ($id === 142) {
            return [392, 1];   // 马铃薯
        }

        // 概率类的先处理
        if ($id === 18 || $id === 161) {
            // 原版：5% 树苗，0.5% 苹果（只有橡树叶掉苹果）
            if ($id === 18 && mt_rand(1, 200) === 1) {
                return [260, 1];
            }

            return mt_rand(1, 20) === 1 ? [6, 1] : [0, 0];
        }
        if ($id === 31) {
            // 31 是「草类」，靠 meta 区分外观：
            //   meta 0 = 棕色枯枝（就是玩家眼里的「枯死的灌木」）→ 什么都不掉
            //   meta 1 = 绿色草丛、meta 2 = 蕨 → 12.5% 掉小麦种子
            // 之前不看 meta，把枯枝也当草丛，所以「打枯木掉种子」。
            if (($meta & 0x3) === 0) {
                return [0, 0];
            }

            return mt_rand(1, 8) === 1 ? [295, 1] : [0, 0];
        }
        if ($id === 32) {                          // 枯木：0~2 根木棍
            return [280, random_int(0, 2)];
        }
        if ($id === 13) {                          // 砂砾：10% 燧石
            return mt_rand(1, 10) === 1 ? [318, 1] : [13, 1];
        }
        if (in_array($id, self::NO_DROP, true)) {
            return [0, 0];
        }
        if (isset(self::DROPS[$id])) {
            return self::DROPS[$id];
        }

        if (!self::hasItem($id)) {
            return [0, 0];   // 没物品形态的方块，掉了也只会让客户端崩
        }

        return [$id, 1];     // 大多数建筑方块掉自己
    }

    /**
     * 主掉落之外额外掉的东西（和原版 getItemDropped / dropBlockAsItemWithChance 对齐）：
     * 成熟小麦额外掉 0~3 个种子，成熟马铃薯 2% 掉毒马铃薯。
     *
     * @return array<int, array{0:int,1:int}> [物品 id, 数量]
     */
    private static function extraDrops(int $id, int $meta): array
    {
        if ($id === 59 && $meta >= 7) {
            // 原版：for i in 0..2，nextInt(15) <= i 时掉一个种子
            $seeds = 0;
            for ($i = 0; $i < 3; $i++) {
                if (random_int(0, 14) <= $i) {
                    $seeds++;
                }
            }

            return $seeds > 0 ? [[295, $seeds]] : [];
        }
        if ($id === 142 && $meta >= 7 && random_int(1, 50) === 1) {
            return [[394, 1]];
        }

        return [];
    }

    /**
     * 方块打碎掉出来的东西。
     * 直接复用 throwItem 那条路 —— 「扔出来的」客户端能画出来，「挖出来的」
     * 画不出来，两边原本是两段几乎一样的发包代码，那就别留两份。
     */
    private function spawnDrop(int $x, int $y, int $z, int $id, int $count, int $meta, ?Connection $owner): void
    {
        // 原版：spawnEntityInWorld(new EntityItem(world, x + 0.5, y + 0.5, z + 0.5, stack))
        $px = $x + 0.5;
        $py = $y + 0.5;
        $pz = $z + 0.5;

        $this->throwItem(
            $px,
            $py,
            $pz,
            $id,
            $count,
            $meta,
            random_int(-900, 900),
            1600,
            random_int(-900, 900),
            0.5
        );

        $this->log(sprintf(
            '  掉落 %dx 方块%d @ %d,%d,%d -> 物品 %0.1f,%0.1f,%0.1f',
            $count,
            $id,
            $x,
            $y,
            $z,
            $px,
            $py,
            $pz
        ));
    }

    private function processDrops(): void
    {
        if ($this->drops === []) {
            return;
        }
        $now = microtime(true);
        foreach ($this->drops as $eid => $d) {
            // 简易物理：按 20 次/秒推进，往下掉、碰到方块就停。
            // 不模拟的话，服务器只知道东西「出生在哪」，刚扔出去就会被判成
            // 还在脚边，于是立刻又被捡回来。
            if (isset($d['vx'])) {
                $frames = (int) (($now - $d['step']) / 0.05);
                if ($frames > 0) {
                    $frames = min($frames, 5);
                    $dead = false;
                    for ($f = 0; $f < $frames; $f++) {
                        // 阻力 + 重力
                        $d['vx'] = (int) ($d['vx'] * 0.98);
                        $d['vy'] = (int) (($d['vy'] - 320) * 0.98);   // 原版 this.w -= 0.04f，0.04*8000=320
                        $d['vz'] = (int) ($d['vz'] * 0.98);

                        $mx = $d['vx'] / 8000;
                        $my = $d['vy'] / 8000;
                        $mz = $d['vz'] / 8000;

                        // 一帧最多挪 0.4 格，再远就拆成几小步。
                        // 不拆的话，一步从地面上方跨到地下空洞里，中间的方块被跳过 ——
                        // 东西就这么穿地掉下去了（日志里 89.1 → 78.5 就是这么来的）。
                        $far = max(abs($mx), abs($my), abs($mz));
                        $sub = max(1, (int) ceil($far / 0.4));
                        $hit = false;
                        for ($s = 0; $s < $sub; $s++) {
                            $nx = $d['x'] + $mx / $sub;
                            $ny = $d['y'] + $my / $sub;
                            $nz = $d['z'] + $mz / $sub;
                            if ($this->solidAt($nx, $ny - 0.02, $nz)) {
                                $hit = true;
                                break;
                            }
                            $d['x'] = $nx;
                            $d['y'] = $ny;
                            $d['z'] = $nz;
                        }
                        if ($hit) {
                            $d['vx'] = $d['vy'] = $d['vz'] = 0;
                            break;
                        }
                        if ($d['y'] < -5) {
                            $dead = true;
                            break;
                        }
                    }
                    if ($dead) {
                        unset($this->drops[$eid]);
                        continue;
                    }
                    $d['step'] = $now;
                    $this->drops[$eid] = $d;
                }
            }

            // 服务端的简易物理和客户端的碰撞不完全一样，定期把真实位置打回去，
            // 否则客户端画在 A、服务端在 B，站得很近也捡不到。
            if (!isset($d['lastSync']) || $now - $d['lastSync'] >= 0.5) {
                foreach ($this->players() as $p) {
                    $p->send(self::C_ENTITY_TELEPORT, (new Writer())
                        ->varInt($eid)
                        ->int((int) ($d['x'] * 32))
                        ->int((int) ($d['y'] * 32))
                        ->int((int) ($d['z'] * 32))
                        ->byte(0)->byte(0)->bool(true));
                }
                $d['lastSync'] = $now;
                $this->drops[$eid] = $d;
            }

            if ($now < $d['at']) {
                continue;
            }

            // 没人要就消失，别在地上堆一辈子
            if ($now > $d['expire']) {
                foreach ($this->players() as $p) {
                    $p->send(self::C_DESTROY_ENTITIES, (new Writer())
                        ->varInt(1)->varInt($eid));
                }
                unset($this->drops[$eid]);
                continue;
            }

            // 原版是「走过去才捡」，不是掉出来就自动吸 —— 离得远就留在原地
            $picker = $this->nearestPlayer($d['x'], $d['y'], $d['z'], 1.8);
            if ($picker === null) {
                // 只记一次，免得刷屏；用来判断到底是「太远」还是「压根没跑到这」
                if (!isset($d['logged'])) {
                    $best = null;
                    $bestD = PHP_FLOAT_MAX;
                    foreach ($this->players() as $p) {
                        $dd = sqrt(
                            ($p->x - $d['x']) ** 2 + (($p->y + 0.9) - $d['y']) ** 2 + ($p->z - $d['z']) ** 2
                        );
                        if ($dd < $bestD) {
                            $bestD = $dd;
                            $best = $p;
                        }
                    }
                    $this->log(sprintf(
                        '  没捡到 eid=%d @ %.1f,%.1f,%.1f | 玩家 %s @ %.1f,%.1f,%.1f dx=%.2f dy=%.2f dz=%.2f',
                        $eid,
                        $d['x'],
                        $d['y'],
                        $d['z'],
                        $best?->username ?? '-',
                        $best?->x ?? 0,
                        $best?->y ?? 0,
                        $best?->z ?? 0,
                        $best === null ? 0 : $best->x - $d['x'],
                        $best === null ? 0 : ($best->y + 0.9) - $d['y'],
                        $best === null ? 0 : $best->z - $d['z']
                    ));
                    $this->drops[$eid]['logged'] = true;
                }
                continue;
            }

            if (!$this->canGive($picker, $d['id'], $d['count'], $d['meta'])) {
                if (!isset($d['loggedFull'])) {
                    $this->log("  背包满，掉落 eid={$eid} 留在地上");
                    $this->drops[$eid]['loggedFull'] = true;
                }
                continue;
            }

            foreach ($this->players() as $p) {
                $p->send(self::C_COLLECT_ITEM, (new Writer())
                    ->varInt($eid)
                    ->varInt($picker->entityId));
                // 有的客户端收到 Collect Item 也不会删实体，补一次销毁
                $p->send(self::C_DESTROY_ENTITIES, (new Writer())
                    ->varInt(1)->varInt($eid));
            }
            $this->giveItem($picker, $d['id'], $d['count'], $d['meta']);
            $this->log(sprintf(
                '  捡到 eid=%d 方块%d x%d -> %s',
                $eid,
                $d['id'],
                $d['count'],
                $picker->username
            ));
            unset($this->drops[$eid]);
        }
    }

    /** 从某个槽位丢东西：$all=true 是 Ctrl+Q 丢一组 */
    private function dropFromSlot(Connection $c, int $slot, bool $all): void
    {
        $it = $c->inventory[$slot] ?? null;
        if ($it === null) {
            return;
        }
        $n = $all ? $it['count'] : 1;
        $left = $it['count'] - $n;
        if ($left <= 0) {
            unset($c->inventory[$slot]);
            $c->send(self::C_SET_SLOT, (new Writer())
                ->byte(0)->short($slot)->short(-1));
        } else {
            $it['count'] = $left;
            $c->inventory[$slot] = $it;
            $this->sendSlot($c, $slot, $it);
        }

        $this->toss($c, $it['id'], $n, $it['damage']);
    }

    /** 朝视线方向扔出一个物品实体，客户端靠 Spawn Object 里的速度画抛物线 */
    private function toss(Connection $c, int $id, int $count, int $damage): void
    {
        $yaw = deg2rad($c->yaw);
        $pitch = deg2rad($c->pitch);
        $dx = -sin($yaw) * cos($pitch);
        $dy = -sin($pitch);
        $dz = cos($yaw) * cos($pitch);
        $px = $c->x + $dx * 0.3;
        $py = $c->y + 1.3 + $dy * 0.3;
        $pz = $c->z + $dz * 0.3;

        $this->throwItem($px, $py, $pz, $id, $count, $damage,
            (int) ($dx * 2400), (int) ($dy * 2400 + 600), (int) ($dz * 2400));
    }

    /** 背包界面里 Q 键：光标上拿着东西时从光标丢 */
    private function dropCursorItem(Connection $c, bool $all): void
    {
        if ($c->cursor === null) {
            return;
        }
        $it = $c->cursor;
        $n = $all ? $it['count'] : 1;
        $left = $it['count'] - $n;
        $c->cursor = $left > 0
            ? ['id' => $it['id'], 'count' => $left, 'damage' => $it['damage']]
            : null;
        $this->sendCursor($c);
        $this->toss($c, $it['id'], $n, $it['damage']);
    }

    /** 给某一个玩家补发一条掉落实体（进服时用，带服务端当前速度）。 */
    private function sendDropSpawn(Connection $p, int $eid, array $d): void
    {
        if (($d['id'] ?? 0) <= 0 || ($d['count'] ?? 0) <= 0) {
            return;
        }

        $p->send(self::C_SPAWN_OBJECT, (new Writer())
            ->varInt($eid)
            ->byte(2)
            ->int((int) ($d['x'] * 32))
            ->int((int) ($d['y'] * 32))
            ->int((int) ($d['z'] * 32))
            ->byte(0)->byte(0)
            ->int(1)
            ->short((int) ($d['vx'] ?? 0))
            ->short((int) ($d['vy'] ?? 0))
            ->short((int) ($d['vz'] ?? 0)));
        $p->send(self::C_ENTITY_METADATA, (new Writer())
            ->varInt($eid)
            ->byte((5 << 5) | 10)
            ->short($d['id'])->byte($d['count'])->short($d['meta'])->byte(0)
            ->unsignedByte(0x7F));
    }

    /** 在指定位置放一个物品实体，速度和坐标都由调用方给。 */
    private function throwItem(
        float $px,
        float $py,
        float $pz,
        int $id,
        int $count,
        int $meta,
        int $vx,
        int $vy,
        int $vz,
        float $delay = 2.0
    ): void {
        // 兜底：id 非法或数量为 0 的物品实体，客户端拿到会渲染空栈 → NPE 崩溃
        // （报错是「实体 null 导致崩溃」）。宁可不生成，也不能让客户端炸。
        if (!self::hasItem($id) || $count <= 0) {
            return;
        }
        $count = min(64, $count);

        $eid = $this->nextEntityId++;

        $n = 0;
        foreach ($this->players() as $p) {
            $n++;
            $p->send(self::C_SPAWN_OBJECT, (new Writer())
                ->varInt($eid)
                ->byte(2)
                ->int((int) ($px * 32))
                ->int((int) ($py * 32))
                ->int((int) ($pz * 32))
                ->byte(0)->byte(0)
                // 1.8 的 Spawn Object 只有在 data>0 时才带速度三个 short。
                // data=0 会让客户端把物品钉在出生点，之后服务端自己算的抛物线
                // 客户端完全看不到，所以捡东西时位置对不上。
                ->int(1)
                ->short($vx)->short($vy)->short($vz));
            $p->send(self::C_ENTITY_METADATA, (new Writer())
                ->varInt($eid)
                ->byte((5 << 5) | 10)
                ->short($id)->byte($count)->short($meta)->byte(0)
                ->unsignedByte(0x7F));
        }

        $now = microtime(true);
        $this->drops[$eid] = [
            'id' => $id,
            'count' => $count,
            'meta' => $meta,
            'x' => $px,
            'y' => $py,
            'z' => $pz,
            'vx' => $vx,
            'vy' => $vy,
            'vz' => $vz,
            'step' => $now,
            'at' => $now + $delay,
            'expire' => $now + 30,
        ];
        $this->log("  丢出 {$count}x 方块{$id} eid={$eid} @ " . round($px, 1) . ',' . round($py, 1) . ',' . round($pz, 1) . " 发给了 {$n} 人");
    }

    private function solidAt(float $x, float $y, float $z): bool
    {
        if ($y < 0 || $y > 255) {
            return false;
        }
        $bx = (int) floor($x);
        $by = (int) floor($y);
        $bz = (int) floor($z);

        return $this->world->chunk($bx >> 4, $bz >> 4)->blockState($bx & 15, $by, $bz & 15) !== 0;
    }

    private function nearestPlayer(float $x, float $y, float $z, float $radius): ?Connection
    {
        // 原版不是算球形距离，而是「玩家碰撞箱往外扩 (1.0, 0.5, 1.0) 再判相交」。
        // 玩家碰撞箱半宽 0.3、高 1.8，所以横向约 ±1.3、纵向从脚底 -0.6 到头顶 +2.0。
        // 用盒子比球更好捡，也更接近原版。
        $best = null;
        $bestDist = PHP_FLOAT_MAX;
        foreach ($this->players() as $p) {
            if (abs($p->x - $x) > 1.3 || abs($p->z - $z) > 1.3) {
                continue;
            }
            if ($y < $p->y - 0.6 || $y > $p->y + 2.0) {
                continue;
            }
            $dist = abs($p->x - $x) + abs($p->z - $z);
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best = $p;
            }
        }

        return $best;
    }

    /** 塞进背包：先堆叠，再找空位，每个变化的槽位回一个 Set Slot */
    private function giveItem(Connection $c, int $id, int $count, int $damage): void
    {
        $slots = array_merge(range(36, 44), range(9, 35));

        foreach ($slots as $slot) {
            $it = $c->inventory[$slot] ?? null;
            if ($it === null || $it['id'] !== $id || $it['damage'] !== $damage
                || $it['count'] >= self::maxStack($id)) {
                continue;
            }
            $add = min(self::maxStack($id) - $it['count'], $count);
            $it['count'] += $add;
            $count -= $add;
            $c->inventory[$slot] = $it;
            $this->sendInventorySlot($c, $slot, $it);
            if ($count <= 0) {
                return;
            }
        }

        foreach ($slots as $slot) {
            if (isset($c->inventory[$slot])) {
                continue;
            }
            $it = ['id' => $id, 'count' => min(self::maxStack($id), $count), 'damage' => $damage];
            $c->inventory[$slot] = $it;
            $count -= $it['count'];
            $this->sendInventorySlot($c, $slot, $it);
            if ($count <= 0) {
                return;
            }
        }
    }

    /** 背包里还能放多少个指定物品 */
    private function giveSpace(Connection $c, int $id, int $damage): int
    {
        $slots = array_merge(range(36, 44), range(9, 35));
        $space = 0;
        foreach ($slots as $slot) {
            $it = $c->inventory[$slot] ?? null;
            if ($it === null) {
                $space += self::maxStack($id);
            } elseif ($it['id'] === $id && $it['damage'] === $damage) {
                $space += max(0, self::maxStack($id) - $it['count']);
            }
        }

        return $space;
    }

    /** 背包里是否放得下这么多（用于合成输出/拾取，放不下就别消耗/别捡） */
    private function canGive(Connection $c, int $id, int $count, int $damage): bool
    {
        return $this->giveSpace($c, $id, $damage) >= $count;
    }

    private function sendSlot(Connection $c, int $slot, array $it): void
    {
        $c->send(self::C_SET_SLOT, (new Writer())
            ->byte(0)
            ->short($slot)
            ->short($it['id'])
            ->byte($it['count'])
            ->short($it['damage'])
            ->byte(0));
    }

    // ---------------- 命令 ----------------

    private function command(Connection $c, string $line): void
    {
        $parts = preg_split('/\s+/', trim($line)) ?: [];
        $cmd = strtolower(ltrim(array_shift($parts) ?? '', '/'));
        $arg = strtolower($parts[0] ?? '');

        switch ($cmd) {
            case 'help':
                $this->sendChat($c, ['text' => '可用命令：', 'color' => 'yellow'], 1);
                $this->sendChat($c, ['text' => '/gm <0|1|2|3>  切换模式（0 生存 / 1 创造）', 'color' => 'gray'], 1);
                $this->sendChat($c, ['text' => '/spawn  回到出生点', 'color' => 'gray'], 1);
                $this->sendChat($c, ['text' => '/who  看看谁在线', 'color' => 'gray'], 1);
                $this->sendChat($c, ['text' => '/block x y z  问服务器这个坐标是什么方块', 'color' => 'gray'], 1);
                $this->sendChat($c, ['text' => '/save  立刻存盘', 'color' => 'gray'], 1);
                $this->sendChat($c, ['text' => '/reload  丢掉内存缓存、按磁盘重发（验证存盘）', 'color' => 'gray'], 1);
                break;

            case 'gm':
            case 'gamemode':
                $gm = match ($arg) {
                    '0', 's', 'survival' => 0,
                    '1', 'c', 'creative' => 1,
                    '2', 'a', 'adventure' => 2,
                    '3', 'sp', 'spectator' => 3,
                    default => -1,
                };
                if ($gm < 0) {
                    $this->sendChat($c, ['text' => '用法：/gm 0|1|2|3', 'color' => 'red'], 1);
                    break;
                }
                $c->gamemode = $gm;
                $c->flying = false;
                $c->send(self::C_CHANGE_GAME_STATE, (new Writer())
                    ->unsignedByte(3)
                    ->float((float) $gm));
                $this->sendAbilities($c);
                foreach ($this->players() as $p) {
                    $this->sendTabAdd($p, $c);
                }
                $this->sendChat($c, [
                    'text' => '模式已切换：' . self::gameModeName($gm),
                    'color' => 'green',
                ], 1);
                break;

            case 'spawn':
                [$sx, $sy, $sz] = $this->world->spawnPoint();
                $c->x = $sx + 0.5;
                $c->y = (float) $sy;
                $c->z = $sz + 0.5;
                $c->send(self::C_POS_LOOK, (new Writer())
                    ->double($c->x)->double($c->y)->double($c->z)
                    ->float(0)->float(0)->byte(0));
                $this->sendChat($c, ['text' => '已回到出生点', 'color' => 'green'], 1);
                break;

            case 'block':
                if (count($parts) < 3) {
                    $this->sendChat($c, ['text' => '用法：/block x y z', 'color' => 'red'], 1);
                    break;
                }
                $bx = (int) $parts[0];
                $by = (int) $parts[1];
                $bz = (int) $parts[2];
                $state = $this->world->chunk($bx >> 4, $bz >> 4)
                    ->blockState($bx & 15, $by, $bz & 15);
                $this->sendChat($c, [
                    'text' => sprintf(
                        '服务端认为 (%d,%d,%d) = 方块 %d meta %d %s',
                        $bx,
                        $by,
                        $bz,
                        $state >> 4,
                        $state & 15,
                        $state === 0 ? '（空气）' : ''
                    ),
                    'color' => 'yellow',
                ], 1);
                break;

            case 'reload':
                $saved = $this->world->save();
                $n = $this->world->forgetChunks();
                foreach ($this->players() as $p) {
                    $p->sentChunks = [];
                    $p->chunkQueue = [];
                    $this->queueChunks($p);
                }
                $this->sendChat($c, [
                    'text' => "已存盘 {$saved} 个，丢掉内存 {$n} 个区块，正在按磁盘重发",
                    'color' => 'green',
                ], 1);
                $this->log("手动 reload：存盘 {$saved}，清缓存 {$n}");
                break;

            case 'spawn':
                // /spawn <名字或 id> [数量]
                // $parts 是 command() 上面已经切好的参数（命令名已被 shift 掉）
                $key = strtolower($parts[0] ?? '');
                $count = max(1, min(20, (int) ($parts[1] ?? 1)));
                $type = 0;
                if (ctype_digit($key)) {
                    $type = (int) $key;
                } else {
                    foreach (self::MOB_TYPES as $id => [$name, $hostile]) {
                        if ($name === $key) { $type = $id; break; }
                    }
                }
                if (!isset(self::MOB_TYPES[$type])) {
                    $names = [];
                    foreach (self::MOB_TYPES as $id => [$name, $hostile]) { $names[] = $name; }
                    $this->sendChat($c, ['text' => '用法：/spawn <' . implode('|', array_slice($names, 0, 6)) . '|...> [数量]', 'color' => 'yellow'], 1);
                    break;
                }
                $baseY = (int) floor($c->y);
                for ($i = 0; $i < $count; $i++) {
                    $px = (int) floor($c->x) + random_int(-4, 4);
                    $pz = (int) floor($c->z) + random_int(-4, 4);
                    $py = $this->groundY($px, $pz, $baseY + 4);
                    if ($py <= 0) { $py = $baseY; }
                    $this->spawnMob($type, $px + 0.5, (float) $py, $pz + 0.5);
                }
                $this->log("  {$c->username} 生成了 {$count} 只 " . self::MOB_TYPES[$type][0]);
                $this->sendChat($c, ['text' => "已生成 {$count} 只 " . self::MOB_TYPES[$type][0] . "（实体数 " . count($this->mobs) . '）', 'color' => 'green'], 1);
                break;

            case 'blocktest':
                // 最小化 A/B 测试：往你面前 2 格发一个方块，看 F3 认不认
                //   /blocktest stone   发石头(1<<4=16)
                //   /blocktest chest   发箱子(864) + 0x35
                $what = strtolower($parts[0] ?? 'stone');
                // 暂停「书架伪装」：否则 1.5 秒一轮的补发会把刚发过去的
                // 箱子状态又刷回书架，测试结果根本看不到。
                $c->chestSections = [];
                $c->chestSectionTimes = [];
                $bx2 = (int) floor($c->x + sin(deg2rad(-$c->yaw)) * 2);
                $bz2 = (int) floor($c->z + cos(deg2rad(-$c->yaw)) * 2);
                $by2 = (int) floor($c->y);
                $posHex = $this->packPositionBytes($bx2, $by2, $bz2);
                if ($what === 'chest' || $what === 'chest35') {
                    $st = 864;
                    $c->send(self::C_BLOCK_CHANGE, (new Writer())->raw($posHex)->varInt($st));
                    if ($what === 'chest35') {
                        // 单独验证 0x35 会不会也炸：chest 只发 0x23，chest35 两个都发
                        $this->sendTileEntity($c, $bx2, $by2, $bz2);
                        $msg = '发箱子 state=864 + 0x35';
                    } else {
                        $msg = '只发方块状态 state=864（不发 0x35）';
                    }
                } else {
                    $st = 1 << 4;
                    $c->send(self::C_BLOCK_CHANGE, (new Writer())->raw($posHex)->varInt($st));
                    $msg = "发石头 state=16";
                }
                $this->sendChat($c, ['text' => "{$msg} @ {$bx2},{$by2},{$bz2}——看 F3 的 Block 行", 'color' => 'aqua'], 1);
                $this->log("  /blocktest {$what} @ {$bx2},{$by2},{$bz2} state={$st}（伪装已暂停）");
                break;

            case 'timeset':
                // /timeset <0-23999>：0 早上、6000 中午、13000 日落、18000 午夜
                if ($arg === '' || !is_numeric($arg)) {
                    $this->sendChat($c, ['text' => '用法：/timeset <0-23999>（0 早、6000 中午、13000 日落、18000 午夜）', 'color' => 'yellow'], 1);
                    break;
                }
                $this->world->setTimeOfDay((int) $arg);
                $this->sendChat($c, ['text' => '时间已设为 ' . $this->world->timeOfDay() . '（' . ($this->isNight() ? '夜' : '昼') . '）', 'color' => 'aqua'], 1);
                $this->log("  {$c->username} /timeset " . (int) $arg);
                break;

            case 'kill':
                // /kill @e 杀光所有生物；/kill 只杀自己（受伤到 0）
                if (($parts[0] ?? '') === '@e') {
                    $n = count($this->mobs);
                    foreach (array_keys($this->mobs) as $eid) {
                        $this->removeMob($eid);
                    }
                    $this->sendChat($c, ['text' => "已清除 {$n} 只生物", 'color' => 'aqua'], 1);
                    $this->log("  {$c->username} /kill @e 清除 {$n} 只");
                } else {
                    $this->damage($c, 20);
                }
                break;

            case 'mobtest':
                // 调试：不管昼夜、不管群系，把 16 种生物在你面前排一排
                $baseY = (int) floor($c->y);
                $fx = $c->x + sin(deg2rad(-$c->yaw)) * 5;
                $fz = $c->z + cos(deg2rad(-$c->yaw)) * 5;
                $i = 0;
                $made = 0;
                foreach (self::MOB_TYPES as $id => [$name, $hostile]) {
                    $px = (int) floor($fx) + ($i % 4) * 2;
                    $pz = (int) floor($fz) + intdiv($i, 4) * 2;
                    $py = $this->groundY($px, $pz, $baseY + 4);
                    if ($py <= 0) { $py = $baseY; }
                    if ($this->spawnMob($id, $px + 0.5, (float) $py, $pz + 0.5) > 0) { $made++; }
                    $i++;
                }
                $this->log("  {$c->username} /mobtest 生成了 {$made} 只（当前共 " . count($this->mobs) . '）');
                $this->sendChat($c, ['text' => "调试：已生成 {$made} 只，站在你前方 5 格", 'color' => 'aqua'], 1);
                break;

            case 'hit':
                [$hx, $hy, $hz, $hid, $hmeta] = $this->raycast($c, 5.0);
                $this->sendChat($c, $hid === 0
                    ? ['text' => '视线 5 格内没有方块', 'color' => 'gray']
                    : [
                        'text' => sprintf(
                            '你正对着 (%d,%d,%d)：方块 id=%d meta=%d %s',
                            $hx,
                            $hy,
                            $hz,
                            $hid,
                            $hmeta,
                            $hmeta !== 0 ? '' : ''
                        ),
                        'color' => 'yellow',
                    ], 1);
                $this->log("  {$c->username} /hit -> {$hx},{$hy},{$hz} id={$hid} meta={$hmeta}");
                break;

            case 'inv':
                $list = [];
                foreach ($c->inventory as $slot => $item) {
                    $list[] = $slot . ':' . $item['id'] . 'x' . $item['count'];
                }
                $this->sendChat($c, [
                    'text' => '服务端记录的背包：' . ($list === [] ? '空' : implode(', ', $list)),
                    'color' => 'yellow',
                ], 1);
                break;

            case 'save':
                $n = $this->world->save();
                $this->sendChat($c, [
                    'text' => "已存盘 {$n} 个区块",
                    'color' => 'green',
                ], 1);
                $this->log("手动存盘 {$n} 个区块");
                break;

            case 'who':
                $names = array_map(static fn (Connection $p) => $p->username, $this->players());
                $this->sendChat($c, ['text' => '在线：' . implode(', ', $names), 'color' => 'yellow'], 1);
                break;

            default:
                $this->sendChat($c, ['text' => "未知命令 /{$cmd}，试试 /help", 'color' => 'red'], 1);
        }
    }

    // ---------------- 杂项 ----------------

    /** 食物 id => 恢复的饱食度（1.8 的常见食物） */
    private const FOOD = [
        260 => 4,   // 苹果
        297 => 5,   // 面包
        322 => 4,   // 金苹果
        349 => 2,   // 生鱼
        350 => 5,   // 熟鱼
        360 => 2,   // 西瓜片
        363 => 8,   // 牛排
        364 => 8,   // 熟猪排
        365 => 4,   // 生鸡肉
        366 => 6,   // 熟鸡肉
        391 => 2,   // 胡萝卜
        392 => 6,   // 烤马铃薯
        393 => 2,   // 马铃薯
        400 => 8,   // 南瓜派
    ];

    /** 右键食物：吃掉一个、回饱食度和血。 */
    private function useItem(Connection $c, int $itemId): void
    {
        $food = self::FOOD[$itemId] ?? 0;
        if ($food <= 0) {
            return;
        }

        // 饱食度满了就不能吃（原版行为）：不扣物品、不放动画
        if ($c->food >= 20) {
            return;
        }

        // 一口 1.6 秒。没有这个，客户端一直发「使用物品」就会连吃，
        // 看起来就像「饱食度自动回满」。
        $now = microtime(true);
        if ($now < $c->nextEatAt) {
            return;
        }
        $c->nextEatAt = $now + 1.6;

        $slot = 36 + $c->heldSlot;
        $it = $c->inventory[$slot] ?? null;
        if ($it === null || $it['id'] !== $itemId) {
            return;
        }

        if ($it['count'] > 1) {
            $it['count']--;
            $c->inventory[$slot] = $it;
            $this->sendSlot($c, $slot, $it);
        } else {
            unset($c->inventory[$slot]);
            $c->send(self::C_SET_SLOT, (new Writer())->byte(0)->short($slot)->short(-1));
        }

        $c->food = min(20, $c->food + $food);
        $c->health = min(20.0, $c->health + 1.0);
        $c->send(self::C_UPDATE_HEALTH, (new Writer())
            ->float($c->health)->varInt($c->food)->float(5.0));
        $this->log("  {$c->username} 吃了方块{$itemId}，饱食度 {$c->food}，血 {$c->health}");
    }

    /** 加疲劳，攒够 4.0 掉 1 点饱食度并推血量包。 */
    private function addExhaustion(Connection $c, float $amount): void
    {
        if ($c->gamemode === 1) {
            return;
        }
        $c->exhaustion += $amount;
        while ($c->exhaustion >= 4.0 && $c->food > 0) {
            $c->exhaustion -= 4.0;
            $c->food--;
            $c->send(self::C_UPDATE_HEALTH, (new Writer())
                ->float($c->health)->varInt($c->food)->float(0.0));
            $this->log("  {$c->username} 饱食度降到 {$c->food}");
        }
    }

    /**
     * 从玩家的眼睛沿着视线走，最多 reach 格，返回第一个非空气方块。
     * 用来回答「你正对着的到底是什么」——不靠截图猜。
     *
     * @return array{0:int,1:int,2:int,3:int,4:int} [x, y, z, id, meta]
     */
    private function raycast(Connection $c, float $reach): array
    {
        $yaw = deg2rad($c->yaw);
        $pitch = deg2rad($c->pitch);
        $ex = $c->x;
        $ey = $c->y + 1.62;          // 眼睛高度
        $ez = $c->z;
        $dx = -sin($yaw) * cos($pitch);
        $dy = -sin($pitch);
        $dz = cos($yaw) * cos($pitch);

        for ($s = 0.0; $s <= $reach; $s += 0.05) {
            $bx = (int) floor($ex + $dx * $s);
            $by = (int) floor($ey + $dy * $s);
            $bz = (int) floor($ez + $dz * $s);
            if ($by < 0 || $by > 255) {
                continue;
            }
            $state = $this->world->chunk($bx >> 4, $bz >> 4)
                ->blockState($bx & 15, $by, $bz & 15);
            // 注意只看 id：空气的 meta 也可能非 0（state=1），不能当成方块
            if (($state >> 4) !== 0) {
                return [$bx, $by, $bz, $state >> 4, $state & 15];
            }
        }

        return [0, 0, 0, 0, 0];
    }

    private function sendHealth(Connection $c): void
    {
        $c->send(self::C_UPDATE_HEALTH, (new Writer())
            ->float($c->health)
            ->varInt($c->food)
            ->float(5.0));
    }

    /** 发送窗口里的一个槽位（null = 空槽） */
    private function sendWindowSlot(Connection $c, int $windowId, int $slot, ?array $item): void
    {
        $c->send(self::C_SET_SLOT, (new Writer())
            ->byte($windowId)->short($slot)->raw($this->slotBytes($item)));
    }

    /**
     * 同步服务端记录的光标物品。
     * 1.8 里光标不是「当前窗口的 -1 号槽」：Set Slot 必须用
     * windowId = -1、slot = -1。用窗口 ID 发，客户端会走容器分支去取
     * get(-1)，直接 IndexOutOfBounds，背包/箱子/工作台全报错。
     */
    private function sendCursor(Connection $c): void
    {
        // 停用。1.8.9 客户端收到 windowId=-1 / slot=-1 的 Set Slot 时会走
        // Container.getSlot(-1) → IndexOutOfBoundsException（日志里每次进服、
        // 每次点击都有一条，length 45/46/63 分别对应背包/工作台/箱子）。
        // 异常发生在主线程的 packet 批里，同一批后面的包直接不处理 ——
        // 箱子的 0x35 就是这么被吞的。
        // 光标由客户端自己维护，服务端不纠正它也不会崩，最多偶尔不同步。
    }

    /** 按当前打开的窗口，把玩家背包槽位换算成容器槽位再发 Set Slot */
    private function sendInventorySlot(Connection $c, int $invSlot, ?array $item): void
    {
        if ($c->chest !== null) {
            $size = $c->chest['size'] ?? 27;
            $slot = $invSlot <= 35 ? $size + ($invSlot - 9) : $size + 27 + ($invSlot - 36);
            $this->sendWindowSlot($c, 1, $slot, $item);

            return;
        }
        if ($c->workbench) {
            $slot = $invSlot <= 35 ? 10 + ($invSlot - 9) : 37 + ($invSlot - 36);
            $this->sendWindowSlot($c, 2, $slot, $item);

            return;
        }
        $this->sendWindowSlot($c, 0, $invSlot, $item);
    }

    /** 关窗/掉线时把光标上的东西塞回背包，别吞掉 */
    private function returnCursor(Connection $c): void
    {
        if ($c->cursor === null) {
            return;
        }
        $this->giveItem($c, $c->cursor['id'], $c->cursor['count'], $c->cursor['damage']);
        $c->cursor = null;
        $this->sendCursor($c);
    }

    /** 物品最大堆叠数：工具/武器/盔甲 1，雪球/鸡蛋/末影珍珠 16，其余 64 */
    private static function maxStack(int $id): int
    {
        if (in_array($id, [
            256, 257, 258, 259, 261,
            267, 268, 269, 270, 271, 272, 273, 274, 275, 276, 277, 278, 279,
            283, 284, 285, 286, 290, 291, 292, 293, 294,
            298, 299, 300, 301, 302, 303, 304, 305, 306, 307, 308, 309,
            310, 311, 312, 313, 314, 315, 316, 317,
            346, 354, 355, 358, 359, 373,
        ], true)) {
            return 1;
        }
        if (in_array($id, [332, 344, 368], true)) {
            return 16;
        }

        return 64;
    }

    /**
     * 左键/右键点击的核心：根据当前槽位和服务端光标算新的 [槽位, 光标]。
     * Click Window 包里带的是客户端预测后的光标，不能直接当槽位内容用。
     *
     * @return array{0:?array,1:?array} [新槽位物品, 新光标物品]
     */
    private static function clickedStacks(?array $cur, ?array $cursor, int $button): array
    {
        if ($cursor === null) {
            if ($cur === null) {
                return [null, null];
            }
            if ($button === 0) {
                return [null, $cur];                 // 拿起整堆
            }
            $half = (int) ceil($cur['count'] / 2);
            $rest = $cur['count'] - $half;
            $slot = $rest > 0 ? ['id' => $cur['id'], 'count' => $rest, 'damage' => $cur['damage']] : null;

            return [$slot, ['id' => $cur['id'], 'count' => $half, 'damage' => $cur['damage']]];
        }

        if ($cur === null) {
            if ($button === 0) {
                return [$cursor, null];              // 放下整堆
            }
            $left = $cursor['count'] - 1;
            $slot = ['id' => $cursor['id'], 'count' => 1, 'damage' => $cursor['damage']];
            $hand = $left > 0 ? ['id' => $cursor['id'], 'count' => $left, 'damage' => $cursor['damage']] : null;

            return [$slot, $hand];
        }

        if ($cur['id'] === $cursor['id'] && $cur['damage'] === $cursor['damage']) {
            if ($button === 0) {
                $total = $cur['count'] + $cursor['count'];
                $take = min(self::maxStack($cur['id']), $total);
                $left = $total - $take;
                $slot = ['id' => $cur['id'], 'count' => $take, 'damage' => $cur['damage']];
                $hand = $left > 0 ? ['id' => $cursor['id'], 'count' => $left, 'damage' => $cursor['damage']] : null;

                return [$slot, $hand];
            }
            if ($cur['count'] < self::maxStack($cur['id'])) {
                $left = $cursor['count'] - 1;
                $slot = ['id' => $cur['id'], 'count' => $cur['count'] + 1, 'damage' => $cur['damage']];
                $hand = $left > 0 ? ['id' => $cursor['id'], 'count' => $left, 'damage' => $cursor['damage']] : null;

                return [$slot, $hand];
            }

            return [$cur, $cursor];
        }

        return $button === 0 ? [$cursor, $cur] : [$cur, $cursor];
    }

    /** Shift 搬运：先合并同类，再找空位，把变化过的槽位都发回客户端 */
    private function shiftStacks(Connection $c, int $from, array $stack, array $range): void
    {
        $remaining = $stack;
        $changed = [];

        for ($i = $range[0]; $i <= $range[1] && $remaining['count'] > 0; $i++) {
            $it = $c->inventory[$i] ?? null;
            if ($it === null || $it['id'] !== $remaining['id']
                || $it['damage'] !== $remaining['damage']
                || $it['count'] >= self::maxStack($remaining['id'])) {
                continue;
            }
            $add = min(self::maxStack($remaining['id']) - $it['count'], $remaining['count']);
            $it['count'] += $add;
            $remaining['count'] -= $add;
            $c->inventory[$i] = $it;
            $changed[$i] = $it;
        }
        if ($remaining['count'] > 0) {
            for ($i = $range[0]; $i <= $range[1]; $i++) {
                if (isset($c->inventory[$i])) {
                    continue;
                }
                $c->inventory[$i] = $remaining;
                $changed[$i] = $remaining;
                $remaining = null;
                break;
            }
        }
        if ($remaining === null) {
            unset($c->inventory[$from]);
            $changed[$from] = null;
        } else {
            $c->inventory[$from] = $remaining;
            if ($remaining['count'] !== $stack['count']) {
                $changed[$from] = $remaining;
            }
        }

        foreach ($changed as $i => $it) {
            $this->sendWindowSlot($c, 0, $i, $it);
        }
    }


    /**
     * Shift+点击背包物品时快速填入合成格（2x2 或 3x3）。
     * 优先放整堆到第一个空格，其次合并到同类格；返回实际移入的数量。
     */
    private function quickFillGrid(Connection $c, int $windowId, array $stack): int
    {
        $limit = $windowId === 2 ? 9 : 4;
        $grid = $windowId === 2 ? $c->craft9 : $c->craftGrid;
        $moved = 0;

        for ($i = 0; $i < $limit; $i++) {
            if ($grid[$i] !== null) {
                continue;
            }
            $grid[$i] = $stack;
            $moved = $stack['count'];
            break;
        }
        if ($moved === 0) {
            for ($i = 0; $i < $limit; $i++) {
                $it = $grid[$i];
                if ($it === null || $it['id'] !== $stack['id'] || $it['damage'] !== $stack['damage']) {
                    continue;
                }
                $add = min(self::maxStack($stack['id']) - $it['count'], $stack['count']);
                if ($add <= 0) {
                    continue;
                }
                $it['count'] += $add;
                $grid[$i] = $it;
                $moved = $add;
                break;
            }
        }
        if ($moved <= 0) {
            return 0;
        }

        if ($windowId === 2) {
            $c->craft9 = $grid;
        } else {
            $c->craftGrid = $grid;
        }

        return $moved;
    }

    /** 1.8 拖动（mode 5）：开始/经过/结束时才真正分发 */
    private function handleDrag(Connection $c, int $windowId, int $slot, int $button): void
    {
        $phase = $button & 3;
        $mouse = ($button >> 2) & 3;
        $this->log("  拖动 window={$windowId} slot={$slot} button={$button} phase={$phase} cursor=" . json_encode($c->cursor));

        if ($phase === 0) {
            if ($slot !== -999 || $c->cursor === null) {
                $this->resetDrag($c);

                return;
            }
            $c->dragPhase = 1;
            $c->dragButton = $mouse;
            $c->dragSlots = [];

            return;
        }

        if ($phase === 1) {
            if ($c->cursor === null) {
                $this->resetDrag($c);

                return;
            }
            if ($c->dragPhase === 0) {
                // 有些客户端会先发经过槽位，补一个开始
                $c->dragPhase = 1;
                $c->dragButton = $mouse;
                $c->dragSlots = [];
            }
            if (!$this->dragSlotAllowed($c, $windowId, $slot)) {
                return;
            }
            $cur = $this->dragGetSlot($c, $windowId, $slot);
            if ($cur !== null && ($cur['id'] !== $c->cursor['id']
                || $cur['damage'] !== $c->cursor['damage']
                || $cur['count'] >= self::maxStack($cur['id']))) {
                return;
            }
            $c->dragSlots[$slot] = $slot;

            return;
        }

        if ($phase === 2) {
            if ($c->dragPhase === 1) {
                $this->applyDrag($c, $windowId);
            }
            $this->resetDrag($c);
        }
    }

    private function resetDrag(Connection $c): void
    {
        $c->dragPhase = 0;
        $c->dragButton = 0;
        $c->dragSlots = [];
    }

    private function dragSlotAllowed(Connection $c, int $windowId, int $slot): bool
    {
        if ($windowId === 2) {
            return ($slot >= 1 && $slot <= 9) || ($slot >= 10 && $slot <= 45);
        }
        if ($windowId === 1) {
            $size = $c->chest['size'] ?? 27;

            return $slot >= 0 && $slot <= $size + 35;
        }

        return ($slot >= 1 && $slot <= 4) || ($slot >= 9 && $slot <= 44);
    }

    private function dragGetSlot(Connection $c, int $windowId, int $slot): ?array
    {
        if ($windowId === 2) {
            if ($slot >= 1 && $slot <= 9) {
                return $c->craft9[$slot - 1];
            }
            $inv = $slot <= 36 ? 9 + ($slot - 10) : 36 + ($slot - 37);

            return $c->inventory[$inv] ?? null;
        }
        if ($windowId === 1) {
            return $this->windowSlots($c)[$slot];
        }
        if ($slot >= 1 && $slot <= 4) {
            return $c->craftGrid[$slot - 1];
        }

        return $c->inventory[$slot] ?? null;
    }

    private function dragSetSlot(Connection $c, int $windowId, int $slot, ?array $item): void
    {
        if ($windowId === 2) {
            if ($slot >= 1 && $slot <= 9) {
                $c->craft9[$slot - 1] = $item;

                return;
            }
            $inv = $slot <= 36 ? 9 + ($slot - 10) : 36 + ($slot - 37);
            if ($item === null) {
                unset($c->inventory[$inv]);
            } else {
                $c->inventory[$inv] = $item;
            }

            return;
        }
        if ($windowId === 1) {
            $this->setWindowSlot($c, $slot, $item);

            return;
        }
        if ($slot >= 1 && $slot <= 4) {
            $c->craftGrid[$slot - 1] = $item;

            return;
        }
        if ($item === null) {
            unset($c->inventory[$slot]);
        } else {
            $c->inventory[$slot] = $item;
        }
    }

    /** 结束拖动时把光标物品按左拖平均/右拖每格一个分发 */
    private function applyDrag(Connection $c, int $windowId): void
    {
        $slots = array_values($c->dragSlots);
        if ($slots === [] || $c->cursor === null) {
            return;
        }

        $remaining = $c->cursor;
        $limit = self::maxStack($remaining['id']);
        $changed = [];
        $gridChanged = false;

        if ($c->dragButton === 0) {
            $n = count($slots);
            $per = intdiv($remaining['count'], $n);
            foreach ($slots as $slot) {
                if ($remaining['count'] <= 0) {
                    break;
                }
                $cur = $this->dragGetSlot($c, $windowId, $slot);
                $have = $cur !== null ? $cur['count'] : 0;
                if ($cur !== null && ($cur['id'] !== $remaining['id']
                    || $cur['damage'] !== $remaining['damage'])) {
                    continue;
                }
                $add = min($per, $limit - $have, $remaining['count']);
                if ($add <= 0) {
                    continue;
                }
                $next = $cur !== null
                    ? ['id' => $cur['id'], 'count' => $have + $add, 'damage' => $cur['damage']]
                    : ['id' => $remaining['id'], 'count' => $add, 'damage' => $remaining['damage']];
                $this->dragSetSlot($c, $windowId, $slot, $next);
                $changed[$slot] = $next;
                $remaining['count'] -= $add;
                if (($windowId === 2 && $slot >= 1 && $slot <= 9)
                    || ($windowId === 0 && $slot >= 1 && $slot <= 4)) {
                    $gridChanged = true;
                }
            }
        } else {
            foreach ($slots as $slot) {
                if ($remaining['count'] <= 0) {
                    break;
                }
                $cur = $this->dragGetSlot($c, $windowId, $slot);
                $have = $cur !== null ? $cur['count'] : 0;
                if ($cur !== null && ($cur['id'] !== $remaining['id']
                    || $cur['damage'] !== $remaining['damage'] || $have >= $limit)) {
                    continue;
                }
                $next = $cur !== null
                    ? ['id' => $cur['id'], 'count' => $have + 1, 'damage' => $cur['damage']]
                    : ['id' => $remaining['id'], 'count' => 1, 'damage' => $remaining['damage']];
                $this->dragSetSlot($c, $windowId, $slot, $next);
                $changed[$slot] = $next;
                $remaining['count']--;
                if (($windowId === 2 && $slot >= 1 && $slot <= 9)
                    || ($windowId === 0 && $slot >= 1 && $slot <= 4)) {
                    $gridChanged = true;
                }
            }
        }

        $c->cursor = $remaining['count'] > 0 ? $remaining : null;
        foreach ($changed as $slot => $item) {
            $this->sendWindowSlot($c, $windowId, $slot, $item);
        }
        if ($gridChanged) {
            if ($windowId === 2) {
                $this->updateWorkbenchOutput($c);
            } else {
                $this->updateCraftOutput($c);
            }
        }
        $this->sendCursor($c);
    }

    /**
     * 玩家自己背包里的点击（windowId 0）。
     * 注意：包里带的是客户端预测后的光标物品，服务端要用自己记录的
     * $c->cursor 和当前槽位重新算，否则「拿起来」会被当成「放回去」，
     * 客户端就会看到东西变双份。
     */
    private function clickInventory(Connection $c, int $slot, int $button, int $mode, ?array $cursor): void
    {
        // 2x2 合成格的 4 个槽位
        if ($slot >= 1 && $slot <= 4) {
            $i = $slot - 1;
            if ($mode === 1) {
                $it = $c->craftGrid[$i];
                if ($it !== null) {
                    $c->craftGrid[$i] = null;
                    $this->sendWindowSlot($c, 0, $slot, null);
                    $this->giveItem($c, $it['id'], $it['count'], $it['damage']);
                    $this->updateCraftOutput($c);
                    $this->sendCursor($c);
                }

                return;
            }
            if ($mode !== 0 || ($button !== 0 && $button !== 1)) {
                $this->sendCursor($c);

                return;
            }
            [$next, $newCursor] = self::clickedStacks($c->craftGrid[$i], $c->cursor, $button);
            $c->craftGrid[$i] = $next;
            $c->cursor = $newCursor;
            $this->sendWindowSlot($c, 0, $slot, $next);
            $this->updateCraftOutput($c);
            $this->sendCursor($c);

            return;
        }
        if ($slot === 0) {
            $this->takeCraftOutput($c, $mode === 1);

            return;
        }

        if ($slot < 9 || $slot > 44) {
            $this->sendCursor($c);   // 盔甲格先不管，至少把光标同步回来

            return;
        }

        $cur = $c->inventory[$slot] ?? null;

        if ($mode === 1 && $cur !== null) {
            // Shift+点击背包物品：优先快速填入 2x2 合成格
            $moved = $this->quickFillGrid($c, 0, $cur);
            if ($moved > 0) {
                $left = $cur['count'] - $moved;
                if ($left > 0) {
                    $cur['count'] = $left;
                    $c->inventory[$slot] = $cur;
                } else {
                    unset($c->inventory[$slot]);
                }
                $this->updateCraftOutput($c);
                $this->sendCursor($c);

                return;
            }

            $range = $slot >= 36 ? [9, 35] : [36, 44];
            $this->shiftStacks($c, $slot, $cur, $range);
            $this->sendCursor($c);

            return;
        }
        if ($mode !== 0 || ($button !== 0 && $button !== 1)) {
            $this->sendCursor($c);

            return;
        }

        [$next, $newCursor] = self::clickedStacks($cur, $c->cursor, $button);
        $c->cursor = $newCursor;
        if ($next === null) {
            unset($c->inventory[$slot]);
        } else {
            $c->inventory[$slot] = $next;
        }
        $this->sendWindowSlot($c, 0, $slot, $next);
        $this->sendCursor($c);
    }

    /** 把 45 格背包推给客户端（windowId 0 = 玩家自己的背包） */
    private function sendPlayerInventory(Connection $c): void
    {
        $w = (new Writer())->byte(0)->short(45);
        // 0 输出，1-4 2x2 合成格，5-8 盔甲，9-44 背包
        $w->raw($this->slotBytes(self::matchCraft2x2($c->craftGrid)));
        for ($i = 0; $i < 4; $i++) {
            $w->raw($this->slotBytes($c->craftGrid[$i]));
        }
        for ($i = 5; $i <= 8; $i++) {
            $w->raw($this->slotBytes($c->inventory[$i] ?? null));
        }
        for ($i = 9; $i < 45; $i++) {
            $w->raw($this->slotBytes($c->inventory[$i] ?? null));
        }
        $c->send(self::C_WINDOW_ITEMS, $w);
    }

    private function sendWorkbenchItems(Connection $c): void
    {
        $w = (new Writer())->byte(2)->short(46);
        // 0 输出，1-9 3x3 合成格，10-36 主背包，37-45 快捷栏
        $w->raw($this->slotBytes(self::matchCraft3x3($c->craft9)));
        for ($i = 0; $i < 9; $i++) {
            $w->raw($this->slotBytes($c->craft9[$i]));
        }
        for ($i = 9; $i <= 35; $i++) {
            $w->raw($this->slotBytes($c->inventory[$i] ?? null));
        }
        for ($i = 36; $i <= 44; $i++) {
            $w->raw($this->slotBytes($c->inventory[$i] ?? null));
        }
        $c->send(self::C_WINDOW_ITEMS, $w);
    }

    private function sendChestItems(Connection $c): void
    {
        $size = $c->chest['size'] ?? 27;
        $w = (new Writer())->byte(1)->short($size + 36);
        foreach ($this->windowSlots($c) as $item) {
            $w->raw($this->slotBytes($item));
        }
        $c->send(self::C_WINDOW_ITEMS, $w);
    }

    /** 用服务端状态整窗覆盖客户端，避免客户端本地预测和背包状态分叉 */
    private function sendFullWindow(Connection $c): void
    {
        if ($c->chest !== null) {
            $this->sendChestItems($c);

            return;
        }
        if ($c->workbench) {
            $this->sendWorkbenchItems($c);

            return;
        }
        $this->sendPlayerInventory($c);
    }

    /** 掉血；掉到 0 就回出生点（不做死亡界面，先把机制跑通）。 */
    private function damage(Connection $c, int $dmg): void
    {
        if ($dmg <= 0 || $c->gamemode === 1) {
            return;
        }
        $c->health = max(0.0, $c->health - $dmg);
        $this->addExhaustion($c, 0.3);   // 原版：每次受伤 0.3
        $c->send(self::C_UPDATE_HEALTH, (new Writer())
            ->float($c->health)->varInt($c->food)->float(5.0));
        $this->log("  {$c->username} 掉血 {$dmg}，剩 {$c->health}");

        if ($c->health <= 0.0) {
            [$sx, $sy, $sz] = $this->world->spawnPoint();
            $c->x = $sx + 0.5;
            $c->y = (float) $sy;
            $c->z = $sz + 0.5;
            $c->health = 20.0;
            $c->fallFrom = -1.0;
            $c->send(self::C_POS_LOOK, (new Writer())
                ->double($c->x)->double($c->y)->double($c->z)
                ->float(0)->float(0)->byte(0));
            $this->sendHealth($c);
        }
    }

    private function sendChat(Connection $c, array $component, int $position = 1): void
    {
        $json = json_encode($component, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }
        $c->send(self::C_CHAT, (new Writer())->string($json)->byte($position));
    }

    private function broadcastChat(string $text, int $position = 0): void
    {
        $json = json_encode(['text' => $text], JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }
        $packet = (new Writer())->string($json)->byte($position);
        foreach ($this->players() as $p) {
            $p->send(self::C_CHAT, $packet);
        }
    }

    private function statusJson(): string
    {
        $json = json_encode([
            'version' => ['name' => self::VERSION, 'protocol' => self::PROTOCOL],
            'players' => [
                'max' => $this->maxPlayers,
                'online' => count($this->players()),
                'sample' => [],
            ],
            'description' => ['text' => $this->motd, 'color' => 'gold'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new \RuntimeException('状态 JSON 编码失败');
        }

        return $json;
    }

    private function tick(): void
    {
        $now = microtime(true);

        foreach ($this->conns as $c) {
            if ($c->closed) {
                continue;
            }
            if ($now - $c->lastRead > 30) {
                if ($c->state === Connection::STATE_PLAY) {
                    $c->send(self::C_DISCONNECT, (new Writer())
                        ->string(json_encode(['text' => '读超时', 'color' => 'red'])));
                    $c->flush();
                }
                $this->drop($c, '读超时');
                continue;
            }

            if ($c->state === Connection::STATE_PLAY
                && $c->keepAliveId !== 0
                && $now - $c->keepAliveSentAt > 30) {
                $this->drop($c, 'Keep Alive 无回应');
            }
        }

        if ($now - $this->lastKeepAlive >= 10) {
            $this->lastKeepAlive = $now;
            foreach ($this->players() as $c) {
                if ($c->keepAliveId !== 0) {
                    continue; // 还没回，交给超时判断，别把计时器续上
                }
                $c->keepAliveId = random_int(1, 0x7FFFFFFF);
                $c->keepAliveSentAt = $now;
                $c->send(self::C_KEEP_ALIVE, (new Writer())->varInt($c->keepAliveId));
            }
        }

        $this->processDrops();
        $this->tickFurnaces($now);
        $this->tickMobs($now);

        // 每秒同步一次时间，客户端天空才会真的昼→夜
        if ($now - $this->lastTimeSync >= 1.0) {
            $this->lastTimeSync = $now;
            foreach ($this->players() as $p) {
                $p->send(self::C_TIME_UPDATE, (new Writer())
                    ->long($this->world->worldAge())
                    ->long($this->world->timeOfDay()));
            }
        }

        // 箱子段补发：进服 2 秒 / 5 秒后，把带箱子的区块段整段再发一次。
        // 只发段数据（方块+光），不发空气、不改方块；客户端收到会重建渲染区块，
        // 这时 RenderChunk 会按方块自己建出 TileEntityChest，箱子就能画出来。
        foreach ($this->players() as $p) {
            if ($p->chestSections === [] || $p->chestSectionTimes === [] || $now < $p->chestSectionTimes[0]) {
                continue;
            }
            array_shift($p->chestSectionTimes);
            $p->chestFixRound++;
            $sent = 0;
            foreach ($p->chestSections as [$scx, $scz, $sy]) {
                // 不发段数据了（段重发会把客户端的 TE 打掉、光也会被旧值覆盖）。
                // 只对这个段里的每个箱子喂一次 0x35：
                // 客户端 handleUpdateTileEntity 第一句 world.s(pos) 是
                // getTileEntity(IMMEDIATE)，会当场 createNewTileEntity。
                $chunk = $this->world->chunk($scx, $scz);
                foreach ($chunk->blockPositions(54) as [$bx, $by, $bz]) {
                    if (($by >> 4) !== $sy) {
                        continue;
                    }
                    // 正确做法（依据客户端源码）：
                    // 1) 先发 0x35，让客户端 chunk.getTileEntity(pos, IMMEDIATE) 造出
                    //    TileEntityChest 并放进 loadedTileEntityList；
                    // 2) 再发一个「同状态」的 0x23。WorldClient.setBlockState 会无条件
                    //    markBlockRangeForRenderUpdate，而 Chunk.setBlockState 发现 state
                    //    没变会返回 null —— 方块不动、TE 不删，只触发一次 RenderChunk.rebuild，
                    //    重建时 TE 才被收进 CompiledChunk，箱子这才画得出来。
                    // 之前发书架(47<<4) 是错的：换方块会把箱子的 TE 删掉。
                    // 走了很久的弯路，结论是 1.8 客户端在「区块已编译完之后」
                    // 收 0x35 也不会把 TE 塞进 CompiledChunk，同状态 0x23 触发重建
                    // 也没用（我们验证过多轮）。
                    //
                    // 所以退回「客户端画成书架」：服务端方块数据仍然是 54，
                    // 右键查的是我们自己的世界数据，开箱/存取完全不受影响。
                    // 书架是储物类方块里唯一不需要 TE 的，一定画得出来。
                    $this->sendTileEntity($p, $bx, $by, $bz);
                    $p->send(self::C_BLOCK_CHANGE, (new Writer())
                        ->raw($this->packPositionBytes($bx, $by, $bz))
                        ->varInt(47 << 4));   // 47 = 书架
                    if ($p->chestFixRound === 1 && !$p->chestHinted) {
                        $p->chestHinted = true;
                        $this->sendChat($p, [
                            'text' => '提示：附近这些「书架」其实是箱子（1.8 客户端渲染限制），右键可正常打开存取。',
                            'color' => 'gray',
                        ], 1);
                    }
                    $sent++;
                }
            }
            if ($p->chestSectionTimes === []) {
                $p->chestSections = [];
            }
            if ($sent > 0) {
                $this->log("  补发箱子段 {$sent} 个 -> {$p->username}（剩 " . count($p->chestSectionTimes) . ' 轮）');
            }
        }

        // 自然回血：生存、饱食度 >= 18、没满血，每 4 秒回 1 点
        foreach ($this->players() as $p) {
            if ($p->gamemode !== 1 && $p->food >= 18 && $p->health > 0 && $p->health < 20
                && $now - $p->lastRegen > 4.0) {
                $p->lastRegen = $now;
                $p->health = min(20.0, $p->health + 1.0);
                $this->addExhaustion($p, 0.6);   // 原版：每回 1 点血消耗 0.6
                $p->send(self::C_UPDATE_HEALTH, (new Writer())
                    ->float($p->health)->varInt($p->food)->float(5.0));
            }
        }

        foreach ($this->players() as $p) {
            if ($p->chunkQueue !== []) {
                $this->flushChunkQueue($p);
            }
            if ($p->resendInvAt > 0 && $now >= $p->resendInvAt) {
                $p->resendInvAt = 0.0;
                $this->sendPlayerInventory($p);
                $this->sendCursor($p);
            }
        }

        // 改了方块两秒内落盘：进程被硬关也最多丢两秒
        if ($now - $this->lastSave >= 2) {
            $this->lastSave = $now;
            $n = $this->world->save();
            if ($n > 0) {
                $this->log("存盘 {$n} 个区块");
            }
        }
    }

    private function drop(Connection $c, string $why): void
    {
        if ($c->closed) {
            return;
        }
        $wasPlaying = $c->state === Connection::STATE_PLAY;
        $key = (int) $c->socket;
        $c->closed = true;
        @fclose($c->socket);
        unset($this->conns[$key]);
        $this->log("- 断开 {$c->remote}" . ($c->username !== '' ? " ({$c->username})" : '') . " ({$why})");

        if ($wasPlaying) {
            // 客户端崩了/直接断线时不会发 Close Window，箱子内容得在这里补存，
            // 不然刚放进去的东西就丢了。
            if ($c->chest !== null) {
                $this->saveChestWindow($c);
                $c->chest = null;
            }
            $this->closeWorkbench($c);
            $this->resetDrag($c);
            if ($c->cursor !== null) {
                // 光标上的东西还没放下就断线：塞回背包，别吞掉
                $cursor = $c->cursor;
                $c->cursor = null;
                $this->giveItem($c, $cursor['id'], $cursor['count'], $cursor['damage']);
            }
            $this->returnCraftGrid($c);
            $this->log("  存背包 " . count($c->inventory) . ' 格');
            $this->world->rememberVitals($c->username, $c->food, $c->health);
            $this->world->rememberGamemode($c->username, $c->gamemode);
            $this->world->rememberInventory($c->username, $c->inventory);
            $this->world->rememberPosition(
                $c->username,
                $c->x,
                $c->y,
                $c->z,
                $c->yaw,
                $c->pitch
            );
            foreach ($this->players() as $other) {
                $other->send(self::C_DESTROY_ENTITIES, (new Writer())
                    ->varInt(1)->varInt($c->entityId));
                $this->sendTabRemove($other, $c);
            }
            $this->broadcastChat('§e' . $c->username . ' 离开了游戏', 1);
            $this->world->save();
        }
    }

    public static function offlineUuid(string $name): string
    {
        $h = md5('OfflinePlayer:' . $name, true);
        $h[6] = chr((ord($h[6]) & 0x0F) | 0x30);
        $h[8] = chr((ord($h[8]) & 0x3F) | 0x80);
        $hex = bin2hex($h);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    private static function gameModeName(int $gm): string
    {
        return match ($gm) {
            0 => '生存',
            1 => '创造',
            2 => '冒险',
            3 => '旁观',
            default => (string) $gm,
        };
    }

    private static function angle(float $deg): int
    {
        return ((int) round($deg * 256 / 360)) & 0xFF;
    }

    private function packPosition(int $x, int $y, int $z): int
    {
        return (($x & 0x3FFFFFF) << 38) | (($y & 0xFFF) << 26) | ($z & 0x3FFFFFF);
    }

    /**
     * 同样一个位置，直接给出 8 字节大端。
     *
     * 上面那个 packPosition 在**负坐标**时是坏的：
     *   ($x & 0x3FFFFFF) << 38  ->  x = -94 时约 1.8e19，超过 PHP_INT_MAX，
     *   PHP 静默转成 float，pack('J') 写出来的 8 字节就是垃圾。
     * 客户端收到错误坐标，Block Change / TileEntity 全落到别处，
     * 表现出来就是「发了包但世界毫无反应」。
     *
     * 这里按 64 位的高/低 32 位分开拼，不产生溢出。
     */
    private function packPositionBytes(int $x, int $y, int $z): string
    {
        $hi = (($x & 0x3FFFFFF) << 6) | (($y & 0xFFF) >> 6);
        $lo = ((($y & 0xFFF) & 0x3F) << 26) | ($z & 0x3FFFFFF);

        return pack('N2', $hi & 0xFFFFFFFF, $lo & 0xFFFFFFFF);
    }

    /** @return array{0:int,1:int,2:int} */
    private function unpackPosition(int $v): array
    {
        $x = $v >> 38;
        if ($x >= 1 << 25) {
            $x -= 1 << 26;
        }
        $y = ($v >> 26) & 0xFFF;
        $z = $v & 0x3FFFFFF;
        if ($z >= 1 << 25) {
            $z -= 1 << 26;
        }

        return [$x, $y, $z];
    }

    /**
     * 找玩家点的那块「空气」旁边最近的箱子。
     * 先按点击的面找（客户端点幽灵半箱时，真箱子就在面朝的方向），
     * 找不到再按四个水平方向兜底。
     *
     * @return array{0:int,1:int,2:int}|null
     */
    private function chestNextTo(int $x, int $y, int $z, int $face): ?array
    {
        [$tx, $ty, $tz] = self::offsetByFace($x, $y, $z, $face);
        if ($this->chestIdAt($tx, $ty, $tz) === 54) {
            return [$tx, $ty, $tz];
        }
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dz]) {
            if ($this->chestIdAt($x + $dx, $y, $z + $dz) === 54) {
                return [$x + $dx, $y, $z + $dz];
            }
        }

        return null;
    }

    /** @return array{0:int,1:int,2:int} */
    private static function offsetByFace(int $x, int $y, int $z, int $face): array
    {
        return match ($face) {
            0 => [$x, $y - 1, $z],
            1 => [$x, $y + 1, $z],
            2 => [$x, $y, $z - 1],
            3 => [$x, $y, $z + 1],
            4 => [$x - 1, $y, $z],
            5 => [$x + 1, $y, $z],
            default => [$x, $y, $z],
        };
    }

    public function log(string $msg): void
    {
        $t = sprintf('%.3f', microtime(true) - $this->startedAt);
        $line = '[' . date('H:i:s') . "][{$t}s] {$msg}\n";
        fwrite(STDOUT, $line);

        // 同时落一份文件：终端滚上去就什么都看不见了
        $dir = __DIR__ . '/../logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents($dir . '/server.log', $line, FILE_APPEND);
    }
}
