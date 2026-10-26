#!/usr/bin/env python3
"""独立于 PHP 实现的存档检查器：自己解 gzip/zlib + NBT，
直接读 level.dat 和 .mca，用来验证 PHP 写出来的东西是真格式、不是自说自话。

用法: python tools/check_save.py [存档目录]
"""
import gzip
import json
import os
import struct
import sys
import zlib

WORLD = sys.argv[1] if len(sys.argv) > 1 else "world"

END, BYTE, SHORT, INT, LONG, FLOAT, DOUBLE = 0, 1, 2, 3, 4, 5, 6
BYTE_ARRAY, STRING, LIST, COMPOUND, INT_ARRAY, LONG_ARRAY = 7, 8, 9, 10, 11, 12


class NBT:
    def __init__(self, data):
        self.d = data
        self.p = 0

    def take(self, n):
        s = self.d[self.p:self.p + n]
        if len(s) != n:
            raise EOFError("NBT 被截断")
        self.p += n
        return s

    def u8(self):
        return self.take(1)[0]

    def i16(self):
        return struct.unpack(">h", self.take(2))[0]

    def i32(self):
        return struct.unpack(">i", self.take(4))[0]

    def i64(self):
        return struct.unpack(">q", self.take(8))[0]

    def string(self):
        return self.take(struct.unpack(">H", self.take(2))[0]).decode("utf-8", "replace")

    def payload(self, t):
        if t == BYTE:
            return struct.unpack(">b", self.take(1))[0]
        if t == SHORT:
            return self.i16()
        if t == INT:
            return self.i32()
        if t == LONG:
            return self.i64()
        if t == FLOAT:
            return struct.unpack(">f", self.take(4))[0]
        if t == DOUBLE:
            return struct.unpack(">d", self.take(8))[0]
        if t == BYTE_ARRAY:
            return self.take(self.i32())
        if t == STRING:
            return self.string()
        if t == LIST:
            et = self.u8()
            return [self.payload(et) for _ in range(self.i32())]
        if t == COMPOUND:
            out = {}
            while True:
                tt = self.u8()
                if tt == END:
                    return out
                name = self.string()
                out[name] = self.payload(tt)
        if t == INT_ARRAY:
            return [self.i32() for _ in range(self.i32())]
        if t == LONG_ARRAY:
            return [self.i64() for _ in range(self.i32())]
        raise ValueError(f"未知 NBT 类型 {t}")


def load_nbt(raw):
    if raw[:2] == b"\x1f\x8b":
        raw = gzip.decompress(raw)
    else:
        try:
            raw = zlib.decompress(raw)
        except zlib.error:
            pass
    n = NBT(raw)
    t = n.u8()
    n.string()
    return n.payload(t)


def main():
    ok = True

    level_path = os.path.join(WORLD, "level.dat")
    if not os.path.isfile(level_path):
        print(f"✗ 没有 {level_path}")
        return 1

    level = load_nbt(open(level_path, "rb").read())["Data"]
    print("[level.dat]")
    print("  " + json.dumps({k: v for k, v in level.items()
                             if isinstance(v, (int, float, str))},
                            ensure_ascii=False, default=str))
    spawn = (level.get("SpawnX"), level.get("SpawnY"), level.get("SpawnZ"))
    print(f"  · 出生点 {spawn}，generator={level.get('generatorName')}")

    region_dir = os.path.join(WORLD, "region")
    files = sorted(f for f in os.listdir(region_dir) if f.endswith(".mca")) \
        if os.path.isdir(region_dir) else []
    if not files:
        print("✗ region/ 里一个 .mca 都没有")
        return 1
    print(f"[region] {len(files)} 个文件: {', '.join(files)}")

    total = 0
    sample = None
    for fn in files:
        data = open(os.path.join(region_dir, fn), "rb").read()
        if len(data) < 8192:
            print(f"  ✗ {fn} 比 8KB 头还短")
            ok = False
            continue
        for i in range(1024):
            off = (data[i * 4] << 16) | (data[i * 4 + 1] << 8) | data[i * 4 + 2]
            cnt = data[i * 4 + 3]
            if off < 2 or cnt == 0:
                continue
            total += 1
            base = off * 4096
            if base + 4 > len(data):
                print(f"  ✗ 第 {i} 格偏移越界")
                ok = False
                continue
            ln = struct.unpack(">I", data[base:base + 4])[0]
            comp = data[base + 4]
            body = data[base + 5:base + 5 + ln - 1]
            try:
                raw = gzip.decompress(body) if comp == 1 else zlib.decompress(body)
                nbt = NBT(raw)
                if nbt.u8() != 10:
                    raise ValueError("根不是 compound")
                nbt.string()
                chunk = nbt.payload(COMPOUND)
            except Exception as e:
                print(f"  ✗ 第 {i} 格解不开: {e}")
                ok = False
                continue
            lv = chunk.get("Level", {})
            cx, cz = lv.get("xPos"), lv.get("zPos")
            if cx == 0 and cz == 0:
                sample = lv

    print(f"  ✓ 共 {total} 个区块条目")

    if sample is None:
        print("  ✗ 没找到 (0,0) 区块")
        return 1

    gaps = []
    secs = {s["Y"]: s for s in sample.get("Sections", [])}
    print(f"  (0,0) 区块 sections: {sorted(secs)} / Biomes {len(sample.get('Biomes', b''))} 字节")
    for y in range(0, 6):
        sec = secs.get(y // 16)
        if sec and y % 16 < 4096:
            i = (y % 16) * 256
            bid = sec["Blocks"][i] if i < len(sec["Blocks"]) else None
            meta = (sec["Data"][i // 2] & 0x0F) if i // 2 < len(sec["Data"]) else 0
            state = None if bid is None else (bid << 4) | meta
        else:
            state = 0
        gaps.append(state)
        print(f"    y={y}: 方块值 {state}")

    want = None
    if len(sys.argv) > 2:
        want = [int(x) for x in sys.argv[2].split(",")]
    if want is None:
        print("  · 没给期望值，只校验格式（世界被人挖过也正常）")
    elif gaps != want:
        print(f"  ✗ 方块序列 {gaps}，期望 {want}")
        ok = False
    else:
        print("  ✓ 方块与预期一致")

    print()
    print("RESULT:", "PASS" if ok else "FAIL")
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
