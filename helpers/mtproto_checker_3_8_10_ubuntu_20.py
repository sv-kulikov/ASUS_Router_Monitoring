#!/usr/bin/env python3
"""
mtproto_checker.py
------------------------------------------------------
Run : python mtproto_checker.py /path/to/mtproto_checker.yaml
Open: http://localhost:8899/
      http://localhost:8899/?refresh=30         (kept for compatibility; now means max cap)
      http://localhost:8899/?force=1            (forces next refresh ASAP)

Notes:
- Hybrid MTProxy checker:
    * dd... secrets  -> checked via Telethon (connect + tiny RPC)
    * ee... secrets with embedded domain -> checked via real TLS handshake (SNI = embedded domain)
- Random refresh interval between X..Y seconds (defaults: 300..600).
- Refreshes immediately on start (no initial sleep).
- Adds alive/dead lists & counts to JSON.
- Windows: uses Selector loop inside checker thread to avoid Proactor noise.
"""

import asyncio
import base64
import json
import logging
import random
import socket
import ssl
import sys
import threading
import time
from contextlib import suppress
from dataclasses import dataclass, asdict
from datetime import datetime
from http.server import HTTPServer, BaseHTTPRequestHandler
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple
from urllib.parse import urlparse, parse_qs

import yaml  # pip install pyyaml
from telethon import TelegramClient
from telethon.tl.functions.help import GetConfigRequest

# Silence Telethon noisy logs
for name in ("telethon", "telethon.network", "telethon.network.connection"):
    logging.getLogger(name).setLevel(logging.CRITICAL)

# ------------------- asyncio.to_thread compatibility (Python 3.8) -------------------
# asyncio.to_thread exists in Python 3.9+. Provide a polyfill for Python 3.8.
if not hasattr(asyncio, "to_thread"):

    async def _to_thread(func, /, *args, **kwargs):
        loop = asyncio.get_running_loop()
        return await loop.run_in_executor(None, lambda: func(*args, **kwargs))

    asyncio.to_thread = _to_thread  # type: ignore


# ------------------- MTProxy connection modes -------------------
def get_mtproxy_connection_classes() -> List[type]:
    classes: List[type] = []

    try:
        from telethon.network.connection import (
            ConnectionTcpMTProxyRandomizedIntermediate,
            ConnectionTcpMTProxyIntermediate,
            ConnectionTcpMTProxyAbridged,
        )
        classes.extend(
            [
                ConnectionTcpMTProxyRandomizedIntermediate,
                ConnectionTcpMTProxyIntermediate,
                ConnectionTcpMTProxyAbridged,
            ]
        )
    except Exception:
        pass

    if not classes:
        try:
            from telethon import connection as conn

            for attr in (
                "ConnectionTcpMTProxyRandomizedIntermediate",
                "ConnectionTcpMTProxyIntermediate",
                "ConnectionTcpMTProxyAbridged",
            ):
                if hasattr(conn, attr):
                    classes.append(getattr(conn, attr))
        except Exception:
            pass

    out: List[type] = []
    seen = set()
    for c in classes:
        if c and c not in seen:
            out.append(c)
            seen.add(c)

    if not out:
        raise ImportError(
            "No MTProxy connection classes found in Telethon. "
            "Expected one of: ConnectionTcpMTProxyRandomizedIntermediate / Intermediate / Abridged."
        )
    return out


MTProxyConnections = get_mtproxy_connection_classes()

# -------- refresh randomization defaults --------
DEFAULT_REFRESH_MIN = 300
DEFAULT_REFRESH_MAX = 600

DEFAULT_PORT = 8899
DEFAULT_CONFIG_FILE = Path(__file__).parent.parent.parent / "config" / "network" / "mtproto_checker.yaml"

data_lock = threading.Lock()
snapshot: Dict[str, Any] = {"timestamp": 0, "proxies": [], "meta": {}}

# Global refresh config (randomized each cycle)
refresh_min_s = DEFAULT_REFRESH_MIN
refresh_max_s = DEFAULT_REFRESH_MAX
next_refresh_in_s = 0  # updated after each cycle
force_refresh_event = threading.Event()

check_timeout_s = 10


# ------------------- secret normalization -------------------
def normalize_secret_to_hex_str(secret: Any) -> str:
    if secret is None:
        raise ValueError("Secret is missing")

    if isinstance(secret, (bytes, bytearray)):
        return bytes(secret).hex()

    if isinstance(secret, str):
        s = secret.strip().replace(" ", "")
        if s.lower().startswith("0x"):
            s = s[2:]

        try:
            bytes.fromhex(s)
            return s.lower()
        except ValueError:
            pass

        pad = (-len(s)) % 4
        s_padded = s + ("=" * pad)
        b = base64.b64decode(s_padded)
        return b.hex()

    raise ValueError(f"Unsupported secret type: {type(secret)}")


