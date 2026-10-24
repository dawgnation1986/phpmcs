#!/usr/bin/env python3
"""十分钟后掉线吗？专门验证 Keep Alive 这条路。

服务端每 10 秒发一次 0x00，客户端必须原样回一个 0x00（VarInt id）。
不回 → 30 秒后踢人。这个测试如果不过，人站在世界里发呆就会被踢。
"""
import os
import socket
import sys
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from play_test import Client, read_varint_buf, read_string, varint  # noqa: E402

FAILS = []


def check(cond, msg):
    print(("  ✓ " if cond else "  ✗ ") + msg)
    if not cond:
        FAILS.append(msg)


def main():
    c = Client("Keeper")
    pid, data = c.login()
    check(pid == 0x02, "登录成功")

    c.collect(1.0)  # 把进服那一堆包收干净
    print("  等 Keep Alive（最多 12 秒）…")
    t0 = time.time()
    ka = None
    c.sock.settimeout(0.2)
    while time.time() - t0 < 12:
        try:
            pid, d = c.read_packet()
        except socket.timeout:
            continue
        if pid == 0x00:
            ka, _ = read_varint_buf(d, 0)
            print(f"  ✓ {time.time() - t0:.1f}s 后收到 Keep Alive id={ka}")
            c.send(0x00, varint(ka))
            break
    c.sock.settimeout(5)

    if ka is None:
        check(False, "12 秒内没收到 Keep Alive")

    time.sleep(0.5)
    c.send(0x01, varint(len(b"still here")) + b"still here")
    echoed = any(
        pid == 0x02 and "still here" in read_string(d, 0)[0]
        for pid, d in c.collect(1.5)
    )
    check(echoed, "回应 Keep Alive 后连接仍然可用（聊天能回声）")

    c.sock.close()
    print()
    print("RESULT:", "FAIL" if FAILS else "PASS")
    return 1 if FAILS else 0


if __name__ == "__main__":
    sys.exit(main())
