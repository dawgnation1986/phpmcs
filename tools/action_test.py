#!/usr/bin/env python3
"""Q 丢物品 + 挥手广播。

1) 按 Q（Player Digging status=4）：手上那格少一个，地上多一个物品实体
2) Ctrl+Q（status=3）：整组丢出去
3) 挥手（0x0A）：旁边的玩家要收到 0x0B Animation

用法: python tools/action_test.py [host] [port]
"""
import os
import struct
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from play_test import Client, read_varint_buf  # noqa: E402

HOST = sys.argv[1] if len(sys.argv) > 1 else "127.0.0.1"
PORT = int(sys.argv[2]) if len(sys.argv) > 2 else 25565
FAILS = []


def check(cond, msg):
    print(("  ✓ " if cond else "  ✗ ") + msg)
    if not cond:
        FAILS.append(msg)
    return cond


def main():
    a = Client("Actor")
    check(a.login()[0] == 0x02, "登录成功")
    a.collect(1.2)

    # 先往 36 号槽塞 5 个圆石（用创造背包的包，反正服务器只认槽位）
    slot = 36
    item = struct.pack(">hbh", 4, 5, 0) + b"\x00"
    a.send(0x10, struct.pack(">h", slot) + item)
    check(any(p == 0x2F for p, _ in a.collect(0.8)), "背包里放了 5 个圆石")

    # ---- 按 Q：丢一个 ----
    a.send(0x07, bytes([4]) + struct.pack(">q", 0) + bytes([1]))
    ev = a.collect(1.2)
    ss = [d for p, d in ev if p == 0x2F]
    so = [d for p, d in ev if p == 0x0E]
    if check(len(ss) >= 1, "按 Q 后收到 Set Slot"):
        cnt = ss[-1][4]
        check(cnt == 4, f"槽位剩 {cnt} 个（期望 4）")
    if check(len(so) >= 1, "地上出现物品实体 (0x0E)"):
        _, pos = read_varint_buf(so[-1], 0)
        typ = so[-1][pos]
        check(typ == 2, f"实体类型={typ}")

    # ---- Ctrl+Q：整组丢 ----
    a.send(0x07, bytes([3]) + struct.pack(">q", 0) + bytes([1]))
    ev = a.collect(1.2)
    ss = [d for p, d in ev if p == 0x2F]
    so = [d for p, d in ev if p == 0x0E]
    if check(len(ss) >= 1, "Ctrl+Q 后收到 Set Slot"):
        emptied = any(struct.unpack(">h", d[3:5])[0] == -1 for d in ss)
        check(emptied, "槽位被清空（收到 itemId=-1）")
    check(len(so) >= 1, "整组丢出（0x0E）")

    # ---- 挥手：另一个人要收到 Animation ----
    b = Client("Watcher")
    check(b.login()[0] == 0x02, "第二个玩家登录")
    b.collect(1.5)

    a.send(0x0A)  # 挥手
    ev = b.collect(1.0)
    anim = [d for p, d in ev if p == 0x0B]
    if check(len(anim) >= 1, "对面收到挥手 (0x0B Animation)"):
        eid, _ = read_varint_buf(anim[-1], 0)
        check(eid > 0, f"挥手实体 id={eid}")

    a.sock.close()
    b.sock.close()
    print()
    print("RESULT:", "FAIL" if FAILS else "PASS")
    return 1 if FAILS else 0


if __name__ == "__main__":
    sys.exit(main())