def looks_like_ee_domain_secret(secret_hex: str) -> bool:
    s = (secret_hex or "").lower().strip()
    if not s.startswith("ee"):
        return False
    if len(s) < 40:
        return False
    try:
        b = bytes.fromhex(s)
    except Exception:
        return False
    if len(b) <= 17:
        return False
    tail = b[17:]
    text = "".join(chr(x) for x in tail if 32 <= x <= 126)
    return "." in text and any(c.isalpha() for c in text)


def extract_domain_from_ee_secret(secret_hex: str) -> Optional[str]:
    try:
        b = bytes.fromhex(secret_hex.lower().strip())
    except Exception:
        return None
    if len(b) <= 17:
        return None
    tail = b[17:]

    allowed = set("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789.-")
    s = "".join(chr(x) for x in tail if 32 <= x <= 126)
    s = "".join(ch for ch in s if ch in allowed)

    s = s.strip(".-")
    if "." not in s:
        return None
    return s


def secret_variants(secret_hex: str) -> List[Tuple[str, str]]:
    s = (secret_hex or "").lower().strip()
    variants: List[Tuple[str, str]] = []

    def add(name: str, val: str):
        val = val.lower()
        if not val:
            return
        if len(val) % 2 != 0:
            return
        if not all(c in "0123456789abcdef" for c in val):
            return
        if val not in [v for _, v in variants]:
            variants.append((name, val))

    add("orig", s)

    if len(s) >= 4 and (s.startswith("ee") or s.startswith("dd")):
        add("strip_prefix", s[2:])

    if s.startswith("ee") and len(s) > 34:
        add("ee_to_dd", "dd" + s[2:])

    if s.startswith("dd") and len(s) > 34:
        add("dd_to_ee", "ee" + s[2:])

    return variants


# ------------------- parsing helpers -------------------
def parse_mtproto_link(link: str) -> Dict[str, Any]:
    u = urlparse(link)
    if u.scheme != "tg" or u.netloc != "proxy":
        raise ValueError(f"Not a tg://proxy link: {link}")

    qs = parse_qs(u.query)
    server = (qs.get("server") or [""])[0].strip()
    port_s = (qs.get("port") or [""])[0].strip()
    secret_raw = (qs.get("secret") or [""])[0]

    if not server:
        raise ValueError("Missing server in mtprotolink")
    if not port_s.isdigit():
        raise ValueError(f"Invalid port in mtprotolink: {port_s}")
    port = int(port_s)

    secret_hex = normalize_secret_to_hex_str(secret_raw)
    return {"server": server, "port": port, "secret_hex": secret_hex}


def load_config(yaml_path: str) -> Dict[str, Any]:
    with open(yaml_path, "r", encoding="utf-8") as f:
        cfg = yaml.safe_load(f) or {}

    api_id = cfg.get("api_id")
    api_hash = cfg.get("api_hash")

    if api_id is None or api_hash is None:
        raise SystemExit("Missing api_id/api_hash. Put them in YAML config.")

    try:
        api_id = int(api_id)
    except ValueError:
        raise SystemExit("api_id must be an integer.")

    proxies = cfg.get("proxies") or []
    if not isinstance(proxies, list) or not proxies:
        raise SystemExit("YAML must contain a non-empty 'proxies' list.")

    parsed = []
    for p in proxies:
        name = (p.get("name") or "").strip()
        link = (p.get("mtprotolink") or "").strip()
        if not name or not link:
            continue
        info = parse_mtproto_link(link)
        parsed.append({"name": name, "mtprotolink": link, **info})

    if not parsed:
        raise SystemExit("No valid proxies found in YAML 'proxies' list.")

    return {"api_id": api_id, "api_hash": api_hash, "proxies": parsed}


def ipport_sort_key(s: str):
    ip, port = s.rsplit(":", 1)
    return (tuple(int(x) for x in ip.split(".")), int(port))


