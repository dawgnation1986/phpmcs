#!/usr/bin/env python3
"""皮肤验收：标签列表（0x38）里到底有没有 textures 属性。

没这一段，别人的客户端只能渲染默认 Steve —— 这就是「后面进来的看不到皮肤」。

用法: python tools/skin_test.py [host] [port] [正版名] [不存在名]
"""
import os
import struct
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from play_test import Client, read_string, read_varint_buf  # noqa: E402

HOST = sys.argv[1] if len(sys.argv) > 1 else "127.0.0.1"
PORT = int(sys.argv[2]) if len(sys.argv) > 2 else 25565
REAL = sys.argv[3] if len(sys.argv) > 3 else "rain_0v0"
FAKE = sys.argv[4] if len(sys.argv) > 4 else "definitely_not_a_name_xyz"
FAILS = []


def check(cond, msg):
    print(("  ✓ " if cond else "  ✗ ") + msg)
    if not cond:
        FAILS.append(msg)
    return cond


def parse_tab_add(d):
    p = 0
    action, p = read_varint_buf(d, p)
    count, p = read_varint_buf(d, p)
    uuid = d[p:p + 16].hex()
    p += 16
    name, p = read_string(d, p)
    prop_count, p = read_varint_buf(d, p)
    props = []
    for _ in range(prop_count):
        pname, p = read_string(d, p)
        value, p = read_string(d, p)
        signed = d[p]
        p += 1
        sig = ""
        if signed:
            sig, p = read_string(d, p)
        props.append((pname, value, sig))
    return action, count, uuid, name, props


def probe(name, expect_textures):
    c = Client(name)
    pid, _ = c.login()
    if pid != 0x02:
        check(False, f"{name} 登录失败")
        return
    tabs = [d for p, d in c.collect(6.0) if p == 0x38]
    if not check(len(tabs) >= 1, f"{name}: 收到标签列表 {len(tabs)} 条"):
        c.sock.close()
        return
    action, count, uuid, pname, props = parse_tab_add(tabs[0])
    check(action == 0 and count == 1, f"{name}: action={action} count={count}")
    check(pname.lower() == name.lower(), f"{name}: 档案名字 = {pname}")

    if expect_textures:
        if check(len(props) == 1, f"{name}: 属性 {len(props)} 条"):
            pn, value, sig = props[0]
            check(pn == "textures", f"属性名 = {pn}")
            check(len(value) > 200, f"value 长度 {len(value)}（base64 的皮肤 JSON）")
            check(len(sig) > 200, f"签名长度 {len(sig)}（客户端要靠它验真）")
    else:
        check(len(props) == 0, f"{name}: 查不到就发 0 条属性（不硬造假签名），实际 {len(props)}")

    c.sock.close()


def main():
    print("[1] 正版名应该有带签名的皮肤属性")
    probe(REAL, True)

    print("[2] 查不到的名字不能硬编一个假属性")
    probe(FAKE, False)

    print("[3] 离线号不能借用同名正版号的皮肤")
    probe("nave", False)

    print()
    print("RESULT:", "FAIL" if FAILS else "PASS")
    return 1 if FAILS else 0


if __name__ == "__main__":
    sys.exit(main())
