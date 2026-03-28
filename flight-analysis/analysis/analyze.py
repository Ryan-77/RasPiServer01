"""
Flight Telemetry Analysis — NTTR / UTTR Airspace Activity Characterization
Produces: analyzed_tracks.csv, aircraft_metrics.csv, anomalies.csv
"""

import pandas as pd
import numpy as np
from pathlib import Path
from scipy import stats

# --- Constants ---
VRATE_THRESHOLD_FPM     = 2000
SPEED_ZSCORE_THRESHOLD  = 2.0
DROPOUT_THRESHOLD       = 0.50
STALENESS_THRESHOLD_SEC = 60
HEADING_VARIANCE_DEG    = 60.0
EMERGENCY_SQUAWKS       = {7700, 7600, 7500}

SQUAWK_LABELS = {
    7700: 'General Emergency',
    7600: 'Radio Failure',
    7500: 'Hijacking',
}

DATA_DIR   = Path(__file__).parent.parent / 'data'
INPUT_FILE = DATA_DIR / 'raw_tracks.csv'


# ---------------------------------------------------------------------------
# 1. Load & Prepare
# ---------------------------------------------------------------------------

def load_and_prepare(filepath):
    df = pd.read_csv(filepath, dtype={'squawk': str})
    df['snapshot_dt'] = pd.to_datetime(df['snapshot_dt'], utc=True)
    df['callsign'] = df['callsign'].fillna('').str.strip().replace('', pd.NA)

    # Convert squawk to int-safe string (strip .0 artifacts from CSV round-trip)
    df['squawk'] = df['squawk'].str.replace(r'\.0$', '', regex=True)

    df = df.sort_values(['icao24', 'snapshot_time']).reset_index(drop=True)
    return df


# ---------------------------------------------------------------------------
# 2. Build Tracks — compute per-row deltas within each aircraft
# ---------------------------------------------------------------------------

def build_tracks(df):
    grp = df.groupby('icao24')

    df['alt_diff_ft']    = grp['baro_altitude_ft'].diff()
    df['time_diff_sec']  = grp['snapshot_time'].diff()
    df['speed_diff_kts'] = grp['velocity_knots'].diff()

    # Avoid division by zero for same-second duplicate snapshots
    df['computed_vrate_fpm'] = np.where(
        df['time_diff_sec'] > 0,
        df['alt_diff_ft'] / (df['time_diff_sec'] / 60.0),
        np.nan
    )
    return df


# ---------------------------------------------------------------------------
# 3. Compute Per-Aircraft Metrics
# ---------------------------------------------------------------------------

def compute_aircraft_metrics(df):
    max_snapshots = df['snapshot_time'].nunique()

    airborne = df[df['on_ground'] == False]

    agg = df.groupby('icao24').agg(
        callsign        = ('callsign',         lambda x: x.dropna().iloc[-1] if x.dropna().size else pd.NA),
        origin_country  = ('origin_country',   'first'),
        region          = ('region',           lambda x: x.mode().iloc[0] if not x.mode().empty else 'UNKNOWN'),
        snapshot_count  = ('snapshot_time',    'count'),
        ever_airborne   = ('on_ground',        lambda x: (~x.astype(bool)).any()),
        max_alt_ft      = ('baro_altitude_ft', 'max'),
        max_speed_kts   = ('velocity_knots',   'max'),
        speed_std_kts   = ('velocity_knots',   'std'),
        heading_std_deg = ('true_track',       'std'),
        max_computed_vrate_fpm = ('computed_vrate_fpm', lambda x: x.abs().max()),
        max_staleness_sec = ('snapshot_time',  lambda x: (
            (x - df.loc[x.index, 'last_contact']).abs().max()
        )),
        lat_center      = ('latitude',         'mean'),
        lon_center      = ('longitude',        'mean'),
    ).reset_index()

    # Airborne-only averages
    ab_agg = airborne.groupby('icao24').agg(
        mean_alt_ft   = ('baro_altitude_ft', 'mean'),
        mean_speed_kts = ('velocity_knots',  'mean'),
    ).reset_index()
    agg = agg.merge(ab_agg, on='icao24', how='left')

    # Squawk codes seen per aircraft
    squawk_agg = (
        df[df['squawk'].notna() & (df['squawk'] != '')]
        .groupby('icao24')['squawk']
        .apply(lambda x: ','.join(sorted(x.unique())))
        .reset_index()
        .rename(columns={'squawk': 'squawk_codes'})
    )
    agg = agg.merge(squawk_agg, on='icao24', how='left')
    agg['squawk_codes'] = agg['squawk_codes'].fillna('')

    agg['dropout_score'] = 1 - (agg['snapshot_count'] / max_snapshots)
    agg['dropout_score'] = agg['dropout_score'].clip(0, 1)

    return agg, max_snapshots


# ---------------------------------------------------------------------------
# 4. Classify Aircraft Role
# ---------------------------------------------------------------------------

def classify_aircraft_role(metrics_df):
    def _classify(row):
        if not row['ever_airborne']:
            return 'GROUND_SUPPORT'
        if row.get('max_speed_kts', 0) > 450 and row.get('max_alt_ft', 0) > 30000:
            return 'HIGH_PERFORMANCE'
        if row.get('heading_std_deg', 0) > HEADING_VARIANCE_DEG or \
           row.get('max_computed_vrate_fpm', 0) > VRATE_THRESHOLD_FPM:
            return 'MANEUVERING'
        if row.get('max_alt_ft', 0) < 10000 and row.get('mean_speed_kts', 0) < 150:
            return 'LOW_SLOW'
        if row.get('dropout_score', 0) > DROPOUT_THRESHOLD:
            return 'LIMITED_COVERAGE'
        return 'TRANSIT'

    metrics_df['role_classification'] = metrics_df.apply(_classify, axis=1)
    return metrics_df


