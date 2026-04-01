"""
Flight Activity Monitor
=======================
Single-run script. Polls api.adsb.lol/v2/mil once, detects patterns in military
aircraft activity (ghosts, clusters, circular flight), correlates them into
named events, and writes alerts to data/monitor.db + data/alerts.csv.

Designed to be called by cron every 5 minutes:
    */5 * * * * cd /path/to/flight-analysis && /path/to/venv/bin/python monitor.py >> /tmp/monitor.log 2>&1
"""

import os
import sys
import fcntl
import requests
from datetime import datetime, timezone
from pathlib import Path

import db
import qc

LOCK_PATH = Path("/tmp/flight_monitor.lock")
from detectors.ghost import detect_ghosts
from detectors.cluster import detect_clusters
from detectors.circular import detect_circular_flight
from detectors.ew import detect_ew_aircraft
from correlator import correlate

# ── Region bounding boxes ────────────────────────────────────────────────────
REGIONS = {
    "NTTR": {"lat": (35.5, 38.5), "lon": (-117.5, -114.0)},
    "UTTR": {"lat": (39.5, 42.0), "lon": (-114.0, -111.5)},
}

API_URL              = "https://api.adsb.lol/v2/mil"
HISTORY_WINDOW_MINUTES = 60


def tag_region(lat, lon):
    if lat is None or lon is None:
        return None
    for name, bounds in REGIONS.items():
        if bounds["lat"][0] <= lat <= bounds["lat"][1] and bounds["lon"][0] <= lon <= bounds["lon"][1]:
            return name
    return "OTHER"


def fetch_military():
    try:
        resp = requests.get(API_URL, timeout=15)
        resp.raise_for_status()
        return resp.json().get("ac", [])
    except Exception as e:
        print(f"[WARN] API fetch failed: {e}", flush=True)
        return []


def run_cycle(conn):
    now = datetime.now(timezone.utc)
    ts  = now.strftime("%Y-%m-%dT%H:%M:%S")
    print(f"[{ts}] Running cycle...", flush=True)

    # 1. Fetch
    snapshot = fetch_military()
    if not snapshot:
        print("  No data returned.", flush=True)
        return
    print(f"  {len(snapshot)} military aircraft globally", flush=True)

    # 1a. QC — evaluate snapshot quality before persisting
    prev_count = db.get_last_qc_count(conn)
    qc_record  = qc.compute_snapshot_qc(snapshot, prev_count)
    qc.insert_snapshot_qc(conn, qc_record)

    flag     = qc_record["quality_flag"]
    cov      = qc_record["coverage_pct"]
    count    = qc_record["aircraft_count"]
    reasons  = qc_record["fail_reasons"] or "none"
    print(f"  QC [{flag}] count={count} coverage={cov:.1%} reasons={reasons}", flush=True)

    if flag == "FAILED":
        print("  [ABORT] Snapshot has FAILED QC — skipping persist and detection.", flush=True)
        return

    # 2. Persist positions
    db.insert_positions(conn, snapshot, ts, tag_region)

    # 3. Pull recent history for track-based detectors
    history = db.get_recent_positions(conn, minutes=HISTORY_WINDOW_MINUTES)

    # 4. Run detectors
    ghosts   = detect_ghosts(snapshot)
    clusters = detect_clusters(snapshot)
    orbits   = detect_circular_flight(history)

    print(f"  Ghosts: {len(ghosts)} | Clusters: {len(clusters)} | Orbits: {len(orbits)}", flush=True)

    for c in clusters:
        types = ", ".join(c["types"]) if c["types"] else "unknown"
        print(f"    Cluster [{c['count']} ac] near {c['centroid_lat']:.2f}, {c['centroid_lon']:.2f} — {types}", flush=True)

    for o in orbits:
        label = o.get("flight") or o["hex"]
        print(f"    Orbit: {label} ({o['direction']}, r={o['radius_nm']}nm, {o['cumulative_heading_deg']:.0f}°)", flush=True)

    # 4a. EW / ISR classification
    ew_contacts = detect_ew_aircraft(snapshot, orbits, clusters)
    print(f"  EW contacts: {len(ew_contacts)}", flush=True)
    for e in ew_contacts:
        label = e.get("flight") or e["hex"]
        print(
            f"    EW [{e['ew_confidence']}] {label} — {e['ew_role']} "
            f"({e.get('ew_basis', '')})",
            flush=True,
        )

    db.insert_ew_contacts(conn, ew_contacts, ts)

    # 5. Correlate into events
    events = correlate(ghosts, clusters, orbits, ew_contacts=ew_contacts)

    # 6. Write alerts (deduped — single DB round-trip for the whole batch)
    new_alerts = db.bulk_insert_alerts(conn, events)

    if new_alerts > 0:
        print(f"  {new_alerts} new alert(s) inserted this cycle.", flush=True)
    elif events:
        print(f"  {len(events)} event(s) detected — all duplicates, skipped.", flush=True)
    else:
        print("  No alertable patterns detected.", flush=True)

    # 7. Export CSV only when there's something new to write
    if new_alerts > 0:
        db.export_alerts_csv(conn)
        print(f"  alerts.csv updated ({new_alerts} new alert(s) this cycle)", flush=True)
    else:
        print(f"  alerts.csv unchanged (no new alerts this cycle)", flush=True)


def main():
    # Support running from any working directory
    script_dir = os.path.dirname(os.path.abspath(__file__))
    os.chdir(script_dir)
    os.makedirs("data", exist_ok=True)

    # Prevent overlapping runs — if a previous cycle is still running, skip this one
    lock_file = open(LOCK_PATH, "w")
    try:
        fcntl.flock(lock_file, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        print("[SKIP] Another monitor.py is still running — exiting.", flush=True)
        lock_file.close()
        sys.exit(0)

    conn = db.get_connection()
    db.init_db(conn)

    try:
        run_cycle(conn)
    except Exception as e:
        print(f"[ERROR] Cycle failed: {e}", flush=True)
        sys.exit(1)
    finally:
        conn.close()
        fcntl.flock(lock_file, fcntl.LOCK_UN)
        lock_file.close()


if __name__ == "__main__":
    main()
