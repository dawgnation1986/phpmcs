#!/usr/bin/env python3
"""按 Anvil 规范独立生成一个「像原版」的存档，用来反向检验 PHP 的读取路径。

和 PHP 自己生成的那个不一样，这个刻意加了：
  - 3 个 section（不是只有一层）
  - 40 层石头 + 一层草，HeightMap 对得上
  - 一个方块实体（箱子）、一个实体（僵尸）
  - 一堆 PHP 不认识的标签（只要不崩、不丢，就说明没瞎解析）

用法: python tools/make_vanilla_world.py [目录]
"""
import gzip
import os
import struct
import sys
import zlib

OUT = sys.argv[1] if len(sys.argv) > 1 else "world_src"

BYTE, SHORT, INT, LONG, FLOAT, DOUBLE = 1, 2, 3, 4, 5, 6
BYTE_ARRAY, STRING, LIST, COMPOUND, INT_ARRAY = 7, 8, 9, 10, 11


def enc(t, v):
    if t == BYTE:
        return struct.pack(">b", v)
    if t == SHORT:
        return struct.pack(">h", v)
    if t == INT:
        return struct.pack(">i", v)
    if t == LONG:
        return struct.pack(">q", v)
    if t == FLOAT:
        return struct.pack(">f", v)
    if t == DOUBLE:
        return struct.pack(">d", v)
    if t == BYTE_ARRAY:
        return struct.pack(">i", len(v)) + v
    if t == STRING:
        b = v.encode("utf-8")
        return struct.pack(">H", len(b)) + b
    if t == LIST:
        et, items = v
        return bytes([et]) + struct.pack(">i", len(items)) + b"".join(enc(et, i) for i in items)
    if t == COMPOUND:
        out = b""
        for k, (tt, vv) in v.items():
            kb = k.encode("utf-8")
            out += bytes([tt]) + struct.pack(">H", len(kb)) + kb + enc(tt, vv)
        return out + b"\x00"
    if t == INT_ARRAY:
        return struct.pack(">i", len(v)) + b"".join(struct.pack(">i", i) for i in v)
    raise ValueError(t)


def nbt(root, name=""):
    return bytes([COMPOUND]) + struct.pack(">H", len(name)) + name.encode() + enc(COMPOUND, root)


def section(y, layer_fn):
    blocks = bytearray(4096)
    for yy in range(16):
        bid = layer_fn(y * 16 + yy)
        for i in range(256):
            blocks[yy * 256 + i] = bid
    return {
        "Y": (BYTE, y),
        "Blocks": (BYTE_ARRAY, bytes(blocks)),
        "Data": (BYTE_ARRAY, bytes(2048)),
        "BlockLight": (BYTE_ARRAY, bytes(2048)),
        "SkyLight": (BYTE_ARRAY, b"\xff" * 2048),
    }


def block_at(y):
    if y == 0:
        return 7        # 基岩
    if y <= 39:
        return 1        # 石头
    if y == 40:
        return 2        # 草
    return 0


def main():
    region_dir = os.path.join(OUT, "region")
    os.makedirs(region_dir, exist_ok=True)

    # ---- level.dat ----
    level = {
        "Data": (COMPOUND, {
            "version": (INT, 19133),
            "LevelName": (STRING, "Vanilla-ish"),
            "generatorName": (STRING, "default"),
            "GameType": (INT, 1),
            "Difficulty": (BYTE, 2),
            "SpawnX": (INT, 0),
            "SpawnY": (INT, 41),
            "SpawnZ": (INT, 0),
            "Time": (LONG, 0),
            "DayTime": (LONG, 6000),
            "LastPlayed": (LONG, 1700000000000),
        }),
    }
    open(os.path.join(OUT, "level.dat"), "wb").write(
        gzip.compress(nbt(level), 6))

    # ---- 区块 (0,0) ----
    heightmap = [41] * 256
    chunk = {
        "Level": (COMPOUND, {
            "xPos": (INT, 0),
            "zPos": (INT, 0),
            "LastUpdate": (LONG, 1234),
            "TerrainPopulated": (BYTE, 1),
            "InhabitedTime": (LONG, 987654),
            "Biomes": (BYTE_ARRAY, bytes([1]) * 256),
            "HeightMap": (INT_ARRAY, heightmap),
            "Sections": (LIST, (COMPOUND, [section(0, block_at),
                                           section(1, block_at),
                                           section(2, block_at)])),
            "Entities": (LIST, (COMPOUND, [
                {"id": (STRING, "Zombie"),
                 "Pos": (LIST, (DOUBLE, [0.5, 41.0, 0.5])),
                 "Health": (SHORT, 20),
                 "CustomName": (STRING, "测试僵尸")},
            ])),
            "TileEntities": (LIST, (COMPOUND, [
                {"id": (STRING, "Chest"),
                 "x": (INT, 0), "y": (INT, 41), "z": (INT, 0),
                 "Items": (LIST, (COMPOUND, []))},
            ])),
        }),
    }
    raw = nbt(chunk)
    comp = zlib.compress(raw, 6)
    body = struct.pack(">I", len(comp) + 1) + b"\x02" + comp
    sectors = (len(body) + 4095) // 4096
    body += b"\x00" * (sectors * 4096 - len(body))

    header = bytearray(8192)
    header[0:4] = bytes([0, 0, 2, sectors])          # 偏移 2 扇区，扇区数
    header[4096:4100] = struct.pack(">I", 1700000000)

    path = os.path.join(region_dir, "r.0.0.mca")
    with open(path, "wb") as f:
        f.write(header)
        f.write(body)

    print(f"已生成 {OUT}")
    print(f"  level.dat  {os.path.getsize(os.path.join(OUT, 'level.dat'))} 字节")
    print(f"  r.0.0.mca  {os.path.getsize(path)} 字节，{sectors} 扇区")
    print(f"  NBT 解压后 {len(raw)} 字节，区块 3 个 section / 1 实体 / 1 方块实体")


if __name__ == "__main__":
    main()
