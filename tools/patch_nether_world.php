<?php
declare(strict_types=1);

/**
 * 一次性补丁：让 AnvilWorld 在「地狱坐标带」走 NetherGenerator。
 * 读一次 → 逐处断言替换 → 只写一次。
 */

$path = __DIR__ . '/../src/World/AnvilWorld.php';
$text = file_get_contents($path);
if ($text === false) {
    fwrite(STDERR, "读不到 $path\n");
    exit(1);
}
$EOL = str_contains($text, "\r\n") ? "\r\n" : "\n";

$edits = [];

// 1) 字段声明
$edits[] = [
    "    /** 独立的地形生成器（噪声/群系拆在 TerrainGenerator.php） */\n    private TerrainGenerator \$generator;",
    "    /** 独立的地形生成器（噪声/群系拆在 TerrainGenerator.php） */\n    private TerrainGenerator \$generator;\n    /** 地狱地形生成器（只在地狱坐标带里用） */\n    private NetherGenerator \$nether;",
];

// 2) 构造里初始化
$edits[] = [
    "        \$this->generator = new TerrainGenerator(\$this->seed);",
    "        \$this->generator = new TerrainGenerator(\$this->seed);\n        \$this->nether = new NetherGenerator(\$this->seed);",
];

// 3) chunk() 分流
$edits[] = [
    "        \$chunk = new Chunk(\$cx, \$cz, \$this->generator->generate(\$cx, \$cz));",
    "        // 地狱坐标带走独立的地形生成器，否则「挖穿天花板看到主世界」\n        \$chunk = new Chunk(\$cx, \$cz, \$this->isNetherChunk(\$cx, \$cz)\n            ? \$this->nether->generate(\$cx, \$cz)\n            : \$this->generator->generate(\$cx, \$cz));",
];

// 4) chunkBiomes 分流 + 新增 isNetherChunk
$edits[] = [
    "    public function chunkBiomes(int \$cx, int \$cz): array\n    {\n        return \$this->generator->biomeGrid(\$cx, \$cz);\n    }",
    "    public function chunkBiomes(int \$cx, int \$cz): array\n    {\n        if (\$this->isNetherChunk(\$cx, \$cz)) {\n            return array_fill(0, 256, 8);   // 8 = Hell\n        }\n\n        return \$this->generator->biomeGrid(\$cx, \$cz);\n    }\n\n    /**\n     * 这一格区块是不是地狱。\n     *\n     * 我们没建真的 DIM-1 目录 —— 地狱是主世界地图上一块远处的坐标带，\n     * 以 (200000, 200000) 为中心，半径 128 区块（2048 格）。\n     * 越界就是普通主世界地形，玩家走不出那么远。\n     */\n    public function isNetherChunk(int \$cx, int \$cz): bool\n    {\n        return abs(\$cx - self::NETHER_CX) <= self::NETHER_RADIUS\n            && abs(\$cz - self::NETHER_CZ) <= self::NETHER_RADIUS;\n    }\n\n    /** 世界坐标是不是在地狱带里 */\n    public function isNetherAt(int \$x, int \$z): bool\n    {\n        return \$this->isNetherChunk(\$x >> 4, \$z >> 4);\n    }",
];

// 5) 常量
$edits[] = [
    "final class AnvilWorld\n{\n    private Tag \$level;",
    "final class AnvilWorld\n{\n    /** 地狱带中心（区块坐标）：世界坐标 200000,200000 */\n    public const NETHER_CX = 12500;\n    public const NETHER_CZ = 12500;\n    /** 地狱带半径（区块数），2048 格见方 */\n    public const NETHER_RADIUS = 128;\n\n    private Tag \$level;",
];

foreach ($edits as $i => [$old, $new]) {
    $old = str_replace("\n", $EOL, $old);
    $new = str_replace("\n", $EOL, $new);
    $n = substr_count($text, $old);
    if ($n !== 1) {
        fwrite(STDERR, "第 " . ($i + 1) . " 处命中 $n 次，中止\n");
        exit(1);
    }
    $text = str_replace($old, $new, $text);
}

file_put_contents($path, $text);
echo "AnvilWorld.php 已更新，" . count($edits) . " 处\n";