# ------------------- error normalization -------------------
def normalize_error(e: BaseException) -> str:
    msg = str(e) or e.__class__.__name__

    if isinstance(e, ConnectionResetError) or "WinError 10054" in msg:
        return "connection_reset: remote host reset the connection"
    if "readexactly size can not be less than zero" in msg:
        return "mtproxy_garbage: negative read size (wrong mode/secret or not MTProxy)"
    if "bytes read on a total of" in msg:
        return "mtproxy_incomplete: remote closed mid-packet (wrong mode/secret or not MTProxy)"
    if "Proxy closed the connection" in msg:
        return "mtproxy_closed: proxy closed after initial payload"
    if "Connection to Telegram failed" in msg:
        return f"telegram_unreachable_via_proxy: {msg}"

    return f"{e.__class__.__name__}: {msg}"


# ------------------- TLS/SNI probe for ee-domain secrets -------------------
def tls_sni_probe(host: str, port: int, sni: str, timeout_s: int) -> Tuple[bool, Optional[str]]:
    try:
        ctx = ssl.create_default_context()
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE

        with socket.create_connection((host, port), timeout=timeout_s) as sock:
            sock.settimeout(timeout_s)
            with ctx.wrap_socket(sock, server_hostname=sni) as ssock:
                ssock.settimeout(timeout_s)
                with suppress(Exception):
                    ssock.send(b"\x17\x03\x03\x00\x01\x00")
                return True, None
    except Exception as e:
        return False, f"tls_probe_failed: {e.__class__.__name__}: {e}"


# ------------------- check logic -------------------
@dataclass
class ProxyStatus:
    name: str
    server: str
    port: int
    secret_preview: str

    ok: bool
    telegram_ok: bool
    mode: Optional[str]
    secret_variant: Optional[str]
    method: str
    latency_ms: Optional[int]
    last_checked: int
    last_ok: Optional[int]
    fail_streak: int
    error: Optional[str]


def proxy_key_ipport(p: Dict[str, Any]) -> str:
    return f"{p['server']}:{p['port']}"


async def _connect_only(client: TelegramClient, timeout_s: int) -> None:
    await asyncio.wait_for(client.connect(), timeout=timeout_s)


async def _rpc_probe(client: TelegramClient, timeout_s: int) -> None:
    await asyncio.wait_for(client(GetConfigRequest()), timeout=timeout_s)


async def _telethon_try_one_combo(
    api_id: int,
    api_hash: str,
    p: Dict[str, Any],
    timeout_s: int,
    conn_cls: type,
    secret_hex: str,
    rpc_timeout_s: int,
) -> Tuple[bool, bool, Optional[str]]:
    client = TelegramClient(
        session=None,
        api_id=api_id,
        api_hash=api_hash,
        connection=conn_cls,
        proxy=(p["server"], p["port"], secret_hex),
        timeout=timeout_s,
    )

    try:
        await _connect_only(client, timeout_s)
        try:
            await _rpc_probe(client, rpc_timeout_s)
            return True, True, None
        except Exception as e_rpc:
            return True, False, normalize_error(e_rpc)
    except Exception as e:
        return False, False, normalize_error(e)
    finally:
        with suppress(Exception):
            await client.disconnect()


async def check_one_proxy(api_id: int, api_hash: str, p: Dict[str, Any], timeout_s: int) -> Dict[str, Any]:
    t0 = time.time()
    secret_hex = p["secret_hex"].lower().strip()

    # 1) ee-domain path: TLS/SNI probe first
    if looks_like_ee_domain_secret(secret_hex):
        domain = extract_domain_from_ee_secret(secret_hex)
        if domain:
            ok_tls, err_tls = await asyncio.to_thread(
                tls_sni_probe, p["server"], p["port"], domain, max(3, min(8, timeout_s))
            )
            if ok_tls:
                return {
                    "ok": True,
                    "telegram_ok": False,
                    "mode": None,
                    "secret_variant": "orig",
                    "method": f"tls_sni:{domain}",
                    "latency_ms": int((time.time() - t0) * 1000),
                    "error": None,
                }
            tls_err = err_tls
        else:
            tls_err = "ee_domain_detected_but_domain_parse_failed"
    else:
        tls_err = None

    # 2) Telethon path
    secret_list = secret_variants(secret_hex)
    rpc_timeout_s = max(3, min(6, timeout_s - 2))
    last_err = None

    for conn_cls in MTProxyConnections:
        mode_name = getattr(conn_cls, "__name__", str(conn_cls))
        for variant_name, variant_secret in secret_list:
            connect_ok, rpc_ok, err = await _telethon_try_one_combo(
                api_id, api_hash, p, timeout_s, conn_cls, variant_secret, rpc_timeout_s
            )
            if connect_ok:
                return {
                    "ok": True,
                    "telegram_ok": bool(rpc_ok),
                    "mode": mode_name,
                    "secret_variant": variant_name,
                    "method": "telethon",
                    "latency_ms": int((time.time() - t0) * 1000),
                    "error": None if rpc_ok else err,
                }
            last_err = err

    return {
        "ok": False,
        "telegram_ok": False,
        "mode": None,
        "secret_variant": None,
        "method": "telethon",
        "latency_ms": int((time.time() - t0) * 1000),
        "error": tls_err or last_err,
    }


