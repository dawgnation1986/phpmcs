#!/usr/bin/env python3
"""验证三件刚补上的事：创造背包、挖方块、模式/能力。

1) 进服第一件事应该收到 Player Abilities，flags 必须有 无敌|允许飞|创造
2) 从背包拿方块（0x10）必须收到 Set Slot（0x2F）回声，否则方块会消失
3) 先放一块石头到 (0,5,0) 再挖掉，两次都要收到 Block Change（0x23）
   —— 放完再挖，跑多少遍都不会因为「已经空了」而失效
4) /gm 0 必须收到 Change Game State（0x2B）+ 新的 Abilities

用法: python tools/dig_test.py [host] [port]
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


def block_change(payload):
    pos = struct.unpack(">q", payload[0:8])[0]
    state, _ = read_varint_buf(payload, 8)
    return unpack_pos(pos), state


def main():
    c = Client("Digger")
    pid, data = c.login()
    check(pid == 0x02, "登录成功")

    packets = c.collect(1.2)

    # 1) 能力
    abil = [d for p, d in packets if p == 0x39]
    if check(len(abil) >= 1, f"收到 Player Abilities x{len(abil)}"):
        flags = abil[0][0]
        check(bool(flags & 0x01), f"flags={flags:#04x} 含无敌 (0x01)")
        check(bool(flags & 0x04), f"flags={flags:#04x} 含允许飞 (0x04)")
        check(bool(flags & 0x08), f"flags={flags:#04x} 含创造 (0x08)")

    # 2) 创造背包拿方块
    slot = 36
    item = struct.pack(">hbh", 1, 64, 0) + b"\x00"   # 石头 ×64
    c.send(0x10, struct.pack(">h", slot) + item)

    got = None
    for pid, d in c.collect(1.0):
        if pid == 0x2F:
            got = d
    if check(got is not None, "收到 Set Slot (0x2F)"):
        win = struct.unpack(">b", got[0:1])[0]
        sl = struct.unpack(">h", got[1:3])[0]
        echo = got[3:]
        check(win == 0, f"windowId={win}")
        check(sl == slot, f"槽位={sl}")
        check(echo == item, f"物品字节原样回传（{len(echo)} 字节）")

    # 3a) 在 (0,5,0) 放一块石头（打 (0,4,0) 的上面那面）
    place = (struct.pack(">q", pack_pos(0, 4, 0)) + bytes([1])
             + struct.pack(">h", 1) + bytes([1]) + struct.pack(">h", 0)
             + bytes([0, 0, 0]))
    c.send(0x08, place)
    bc = [d for p, d in c.collect(1.2) if p == 0x23]
    if check(len(bc) >= 1, f"放方块收到 Block Change x{len(bc)}"):
        pos, state = block_change(bc[0])
        check(pos == (0, 5, 0), f"坐标={pos}")
        check(state == 1 << 4, f"状态={state}（石头 1<<4）")

    # 3b) 再挖掉它（此时还在创造模式，原版不掉东西）
    c.send(0x07, bytes([0]) + struct.pack(">q", pack_pos(0, 5, 0)) + bytes([1]))
    events = c.collect(1.2)
    bc = [d for p, d in events if p == 0x23]
    if check(len(bc) >= 1, f"挖方块收到 Block Change x{len(bc)}"):
        pos, state = block_change(bc[0])
        check(pos == (0, 5, 0), f"坐标={pos}")
        check(state == 0, f"状态={state}（0=空气）")
    check(not any(p == 0x0E for p, _ in events), "创造模式不掉落（没收到 0x0E）")

    # 4) 切生存模式
    cmd = b"/gm 0"
    c.send(0x01, varint(len(cmd)) + cmd)
    after = c.collect(1.2)
    gs = [d for p, d in after if p == 0x2B]
    ab2 = [d for p, d in after if p == 0x39]
    if check(len(gs) >= 1, "收到 Change Game State (0x2B)"):
        reason = gs[0][0]
        value = struct.unpack(">f", gs[0][1:5])[0]
        check(reason == 3, f"reason={reason}（3=切模式）")
        check(abs(value) < 0.01, f"gamemode={value}")
    if check(len(ab2) >= 1, "切完模式补发 Abilities"):
        check(not (ab2[-1][0] & 0x04), f"生存模式 flags={ab2[-1][0]:#04x} 不再允许飞")

    c.sock.close()
    print()
    print("RESULT:", "FAIL" if FAILS else "PASS")
    return 1 if FAILS else 0


if __name__ == "__main__":
    sys.exit(main())
