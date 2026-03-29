"""
EW / ISR Aircraft Classifier — Phase 2 detector.

Classifies military aircraft as Electronic Warfare, SIGINT, AWACS, or ISR assets
based on type code, callsign prefix, and behavioral scoring (orbit + altitude + proximity).

Since the feed (api.adsb.lol/v2/mil) is military-only, confidence thresholds are
intentionally aggressive — any type-matched aircraft is at minimum PROBABLE.
"""

import math

EARTH_RADIUS_NM = 3440.065

# ── EW Type Registry ──────────────────────────────────────────────────────────

EW_TYPE_REGISTRY = {
    # Electronic Attack
    "EA18":  "EA-18G Growler (Electronic Attack)",
    "EA6B":  "EA-6B Prowler (Electronic Attack)",
    # SIGINT / ELINT
    "RC135": "RC-135 Rivet Joint (SIGINT/ELINT)",
    "RC12":  "RC-12 Guardrail (SIGINT)",
    "EP3":   "EP-3E Aries (SIGINT)",
    "E8":    "E-8 JSTARS (Ground Surveillance)",
    # AWACS / C2
    "E3CF":  "E-3 Sentry AWACS",
    "E7":    "E-7 Wedgetail AEW&C",
    "E2":    "E-2 Hawkeye AEW",
    # ISR / Recon
    "U2":    "U-2 Dragon Lady (High-Alt Recon)",
    "RQ4":   "RQ-4 Global Hawk (HALE UAV)",
    "MQ9":   "MQ-9 Reaper (ISR/Strike)",
    "MC12":  "MC-12W Liberty (ISR)",
    # EW / Jamming — since feed is military-only, C130 = probable EC-130
    "EC130": "EC-130H Compass Call (EW/Jamming)",
    "C130":  "C-130 (probable EC-130 variant)",
    # King Air ISR variants — military feed so treat as ISR
    "B350":  "King Air 350 (ISR variant)",
    "BE20":  "King Air 200 (ISR variant)",
    "PC12":  "PC-12 (light ISR)",
}

# Type codes that start as PROBABLE and upgrade to CONFIRMED on behavioral evidence
_PROBABLE_TYPES = {"C130", "B350", "BE20", "PC12"}

# Callsign prefixes that confirm EW/ISR tasking
EW_CALLSIGN_PREFIXES = {
    "AWACS", "RIVET", "COBRA", "IRON", "SHADOW", "TACAMO", "RECON", "JANET",
}

NEAR_CLUSTER_NM = 150.0


# ── Helpers ───────────────────────────────────────────────────────────────────

def _haversine_nm(lat1, lon1, lat2, lon2):
    """Standard haversine distance in nautical miles."""
    lat1, lon1, lat2, lon2 = map(math.radians, [lat1, lon1, lat2, lon2])
    dlat = lat2 - lat1
    dlon = lon2 - lon1
    a = (math.sin(dlat / 2) ** 2
         + math.cos(lat1) * math.cos(lat2) * math.sin(dlon / 2) ** 2)
    return 2 * EARTH_RADIUS_NM * math.asin(math.sqrt(a))


def _nearest_cluster_dist(lat, lon, clusters):
    """
    Returns minimum distance in nm to any cluster centroid,
    or None if clusters list is empty or lat/lon is missing.
    """
    if lat is None or lon is None or not clusters:
        return None
    dists = [
        _haversine_nm(lat, lon, c["centroid_lat"], c["centroid_lon"])
        for c in clusters
        if c.get("centroid_lat") is not None and c.get("centroid_lon") is not None
    ]
    return min(dists) if dists else None


def _is_in_orbit(hex_id, orbits):
    """Returns True if the aircraft hex appears in any confirmed orbit."""
    return any(o["hex"] == hex_id for o in orbits)


def _callsign_matches_ew(flight):
    """Returns True if the callsign starts with a known EW/ISR prefix."""
    if not flight:
        return False
    cs = flight.strip().upper()
    return any(cs.startswith(prefix) for prefix in EW_CALLSIGN_PREFIXES)


# ── Behavioral Scoring ────────────────────────────────────────────────────────

