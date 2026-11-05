<?php
declare(strict_types=1);

/**
 * 一次性补丁：AnvilWorld 接上末地带。
 */

$path = __DIR__ . '/../src/World/AnvilWorld.php';
$text = file_get_contents($path);
$EOL = str_contains($text, "\r\n") ? "\r\n" : "\n";
$text = str_replace("\r\n", "\n", $text);

$edits = [];

// 1) 常量
$edits[] = [
    "    /** 地狱带半径（区块数），512 区块 = 8192 格见方，走不到边 */\n    public const NETHER_RADIUS = 512;",
    "    /** 地狱带半径（区块数），512 区块 = 8192 格见方，走不到边 */\n    public const NETHER_RADIUS = 512;\n\n    /** 末地带中心（区块坐标）：世界坐标 300000,300000 */\n    public const END_CX = 18750;\n    public const END_CZ = 18750;\n    /** 末地岛半径约 1000 格，留点余量 */\n    public const END_RADIUS = 128;",
];

// 2) 字段
$edits[] = [
    "    /** 地狱地形生成器（只在地狱坐标带里用） */\n    private NetherGenerator \$nether;",
    "    /** 地狱地形生成器（只在地狱坐标带里用） */\n    private NetherGenerator \$nether;\n    /** 末地地形生成器 */\n    private EndGenerator \$end;",
];

// 3) 构造
$edits[] = [
    "        \$this->nether = new NetherGenerator(\$this->seed);",
    "        \$this->nether = new NetherGenerator(\$this->seed);\n        \$this->end = new EndGenerator(\$this->seed, self::END_CX, self::END_CZ);",
];

// 4) chunk() 分流
$edits[] = [
    "        \$isNether = \$this->isNetherChunk(\$cx, \$cz);\n        if (\$isNether && \$this->netherGenCount < 3) {\n            \$this->netherGenCount++;\n            error_log(sprintf('[phpmcs] 地狱地形生成 %d,%d（第 %d 个）', \$cx, \$cz, \$this->netherGenCount));\n        }\n        \$chunk = new Chunk(\$cx, \$cz, \$isNether\n            ? \$this->nether->generate(\$cx, \$cz)\n            : \$this->generator->generate(\$cx, \$cz));",
    "        \$isNether = \$this->isNetherChunk(\$cx, \$cz);\n        \$isEnd = \$this->isEndChunk(\$cx, \$cz);\n        if (\$isNether && \$this->netherGenCount < 3) {\n            \$this->netherGenCount++;\n            error_log(sprintf('[phpmcs] 地狱地形生成 %d,%d（第 %d 个）', \$cx, \$cz, \$this->netherGenCount));\n        }\n        if (\$isEnd) {\n            \$tag = \$this->end->generate(\$cx, \$cz);\n        } elseif (\$isNether) {\n            \$tag = \$this->nether->generate(\$cx, \$cz);\n        } else {\n            \$tag = \$this->generator->generate(\$cx, \$cz);\n        }\n        \$chunk = new Chunk(\$cx, \$cz, \$tag);",
];

// 5) chunkBiomes 分流
$edits[] = [
    "        if (\$this->isNetherChunk(\$cx, \$cz)) {\n            return array_fill(0, 256, 8);   // 8 = Hell\n        }",
    "        if (\$this->isNetherChunk(\$cx, \$cz)) {\n            return array_fill(0, 256, 8);   // 8 = Hell\n        }\n        if (\$this->isEndChunk(\$cx, \$cz)) {\n            return array_fill(0, 256, 9);   // 9 = The End\n        }",
];

// 6) isEndChunk / isEndAt
$edits[] = [
    "    /** 世界坐标是不是在地狱带里 */\n    public function isNetherAt(int \$x, int \$z): bool\n    {\n        return \$this->isNetherChunk(\$x >> 4, \$z >> 4);\n    }",
    "    /** 世界坐标是不是在地狱带里 */\n    public function isNetherAt(int \$x, int \$z): bool\n    {\n        return \$this->isNetherChunk(\$x >> 4, \$z >> 4);\n    }\n\n    /** 这一格区块是不是末地 */\n    public function isEndChunk(int \$cx, int \$cz): bool\n    {\n        return abs(\$cx - self::END_CX) <= self::END_RADIUS\n            && abs(\$cz - self::END_CZ) <= self::END_RADIUS;\n    }\n\n    /** 世界坐标是不是在末地带里 */\n    public function isEndAt(int \$x, int \$z): bool\n    {\n        return \$this->isEndChunk(\$x >> 4, \$z >> 4);\n    }",
];

foreach ($edits as $i => [$old, $new]) {
    $n = substr_count($text, $old);
    if ($n !== 1) {
        fwrite(STDERR, "第 " . ($i + 1) . " 处命中 $n 次\n");
        exit(1);
    }
    $text = str_replace($old, $new, $text);
}

$text = str_replace("\n", $EOL, $text);
file_put_contents($path, $text);
echo "AnvilWorld 已接上末地，" . count($edits) . " 处\n";
