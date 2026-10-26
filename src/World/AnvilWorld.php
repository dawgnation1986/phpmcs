<?php
declare(strict_types=1);

namespace PhpMc\World;

use PhpMc\Nbt\Nbt;
use PhpMc\Nbt\Tag;

/**
 * Anvil 存档（.mca + level.dat），读写都走真格式。
 *
 * region 文件布局：
 *   0x0000  1024 × 4 字节：3 字节扇区偏移 + 1 字节扇区数（偏移按 4096 字节算）
 *   0x1000  1024 × 4 字节：时间戳
 *   0x2000 起是区块数据：4 字节长度 + 1 字节压缩类型（1 gzip / 2 zlib / 3 无）+ 数据
 *
 * 一个 region 管 32×32 个区块，所以 chunk(-1,-1) 落在 r.-1.-1.mca 的第 1023 格。
 */
final class AnvilWorld
{
    private Tag $level;
    /** @var array<string, Chunk> */
    private array $chunks = [];
    /** @var array<string, resource|null> */
    private array $handles = [];
    public bool $created = false;
    /** @var array<string, bool> 已经记过「读不到」的区块，别刷屏 */
    private array $missingLogged = [];

    public function __construct(private string $dir)
    {
        $this->dir = rtrim($dir, '/\\');
        $levelPath = $this->dir . DIRECTORY_SEPARATOR . 'level.dat';

        if (is_file($levelPath)) {
            $this->level = Nbt::parseFile($levelPath);
        } else {
            $this->level = self::defaultLevel();
            $this->created = true;
            $this->ensureSkeleton();
            $this->writeLevelDat();
        }
    }

