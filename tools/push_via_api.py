#!/usr/bin/env python3
"""这台机器上 git 的 443 被代理挡了（github.com 502），但 api.github.com 能通，
所以用 Git Data API 推：建 blob -> 建 tree -> 建 commit -> 更新 ref。

用法: GH_TOKEN=xxx python tools/push_via_api.py [owner/repo]
token 只从环境变量读，不写盘。
"""
import base64
import json
import os
import subprocess
import sys

TOKEN = os.environ.get("GH_TOKEN", "")
REPO = sys.argv[1] if len(sys.argv) > 1 else "dawgnation1986/phpmcs"
API = "https://api.github.com"


def api(method, path, payload=None):
    cmd = [
        "curl", "-s", "-m", "30", "-X", method,
        "-H", f"Authorization: token {TOKEN}",
        "-H", "Accept: application/vnd.github+json",
    ]
    tmp = None
    if payload is not None:
        # base64 塞命令行会超 Windows 32KB 上限，落文件再 @
        tmp = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", ".cache", "_payload.json")
        os.makedirs(os.path.dirname(tmp), exist_ok=True)
        with open(tmp, "w", encoding="utf-8") as fh:
            json.dump(payload, fh)
        cmd += ["-H", "Content-Type: application/json", "-d", "@" + os.path.abspath(tmp)]
    cmd.append(API + path)
    out = subprocess.run(cmd, capture_output=True, text=True).stdout
    if tmp and os.path.exists(tmp):
        os.remove(tmp)
    try:
        return json.loads(out)
    except json.JSONDecodeError:
        sys.exit(f"接口返回不是 JSON: {out[:300]}")


def main():
    if TOKEN == "":
        sys.exit("先 export GH_TOKEN=...")

    files = subprocess.run(
        ["git", "ls-files"], capture_output=True, text=True
    ).stdout.split()
    if not files:
        sys.exit("没有文件可推")

    head = api("GET", f"/repos/{REPO}/git/ref/heads/main")
    parent = head.get("object", {}).get("sha")
    if parent is None:
        sys.exit(f"读不到 main 分支: {head}")

    tree = []
    for f in files:
        blob = api("POST", f"/repos/{REPO}/git/blobs", {
            "content": base64.b64encode(open(f, "rb").read()).decode(),
            "encoding": "base64",
        })
        if "sha" not in blob:
            sys.exit(f"建 blob 失败 {f}: {blob}")
        tree.append({"path": f, "mode": "100644", "type": "blob", "sha": blob["sha"]})

    made = api("POST", f"/repos/{REPO}/git/trees", {"tree": tree})
    if "sha" not in made:
        sys.exit(f"建 tree 失败: {made}")

    message = subprocess.run(
        ["git", "log", "-1", "--pretty=%B"], capture_output=True, text=True
    ).stdout.strip()
    commit = api("POST", f"/repos/{REPO}/git/commits", {
        "message": message or "update",
        "tree": made["sha"],
        "parents": [parent],
    })
    if "sha" not in commit:
        sys.exit(f"建 commit 失败: {commit}")

    ref = api("PATCH", f"/repos/{REPO}/git/refs/heads/main",
              {"sha": commit["sha"], "force": True})
    if "ref" not in ref:
        sys.exit(f"更新分支失败: {ref}")

    print(f"{len(files)} 个文件已推送到 {REPO}@{commit['sha'][:7]}")


if __name__ == "__main__":
    main()
