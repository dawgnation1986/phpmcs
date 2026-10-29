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
    /** 地形噪声的种子，取自 level.dat 的 RandomSeed */
    private int $seed = 0;
    /** 独立的地形生成器（噪声/群系拆在 TerrainGenerator.php） */
    private TerrainGenerator $generator;
    /** 旧生成器写过、等待迁移落盘的区块 key */
    private array $migrated = [];
    /** 旧生成区块的 LastUpdate 阈值（Unix 秒） */
    private int $migrationCutoff = 0;

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
            $this->ensureStructureFiles();
            @copy(
                $this->dir . DIRECTORY_SEPARATOR . 'level.dat',
                $this->dir . DIRECTORY_SEPARATOR . 'level.dat_old'
            );
        }
        $this->seed = Nbt::int($this->data(), 'RandomSeed', 0);
        $this->generator = new TerrainGenerator($this->seed);
        // 旧生成器用 time() 当 LastUpdate，原版存的是游戏刻（通常很小）。
        // 用等级存档的 LastPlayed 做下限，避免把正常原版区块当成旧生成区块。
        $this->migrationCutoff = max(
            1_000_000_000,
            intdiv(Nbt::int($this->data(), 'LastPlayed', 0), 1000)
        );
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

    /**
     * 原版的 data/ 下有几个结构表，即使没有村庄也会存在。
     * 这里补空的，让存档目录结构跟原版一致，外部工具能正常读。
     */
    private function ensureStructureFiles(): void
    {
        $empty = Tag::compound(['Valid' => Tag::byte(1)]);
        foreach (['villages.dat', 'villages_end.dat', 'villages_nether.dat'] as $name) {
            $path = $this->dir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . $name;
            if (!is_file($path)) {
                @file_put_contents($path, Nbt::writeGzip($empty));
            }
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

    /**
     * 1.8 客户端把某格设成箱子(54)时会走 BlockContainer 分支，在 1.8.9 上抛 NPE，
     * 方块状态永远落不了地。所以箱子方块统一用书架(47)承载，
     * 是不是容器由 ChestStore 里的坐标记录决定。
     * 这里在读区块时一次性把 54 换成 47，随脏区块一起存盘。
     */
    private function migrateChests(Chunk $chunk): void
    {
        $changed = 0;
        foreach ($chunk->blockPositions(54) as [$x, $y, $z]) {
            if ($chunk->setBlock($x, $y, $z, 47, 0)) {
                $changed++;
            }
        }
        if ($changed > 0) {
            error_log("[phpmcs]   迁移 {$changed} 个箱子 -> 书架");
        }
    }

    public function chunk(int $cx, int $cz): Chunk
    {
        $key = $cx . ',' . $cz;
        if (isset($this->chunks[$key])) {
            return $this->chunks[$key];
        }

        $root = $this->readChunk($cx, $cz);
        if ($root !== null) {
            $chunk = new Chunk($cx, $cz, $root);
            if (isset($this->migrated[$key])) {
                // 旧生成区块的 nibble 已经就地修正，写回磁盘
                $chunk->dirty = true;
                unset($this->migrated[$key]);
            }

            $this->importChestTiles($chunk);

            return $this->chunks[$key] = $chunk;
        }

        // 读不到就生成地形。这一步必须留痕：玩家遇到过「跑着跑着地形全变超平坦」，
        // 说明某个区块读失败了，然后这份生成的平地被缓存下来顶替了真地形。
        if ($this->created === false && !isset($this->missingLogged[$cx . ',' . $cz])) {
            $this->missingLogged[$cx . ',' . $cz] = true;
            $rx = $cx >> 5;
            $rz = $cz >> 5;
            $path = $this->dir . DIRECTORY_SEPARATOR . 'region'
                . DIRECTORY_SEPARATOR . "r.{$rx}.{$rz}.mca";
            error_log(sprintf(
                '[phpmcs] 区块 %d,%d 读不到，临时生成地形顶替（%s %s）',
                $cx,
                $cz,
                is_file($path) ? '存在' : '不存在',
                $path
            ));
        }
        $chunk = new Chunk($cx, $cz, $this->generator->generate($cx, $cz));
        // 磁盘上本来就没有这个区块：生成后落盘，下次直接读，不再临时生成。
        // 条目存在但读坏了就先不写，避免把可能有救的数据覆盖掉。
        $chunk->dirty = !$this->hasChunkEntry($cx, $cz);

        return $this->chunks[$key] = $chunk;
    }

    /** 磁盘上有没有这个区块的条目（不管能不能解析） */
    private function hasChunkEntry(int $cx, int $cz): bool
    {
        $fh = $this->regionHandle($cx >> 5, $cz >> 5, false);
        if ($fh === null) {
            return false;
        }
        $idx = ($cx & 31) + ($cz & 31) * 32;
        fseek($fh, $idx * 4);
        $entry = fread($fh, 4);
        if ($entry === false || strlen($entry) < 4) {
            return false;
        }
        $offset = (ord($entry[0]) << 16) | (ord($entry[1]) << 8) | ord($entry[2]);

        return $offset >= 2;
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
            $root = Nbt::parse($raw);
        } catch (\Throwable) {
            return null;
        }
        $this->migrateLegacyMeta($root, $cx, $cz);

        return $root;
    }

    /**
     * 旧版生成器把 Data / SkyLight / BlockLight 的 nibble 顺序写反了
     * （偶数索引写到了高 4 位）。这里按 chunk 整体交换回来，并打上新标记。
     * 原版区块的 LastUpdate 是游戏刻（通常很小），旧生成器用的是 Unix 时间戳，
     * 用 level.dat 的 LastPlayed 做阈值，避免误伤原版区块。
     */
    private function migrateLegacyMeta(Tag $root, int $cx, int $cz): void
    {
        $level = Nbt::child($root, 'Level');
        if ($level === null || Nbt::child($level, 'phpMcGenerated') !== null) {
            return;
        }
        if (Nbt::int($level, 'LastUpdate') <= $this->migrationCutoff) {
            return;
        }

        foreach (Nbt::listItems($level, 'Sections') as $sec) {
            foreach (['Data', 'SkyLight', 'BlockLight'] as $name) {
                $arr = Nbt::bytes($sec, $name);
                if ($arr === null || strlen($arr) !== 2048) {
                    continue;
                }
                $out = '';
                for ($i = 0; $i < 2048; $i++) {
                    $b = ord($arr[$i]);
                    $out .= chr((($b & 0x0F) << 4) | ($b >> 4));
                }
                $sec->value[$name] = Tag::byteArray($out);
            }
        }
        $level->value['phpMcGenerated'] = Tag::byte(1);
        $this->migrated[$cx . ',' . $cz] = true;
        error_log("[phpmcs] 迁移旧生成区块 {$cx},{$cz} 的 meta/光照");
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
    /** 上次写 level.dat 的时间（节流用） */
    private float $levelSavedAt = 0.0;

    public function save(): int
    {
        // 原版保存时会重写 level.dat（并把旧的留成 level.dat_old）。
        // 我们的 save() 每 2 秒跑一次，没必要这么勤，30 秒写一次就够。
        if (microtime(true) - $this->levelSavedAt > 30.0) {
            $this->writeLevelDat();
            $this->levelSavedAt = microtime(true);
        }

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

    /** @var array<callable> 额外 TE 提供者：fn(cx,cz): Tag[] */
    private array $tileProviders = [];

    /**
     * 注册一个 TE 提供者。写区块时会把它的返回值并进 TileEntities。
     * 提供者负责自己那类 TE 的完整内容（比如熔炉的 Items/BurnTime）。
     */
    public function addTileProvider(callable $fn): void
    {
        $this->tileProviders[] = $fn;
    }

    /**
     * 本区块里的告示牌：[[x, y, z, [行1,行2,行3,行4]], ...]
     * 告示牌只存在区块 NBT 里，没有单独的 JSON。
     */
    public function signsInChunk(int $cx, int $cz): array
    {
        $chunk = $this->chunk($cx, $cz);
        $root = $chunk->root->value['Level'] ?? null;
        if (!$root instanceof Tag) {
            return [];
        }
        $te = $root->value['TileEntities'] ?? null;
        if (!$te instanceof Tag) {
            return [];
        }

        $out = [];
        foreach ($te->value['items'] ?? [] as $t) {
            if (!$t instanceof Tag || ($t->value['id']->value ?? '') !== 'Sign') {
                continue;
            }
            $lines = [];
            for ($i = 1; $i <= 4; $i++) {
                $lines[] = (string) ($t->value['Text' . $i]->value ?? '');
            }
            $out[] = [
                (int) ($t->value['x']->value ?? 0),
                (int) ($t->value['y']->value ?? 0),
                (int) ($t->value['z']->value ?? 0),
                $lines,
            ];
        }

        return $out;
    }

    /** 写入/更新一块告示牌的文字（原版格式：Text1..Text4 是 JSON 字符串） */
    public function setSignText(int $x, int $y, int $z, array $lines): void
    {
        $chunk = $this->chunk($x >> 4, $z >> 4);
        $root = $chunk->root->value['Level'] ?? null;
        if (!$root instanceof Tag) {
            return;
        }

        $items = [];
        $existing = $root->value['TileEntities'] ?? null;
        if ($existing instanceof Tag) {
            foreach ($existing->value['items'] ?? [] as $t) {
                if (!$t instanceof Tag) {
                    continue;
                }
                $sameSign = ($t->value['id']->value ?? '') === 'Sign'
                    && (int) ($t->value['x']->value ?? 0) === $x
                    && (int) ($t->value['y']->value ?? 0) === $y
                    && (int) ($t->value['z']->value ?? 0) === $z;
                if ($sameSign) {
                    continue;   // 这块重写
                }
                $items[] = $t;
            }
        }

        $lines = array_pad(array_slice($lines, 0, 4), 4, '');
        $items[] = Tag::compound([
            'id' => Tag::str('Sign'),
            'x' => Tag::int($x), 'y' => Tag::int($y), 'z' => Tag::int($z),
            'Text1' => Tag::str((string) $lines[0]),
            'Text2' => Tag::str((string) $lines[1]),
            'Text3' => Tag::str((string) $lines[2]),
            'Text4' => Tag::str((string) $lines[3]),
        ]);
        $root->value['TileEntities'] = Tag::list(Tag::COMPOUND, $items);
        $chunk->dirty = true;
    }

    /** 箱子存储（由 bin/server.php 注入；写区块时把箱子 TE 一并写进 NBT） */
    private ?ChestStore $chests = null;

    public function attachChestStore(ChestStore $chests): void
    {
        $this->chests = $chests;
    }

    /** 区块读回来后，把里面的箱子 TE 物品导入箱子存储（已有则跳过，不覆盖新数据） */
    private function importChestTiles(Chunk $chunk): void
    {
        if ($this->chests === null) {
            return;
        }
        $root = $chunk->root->value['Level'] ?? null;
        if (!$root instanceof Tag) {
            return;
        }
        $te = $root->value['TileEntities'] ?? null;
        if (!$te instanceof Tag) {
            return;
        }

        $imported = 0;
        foreach ($te->value['items'] ?? [] as $t) {
            if (!$t instanceof Tag) {
                continue;
            }
            $id = $t->value['id']->value ?? '';
            if ($id !== 'Chest') {
                continue;
            }
            $x = (int) ($t->value['x']->value ?? 0);
            $y = (int) ($t->value['y']->value ?? 0);
            $z = (int) ($t->value['z']->value ?? 0);
            if ($this->chests->has($x, $y, $z)) {
                continue;
            }
            $slots = array_fill(0, 27, null);
            foreach ($t->value['Items']->value['items'] ?? [] as $it) {
                $s2 = (int) ($it->value['Slot']->value ?? -1);
                if ($s2 < 0 || $s2 > 26) {
                    continue;
                }
                $slots[$s2] = [
                    'id' => (int) ($it->value['id']->value ?? 0),
                    'count' => (int) ($it->value['Count']->value ?? 0),
                    'damage' => (int) ($it->value['Damage']->value ?? 0),
                ];
            }
            $this->chests->save($x, $y, $z, $slots);
            $imported++;
        }
        if ($imported > 0) {
            error_log(sprintf('[phpmcs] 区块 %d,%d 从 NBT 恢复了 %d 个箱子', $chunk->cx, $chunk->cz, $imported));
        }
    }

    /** 箱子内容变了要重写这个区块，否则 .mca 里的 TE 是旧的 */
    public function markChunkDirty(int $x, int $z): void
    {
        $key = ($x >> 4) . ',' . ($z >> 4);
        if (isset($this->chunks[$key])) {
            $this->chunks[$key]->dirty = true;
        }
    }

    /** 把本区块的箱子写成 TileEntities，方块和数据就存在同一份区块里了 */
    private function injectChestTiles(Chunk $chunk): void
    {
        if ($this->chests === null) {
            return;
        }

        $list = [];
        foreach ($this->chests->positionsInChunk($chunk->cx, $chunk->cz) as [$x, $y, $z]) {
            $items = [];
            foreach ($this->chests->load($x, $y, $z) as $slot => $it) {
                if (!is_array($it) || !isset($it['id']) || (int) ($it['count'] ?? 0) <= 0) {
                    continue;
                }
                $items[] = Tag::compound([
                    'Slot' => Tag::byte((int) $slot),
                    'id' => Tag::short((int) $it['id']),
                    'Count' => Tag::byte((int) $it['count']),
                    'Damage' => Tag::short((int) ($it['damage'] ?? 0)),
                ]);
            }
            $list[] = Tag::compound([
                'id' => Tag::str('Chest'),
                'x' => Tag::int($x), 'y' => Tag::int($y), 'z' => Tag::int($z),
                'Items' => Tag::list(Tag::COMPOUND, $items),
            ]);
        }

        $root = $chunk->root->value['Level'] ?? null;
        if (!$root instanceof Tag) {
            return;
        }

        // 合并而不是覆盖：真存档里还有告示牌 / 熔炉 / 陷阱箱……
        // 直接整表替换会把它们抹掉。这里只把「Chest」类型换成我们这份，
        // 其它 TE 原样保留。
        // 收集各个 provider 的 TE（熔炉等），并记下它们“认领”的 id
        $extra = [];
        $owned = ['Chest' => true];   // 箱子由 ChestStore 负责
        foreach ($this->tileProviders as $fn) {
            foreach ($fn($chunk->cx, $chunk->cz) as $tag) {
                if (!$tag instanceof Tag) {
                    continue;
                }
                $extra[] = $tag;
                $id = $tag->value['id']->value ?? '';
                if ($id !== '') {
                    $owned[$id] = true;
                }
            }
        }

        // 保留磁盘上那些没人认领的 TE（告示牌、刷怪箱……）
        $kept = [];
        $existing = $root->value['TileEntities'] ?? null;
        if ($existing instanceof Tag) {
            foreach ($existing->value['items'] ?? [] as $t) {
                if (!$t instanceof Tag) {
                    continue;
                }
                $id = $t->value['id']->value ?? '';
                if (isset($owned[$id])) {
                    continue;   // 这类由我们重写
                }
                $kept[] = $t;
            }
        }

        $root->value['TileEntities'] = Tag::list(Tag::COMPOUND, array_merge($kept, $list, $extra));
    }

    private function writeChunk(Chunk $chunk): bool
    {
        $this->injectChestTiles($chunk);

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
        // 原版每次保存前把旧的 level.dat 备份成 level.dat_old
        if (is_file($path)) {
            @copy($path, $this->dir . DIRECTORY_SEPARATOR . 'level.dat_old');
        }
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
        $dat = $this->readPlayerDat($name);
        if ($dat !== null && isset($dat['pos']['x'], $dat['pos']['y'], $dat['pos']['z'])) {
            return $dat['pos'];
        }
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
    
        // 同步刷新 playerdata/<uuid>.dat（现在是读取正本）
        $this->writePlayerDat($name, $pos[$key]);
    }

    /**
     * 背包。存在同一个 players 文件里（inv 字段）。
     * 槽位用玩家背包编号：9-35 主区、36-44 快捷栏。
     *
     * @return array<int, array{id:int,count:int,damage:int}>
     */
    /**
     * 读原版格式的 playerdata/<uuid>.dat。
     * 读不出来（文件不存在/损坏）返回 null，调用方退回 JSON。
     * @return array{inv:array,pos:array,hp:float,food:int}|null
     */
    public function readPlayerDat(string $name): ?array
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . 'playerdata'
            . DIRECTORY_SEPARATOR . $this->offlineUuid($name) . '.dat';
        if (!is_file($path)) {
            return null;
        }

        try {
            $tag = Nbt::parseFile($path);
        } catch (\Throwable $e) {
            return null;   // 坏了就当没有，退回 JSON 备份
        }

        $root = $tag->value[''] ?? $tag;
        if (!$root instanceof Tag) {
            return null;
        }
        $v = $root->value;

        $inv = [];
        $list = $v['Inventory'] ?? null;
        if ($list instanceof Tag) {
            foreach ($list->value['items'] ?? [] as $it) {
                if (!$it instanceof Tag) {
                    continue;
                }
                $slot = (int) ($it->value['Slot']->value ?? -1);
                $id = (int) ($it->value['id']->value ?? 0);
                $cnt = (int) ($it->value['Count']->value ?? 0);
                if ($slot < 0 || $id <= 0 || $cnt <= 0) {
                    continue;
                }
                $inv[$slot] = [
                    'id' => $id,
                    'count' => $cnt,
                    'damage' => (int) ($it->value['Damage']->value ?? 0),
                ];
            }
        }

        $pos = [];
        $p = $v['Pos'] ?? null;
        if ($p instanceof Tag) {
            $a = $p->value['items'] ?? [];
            if (count($a) >= 3) {
                $pos = [
                    'x' => (float) $a[0]->value,
                    'y' => (float) $a[1]->value,
                    'z' => (float) $a[2]->value,
                ];
                $r = $v['Rotation'] ?? null;
                if ($r instanceof Tag) {
                    $b = $r->value['items'] ?? [];
                    $pos['yaw'] = (float) ($b[0]->value ?? 0);
                    $pos['pitch'] = (float) ($b[1]->value ?? 0);
                }
            }
        }

        return [
            'inv' => $inv,
            'pos' => $pos,
            'hp' => (float) ($v['Health']->value ?? 20.0),
            'food' => (int) ($v['foodLevel']->value ?? 20),
        ];
    }

    public function inventory(string $name): array
    {
        // 正本优先：playerdata/<uuid>.dat；读不到才退回 JSON 备份
        $dat = $this->readPlayerDat($name);
        $inv = $dat !== null && $dat['inv'] !== []
            ? $dat['inv']
            : ($this->playerPositions()[strtolower($name)]['inv'] ?? []);
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

    /**
     * 世界时间（0~23999）。真的在走：每秒 20 tick，24000 tick 一天 = 20 分钟。
     * 从 0 点开始，客户端靠 0x03 Time Update 跟着走。
     */
    private ?int $timeOffset = null;

    /** 世界种子（level.dat 的 RandomSeed） */
    /** 区块的 16x16 群系（不触发生成） */
    /** 矩形区域的群系（方块坐标），一次算完 */
    public function biomeArea(int $x, int $z, int $w, int $h): array
    {
        return $this->generator->biomeArea($x, $z, $w, $h);
    }

    public function chunkBiomes(int $cx, int $cz): array
    {
        return $this->generator->biomeGrid($cx, $cz);
    }

    public function seed(): int
    {
        return $this->seed;
    }

    public function timeOfDay(): int
    {
        $v = (int) fmod(microtime(true) * 20, 24000.0) + ($this->timeOffset ?? 0);
        $v %= 24000;

        return $v < 0 ? $v + 24000 : $v;
    }

    /** 直接把时间拨到某个 tick（/timeset 用）。 */
    public function setTimeOfDay(int $t): void
    {
        $t = (($t % 24000) + 24000) % 24000;
        $this->timeOffset = $t - (int) fmod(microtime(true) * 20, 24000.0);
    }

    /** 世界总 tick 数（Time Update 里第一个 long）。 */
    public function worldAge(): int
    {
        return (int) (microtime(true) * 20);
    }

    /** @return array{food:int,hp:float} 上次下线的饱食度和血量 */
    public function vitals(string $name): array
    {
        $v = $this->playerPositions()[strtolower($name)] ?? [];

        return [
            'food' => isset($v['food']) ? max(0, min(20, (int) $v['food'])) : 20,
            'hp' => isset($v['hp']) ? max(0.0, min(20.0, (float) $v['hp'])) : 20.0,
        ];
    }

    public function rememberVitals(string $name, int $food, float $health): void
    {
        $key = strtolower($name);
        $pos = $this->playerPositions();
        $pos[$key] = array_merge($pos[$key] ?? [], ['food' => $food, 'hp' => round($health, 1)]);
        $this->playerPos = $pos;
        @file_put_contents(
            $this->posFile(),
            json_encode($pos, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            LOCK_EX
        );
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
    /** 离线 UUID（和 Server::offlineUuid 同一套算法）*/
    private function offlineUuid(string $name): string
    {
        $h = md5('OfflinePlayer:' . $name, true);
        $h[6] = chr((ord($h[6]) & 0x0F) | 0x30);
        $h[8] = chr((ord($h[8]) & 0x3F) | 0x80);
        $hex = bin2hex($h);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    /**
     * 额外写一份原版格式的玩家存档：playerdata/<uuid>.dat。
     * 背包的正本还是 .phpmcs_players.json（服务端自己读），
     * 这里只是让目录结构跟原版对齐，外部工具（地图/编辑器）能读到人。
     */
    private function writePlayerDat(string $name, array $rec): void
    {
        $uuid = $this->offlineUuid($name);
        $dir = $this->dir . DIRECTORY_SEPARATOR . 'playerdata';
        @mkdir($dir, 0777, true);

        $items = [];
        foreach (($rec['inv'] ?? []) as $slot => $it) {
            if (!is_array($it) || !isset($it['id']) || (int) ($it['count'] ?? 0) <= 0) {
                continue;
            }
            $items[] = Tag::compound([
                'Slot' => Tag::byte((int) $slot),
                'id' => Tag::short((int) $it['id']),
                'Count' => Tag::byte((int) $it['count']),
                'Damage' => Tag::short((int) ($it['damage'] ?? 0)),
            ]);
        }

        $root = Tag::compound([
            'Inventory' => Tag::list(Tag::COMPOUND, $items),
            'Pos' => Tag::list(Tag::DOUBLE, [
                Tag::double((float) ($rec['x'] ?? 0)),
                Tag::double((float) ($rec['y'] ?? 64)),
                Tag::double((float) ($rec['z'] ?? 0)),
            ]),
            'Rotation' => Tag::list(Tag::FLOAT, [
                Tag::float((float) ($rec['yaw'] ?? 0)),
                Tag::float((float) ($rec['pitch'] ?? 0)),
            ]),
            'Health' => Tag::float(20.0),
            'foodLevel' => Tag::int(20),
            'XpLevel' => Tag::int(0),
            'XpTotal' => Tag::int(0),
            'playerGameType' => Tag::int(1),
            'Dimension' => Tag::int(0),
            'OnGround' => Tag::byte(1),
        ]);

        @file_put_contents(
            $dir . DIRECTORY_SEPARATOR . $uuid . '.dat',
            Nbt::writeGzip(Tag::compound(['' => $root]))
        );

        // stats/<uuid>.json —— 原版放统计，这里给一份最小的合法 JSON
        $statsDir = $this->dir . DIRECTORY_SEPARATOR . 'stats';
        @mkdir($statsDir, 0777, true);
        $statsPath = $statsDir . DIRECTORY_SEPARATOR . $uuid . '.json';
        if (!is_file($statsPath)) {
            @file_put_contents($statsPath, json_encode([
                'stat.leaveGame' => 0,
                'stat.playOneMinute' => 0,
                'stat.walkOneCm' => 0,
            ], JSON_PRETTY_PRINT));
        }
    }

    public function rememberInventory(string $name, array $inv): void
    {
        $key = strtolower($name);
        $pos = $this->playerPositions();
        $pos[$key] = array_merge($pos[$key] ?? [], ['inv' => $inv]);
        $this->writePlayerDat($name, $pos[$key]);
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
                'generatorName' => Tag::str('default'),
                'generatorVersion' => Tag::int(1),
                'RandomSeed' => Tag::long(random_int(-2147483647, 2147483647)),
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
}
