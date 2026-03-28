"""
Data Acquisition Script - OpenSky Network ADS-B State Vectors
Bounding box: Nevada Test & Training Range / Nellis AFB region
Collects multiple snapshots to build flight tracks over time.
"""

import requests
import pandas as pd
import time
import os
import json
from datetime import datetime, timezone

# --- Config ---
# Bounding boxes for each range region
REGIONS = {
    'NTTR': {   # Nevada Test & Training Range / Nellis AFB
        'lat_min': 35.5, 'lat_max': 38.5,
        'lon_min': -117.5, 'lon_max': -114.0,
    },
    'UTTR': {   # Utah Test & Training Range / Hill AFB / Dugway
        'lat_min': 39.5, 'lat_max': 42.0,
        'lon_min': -114.0, 'lon_max': -111.5,
    },
}

SNAPSHOTS = 20          # number of polls
INTERVAL_SEC = 30       # seconds between polls
OUTPUT_DIR = os.path.join(os.path.dirname(__file__), '..', 'data')
OUTPUT_FILE = os.path.join(OUTPUT_DIR, 'raw_tracks.csv')

# OpenSky columns per their API docs
COLUMNS = [
    'icao24', 'callsign', 'origin_country', 'time_position',
    'last_contact', 'longitude', 'latitude', 'baro_altitude',
    'on_ground', 'velocity', 'true_track', 'vertical_rate',
    'sensors', 'geo_altitude', 'squawk', 'spi', 'position_source'
]

def fetch_snapshot(region_name, bounds):
    url = (
        f"https://opensky-network.org/api/states/all"
        f"?lamin={bounds['lat_min']}&lomin={bounds['lon_min']}"
        f"&lamax={bounds['lat_max']}&lomax={bounds['lon_max']}"
    )
    try:
        resp = requests.get(url, timeout=15)
        resp.raise_for_status()
        data = resp.json()
        states = data.get('states', [])
        timestamp = data.get('time', int(datetime.now(timezone.utc).timestamp()))
        return states, timestamp
    except requests.exceptions.RequestException as e:
        print(f"  [warn] {region_name} request failed: {e}")
        return [], None

def main():
    os.makedirs(OUTPUT_DIR, exist_ok=True)
    all_records = []

    print(f"Collecting {SNAPSHOTS} snapshots every {INTERVAL_SEC}s")
    print(f"Regions: {', '.join(REGIONS.keys())}\n")

    for i in range(1, SNAPSHOTS + 1):
        print(f"  Snapshot {i}/{SNAPSHOTS}")
        for region_name, bounds in REGIONS.items():
            states, ts = fetch_snapshot(region_name, bounds)
            if states:
                for s in states:
                    record = dict(zip(COLUMNS, s))
                    record['snapshot_time'] = ts
                    record['region'] = region_name
                    all_records.append(record)
                print(f"    {region_name}: {len(states)} aircraft")
            else:
                print(f"    {region_name}: no data")

        if i < SNAPSHOTS:
            time.sleep(INTERVAL_SEC)

    if not all_records:
        print("\nNo data collected. Check your connection or try again.")
        return

    df = pd.DataFrame(all_records)

    # Clean up
    df['callsign'] = df['callsign'].str.strip()
    df['snapshot_dt'] = pd.to_datetime(df['snapshot_time'], unit='s', utc=True)
    df['baro_altitude_ft'] = df['baro_altitude'] * 3.28084      # meters -> feet
    df['geo_altitude_ft'] = df['geo_altitude'] * 3.28084
    df['velocity_knots'] = df['velocity'] * 1.94384             # m/s -> knots
    df['vertical_rate_fpm'] = df['vertical_rate'] * 196.85      # m/s -> ft/min

    df.to_csv(OUTPUT_FILE, index=False)
    print(f"\nSaved {len(df)} records -> {OUTPUT_FILE}")
    print(f"Time range: {df['snapshot_dt'].min()} to {df['snapshot_dt'].max()}")
    for region, grp in df.groupby('region'):
        print(f"  {region}: {grp['icao24'].nunique()} unique aircraft, {len(grp)} records")

if __name__ == '__main__':
    main()
