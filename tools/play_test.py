#!/usr/bin/env python3
"""按 1.8.9 客户端的真实流程走完一整遍登录，逐字段核对服务端发的东西。

不是「连上就算过」：它会把区块数据按 1.8 的格式拆开，验证
  - Login Success 里的 UUID 是不是 Java 那个离线 UUID（MD5 v3）
  - Join Game 的字段顺序对不对
  - 区块负载长度 == bitmask 位数 * 8192 + 256，且草方块真的在第 3 层
  - 出生点 / 位置同步能不能解析
  - 聊天能不能原样回声
  - 两个人进同一个服，能不能互相看见、动起来有没有广播

用法: python tools/play_test.py [host] [port]
"""
import hashlib
import json
import socket
import struct
import sys
import time

HOST = sys.argv[1] if len(sys.argv) > 1 else "127.0.0.1"
PORT = int(sys.argv[2]) if len(sys.argv) > 2 else 25565
PROTO = 47

FAILS = []


def check(cond, msg):
    print(("  ✓ " if cond else "  ✗ ") + msg)
    if not cond:
        FAILS.append(msg)
    return cond


# ---------- 编解码 ----------

def varint(v):
    v &= 0xFFFFFFFF
    out = b""
    while True:
        b = v & 0x7F
        v >>= 7
        out += bytes([b | 0x80 if v else b])
        if not v:
            return out


class NeedMore(Exception):
    pass


def read_varint_buf(buf, pos):
    num = 0
    shift = 0
    while True:
        if pos >= len(buf):
            raise NeedMore
        b = buf[pos]
        pos += 1
        num |= (b & 0x7F) << shift
        if not b & 0x80:
            if num & 0x80000000:
                num -= 0x100000000
            return num, pos
        shift += 7


def read_string(buf, pos=0):
    n, pos = read_varint_buf(buf, pos)
    if pos + n > len(buf):
        raise NeedMore
    return buf[pos:pos + n].decode("utf-8"), pos + n


class Client:
    def __init__(self, name):
        self.name = name
        self.sock = socket.create_connection((HOST, PORT), timeout=5)
        self.sock.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
        self.buf = b""
        self.entity_id = 0

    def send(self, pid, payload=b""):
        body = varint(pid) + payload
        self.sock.sendall(varint(len(body)) + body)

    def _fill(self):
        d = self.sock.recv(65536)
        if not d:
            raise EOFError("服务端关了连接")
        self.buf += d

    def read_packet(self):
        while True:
            try:
                length, pos = read_varint_buf(self.buf, 0)
            except NeedMore:
                self._fill()
                continue
            if len(self.buf) - pos < length:
                self._fill()
                continue
            payload = self.buf[pos:pos + length]
            self.buf = self.buf[pos + length:]
            pid, p = read_varint_buf(payload, 0)
            if pid == 0x40:
                text, _ = read_string(payload, p)
                raise RuntimeError("服务端断开: " + text)
            return pid, payload[p:]

    def collect(self, seconds=0.8):
        """把接下来 seconds 内能收到的包都收下来。"""
        self.sock.settimeout(0.15)
        out = []
        deadline = time.time() + seconds
        while time.time() < deadline:
            try:
                out.append(self.read_packet())
            except socket.timeout:
                continue
            except NeedMore:
                continue
        self.sock.settimeout(5)
        return out

    def login(self):
        host = HOST.encode()
        hs = (varint(PROTO) + varint(len(host)) + host
              + struct.pack(">H", PORT) + varint(2))
        self.send(0x00, hs)
        n = self.name.encode()
        self.send(0x00, varint(len(n)) + n)
        pid, data = self.read_packet()
        return pid, data


def offline_uuid(name):
    h = bytearray(hashlib.md5(("OfflinePlayer:" + name).encode()).digest())
    h[6] = (h[6] & 0x0F) | 0x30
    h[8] = (h[8] & 0x3F) | 0x80
    x = h.hex()
    return f"{x[0:8]}-{x[8:12]}-{x[12:16]}-{x[16:20]}-{x[20:32]}"