def _behavioral_score(ac, orbits, clusters):
    """
    Computes a 0.0–1.0+ behavioral score and a list of human-readable reasons.

    Scoring:
      In confirmed orbit      : +0.35
      Alt > 25 000 ft         : +0.20
      Alt > 55 000 ft         : +0.15 additional
      Speed 250–400 kts       : +0.10
      Near cluster (≤150 nm)  : +0.20
      Ghost (no callsign/type) in orbit : +0.10 bonus

    Returns (score: float, reasons: list[str])
    """
    score = 0.0
    reasons = []

    hex_id = ac.get("hex", "")
    flight = (ac.get("flight") or "").strip()
    ac_type = ac.get("t") or ac.get("type")
    lat = ac.get("lat")
    lon = ac.get("lon")
    gs  = ac.get("gs")

    # Altitude — parse robustly; "ground" or None → skip
    alt_baro = ac.get("alt_baro")
    alt_ft = None
    if alt_baro is not None and str(alt_baro).lower() not in ("ground", "", "none"):
        try:
            alt_ft = float(alt_baro)
        except (ValueError, TypeError):
            pass

    in_orbit = _is_in_orbit(hex_id, orbits)
    if in_orbit:
        score += 0.35
        reasons.append("in confirmed orbit")

    if alt_ft is not None and alt_ft > 25000:
        score += 0.20
        reasons.append(f"high altitude ({alt_ft:.0f} ft)")
        if alt_ft > 55000:
            score += 0.15
            reasons.append("very high altitude (>55 000 ft)")

    if gs is not None and 250 <= gs <= 400:
        score += 0.10
        reasons.append(f"EW-range speed ({gs:.0f} kts)")

    near_dist = _nearest_cluster_dist(lat, lon, clusters)
    if near_dist is not None and near_dist <= NEAR_CLUSTER_NM:
        score += 0.20
        reasons.append(f"near cluster ({near_dist:.0f} nm)")

    # Ghost bonus: no callsign AND no type AND in orbit
    if not flight and not ac_type and in_orbit:
        score += 0.10
        reasons.append("ghost contact in orbit")

    return score, reasons


# ── Main Detector ─────────────────────────────────────────────────────────────

def detect_ew_aircraft(snapshot, orbits, clusters):
    """
    Classifies EW/ISR aircraft from a snapshot.

    snapshot : list of dicts from api.adsb.lol
    orbits   : list of orbit dicts from detect_circular_flight()
    clusters : list of cluster dicts from detect_clusters()

    Returns list of contact dicts.
    """
    contacts = []

    for ac in snapshot:
        hex_id  = ac.get("hex", "")
        flight  = (ac.get("flight") or "").strip() or None
        ac_type = ac.get("t") or ac.get("type")
        lat     = ac.get("lat")
        lon     = ac.get("lon")
        alt_baro_raw = ac.get("alt_baro")
        alt_baro = str(alt_baro_raw) if alt_baro_raw is not None else None
        gs      = ac.get("gs")

        in_orbit    = _is_in_orbit(hex_id, orbits)
        near_dist   = _nearest_cluster_dist(lat, lon, clusters)
        near_cluster = near_dist is not None and near_dist <= NEAR_CLUSTER_NM
        cs_match    = _callsign_matches_ew(flight)

        ew_role       = None
        ew_confidence = None
        ew_basis      = None

        # ── Type-based classification ─────────────────────────────────────────
        type_upper = (ac_type or "").upper().strip()

        if type_upper in EW_TYPE_REGISTRY:
            ew_role = EW_TYPE_REGISTRY[type_upper]

            if type_upper in _PROBABLE_TYPES:
                # Start as PROBABLE, upgrade to CONFIRMED on evidence
                if in_orbit or near_cluster or cs_match:
                    ew_confidence = "CONFIRMED"
                    upgrade_reasons = []
                    if in_orbit:
                        upgrade_reasons.append("in orbit")
                    if near_cluster:
                        upgrade_reasons.append(f"near cluster ({near_dist:.0f} nm)")
                    if cs_match:
                        upgrade_reasons.append("callsign prefix match")
                    ew_basis = (
                        f"Type match ({type_upper}) + "
                        + ", ".join(upgrade_reasons)
                    )
                else:
                    ew_confidence = "PROBABLE"
                    ew_basis = (
                        f"Type match ({type_upper}) — probable EW variant "
                        f"(military-only feed)"
                    )
            else:
                # Exact known EW/ISR type on military-only feed → CONFIRMED
                ew_confidence = "CONFIRMED"
                ew_basis = f"Type code {type_upper} confirmed EW/ISR on military feed"

        else:
            # ── Behavioral classification (no type match) ─────────────────────
            b_score, b_reasons = _behavioral_score(ac, orbits, clusters)

            if b_score >= 0.55:
                ew_confidence = "PROBABLE"
                ew_role       = "Unknown EW/ISR (behavioral)"
                ew_basis      = f"Behavioral score {b_score:.2f}: " + ", ".join(b_reasons)
            elif b_score >= 0.35:
                ew_confidence = "POSSIBLE"
                ew_role       = "Unknown EW/ISR (behavioral)"
                ew_basis      = f"Behavioral score {b_score:.2f}: " + ", ".join(b_reasons)
            else:
                # Not EW — skip
                continue

        contacts.append({
            "hex":              hex_id,
            "flight":           flight,
            "type":             ac_type,
            "lat":              lat,
            "lon":              lon,
            "alt_baro":         alt_baro,
            "gs":               gs,
            "ew_role":          ew_role,
            "ew_confidence":    ew_confidence,
            "ew_basis":         ew_basis,
            "in_orbit":         in_orbit,
            "near_cluster":     near_cluster,
            "cluster_dist_nm":  round(near_dist, 1) if near_dist is not None else None,
        })

    return contacts
