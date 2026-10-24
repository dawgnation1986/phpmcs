<?php
declare(strict_types=1);

namespace PhpMc;

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
    public int $gamemode = 1;
    public bool $flying = false;
    /** 手上拿的是快捷栏第几格（0-8），客户端切物品时会告诉我们 */
    public int $heldSlot = 0;
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
    public const PROTOCOL = 47;
    public const VERSION = '1.8.9';

    // 客户端包 ID
    private const C_KEEP_ALIVE = 0x00;
    private const C_JOIN_GAME = 0x01;
    private const C_CHAT = 0x02;
    private const C_SPAWN_POSITION = 0x05;
    private const C_POS_LOOK = 0x08;
    private const C_SPAWN_PLAYER = 0x0C;
    private const C_DESTROY_ENTITIES = 0x13;
    private const C_ENTITY_LOOK = 0x16;
    private const C_ENTITY_TELEPORT = 0x18;
    private const C_HEAD_LOOK = 0x19;
    private const C_CHUNK_DATA = 0x21;
    // 1.8：0x0B 是 Animation（挥手），0x0D 才是 Collect Item。
    // 写成 0x0B 的话客户端只会挥一下手，地上的东西永远不消失。
    private const C_ANIMATION = 0x0B;
    private const C_COLLECT_ITEM = 0x0D;
    private const C_SPAWN_OBJECT = 0x0E;
    private const C_ENTITY_VELOCITY = 0x12;
    private const C_ENTITY_METADATA = 0x1C;
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
    /** @var array<string, float> 上次整块重发的时间，用来做节流 */
    private array $lastChunkPush = [];
    private float $startedAt;
    private float $lastKeepAlive = 0.0;
    private float $lastSave = 0.0;

    public function __construct(
        private string $host,
        private int $port,
        private string $motd,
        private AnvilWorld $world,
        private Skins $skins,
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
        $c->gamemode = $this->gamemode;

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
        $c->send(self::C_SPAWN_POSITION, (new Writer())->long($this->packPosition($sx, $sy, $sz)));
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
                // 创造点一下就碎（status 0）；生存等客户端挖完（status 2）
                if (($c->gamemode === 1 && $status === 0) || ($c->gamemode !== 1 && $status === 2)) {
                    if ($state === 0) {
                        // 客户端以为这里有方块，服务端这边是空气。
                        // 直接把这个「空气」打回去，客户端收到就会把幽灵方块擦掉，
                        // 不用等玩家再放一个方块来触发区块更新。
                        $c->send(self::C_BLOCK_CHANGE, (new Writer())
                            ->long($this->packPosition($x, $y, $z))
                            ->varInt(0));
                    } else {
                        $this->breakBlock($c, $x, $y, $z);
                    }
                }
                break;

            case 0x08: // 放方块
                [$x, $y, $z] = $this->unpackPosition($r->readLong());
                $face = $r->readUnsignedByte();
                $itemId = $r->readShort();
                if ($itemId <= 0 || $itemId > 255) {
                    break; // 手上不是方块
                }
                $r->readByte();               // count
                $damage = $r->readShort();     // 方块 meta 藏在 damage 里
                [$tx, $ty, $tz] = self::offsetByFace($x, $y, $z, $face);
                $this->log("  放 {$itemId} @ {$tx},{$ty},{$tz}（点的 {$x},{$y},{$z} 面 {$face}）");
                $this->changeBlock($tx, $ty, $tz, $itemId, $damage & 0x0F);
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

            case 0x0E: // Click Window：背包里按 Q 走这里（mode 4）
                $windowId = $r->readByte();
                $slot = $r->readShort();
                $button = $r->readByte();
                $mode = $r->readByte();
                if ($mode === 4 && $windowId === 0) {
                    $this->dropFromSlot($c, $slot, $button === 1);
                }
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

    private function changeBlock(int $x, int $y, int $z, int $id, int $meta): void
    {
        if ($y < 0 || $y > 255) {
            return;
        }
        $chunk = $this->world->chunk($x >> 4, $z >> 4);
        $existing = $chunk->blockState($x & 15, $y, $z & 15);
        if ($id !== 0 && $existing !== 0 && !self::isReplaceable($existing >> 4)) {
            return; // 目标被实心方块占着，放不进去
        }
        if ($existing !== 0) {
            $this->log("  替换方块 {$existing} @ {$x},{$y},{$z}");
        }
        if (!$chunk->setBlock($x & 15, $y, $z & 15, $id, $meta)) {
            return;
        }
        $this->broadcastBlockChange($x, $y, $z, (($id & 0xFF) << 4) | ($meta & 0x0F));
        $this->markSection($x, $y, $z);
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
        if (!$chunk->setBlock($x & 15, $y, $z & 15, 0, 0)) {
            return;
        }
        $this->broadcastBlockChange($x, $y, $z, 0);
        $this->markSection($x, $y, $z);

        // 创造模式不掉落（原版行为）
        if ($c->gamemode === 1) {
            return;
        }

        [$dropId, $count] = self::dropOf($id, $meta);
        if ($dropId > 0) {
            $this->spawnDrop($x, $y, $z, $dropId, $count, $dropId === $id ? $meta : 0, $c);
        }
    }

    /**
     * 1.8 没有单独的光照包。方块一变，客户端那片光就停在旧数据上，
     * 于是挖开的洞是黑的。唯一的办法是把这一段重新发一遍（带新光照）。
     */
    private function markSection(int $x, int $y, int $z): void
    {
        // 记整个区块，不再记某一段 —— 分段更新（groundUp=false）客户端不认，
        // 只有整块重发才会刷新。玩家实测：走进去的新区块正常，改过的老区块不刷新。
        $cx = $x >> 4;
        $cz = $z >> 4;
        $this->dirtySections[$cx . ',' . $cz] = [$cx, $cz];
    }

    /** 每拍最多重发两个区块，别把带宽打爆；同一个区块 0.15 秒内不重复发。 */
    private function flushSections(): void
    {
        if ($this->dirtySections === []) {
            return;
        }
        $now = microtime(true);
        $sent = 0;
        foreach ($this->dirtySections as $key => [$cx, $cz]) {
            if (($this->lastChunkPush[$key] ?? 0.0) > $now - 0.15) {
                continue;
            }
            unset($this->dirtySections[$key]);
            $this->lastChunkPush[$key] = $now;

            [$mask, $data] = $this->world->chunkPayload($cx, $cz);
            $packet = (new Writer())
                ->int($cx)
                ->int($cz)
                ->bool(true)                 // 整块发，客户端才认
                ->short($mask)
                ->varInt(strlen($data))
                ->raw($data);
            foreach ($this->players() as $p) {
                $p->send(self::C_CHUNK_DATA, $packet);
            }
            if (++$sent >= 2) {
                break;
            }
        }
    }

    private function broadcastBlockChange(int $x, int $y, int $z, int $state): void
    {
        $packet = (new Writer())
            ->long($this->packPosition($x, $y, $z))
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
    private static function dropOf(int $id, int $meta): array
    {
        return match (true) {
            $id === 1 => [4, 1],                    // 石头 -> 圆石
            $id === 2, $id === 110 => [3, 1],       // 草方块 / 菌丝 -> 土
            $id === 3 => [3, 1],                    // 土
            $id === 12 => [12, 1],                  // 沙子
            $id === 13 => [13, 1],                  // 砂砾
            // 树叶：通常什么都不掉，5% 掉树苗（原版还有 0.5% 苹果，先不折腾）
            $id === 18 => mt_rand(1, 20) === 1 ? [6, 1] : [0, 0],
            $id === 20, $id === 79, $id === 95 => [0, 0],   // 玻璃 / 冰 / 染色玻璃
            $id === 73, $id === 74 => [331, 1],     // 红石矿 -> 红石粉
            $id === 78 => [332, 1],                 // 雪层 -> 雪球
            $id === 82 => [337, 4],                 // 黏土 -> 4 个黏土球
            $id === 89 => [89, 1],                  // 萤石
            $id === 103 => [360, 1],                // 西瓜 -> 西瓜片
            $id === 7 => [0, 0],                    // 基岩（本来就挖不动，兜底）
            default => [$id, 1],
        };
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

        // 朝视线方向扔出去
        $yaw = deg2rad($c->yaw);
        $pitch = deg2rad($c->pitch);
        $dx = -sin($yaw) * cos($pitch);
        $dy = -sin($pitch);
        $dz = cos($yaw) * cos($pitch);
        $px = $c->x + $dx * 0.3;
        $py = $c->y + 1.3 + $dy * 0.3;
        $pz = $c->z + $dz * 0.3;

        $this->throwItem($px, $py, $pz, $it['id'], $n, $it['damage'],
            (int) ($dx * 2400), (int) ($dy * 2400 + 600), (int) ($dz * 2400));
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
        $eid = $this->nextEntityId++;

        foreach ($this->players() as $p) {
            $p->send(self::C_SPAWN_OBJECT, (new Writer())
                ->varInt($eid)
                ->byte(2)
                ->int((int) ($px * 32))
                ->int((int) ($py * 32))
                ->int((int) ($pz * 32))
                ->byte(0)->byte(0)
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
        $this->log("  丢出 {$count}x 方块{$id} @ " . round($px, 1) . ',' . round($py, 1) . ',' . round($pz, 1));
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
            if ($it === null || $it['id'] !== $id || $it['damage'] !== $damage || $it['count'] >= 64) {
                continue;
            }
            $add = min(64 - $it['count'], $count);
            $it['count'] += $add;
            $count -= $add;
            $c->inventory[$slot] = $it;
            $this->sendSlot($c, $slot, $it);
            if ($count <= 0) {
                return;
            }
        }

        foreach ($slots as $slot) {
            if (isset($c->inventory[$slot])) {
                continue;
            }
            $it = ['id' => $id, 'count' => min(64, $count), 'damage' => $damage];
            $c->inventory[$slot] = $it;
            $count -= $it['count'];
            $this->sendSlot($c, $slot, $it);
            if ($count <= 0) {
                return;
            }
        }
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
        $this->flushSections();

        foreach ($this->players() as $p) {
            if ($p->chunkQueue !== []) {
                $this->flushChunkQueue($p);
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