def main():
    print("[1] 单客户端走完登录")

    a = Client("Steve")
    t0 = time.time()
    pid, data = a.login()
    elapsed = time.time() - t0
    check(pid == 0x02, f"收到 Login Success (0x02)，耗时 {elapsed * 1000:.0f}ms")
    if pid != 0x02:
        return 1

    uuid, pos = read_string(data, 0)
    name, pos = read_string(data, pos)
    want = offline_uuid("Steve")
    check(name == "Steve", f"用户名 = {name}")
    check(uuid == want, f"离线 UUID 正确: {uuid}")
    if uuid != want:
        check(False, f"期望 {want}")

    # 收 play 包，直到拿到区块和位置同步
    chunks = []
    join = None
    spawn_pos = None
    pos_look = None
    deadline = time.time() + 6
    while time.time() < deadline:
        pid, d = a.read_packet()
        if pid == 0x01 and join is None:
            join = d
        elif pid == 0x21:
            chunks.append(d)
        elif pid == 0x05:
            spawn_pos = d
        elif pid == 0x08:
            pos_look = d
        if pos_look is not None and chunks:
            break

    if check(join is not None, "收到 Join Game (0x01)"):
        eid, gm, dim, diff, maxp = struct.unpack(">iBbBB", join[0:8])
        level, p = read_string(join, 8)
        reduced = join[p]
        a.entity_id = eid
        check(eid > 0, f"entityId = {eid}")
        check(gm in (0, 1, 2, 3), f"gamemode = {gm}（1=创造）")
        check(dim == 0, f"维度 = {dim}（0=主世界）")
        check(level == "flat", f"levelType = {level}")
        check(reduced == 0, "reducedDebugInfo = 0")

    check(len(chunks) > 0, f"收到区块包 {len(chunks)} 个")

    if chunks:
        d = chunks[0]
        cx, cz = struct.unpack(">ii", d[0:8])
        ground = d[8]
        mask = struct.unpack(">H", d[9:11])[0]
        size, p = read_varint_buf(d, 11)
        payload = d[p:p + size]
        popcount = bin(mask).count("1")
        expect = popcount * (8192 + 2048 + 2048) + 256  # 方块 + 方块光 + 天空光 + 生物群系
        check(ground == 1, f"groundUp = {ground}")
        check(mask == 0x0001, f"bitmask = {mask:#06x}（只有第 0 层有方块）")
        check(size == len(payload), f"size 字段 = 实际负载 {size}")
        check(len(payload) == expect,
              f"负载 {len(payload)} 字节 = {popcount}×(8192+2048+2048) + 256")

        # 方块索引 (y<<8)|(z<<4)|x，值是小端 ushort
        bedrock = struct.unpack("<H", payload[(0 << 8) * 2:(0 << 8) * 2 + 2])[0]
        grass = struct.unpack("<H", payload[(3 << 8) * 2:(3 << 8) * 2 + 2])[0]
        air = struct.unpack("<H", payload[(5 << 8) * 2:(5 << 8) * 2 + 2])[0]
        check(bedrock == 7 << 4, f"y=0 基岩 = {bedrock}（期望 {7 << 4}）")
        check(grass == 2 << 4, f"y=3 草方块 = {grass}（期望 {2 << 4}）")
        check(air == 0, f"y=5 空气 = {air}")
        check(payload[-256:] == b"\x01" * 256, "生物群系 256 字节 = 平原(1)")

        skylight = payload[8192 + 2048:8192 + 4096]
        check(skylight == b"\xff" * 2048, "天空光全 15（不会黑）")

    if check(spawn_pos is not None, "收到 Spawn Position (0x05)"):
        packed = struct.unpack(">q", spawn_pos)[0]
        sx = packed >> 38
        if sx >= 1 << 25:
            sx -= 1 << 26
        sy = (packed >> 26) & 0xFFF
        sz = packed & 0x3FFFFFF
        if sz >= 1 << 25:
            sz -= 1 << 26
        check((sx, sy, sz) == (0, 4, 0), f"出生点 = {sx},{sy},{sz}（期望 0,4,0）")

    if check(pos_look is not None, "收到 Player Position And Look (0x08)"):
        x, y, z = struct.unpack(">ddd", pos_look[0:24])
        check(abs(y - 4.0) < 0.01, f"玩家脚底 y = {y:.3f}（站在草方块顶上）")

        # 客户端回一个位置，模拟落地
        a.send(0x06, struct.pack(">dddff?", x, y, z, 0.0, 0.0, True))

    print("[2] 聊天回声")
    a.send(0x01, varint(len(b"hello from php server")) + b"hello from php server")
    got = None
    for pid, d in a.collect(1.5):
        if pid == 0x02:
            text, _ = read_string(d, 0)
            try:
                j = json.loads(text)
            except json.JSONDecodeError:
                continue
            if "hello from php server" in j.get("text", ""):
                got = j["text"]
    check(got is not None, f"回声内容: {got!r}")

    print("[3] 两个人互相可见")
    b = Client("Alex")
    pid, data = b.login()
    check(pid == 0x02, "Alex 登录成功")
    uuid_b, _ = read_string(data, 0)

    bpackets = b.collect(1.0)
    ids = [p for p, _ in bpackets]
    check(0x01 in ids, "Alex 收到 Join Game")

    # 服务端会记住下线位置，所以不能写死移动目标 —— 否则第二次跑时
    # 目标就是它现在站的地方，服务器判定「没动」自然不广播。
    bpos = None
    for p, d in bpackets:
        if p == 0x08:
            bpos = struct.unpack(">ddd", d[0:24])
    target_x = (bpos[0] + 2.0) if bpos else 3.5

    events = a.collect(1.5)
    check(any(p == 0x38 for p, _ in events), "Steve 收到标签列表新增 (0x38)")
    spawned = [d for p, d in events if p == 0x0C]
    check(len(spawned) == 1, f"Steve 收到 Alex 的出生实体 (0x0C) x{len(spawned)}")

    if spawned:
        aid, p = read_varint_buf(spawned[0], 0)   # entityId 是 VarInt，不是定长 int
        got_uuid = spawned[0][p:p + 16].hex()
        check(got_uuid == uuid_b.replace("-", ""), f"实体 UUID 一致: {got_uuid[:8]}…")
        b.entity_id = aid

    # Alex 走两步，Steve 应该收到 Entity Teleport
    b.send(0x06, struct.pack(">dddff?", target_x, 4.0, 0.5, 90.0, 0.0, True))
    tel = [d for p, d in a.collect(1.0) if p == 0x18]
    check(len(tel) >= 1, f"Steve 收到移动广播 (0x18) x{len(tel)}")
    if tel:
        eid, p = read_varint_buf(tel[-1], 0)
        fx, fy, fz = struct.unpack(">iii", tel[-1][p:p + 12])
        check(eid == b.entity_id, f"广播的实体 id = {eid}（Alex 是 {b.entity_id}）")
        check(abs(fx / 32 - target_x) < 0.05,
              f"广播坐标 x = {fx / 32:.2f}（Alex 走到 {target_x:.2f}）")

    a.sock.close()
    b.sock.close()

    print()
    if FAILS:
        print(f"RESULT: FAIL ({len(FAILS)} 项)")
        for f in FAILS:
            print("  -", f)
        return 1
    print("RESULT: PASS")
    return 0


if __name__ == "__main__":
    sys.exit(main())
