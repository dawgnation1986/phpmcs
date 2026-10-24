#!/usr/bin/env python3
"""掉落物验收：生存模式挖一块自己刚放下的石头，得看到「掉出来 → 被吸走 → 进背包」。

先放再挖，跑多少遍都成立（不会因为上次已经挖空而假失败）。

顺序应该是：
  0x23 Block Change（石头放上去了）
  0x23 Block Change（挖掉）
  0x0E Spawn Object（掉出来一个物品实体，type=2）
  0x12 Entity Velocity（弹一下）
  0x1C Entity Metadata（这个实体是圆石）
  0x0B Collect Item（0.6 秒后吸走）
  0x2F Set Slot（背包里出现圆石）

用法: python tools/drop_test.py [host] [port]
"""
import os
import struct
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from play_test import Client, read_varint_buf, varint  # noqa: E402

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


def unpack_pos(v):
    x = v >> 38
    if x >= 1 << 25:
        x -= 1 << 26
    y = (v >> 26) & 0xFFF
    z = v & 0x3FFFFFF
    if z >= 1 << 25:
        z -= 1 << 26
    return x, y, z


def main():
    c = Client("Dropper")
    pid, _ = c.login()
    check(pid == 0x02, "登录成功")
    c.collect(1.2)

    cmd = b"/gm 0"
    c.send(0x01, varint(len(cmd)) + cmd)
    check(any(p == 0x2B for p, _ in c.collect(1.0)), "已切到生存模式")

    # 先在 (0,5,0) 放一块石头：打 (0,4,0) 的上面那一面
    place = (struct.pack(">q", pack_pos(0, 4, 0)) + bytes([1])
             + struct.pack(">h", 1) + bytes([1]) + struct.pack(">h", 0)
             + bytes([0, 0, 0]))
    c.send(0x08, place)
    placed = [d for p, d in c.collect(1.0) if p == 0x23]
    if check(len(placed) >= 1, "石头放上去了 (0x23)"):
        pos = struct.unpack(">q", placed[0][0:8])[0]
        state, _ = read_varint_buf(placed[0], 8)
        check(unpack_pos(pos) == (0, 5, 0), f"坐标={unpack_pos(pos)}")
        check(state == 1 << 4, f"状态={state}（石头）")

    # 真实客户端：先 status=0（开始挖），挖完 status=2
    x, y, z = 0, 5, 0
    c.send(0x07, bytes([0]) + struct.pack(">q", pack_pos(x, y, z)) + bytes([1]))
    c.send(0x07, bytes([2]) + struct.pack(">q", pack_pos(x, y, z)) + bytes([1]))

    events = c.collect(2.5)
    print(f"  收到包: {[hex(i) for p, i in ((p, p) for p, _ in events)]}")

    bc = [d for p, d in events if p == 0x23]
    if check(len(bc) >= 1, "方块被移除 (0x23)"):
        state, _ = read_varint_buf(bc[-1], 8)
        check(state == 0, f"状态={state}")

    # 1.8 没有光照包，只能重发这一段区块，客户端才会重新算光
    sec = [d for p, d in events if p == 0x21]
    if check(len(sec) >= 1, "收到区块段更新 (0x21)"):
        cx, cz = struct.unpack(">ii", sec[-1][0:8])
        ground = sec[-1][8]
        mask = struct.unpack(">H", sec[-1][9:11])[0]
        check(ground == 0, f"groundUp={ground}（只更新一段）")
        check((cx, cz) == (0, 0) and mask == 1, f"区块={cx},{cz} mask={mask:#06x}")

    so = [d for p, d in events if p == 0x0E]
    if check(len(so) >= 1, "掉落物实体生成了 (0x0E)"):
        _, pos = read_varint_buf(so[0], 0)
        typ = so[0][pos]
        pos += 1
        ox, oy, oz = struct.unpack(">iii", so[0][pos:pos + 12])
        pos += 12
        pos += 2                                    # pitch + yaw
        data = struct.unpack(">i", so[0][pos:pos + 4])[0]
        pos += 4
        vx, vy, vz = struct.unpack(">hhh", so[0][pos:pos + 6])
        pos += 6
        check(typ == 2, f"实体类型={typ}（2=物品）")
        check(abs(ox / 32 - (x + 0.5)) < 0.2, f"x={ox / 32:.2f}")
        check(abs(oy / 32 - (y + 0.25)) < 0.3, f"y={oy / 32:.2f}")
        check(data != 0, f"data={data}（速度有效）")
        check(vy > 0, f"往上弹 vy={vy}")
        check(pos == len(so[0]),
              f"包刚好用完 {pos}/{len(so[0])} 字节（1.8 的速度字段少了客户端整包丢）")

    md = [d for p, d in events if p == 0x1C]
    if check(len(md) >= 1, "掉的是什么东西 (0x1C)"):
        _, pos = read_varint_buf(md[0], 0)
        tag = md[0][pos]
        idx = tag & 31          # 1.8：低 5 位是索引
        typ = tag >> 5          # 高 3 位是类型
        pos += 1
        item_id, count, damage = struct.unpack(">hbh", md[0][pos:pos + 5])
        check(tag == 0xAA, f"头字节={tag:#04x}（5<<5|10，写成两个字节客户端会崩）")
        check(idx == 10, f"索引={idx}（10=物品）")
        check(typ == 5, f"类型={typ}（5=Slot）")
        check(item_id == 4, f"物品 id={item_id}（石头掉圆石=4）")
        check(count == 1, f"数量={count}")

    check(any(p == 0x0D for p, _ in events), "被吸走了 (0x0D Collect Item)")

    ss = [d for p, d in events if p == 0x2F]
    if check(len(ss) >= 1, "背包收到东西 (0x2F)"):
        slot = struct.unpack(">h", ss[-1][1:3])[0]
        item_id, count, damage = struct.unpack(">hbh", ss[-1][3:8])
        check(36 <= slot <= 44, f"落在快捷栏槽位 {slot}")
        check(item_id == 4 and count == 1, f"物品 id={item_id} 数量={count}")

    c.sock.close()
    print()
    print("RESULT:", "FAIL" if FAILS else "PASS")
    return 1 if FAILS else 0


if __name__ == "__main__":
    sys.exit(main())