    /**
     * 原版存档的目录骨架：单机打开时会看到 data / playerdata / stats / DIM-1 / DIM1。
     * 我们建新世界时只建 region 的话，看起来就像「缺了一半」。
     */
    private function ensureSkeleton(): void
    {
        foreach (['data', 'playerdata', 'stats', 'region', 'DIM-1/region', 'DIM1/region'] as $sub) {
            @mkdir(
                $this->dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $sub),
                0777,
                true
            );
        }
    }

    private function data(): ?Tag
    {
        return Nbt::child($this->level, 'Data');
    }

    public function levelName(): string
    {
        return Nbt::str($this->data(), 'LevelName', basename($this->dir));
    }

    /** flat / default —— 客户端拿它决定一些渲染细节 */
    public function generator(): string
    {
        return Nbt::str($this->data(), 'generatorName', $this->created ? 'flat' : 'default');
    }

    public function gameType(): int
    {
        return Nbt::int($this->data(), 'GameType', 1);
    }

    /** @return array{0:int,1:int,2:int} */
    public function spawnPoint(): array
    {
        $x = Nbt::int($this->data(), 'SpawnX', 0);
        $z = Nbt::int($this->data(), 'SpawnZ', 0);
        $y = Nbt::int($this->data(), 'SpawnY', 64);

        // level.dat 里的 SpawnY 可能是很久以前存的，甚至正好指着石头里
        // （这个存档写的是 64，地表实际在 84）。用高度图抬到地表，
        // 不然人一进来就在地底，满屏黑。
        $h = $this->chunk($x >> 4, $z >> 4)->heightAt($x & 15, $z & 15);
        if ($h > 1) {
            $y = $h;
        }

        return [$x, $y, $z];
    }

    // ---------------- 区块 ----------------

    public function chunk(int $cx, int $cz): Chunk
    {
        $key = $cx . ',' . $cz;
        if (isset($this->chunks[$key])) {
            return $this->chunks[$key];
        }

        $root = $this->readChunk($cx, $cz);
        if ($root !== null) {
            return $this->chunks[$key] = new Chunk($cx, $cz, $root);
        }

        // 读不到就生成平地。这一步必须留痕：玩家遇到过「跑着跑着地形全变超平坦」，
        // 说明某个区块读失败了，然后这份生成的平地被缓存下来顶替了真地形。
        if ($this->created === false && !isset($this->missingLogged[$cx . ',' . $cz])) {
            $this->missingLogged[$cx . ',' . $cz] = true;
            $rx = $cx >> 5;
            $rz = $cz >> 5;
            $path = $this->dir . DIRECTORY_SEPARATOR . 'region'
                . DIRECTORY_SEPARATOR . "r.{$rx}.{$rz}.mca";
            error_log(sprintf(
                '[phpmcs] 区块 %d,%d 读不到，临时用平地顶替（%s %s）',
                $cx,
                $cz,
                is_file($path) ? '存在' : '不存在',
                $path
            ));
        }
        $chunk = new Chunk($cx, $cz, self::flatChunk($cx, $cz));
        // 我们自己造的世界：生成即落盘；别人的存档：只发不写，免得把平地糊进人家世界
        $chunk->dirty = $this->created;

        return $this->chunks[$key] = $chunk;
    }

    /** @return array{0:int,1:string} */
    public function chunkPayload(int $cx, int $cz): array
    {
        return $this->chunk($cx, $cz)->payload();
    }

    private function readChunk(int $cx, int $cz): ?Tag
    {
        $fh = $this->regionHandle($cx >> 5, $cz >> 5, false);
        if ($fh === null) {
            return null;
        }

        $idx = ($cx & 31) + ($cz & 31) * 32;
        fseek($fh, $idx * 4);
        $entry = fread($fh, 4);
        if ($entry === false || strlen($entry) < 4) {
            return null;
        }
        $offset = (ord($entry[0]) << 16) | (ord($entry[1]) << 8) | ord($entry[2]);
        if ($offset < 2) {
            return null;
        }

        fseek($fh, $offset * 4096);
        $lenRaw = fread($fh, 4);
        if ($lenRaw === false || strlen($lenRaw) < 4) {
            return null;
        }
        $len = unpack('N', $lenRaw)[1];
        if ($len <= 1 || $len > 64 * 1024 * 1024) {
            return null;
        }
        $comp = ord(fread($fh, 1));
        $body = fread($fh, $len - 1);
        if ($body === false || strlen($body) !== $len - 1) {
            return null;
        }

        $raw = match ($comp) {
            1 => @gzdecode($body),
            2 => @gzuncompress($body),
            3 => $body,
            default => false,
        };
        if ($raw === false) {
            return null;
        }

        try {
            return Nbt::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    // ---------------- 存盘 ----------------

    /**
     * 丢掉内存里的区块缓存（先落盘），下次访问会重新读磁盘。
     * 用来验证「磁盘上到底是啥」——如果是内存旧了，reload 之后就对了。
     */
    public function forgetChunks(): int
    {
        $this->save();
        $n = count($this->chunks);
        $this->chunks = [];
        foreach ($this->handles as $h) {
            if (is_resource($h)) {
                @fclose($h);
            }
        }
        $this->handles = [];

        return $n;
    }

    /** 把脏区块写回去，返回写了几个。 */
    public function save(): int
    {
        $n = 0;
        foreach ($this->chunks as $chunk) {
            if (!$chunk->dirty) {
                continue;
            }
            if ($this->writeChunk($chunk)) {
                $chunk->dirty = false;
                $n++;
            }
        }

        return $n;
    }

    private function writeChunk(Chunk $chunk): bool
    {
        $raw = Nbt::write($chunk->root);
        $compressed = gzcompress($raw, 6);
        if ($compressed === false) {
            throw new \RuntimeException('区块 zlib 压缩失败');
        }

        $body = pack('N', strlen($compressed) + 1) . "\x02" . $compressed;
        $sectors = max(1, (int) ceil(strlen($body) / 4096));
        $body .= str_repeat("\x00", $sectors * 4096 - strlen($body));

        $rx = $chunk->cx >> 5;
        $rz = $chunk->cz >> 5;
        $fh = $this->regionHandle($rx, $rz, true);
        if ($fh === null) {
            return false;
        }

        $idx = ($chunk->cx & 31) + ($chunk->cz & 31) * 32;

        fseek($fh, $idx * 4);
        $entry = fread($fh, 4) ?: "\x00\x00\x00\x00";
        $oldOffset = (ord($entry[0]) << 16) | (ord($entry[1]) << 8) | ord($entry[2]);
        $oldSectors = strlen($entry) >= 4 ? ord($entry[3]) : 0;

        if ($oldOffset >= 2 && $oldSectors >= $sectors) {
            // 原来那格够大，原地覆盖，不留垃圾
            $writeAt = $oldOffset * 4096;
            $useSectors = $oldSectors;
        } else {
            fseek($fh, 0, SEEK_END);
            $size = ftell($fh);
            $writeAt = max(8192, (int) (ceil($size / 4096) * 4096));
            $useSectors = $sectors;
        }

        fseek($fh, $writeAt);
        if (fwrite($fh, $body) === false) {
            return false;
        }

        $sectorOffset = intdiv($writeAt, 4096);
        fseek($fh, $idx * 4);
        fwrite($fh, chr(($sectorOffset >> 16) & 0xFF) . chr(($sectorOffset >> 8) & 0xFF)
            . chr($sectorOffset & 0xFF) . chr($useSectors & 0xFF));

        fseek($fh, 4096 + $idx * 4);
        fwrite($fh, pack('N', time()));
        fflush($fh);

        return true;
    }

    /** @return resource|null */
    private function regionHandle(int $rx, int $rz, bool $create)
    {
        $key = $rx . ',' . $rz;
        if (array_key_exists($key, $this->handles)) {
            $cached = $this->handles[$key];
            // 读的时候这个 region 不存在会缓存 null，要写的时候必须重新开一次
            if ($cached !== null || !$create) {
                return $cached;
            }
        }

        $path = $this->dir . DIRECTORY_SEPARATOR . 'region' . DIRECTORY_SEPARATOR . "r.{$rx}.{$rz}.mca";
        if (!is_file($path)) {
            if (!$create) {
                return $this->handles[$key] = null;
            }
            @mkdir(dirname($path), 0777, true);
            $fh = @fopen($path, 'c+b');
            if ($fh === false) {
                return $this->handles[$key] = null;
            }
            fwrite($fh, str_repeat("\x00", 8192));
            fflush($fh);

            return $this->handles[$key] = $fh;
        }

        $fh = @fopen($path, 'r+b');
        if ($fh === false) {
            $fh = @fopen($path, 'rb');
        }

        return $this->handles[$key] = $fh ?: null;
    }

    private function writeLevelDat(): void
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . 'level.dat';
        if (file_put_contents($path, Nbt::writeGzip($this->level), LOCK_EX) === false) {
            throw new \RuntimeException("写 level.dat 失败: {$path}");
        }
    }

    // ---------------- 玩家位置 ----------------

    /** @var array<string, array{x:float,y:float,z:float,yaw:float,pitch:float}>|null */
    private ?array $playerPos = null;

    private function posFile(): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . '.phpmcs_players.json';
    }

    /** @return array<string, array{x:float,y:float,z:float,yaw:float,pitch:float}> */
    private function playerPositions(): array
    {
        if ($this->playerPos === null) {
            $this->playerPos = [];
            if (is_file($this->posFile())) {
                $json = json_decode((string) @file_get_contents($this->posFile()), true);
                if (is_array($json)) {
                    $this->playerPos = $json;
                }
            }
        }

        return $this->playerPos;
    }

    /** @return array{x:float,y:float,z:float,yaw:float,pitch:float}|null */
    public function lastPosition(string $name): ?array
    {
        $p = $this->playerPositions()[strtolower($name)] ?? null;
        if (!is_array($p) || !isset($p['x'], $p['y'], $p['z'])) {
            return null;
        }

        return $p;
    }

    public function rememberPosition(
        string $name,
        float $x,
        float $y,
        float $z,
        float $yaw,
        float $pitch
    ): void {
        $key = strtolower($name);
        $pos = $this->playerPositions();
        // 合并而不是覆盖：同一个文件里还存着背包（inv）
        $pos[$key] = array_merge($pos[$key] ?? [], [
            'x' => round($x, 3),
            'y' => round($y, 3),
            'z' => round($z, 3),
            'yaw' => round($yaw, 1),
            'pitch' => round($pitch, 1),
        ]);
        $this->playerPos = $pos;
        @file_put_contents(
            $this->posFile(),
            json_encode($pos, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    /**
     * 背包。存在同一个 players 文件里（inv 字段）。
     * 槽位用玩家背包编号：9-35 主区、36-44 快捷栏。
     *
     * @return array<int, array{id:int,count:int,damage:int}>
     */
    public function inventory(string $name): array
    {
        $inv = $this->playerPositions()[strtolower($name)]['inv'] ?? [];
        if (!is_array($inv)) {
            return [];
        }

        $out = [];
        foreach ($inv as $slot => $item) {
            if (!is_array($item) || !isset($item['id'])) {
                continue;
            }
            $s = (int) $slot;
            if ($s < 0 || $s > 44) {
                continue;
            }
            $out[$s] = [
                'id' => (int) $item['id'],
                'count' => (int) ($item['count'] ?? 1),
                'damage' => (int) ($item['damage'] ?? 0),
            ];
        }

        return $out;
    }

    /** 上次的游戏模式（创造/生存）。没有记录就返回 null。 */
    public function playerGamemode(string $name): ?int
    {
        $gm = $this->playerPositions()[strtolower($name)]['gm'] ?? null;

        return is_int($gm) ? $gm : (is_numeric($gm) ? (int) $gm : null);
    }

    public function rememberGamemode(string $name, int $gamemode): void
    {
        $key = strtolower($name);
        $pos = $this->playerPositions();
        $pos[$key] = array_merge($pos[$key] ?? [], ['gm' => $gamemode]);
        $this->playerPos = $pos;
        @file_put_contents(
            $this->posFile(),
            json_encode($pos, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    /** @param array<int, array{id:int,count:int,damage:int}> $inv */
    public function rememberInventory(string $name, array $inv): void
    {
        $key = strtolower($name);
        $pos = $this->playerPositions();
        $pos[$key] = array_merge($pos[$key] ?? [], ['inv' => $inv]);
        $this->playerPos = $pos;
        @file_put_contents(
            $this->posFile(),
            json_encode($pos, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    // ---------------- 造世界 ----------------

    private static function defaultLevel(): Tag
    {
        return Tag::compound([
            'Data' => Tag::compound([
                'version' => Tag::int(19133),
                'LevelName' => Tag::str('PHP World'),
                'generatorName' => Tag::str('flat'),
                'generatorVersion' => Tag::int(0),
                'GameType' => Tag::int(1),
                'Difficulty' => Tag::byte(0),
                'DifficultyLocked' => Tag::byte(0),
                'hardcore' => Tag::byte(0),
                'allowCommands' => Tag::byte(1),
                'initialized' => Tag::byte(1),
                'MapFeatures' => Tag::byte(1),
                'SpawnX' => Tag::int(0),
                'SpawnY' => Tag::int(4),
                'SpawnZ' => Tag::int(0),
                'Time' => Tag::long(0),
                'DayTime' => Tag::long(6000),
                'LastPlayed' => Tag::long((int) (microtime(true) * 1000)),
                'raining' => Tag::byte(0),
                'rainTime' => Tag::int(0),
                'thundering' => Tag::byte(0),
                'thunderTime' => Tag::int(0),
                'clearWeatherTime' => Tag::int(0),
            ]),
        ]);
    }

    private static function flatChunk(int $cx, int $cz): Tag
    {
        $blocks = '';
        for ($y = 0; $y < 16; $y++) {
            $id = [0 => 7, 1 => 3, 2 => 3, 3 => 2][$y] ?? 0;
            $blocks .= str_repeat(chr($id), 256);
        }

        $section = Tag::compound([
            'Y' => Tag::byte(0),
            'Blocks' => Tag::byteArray($blocks),
            'Data' => Tag::byteArray(str_repeat("\x00", 2048)),
            'BlockLight' => Tag::byteArray(str_repeat("\x00", 2048)),
            'SkyLight' => Tag::byteArray(str_repeat("\xff", 2048)),
        ]);

        return Tag::compound([
            'Level' => Tag::compound([
                'xPos' => Tag::int($cx),
                'zPos' => Tag::int($cz),
                'LastUpdate' => Tag::long(0),
                'TerrainPopulated' => Tag::byte(1),
                'InhabitedTime' => Tag::long(0),
                'Biomes' => Tag::byteArray(str_repeat("\x01", 256)),
                'HeightMap' => Tag::intArray(array_fill(0, 256, 4)),
                'Sections' => Tag::list(Tag::COMPOUND, [$section]),
                'Entities' => Tag::list(Tag::COMPOUND, []),
                'TileEntities' => Tag::list(Tag::COMPOUND, []),
            ]),
        ]);
    }
}
