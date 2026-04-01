"""
Circular flight detector — identifies aircraft flying sustained orbits.
Uses cumulative heading change over the position history window.
A confirmed orbit requires >= 540 degrees of cumulative heading rotation
(1.5 full loops) with consistent radius from the track centroid (<= 5nm std dev).

Filters:
  - HEADING_THRESHOLD_DEG = 540  — requires 1.5 loops; a single 360° turn during
    a training maneuver or course correction no longer qualifies; genuine holding
    orbits and ISR patterns easily exceed this over a 60-minute window
  - MAX_RADIUS_STD_NM = 5        — tightened from 8; a genuine racetrack or
    holding orbit has a consistent radius; large std dev = straight-leg drift
  - MIN_RADIUS_NM = 3            — tight circles < 3nm are maneuvering, not orbiting
  - MAX_TIME_GAP_MINUTES = 15    — heading deltas across large time gaps are
    meaningless; skip legs where the position reports are too far apart in time
  - MIN_POINTS = 8               — raised from 5; at 1-min cron cadence, 8 points
    = 8 minutes of data; 5 points is insufficient to confirm a sustained pattern
"""

import math
from collections import defaultdict
from datetime import datetime

EARTH_RADIUS_NM = 3440.065
MIN_POINTS            = 8
HEADING_THRESHOLD_DEG = 540.0   # raised from 360 — 1.5 full loops required
MAX_RADIUS_STD_NM     = 5.0     # tightened from 8 — tighter shape filter
MIN_RADIUS_NM         = 3.0     # exclude tight maneuvering circles
MAX_TIME_GAP_MINUTES  = 15.0    # skip legs with stale position data


def _haversine_nm(lat1, lon1, lat2, lon2):
    lat1, lon1, lat2, lon2 = map(math.radians, [lat1, lon1, lat2, lon2])
    dlat = lat2 - lat1
    dlon = lon2 - lon1
    a = math.sin(dlat / 2) ** 2 + math.cos(lat1) * math.cos(lat2) * math.sin(dlon / 2) ** 2
    return 2 * EARTH_RADIUS_NM * math.asin(math.sqrt(a))


def _bearing(lat1, lon1, lat2, lon2):
    lat1, lon1, lat2, lon2 = map(math.radians, [lat1, lon1, lat2, lon2])
    dlon = lon2 - lon1
    x = math.sin(dlon) * math.cos(lat2)
    y = math.cos(lat1) * math.sin(lat2) - math.sin(lat1) * math.cos(lat2) * math.cos(dlon)
    return (math.degrees(math.atan2(x, y)) + 360) % 360


def _heading_delta(b1, b2):
    """Signed shortest angular difference b2 - b1, in range [-180, 180]."""
    delta = (b2 - b1 + 180) % 360 - 180
    return delta


def detect_circular_flight(history_rows):
    """
    history_rows: list of dicts from db.get_recent_positions()
    Returns list of orbit dicts for confirmed circular patterns.
    """
    # Group positions by aircraft hex
    tracks = defaultdict(list)
    for row in history_rows:
        if row.get("lat") and row.get("lon"):
            tracks[row["hex"]].append(row)

    orbits = []
    for hex_code, points in tracks.items():
        if len(points) < MIN_POINTS:
            continue

        # Sort by time
        points = sorted(points, key=lambda r: r["snapshot_time"])

        # Compute cumulative heading change, skipping legs with large time gaps
        # (a 30-minute gap between position reports makes bearing calculation meaningless)
        bearings = []
        bearing_times = []
        for i in range(1, len(points)):
            try:
                t1 = datetime.fromisoformat(points[i - 1]["snapshot_time"])
                t2 = datetime.fromisoformat(points[i]["snapshot_time"])
                gap_min = abs((t2 - t1).total_seconds()) / 60.0
                if gap_min > MAX_TIME_GAP_MINUTES:
                    continue  # stale gap — don't credit heading change across it
            except (ValueError, TypeError):
                pass  # can't parse time — proceed without gap check

            b = _bearing(
                points[i - 1]["lat"], points[i - 1]["lon"],
                points[i]["lat"],     points[i]["lon"],
            )
            bearings.append(b)

        if len(bearings) < 2:
            continue

        cumulative = 0.0
        for i in range(1, len(bearings)):
            cumulative += _heading_delta(bearings[i - 1], bearings[i])

        if abs(cumulative) < HEADING_THRESHOLD_DEG:
            continue

        # Check radius consistency — distance of each point from centroid
        lats = [p["lat"] for p in points]
        lons = [p["lon"] for p in points]
        c_lat = sum(lats) / len(lats)
        c_lon = sum(lons) / len(lons)
        distances = [_haversine_nm(p["lat"], p["lon"], c_lat, c_lon) for p in points]
        radius_nm = sum(distances) / len(distances)
        radius_std = (sum((d - radius_nm) ** 2 for d in distances) / len(distances)) ** 0.5

        if radius_std > MAX_RADIUS_STD_NM:
            continue

        # Exclude tight maneuvering circles — not a meaningful orbit
        if radius_nm < MIN_RADIUS_NM:
            continue

        orbits.append({
            "hex": hex_code,
            "flight": points[-1].get("flight"),
            "type": points[-1].get("type"),
            "centroid_lat": c_lat,
            "centroid_lon": c_lon,
            "last_lat": points[-1]["lat"],   # most recent known position — used by
            "last_lon": points[-1]["lon"],   # correlator to detect stale orbits
            "radius_nm": round(radius_nm, 1),
            "cumulative_heading_deg": round(abs(cumulative), 1),
            "direction": "CW" if cumulative > 0 else "CCW",
            "point_count": len(points),
        })

    return orbits
