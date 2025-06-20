#!/usr/bin/env python3
"""
mac_ip_server.py
------------------------------------------------------
Run : python mac_ip_server.py
Open: http://localhost:8888/
      http://localhost:8888/?demo=1
      http://localhost:8888/?refresh=30
      http://localhost:8888/?subnet=192.168.1.0/24
"""
import ipaddress
import json
import os
import re
import subprocess
import threading
import time
from datetime import datetime
from http.server import HTTPServer, BaseHTTPRequestHandler
from urllib.parse import urlparse, parse_qs

DEFAULT_REFRESH = 60
refresh_interval = DEFAULT_REFRESH
subnet_cidr = "192.168.1.0/24"

data_lock = threading.Lock()
snapshot = {"timestamp": 0, "table": []}
online_since = {}  # mac -> first_seen_timestamp


# ---------- helpers ----------------------------------------------------------
def classify_ip(ip: str) -> str:
    a = ipaddress.IPv4Address(ip)
    if ip == "255.255.255.255": return "broadcast_all"
    if a in ipaddress.IPv4Network("224.0.0.0/24"): return "multicast_local"
    if a.is_multicast:
        return "multicast_admin" if a in ipaddress.IPv4Network("239.0.0.0/8") else "multicast_global"
    if ip.endswith(".255"): return "broadcast_subnet"
    return "unicast_private" if a.is_private else "unicast_public"


def mask_entry(e: dict) -> dict:
    ip_parts = e["ip"].split(".")
    mac_parts = e["mac"].split(":")
    e = e.copy()
    e["ip"] = ".".join(ip_parts[:-1] + ["xxx"])
    e["mac"] = ":".join(mac_parts[:2] + ["xx"] * 4)
    return e


def ping(host: str):
    cmd = ["ping", "-n" if os.name == "nt" else "-c", "1", "-w", "300", host]
    subprocess.run(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)


def ping_sweep(cidr: str):
    threads = []
    for ip in ipaddress.IPv4Network(cidr, strict=False).hosts():
        t = threading.Thread(target=ping, args=(str(ip),))
        t.start()
        threads.append(t)
    for t in threads: t.join()


def parse_arp(now: int) -> list[dict]:
    """Return list of dicts with ip, mac, kind, seen."""
    out = subprocess.run(["arp", "-a"], capture_output=True, text=True).stdout
    current_macs = set()
    table = []
    for line in out.splitlines():
        m = re.match(r"\s*([\d.]+)\s+([0-9A-Fa-f\-:]{11,})", line)
        if m:
            ip, mac = m.group(1), m.group(2).lower().replace("-", ":")
            current_macs.add(mac)
            if mac not in online_since:
                online_since[mac] = now  # first time seen or reappeared
            table.append({
                "ip": ip,
                "mac": mac,
                "kind": classify_ip(ip),
                "seen": now - online_since[mac]
            })
    # purge devices that vanished
    for mac in list(online_since):
        if mac not in current_macs:
            online_since.pop(mac, None)
    return table


# ---------- background refresh ----------------------------------------------
def refresher():
    global snapshot
    while True:
        ping_sweep(subnet_cidr)
        time.sleep(1)
        now = int(time.time())
        with data_lock:
            snapshot = {"timestamp": now, "table": parse_arp(now)}
        time.sleep(refresh_interval)


# ---------- HTTP server ------------------------------------------------------
class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        global refresh_interval
        global subnet_cidr
        qs = parse_qs(urlparse(self.path).query)
        demo = qs.get("demo", ["0"])[0] == "1"
        if "refresh" in qs:
            try:
                refresh_interval = max(5, int(qs["refresh"][0]))
            except ValueError:
                pass
        if "subnet" in qs:
            try:
                subnet_cidr = qs["subnet"][0]
            except ValueError:
                pass

        with data_lock:
            data = snapshot.copy()

        entries = [e.copy() for e in data["table"]]
        if demo:
            entries = [mask_entry(e) for e in entries]

        unicast_count = sum(1 for e in entries if e["kind"].startswith("unicast"))
        dt_str = datetime.fromtimestamp(data["timestamp"]).strftime("%Y-%m-%d %H:%M:%S")

        body = json.dumps({
            "timestamp": data["timestamp"],
            "datetime": dt_str,
            "refresh": refresh_interval,
            "subnet": subnet_cidr,
            "records": len(entries),
            "devices": unicast_count,
            "entries": entries
        }, indent=2)

        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.end_headers()
        self.wfile.write(body.encode())

    def log_message(self, *_):
        pass


# ---------- main -------------------------------------------------------------
if __name__ == "__main__":
    threading.Thread(target=refresher, daemon=True).start()
    HOST, PORT = "0.0.0.0", 8888
    print(f"Serving http://{HOST}:{PORT}/?demo=1  subnet={subnet_cidr}  refresh={DEFAULT_REFRESH}s")
    HTTPServer((HOST, PORT), Handler).serve_forever()
