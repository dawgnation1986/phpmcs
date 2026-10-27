<?php
declare(strict_types=1);

/**
 * 启动入口：
 *   php bin/server.php [端口] [MOTD] [存档目录]
 * 例：php bin/server.php 25565 "PHP 写的 1.8.9" world
 *
 * 存档目录不给就用项目下的 world/；目录里没有 level.dat 就按 Anvil 格式新建一份，
 * 新建出来的是标准存档，可以直接用 Minecraft 单机打开。
 */

require __DIR__ . '/../src/Protocol.php';
require __DIR__ . '/../src/Nbt/Tag.php';
require __DIR__ . '/../src/Nbt/Nbt.php';
require __DIR__ . '/../src/World/Chunk.php';
require __DIR__ . '/../src/World/TerrainGenerator.php';
require __DIR__ . '/../src/World/AnvilWorld.php';
require __DIR__ . '/../src/World/ChestStore.php';
require __DIR__ . '/../src/ChestTrait.php';
require __DIR__ . '/../src/MobTrait.php';
require __DIR__ . '/../src/FurnaceTrait.php';
require __DIR__ . '/../src/CraftingTrait.php';
require __DIR__ . '/../src/Skin/Skins.php';
require __DIR__ . '/../src/Server.php';

$port = 25565;
if (isset($argv[1]) && $argv[1] !== '') {
    $port = (int) $argv[1];
} elseif (getenv('MC_PORT') !== false && getenv('MC_PORT') !== '') {
    $port = (int) getenv('MC_PORT');
}

$motd = $argv[2] ?? 'PHP 写的 1.8.9 服务器';
$worldDir = $argv[3] ?? (__DIR__ . '/../world');

// 单实例守卫。
// Windows 的 SO_REUSEADDR 允许多个进程绑同一端口，后起的那个不报错，
// 于是「重启了但玩家连到的还是旧进程」——改的代码看起来完全没生效。
// 这里先探一下端口，有人占着就直接退出，别制造第二个幽灵。
$probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);
if ($probe !== false) {
    fclose($probe);
    fwrite(STDERR, "端口 {$port} 已经被另一个进程占着了。\n");
    fwrite(STDERR, "先把它关掉（netstat -ano | findstr :{$port} 看 PID），再来一遍。\n");
    exit(1);
}

$world = new PhpMc\World\AnvilWorld($worldDir);

// 皮肤白名单：游戏内名字 => 要查的正版名。离线号不要往里加，
// 否则会查到同名的陌生人，把人家的皮肤贴到你头上。
$allow = [];
$skinConf = __DIR__ . '/../skins.conf.json';
if (is_file($skinConf)) {
    $json = json_decode((string) @file_get_contents($skinConf), true);
    if (is_array($json)) {
        foreach ($json as $k => $v) {
            if (is_string($v) && $v !== '' && is_string($k) && ($k[0] ?? '') !== '_') {
                $allow[strtolower($k)] = $v;
            }
        }
    }
}

$skins = new PhpMc\Skin\Skins(__DIR__ . '/../cache/skins.json', $allow);
$chests = new PhpMc\World\ChestStore(
    __DIR__ . '/../cache/chests-' . substr(md5($worldDir), 0, 8) . '.json'
);
$gamemode = $world->created ? 1 : $world->gameType();

$server = new PhpMc\Server('0.0.0.0', $port, $motd, $world, $skins, $chests, 20, $gamemode, 3);
$server->run();
