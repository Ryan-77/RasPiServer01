import sqlite3
import csv
import math
import os
from datetime import datetime, timezone

import qc as qc_module

DB_PATH = os.path.join(os.path.dirname(__file__), "data", "monitor.db")
ALERTS_CSV = os.path.join(os.path.dirname(__file__), "data", "alerts.csv")

EARTH_RADIUS_NM = 3440.065
DEDUP_RADIUS_NM = 50
DEDUP_MINUTES = 30


def get_connection(path=DB_PATH):
    conn = sqlite3.connect(path, check_same_thread=False)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA journal_mode=WAL")
    return conn


def init_db(conn):
    conn.executescript("""
        CREATE TABLE IF NOT EXISTS aircraft_positions (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            hex             TEXT NOT NULL,
            flight          TEXT,
            registration    TEXT,
            type            TEXT,
            lat             REAL,
            lon             REAL,
            alt_baro        TEXT,
            gs              REAL,
            snapshot_time   TEXT NOT NULL,
            region          TEXT
        );

        CREATE TABLE IF NOT EXISTS alerts (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            timestamp       TEXT NOT NULL,
            alert_type      TEXT NOT NULL,
            severity        TEXT NOT NULL,
            region          TEXT,
            summary         TEXT,
            detail          TEXT,
            aircraft_hexes  TEXT,
            centroid_lat    REAL,
            centroid_lon    REAL,
            acknowledged    INTEGER DEFAULT 0
        );

        CREATE INDEX IF NOT EXISTS idx_positions_time ON aircraft_positions(snapshot_time);
        CREATE INDEX IF NOT EXISTS idx_positions_hex  ON aircraft_positions(hex);
        CREATE INDEX IF NOT EXISTS idx_alerts_time    ON alerts(timestamp);
    """)
    # Initialise QC table (defined in qc.py to avoid circular imports)
    qc_module.init_qc_table(conn)
    # Initialise EW contacts table
    init_ew_table(conn)
    # Migrate existing DB if columns are missing
    for col, typedef in [("centroid_lat", "REAL"), ("centroid_lon", "REAL")]:
        try:
            conn.execute(f"ALTER TABLE alerts ADD COLUMN {col} {typedef}")
        except sqlite3.OperationalError:
            pass  # Column already exists
    conn.commit()


def insert_positions(conn, records, snapshot_time, region_fn):
    """
    records: list of dicts from api.adsb.lol
    region_fn: callable(lat, lon) -> region string or None
    """
    rows = []
    for a in records:
        lat = a.get("lat")
        lon = a.get("lon")
        rows.append((
            a.get("hex"),
            (a.get("flight") or "").strip() or None,
            a.get("r"),
            a.get("t"),
            lat,
            lon,
            str(a.get("alt_baro", "")) if a.get("alt_baro") is not None else None,
            a.get("gs"),
            snapshot_time,
            region_fn(lat, lon) if (lat and lon) else None,
        ))
    conn.executemany(
        """INSERT INTO aircraft_positions
           (hex, flight, registration, type, lat, lon, alt_baro, gs, snapshot_time, region)
           VALUES (?,?,?,?,?,?,?,?,?,?)""",
        rows,
    )
    conn.commit()


def get_recent_positions(conn, minutes=60):
    """Return rows from the last N minutes as list of dicts."""
    cutoff = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S")
    cur = conn.execute(
        """SELECT * FROM aircraft_positions
           WHERE snapshot_time >= datetime(?, '-' || ? || ' minutes')
           ORDER BY hex, snapshot_time""",
        (cutoff, minutes),
    )
    return [dict(r) for r in cur.fetchall()]


def _haversine_nm(lat1, lon1, lat2, lon2):
    lat1, lon1, lat2, lon2 = map(math.radians, [lat1, lon1, lat2, lon2])
    dlat = lat2 - lat1
    dlon = lon2 - lon1
    a = math.sin(dlat / 2) ** 2 + math.cos(lat1) * math.cos(lat2) * math.sin(dlon / 2) ** 2
    return 2 * EARTH_RADIUS_NM * math.asin(math.sqrt(a))


