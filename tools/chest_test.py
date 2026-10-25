#!/usr/bin/env python3
"""箱子端到端：放箱子 -> 右键开 -> 把一个物品放进箱子 -> 关掉 -> 重开还在。

用法: python tools/chest_test.py [host] [port]
"""
import os
import random
import struct
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from play_test import Client, read_string, read_varint_buf, varint  # noqa: E402

HOST = sys.argv[1] if len(sys.argv) > 1 else "127.0.0.1"
PORT = int(sys.argv[2]) if len(sys.argv) > 2 else 25565
FAILS = []


def check(cond, msg):
    print(("  ✓ " if cond else "  ✗ ") + msg)
    if not cond:
        FAILS.append(msg)
    return cond


def pack_pos(x, y, z):
    return ((x & 0x3FFFFFF) << 38) | ((y & 0xFFF) << 26) | (z & 0x3FFFFFF)


def read_slot(d, p=0):
    item_id = struct.unpack(">h", d[p:p + 2])[0]
    if item_id < 0:
        return None, p + 2
    count = d[p + 2]
    damage = struct.unpack(">h", d[p + 3:p + 5])[0]
    # 槽位数据是 short/byte/short/NBT(1 字节，0 表示没有)，共 6 字节
    return {"id": item_id, "count": count, "damage": damage}, p + 6


def slot_bytes(item):
    if item is None:
        return struct.pack(">h", -1)
    return struct.pack(">h", item["id"]) + bytes([item["count"]]) + struct.pack(">h", item["damage"]) + b"\x00"


def parse_window_items(d):
    win = d[0]
    count = struct.unpack(">h", d[1:3])[0]
    items = []
    p = 3
    for _ in range(count):
        item, p = read_slot(d, p)
        items.append(item)
    return win, items


def main():
    c = Client("ChestTester")
    check(c.login()[0] == 0x02, "登录成功")
    c.collect(1.2)

    # 每次用不同坐标，不然上一次留下的箱子会让「放置」被判成没变化
    cx, cz = random.randint(0, 60), random.randint(0, 60)

    # 1) 在地上放一个箱子（点地表那块方块的上面）
    place = (struct.pack(">q", pack_pos(cx, 3, cz)) + bytes([1])
             + struct.pack(">h", 54) + bytes([1]) + struct.pack(">h", 0)
             + bytes([0, 0, 0]))
    c.send(0x08, place)
    ev = c.collect(1.0)
    bc = [d for p, d in ev if p == 0x23]
    if check(len(bc) >= 1, "箱子放下了 (0x23)"):
        state, _ = read_varint_buf(bc[-1], 8)
        check(state == 54 << 4, f"方块 = {state >> 4}（54=箱子）")

    # 2) 空手右键 -> 开窗
    c.send(0x08, struct.pack(">q", pack_pos(cx, 4, cz)) + bytes([1]) + struct.pack(">h", -1))
    ev = c.collect(1.2)
    opened = [d for p, d in ev if p == 0x2D]
    items_pkt = [d for p, d in ev if p == 0x30]
    if check(len(opened) >= 1, "收到 Open Window (0x2D)"):
        win = opened[0][0]
        typ, p = read_string(opened[0], 1)
        title, p = read_string(opened[0], p)
        slots = opened[0][p]
        check(win == 1, f"windowId = {win}")
        check(typ == "minecraft:chest", f"类型 = {typ}")
        check(slots == 27, f"槽位数 = {slots}")
        check("箱子" in title, f"标题 = {title}")
    if check(len(items_pkt) >= 1, "收到 Window Items (0x30)"):
        win, items = parse_window_items(items_pkt[0])
        check(len(items) == 63, f"窗口物品数 = {len(items)}（27 箱 + 36 玩家）")

    # 3) 手上拿 5 个石头，点箱子第 0 格 -> 放进箱子
    stone = {"id": 4, "count": 5, "damage": 0}
    click = (bytes([1]) + struct.pack(">h", 0) + bytes([0])
             + struct.pack(">h", 1) + bytes([0]) + slot_bytes(stone))
    c.send(0x0E, click)
    ev = c.collect(1.0)
    ss = [d for p, d in ev if p == 0x2F]
    cf = [d for p, d in ev if p == 0x32]
    if check(len(ss) >= 1, "收到 Set Slot (0x2F)"):
        slot = struct.unpack(">h", ss[-1][1:3])[0]
        item, _ = read_slot(ss[-1], 3)
        check(slot == 0, f"变更槽位 = {slot}")
        check(item is not None and item["id"] == 4 and item["count"] == 5,
              f"槽里现在是 {item}")
    if check(len(cf) >= 1, "收到 Confirm Transaction (0x32)"):
        check(cf[-1][0] == 1 and cf[-1][3] == 1, "确认的是窗口 1、accepted=true")

    # 4) 关窗口
    c.send(0x0D, bytes([1]))
    ev = c.collect(0.8)
    check(any(p == 0x31 for p, _ in ev), "收到 Close Window (0x31)")

    # 5) 重新打开，东西还在
    c.send(0x08, struct.pack(">q", pack_pos(cx, 4, cz)) + bytes([1]) + struct.pack(">h", -1))
    ev = c.collect(1.2)
    again = [d for p, d in ev if p == 0x30]
    if check(len(again) >= 1, "重新打开箱子"):
        _, items = parse_window_items(again[0])
        first = items[0] if items else None
        check(first is not None and first["id"] == 4 and first["count"] == 5,
              f"箱子第 0 格 = {first}（存盘生效）")

    c.sock.close()
    print()
    print("RESULT:", "FAIL" if FAILS else "PASS")
    return 1 if FAILS else 0


if __name__ == "__main__":
    sys.exit(main())
