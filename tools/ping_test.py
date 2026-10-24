#!/usr/bin/env python3
"""按 1.8.9 (proto 47) 客户端的真实顺序打一遍服务端，验证：
  1. 分帧（VarInt 长度前缀）能否处理被 TCP 切碎的包
  2. status 返回的 JSON 是否含 protocol=47 / version=1.8.9
  3. ping 的 long 是否原样回传
用法: python tools/ping_test.py [host] [port]
"""
import json
import socket
import struct
import sys
import time

HOST = sys.argv[1] if len(sys.argv) > 1 else "127.0.0.1"
PORT = int(sys.argv[2]) if len(sys.argv) > 2 else 25565

PROTO = 47


def varint(v):
    v &= 0xFFFFFFFF
    out = b""
    while True:
        b = v & 0x7F
        v >>= 7
        out += bytes([b | 0x80 if v else b])
        if not v:
            return out


def recv_exact(sock, n):
    buf = b""
    while len(buf) < n:
        chunk = sock.recv(n - len(buf))
        if not chunk:
            raise EOFError("连接被关闭")
        buf += chunk
    return buf


def read_varint(sock):
    num = 0
    shift = 0
    while True:
        b = recv_exact(sock, 1)[0]
        num |= (b & 0x7F) << shift
        if not b & 0x80:
            return num
        shift += 7


def read_packet(sock):
    length = read_varint(sock)
    data = recv_exact(sock, length)
    pos = 0
    pid = 0
    shift = 0
    while True:
        b = data[pos]
        pos += 1
        pid |= (b & 0x7F) << shift
        if not b & 0x80:
            break
        shift += 7
    return pid, data[pos:]


def read_string(payload, pos=0):
    n = 0
    shift = 0
    while True:
        b = payload[pos]
        pos += 1
        n |= (b & 0x7F) << shift
        if not b & 0x80:
            break
        shift += 7
    return payload[pos:pos + n].decode("utf-8"), pos + n


def packet(pid, payload=b""):
    body = varint(pid) + payload
    return varint(len(body)) + body


def main():
    ok = True
    sock = socket.create_connection((HOST, PORT), timeout=5)

    host = HOST.encode()
    handshake = (
        varint(PROTO)
        + varint(len(host)) + host
        + struct.pack(">H", PORT)
        + varint(1)  # next state = status
    )
    raw = packet(0x00, handshake)

    # 故意把包切成 3 段分别发，模拟 MSS 分片 / TCP 粘包
    third = max(1, len(raw) // 3)
    for piece in (raw[:third], raw[third:third * 2], raw[third * 2:]):
        sock.sendall(piece)
        time.sleep(0.05)
    print(f"[1] 握手包 {len(raw)} 字节，拆成 3 段发送完成")

    sock.sendall(packet(0x00))  # status request
    pid, payload = read_packet(sock)
    if pid != 0x00:
        print(f"  ✗ 期望 status 响应 0x00，收到 {pid:#x}")
        return 1

    text, _ = read_string(payload)
    status = json.loads(text)
    print(f"  ✓ status 包 ID=0x00，JSON {len(text)} 字节")
    print(f"  {json.dumps(status, ensure_ascii=False)}")

    ver = status.get("version", {})
    if ver.get("protocol") != PROTO:
        print(f"  ✗ protocol 应为 {PROTO}，实际 {ver.get('protocol')}")
        ok = False
    if ver.get("name") != "1.8.9":
        print(f"  ✗ version.name 应为 1.8.9，实际 {ver.get('name')}")
        ok = False

    payload_long = 0x1122334455667788
    sock.sendall(packet(0x01, struct.pack(">q", payload_long)))
    pid, payload = read_packet(sock)
    echoed = struct.unpack(">q", payload)[0]
    if pid == 0x01 and echoed == payload_long:
        print(f"  ✓ ping 0x01 回传一致: {echoed:#x}")
    else:
        print(f"  ✗ ping 回传不一致: pid={pid:#x} value={echoed:#x}")
        ok = False

    sock.close()

    # 再走一次登录入口，确认状态机切到 login 不炸
    sock = socket.create_connection((HOST, PORT), timeout=5)
    name = b"Steve"
    login_hs = (
        varint(PROTO) + varint(len(host)) + host
        + struct.pack(">H", PORT) + varint(2)
    )
    sock.sendall(packet(0x00, login_hs))
    sock.sendall(packet(0x00, varint(len(name)) + name))
    time.sleep(0.2)
    sock.close()
    print("[2] 登录握手 + Login Start 已发送（服务端日志应出现 user=Steve）")

    print("RESULT:", "PASS" if ok else "FAIL")
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