async def check_all(api_id: int, api_hash: str, proxies: List[Dict[str, Any]], timeout_s: int) -> List[Dict[str, Any]]:
    tasks = [check_one_proxy(api_id, api_hash, p, timeout_s) for p in proxies]
    results = await asyncio.gather(*tasks, return_exceptions=True)

    fixed: List[Dict[str, Any]] = []
    for r in results:
        if isinstance(r, Exception):
            fixed.append(
                {
                    "ok": False,
                    "telegram_ok": False,
                    "mode": None,
                    "secret_variant": None,
                    "method": "internal",
                    "latency_ms": 0,
                    "error": normalize_error(r),
                }
            )
        else:
            fixed.append(r)
    return fixed


# ------------------- asyncio runner (Windows Selector loop) -------------------
def run_asyncio(coro):
    if sys.platform.startswith("win"):
        asyncio.set_event_loop_policy(asyncio.WindowsSelectorEventLoopPolicy())
    loop = asyncio.new_event_loop()
    try:
        asyncio.set_event_loop(loop)
        return loop.run_until_complete(coro)
    finally:
        with suppress(Exception):
            loop.run_until_complete(loop.shutdown_asyncgens())
        with suppress(Exception):
            loop.close()
        asyncio.set_event_loop(None)


# ------------------- background refresher -------------------
class State:
    def __init__(self):
        self.fail_streak: Dict[str, int] = {}
        self.last_ok: Dict[str, int] = {}


STATE = State()


def compute_next_sleep_s() -> int:
    a = int(refresh_min_s)
    b = int(refresh_max_s)
    if a < 1:
        a = 1
    if b < a:
        b = a
    return random.randint(a, b)


def refresher(api_id: int, api_hash: str, proxies: List[Dict[str, Any]]):
    global snapshot, next_refresh_in_s

    # (1) Refresh immediately on start
    first = True

    while True:
        if not first:
            # (2) Random sleep between min..max, but allow force refresh
            next_refresh_in_s = compute_next_sleep_s()
            force_refresh_event.clear()
            force_refresh_event.wait(timeout=next_refresh_in_s)
        else:
            next_refresh_in_s = 0
            first = False

        now = int(time.time())

        try:
            results = run_asyncio(check_all(api_id, api_hash, proxies, check_timeout_s))
        except Exception as e:
            with data_lock:
                snapshot = {
                    "timestamp": now,
                    "datetime": datetime.fromtimestamp(now).strftime("%Y-%m-%d %H:%M:%S"),
                    "meta": {
                        "refresh_min_s": refresh_min_s,
                        "refresh_max_s": refresh_max_s,
                        "next_refresh_in_s": next_refresh_in_s,
                        "timeout_s": check_timeout_s,
                        "error": f"refresher_failed: {normalize_error(e)}",
                    },
                    "alive_list": [],
                    "alive_count": 0,
                    "dead_list": [],
                    "dead_count": 0,
                    "proxies": [],
                }
            continue

        statuses: List[ProxyStatus] = []
        alive_list: List[str] = []
        dead_list: List[str] = []
        alive_count = 0
        telegram_ok_count = 0

        for p, r in zip(proxies, results):
            key = f"{p['server']}:{p['port']}:{p['secret_hex'][:8]}"
            ipport = proxy_key_ipport(p)

            prev_streak = STATE.fail_streak.get(key, 0)

            if r.get("ok"):
                alive_count += 1
                alive_list.append(ipport)
                STATE.fail_streak[key] = 0
                STATE.last_ok[key] = now
                if r.get("telegram_ok"):
                    telegram_ok_count += 1
            else:
                dead_list.append(ipport)
                STATE.fail_streak[key] = prev_streak + 1

            last_ok_ts = STATE.last_ok.get(key)

            secret_hex = p.get("secret_hex", "")
            preview = (secret_hex[:8] + "…" + secret_hex[-6:]) if len(secret_hex) >= 14 else secret_hex

            statuses.append(
                ProxyStatus(
                    name=p["name"],
                    server=p["server"],
                    port=p["port"],
                    secret_preview=preview,
                    ok=bool(r.get("ok")),
                    telegram_ok=bool(r.get("telegram_ok")),
                    mode=r.get("mode"),
                    secret_variant=r.get("secret_variant"),
                    method=r.get("method", "telethon"),
                    latency_ms=r.get("latency_ms"),
                    last_checked=now,
                    last_ok=last_ok_ts,
                    fail_streak=STATE.fail_streak.get(key, 0),
                    error=r.get("error"),
                )
            )

        dead_count = len(dead_list)

        statuses.sort(key=lambda s: (not s.ok, not s.telegram_ok, s.latency_ms if s.latency_ms is not None else 10**9))

        with data_lock:
            snapshot = {
                "timestamp": now,
                "datetime": datetime.fromtimestamp(now).strftime("%Y-%m-%d %H:%M:%S"),
                "meta": {
                    "refresh_min_s": refresh_min_s,
                    "refresh_max_s": refresh_max_s,
                    "next_refresh_in_s": next_refresh_in_s,
                    "timeout_s": check_timeout_s,
                    "total": len(statuses),
                    "alive": alive_count,
                    "telegram_ok": telegram_ok_count,
                    "modes_available": [getattr(c, "__name__", str(c)) for c in MTProxyConnections],
                },
                "alive_list": sorted(set(alive_list), key=ipport_sort_key),
                "alive_count": alive_count,
                "dead_list": sorted(set(dead_list), key=ipport_sort_key),
                "dead_count": dead_count,
                "proxies": [asdict(s) for s in statuses],
            }