def _alert_is_duplicate(conn, alert_type, centroid_lat, centroid_lon, within_minutes=DEDUP_MINUTES):
    """
    Duplicate = same alert_type fired within `within_minutes` AND within DEDUP_RADIUS_NM
    of the new alert's centroid. Alerts with no centroid fall back to type-only dedup.
    """
    cur = conn.execute(
        """SELECT centroid_lat, centroid_lon FROM alerts
           WHERE alert_type = ?
             AND timestamp >= datetime('now', '-' || ? || ' minutes')""",
        (alert_type, within_minutes),
    )
    rows = cur.fetchall()
    if not rows:
        return False

    # No centroid on new alert — dedupe by type alone
    if centroid_lat is None or centroid_lon is None:
        return True

    for row in rows:
        r_lat, r_lon = row[0], row[1]
        # Existing alert also has no centroid — treat as match
        if r_lat is None or r_lon is None:
            return True
        dist = _haversine_nm(centroid_lat, centroid_lon, r_lat, r_lon)
        if dist <= DEDUP_RADIUS_NM:
            return True

    return False


def insert_alert(conn, alert):
    """
    alert: dict with keys alert_type, severity, region, summary, detail,
           aircraft_hexes, and optionally centroid_lat, centroid_lon.
    Returns True if inserted, False if duplicate.
    """
    c_lat = alert.get("centroid_lat")
    c_lon = alert.get("centroid_lon")

    if _alert_is_duplicate(conn, alert["alert_type"], c_lat, c_lon):
        return False

    conn.execute(
        """INSERT INTO alerts
           (timestamp, alert_type, severity, region, summary, detail, aircraft_hexes,
            centroid_lat, centroid_lon)
           VALUES (datetime('now'), ?, ?, ?, ?, ?, ?, ?, ?)""",
        (
            alert["alert_type"],
            alert["severity"],
            alert.get("region"),
            alert.get("summary"),
            alert.get("detail"),
            ",".join(alert.get("aircraft_hexes", [])),
            c_lat,
            c_lon,
        ),
    )
    conn.commit()
    return True


def export_alerts_csv(conn, path=ALERTS_CSV):
    cur = conn.execute("SELECT * FROM alerts ORDER BY timestamp DESC")
    rows = cur.fetchall()
    if not rows:
        return
    with open(path, "w", newline="") as f:
        writer = csv.writer(f)
        writer.writerow([d[0] for d in cur.description])
        writer.writerows(rows)


def init_ew_table(conn):
    """Creates the ew_contacts table and indexes if they don't exist."""
    conn.executescript("""
        CREATE TABLE IF NOT EXISTS ew_contacts (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            snapshot_time   TEXT NOT NULL,
            hex             TEXT NOT NULL,
            flight          TEXT,
            type            TEXT,
            lat             REAL,
            lon             REAL,
            alt_baro        TEXT,
            gs              REAL,
            ew_role         TEXT NOT NULL,
            ew_confidence   TEXT NOT NULL,
            ew_basis        TEXT,
            in_orbit        INTEGER DEFAULT 0,
            near_cluster    INTEGER DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS idx_ew_time ON ew_contacts(snapshot_time);
        CREATE INDEX IF NOT EXISTS idx_ew_hex  ON ew_contacts(hex);
    """)
    conn.commit()


def insert_ew_contacts(conn, ew_contacts, snapshot_time):
    """
    Bulk-inserts a list of EW contact dicts.
    ew_contacts: list of dicts from detect_ew_aircraft()
    snapshot_time: ISO timestamp string
    """
    if not ew_contacts:
        return
    rows = [
        (
            snapshot_time,
            c["hex"],
            c.get("flight"),
            c.get("type"),
            c.get("lat"),
            c.get("lon"),
            c.get("alt_baro"),
            c.get("gs"),
            c["ew_role"],
            c["ew_confidence"],
            c.get("ew_basis"),
            1 if c.get("in_orbit") else 0,
            1 if c.get("near_cluster") else 0,
        )
        for c in ew_contacts
    ]
    conn.executemany(
        """INSERT INTO ew_contacts
           (snapshot_time, hex, flight, type, lat, lon, alt_baro, gs,
            ew_role, ew_confidence, ew_basis, in_orbit, near_cluster)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)""",
        rows,
    )
    conn.commit()


def get_recent_ew(conn, hours=24):
    """Return EW contacts from the last N hours as a list of dicts."""
    cur = conn.execute(
        """SELECT * FROM ew_contacts
           WHERE snapshot_time >= datetime('now', '-' || ? || ' hours')
           ORDER BY snapshot_time DESC""",
        (hours,),
    )
    return [dict(r) for r in cur.fetchall()]


def get_last_qc_count(conn):
    """Return the aircraft_count from the most recent QC snapshot, or None."""
    cur = conn.execute(
        "SELECT aircraft_count FROM snapshot_qc ORDER BY snapshot_time DESC LIMIT 1"
    )
    row = cur.fetchone()
    return row[0] if row else None