# ---------------------------------------------------------------------------
# 5. Flag Anomalies
# ---------------------------------------------------------------------------

def flag_anomalies(metrics_df, max_snapshots):
    records = []

    def add_flag(row, flag_type, detail, severity):
        records.append({
            'icao24':    row['icao24'],
            'callsign':  row.get('callsign', ''),
            'region':    row.get('region', ''),
            'flag_type': flag_type,
            'detail':    detail,
            'severity':  severity,
        })

    # Compute per-region speed z-scores
    region_stats = metrics_df.groupby('region')['max_speed_kts'].agg(['mean', 'std'])
    metrics_df = metrics_df.merge(
        region_stats.rename(columns={'mean': 'region_speed_mean', 'std': 'region_speed_std'}),
        on='region', how='left'
    )
    metrics_df['speed_zscore'] = (
        (metrics_df['max_speed_kts'] - metrics_df['region_speed_mean'])
        / metrics_df['region_speed_std'].replace(0, np.nan)
    )

    for _, row in metrics_df.iterrows():
        # FLAG 1 — Rapid Altitude Change
        vrate = row.get('max_computed_vrate_fpm', 0)
        if pd.notna(vrate) and abs(vrate) > VRATE_THRESHOLD_FPM:
            severity = 'HIGH' if abs(vrate) > 3000 else 'MEDIUM'
            add_flag(row, 'RAPID_ALTITUDE_CHANGE',
                     f"{abs(vrate):,.0f} fpm computed from position delta", severity)

        # FLAG 2 — Speed Outlier (per-region z-score)
        z = row.get('speed_zscore', 0)
        if pd.notna(z) and abs(z) > SPEED_ZSCORE_THRESHOLD:
            add_flag(row, 'SPEED_OUTLIER',
                     f"{row['max_speed_kts']:.1f} kts (z={z:.2f})", 'MEDIUM')

        # FLAG 3 — Data Dropout
        dropout = row.get('dropout_score', 0)
        if dropout > DROPOUT_THRESHOLD:
            seen = row['snapshot_count']
            severity = 'MEDIUM' if dropout > 0.75 else 'LOW'
            add_flag(row, 'DATA_DROPOUT',
                     f"Seen in {seen}/{max_snapshots} snapshots ({dropout:.0%} dropout)", severity)

        # FLAG 4 — Emergency Squawk
        codes_str = str(row.get('squawk_codes', ''))
        for code in EMERGENCY_SQUAWKS:
            if str(code) in codes_str.split(','):
                label = SQUAWK_LABELS.get(code, 'Unknown')
                add_flag(row, 'EMERGENCY_SQUAWK',
                         f"Squawk {code} detected ({label})", 'CRITICAL')

        # FLAG 5 — Signal Staleness
        staleness = row.get('max_staleness_sec', 0)
        if pd.notna(staleness) and staleness > STALENESS_THRESHOLD_SEC:
            add_flag(row, 'SIGNAL_STALENESS',
                     f"Max contact lag: {staleness:.0f}s (threshold: {STALENESS_THRESHOLD_SEC}s)", 'LOW')

        # FLAG 6 — High Heading Variance
        hdg_std = row.get('heading_std_deg', 0)
        if pd.notna(hdg_std) and hdg_std > HEADING_VARIANCE_DEG:
            add_flag(row, 'HIGH_HEADING_VARIANCE',
                     f"Heading std dev: {hdg_std:.1f} deg", 'LOW')

    return pd.DataFrame(records)


# ---------------------------------------------------------------------------
# 6. Save Outputs & Print Summary
# ---------------------------------------------------------------------------

def save_outputs(tracks_df, metrics_df, anomalies_df):
    tracks_df.to_csv(DATA_DIR / 'analyzed_tracks.csv', index=False)
    metrics_df.to_csv(DATA_DIR / 'aircraft_metrics.csv', index=False)
    anomalies_df.to_csv(DATA_DIR / 'anomalies.csv', index=False)

    print("\n=== ANALYSIS SUMMARY ===")
    print(f"Total records:   {len(tracks_df):,}")
    print(f"Total aircraft:  {metrics_df['icao24'].nunique()}")
    print(f"  Airborne:      {metrics_df['ever_airborne'].sum()}")
    print(f"  Ground:        {(~metrics_df['ever_airborne']).sum()}")

    print("\nBy Region:")
    for region, grp in metrics_df.groupby('region'):
        print(f"  {region}: {len(grp)} aircraft")

    print("\nRole Classification:")
    for role, count in metrics_df['role_classification'].value_counts().items():
        print(f"  {role}: {count}")

    print(f"\nAnomalies: {len(anomalies_df)} flags across {anomalies_df['icao24'].nunique() if not anomalies_df.empty else 0} aircraft")
    if not anomalies_df.empty:
        for flag_type, count in anomalies_df['flag_type'].value_counts().items():
            print(f"  {flag_type}: {count}")

    print(f"\nOutputs saved to {DATA_DIR}/")


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

if __name__ == '__main__':
    print(f"Loading {INPUT_FILE} ...")
    df = load_and_prepare(INPUT_FILE)
    df = build_tracks(df)
    metrics, max_snapshots = compute_aircraft_metrics(df)
    metrics = classify_aircraft_role(metrics)
    anomalies = flag_anomalies(metrics, max_snapshots)

    # Join role back to tracks for dashboard use
    df = df.merge(metrics[['icao24', 'role_classification']], on='icao24', how='left')

    save_outputs(df, metrics, anomalies)