# ------------------- HTTP server -------------------
class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        global refresh_min_s, refresh_max_s

        qs = parse_qs(urlparse(self.path).query)

        # Compatibility: if user supplies ?refresh=N, treat it as "max cap"
        # and keep min at 1/2 of it (but not less than 5).
        if "refresh" in qs:
            try:
                mx = int(qs["refresh"][0])
                mx = max(5, mx)
                refresh_max_s = mx
                refresh_min_s = max(5, mx // 2)
            except ValueError:
                pass

        # Optional: allow explicit min/max via query params if desired.
        if "refresh_min" in qs:
            try:
                refresh_min_s = max(1, int(qs["refresh_min"][0]))
            except ValueError:
                pass
        if "refresh_max" in qs:
            try:
                refresh_max_s = max(1, int(qs["refresh_max"][0]))
            except ValueError:
                pass

        if refresh_max_s < refresh_min_s:
            refresh_max_s = refresh_min_s

        force = qs.get("force", ["0"])[0] == "1"
        if force:
            # trigger immediate refresh (refresher thread will wake up)
            force_refresh_event.set()

        with data_lock:
            data = snapshot.copy()

        body = json.dumps(data, indent=2)
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.end_headers()
        self.wfile.write(body.encode("utf-8"))

    def log_message(self, *_):
        pass


# ------------------- main -------------------
def main():
    if len(sys.argv) < 2:
        if DEFAULT_CONFIG_FILE.exists():
            sys.argv.append(str(DEFAULT_CONFIG_FILE))
        else:
            raise SystemExit(f"Usage: {sys.argv[0]} /path/to/mtproto_checker.yaml")

    cfg = load_config(sys.argv[1])
    api_id = cfg["api_id"]
    api_hash = cfg["api_hash"]
    proxies = cfg["proxies"]

    now = int(time.time())
    with data_lock:
        global snapshot
        snapshot = {
            "timestamp": now,
            "datetime": datetime.fromtimestamp(now).strftime("%Y-%m-%d %H:%M:%S"),
            "meta": {
                "refresh_min_s": refresh_min_s,
                "refresh_max_s": refresh_max_s,
                "next_refresh_in_s": 0,
                "timeout_s": check_timeout_s,
                "total": len(proxies),
                "alive": 0,
                "telegram_ok": 0,
                "modes_available": [getattr(c, "__name__", str(c)) for c in MTProxyConnections],
            },
            "alive_list": [],
            "alive_count": 0,
            "dead_list": [],
            "dead_count": len(proxies),
            "proxies": [],
        }

    threading.Thread(target=refresher, args=(api_id, api_hash, proxies), daemon=True).start()

    host, port = "0.0.0.0", DEFAULT_PORT
    print(
        f"Serving http://{host}:{port}/  proxies={len(proxies)}  "
        f"refresh=random({refresh_min_s}..{refresh_max_s})s"
    )
    HTTPServer((host, port), Handler).serve_forever()


if __name__ == "__main__":
    main()
