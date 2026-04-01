"""
EW / ISR Aircraft Classifier — Phase 2 detector.

Classifies military aircraft as Electronic Warfare, SIGINT, AWACS, or ISR assets
based on type code, callsign prefix, and behavioral scoring (orbit + altitude + proximity).

Type tiers (military-only feed):
  _CONFIRMED_TYPES  — dedicated EW/ISR platforms; type match alone is sufficient
  _PROBABLE_TYPES   — ISR-specific King Air variants; PROBABLE by type, CONFIRMED
                      on orbit/proximity/callsign evidence
  _POSSIBLE_TYPES   — common transports with rare EW variants (C-130 / EC-130);
                      POSSIBLE by type alone, requires orbit or proximity for PROBABLE,
                      requires both (or callsign) for CONFIRMED

Behavioral scoring minimum altitude gate: aircraft below MIN_EW_ALT_FT are not
scored — EW/ISR missions are not conducted at low altitude and approach/departure
phases create false orbit and proximity signals.
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

# Dedicated EW/ISR platforms — type match on military feed is sufficient for CONFIRMED
_CONFIRMED_TYPES = {
    "EA18", "EA6B", "RC135", "RC12", "EP3", "E8", "E3CF", "E7", "E2",
    "U2", "RQ4", "MQ9", "MC12", "EC130",
}

# ISR-specific King Air variants — PROBABLE by type, upgrade to CONFIRMED on evidence
_PROBABLE_TYPES = {"B350", "BE20", "PC12"}

# Common transports with rare EW variants — POSSIBLE by type only;
# requires orbit or proximity for PROBABLE; both (or callsign) for CONFIRMED
_POSSIBLE_TYPES = {"C130"}

# Minimum altitude for behavioral scoring — EW/ISR missions are not flown at low
# altitude; aircraft in approach/departure would otherwise accumulate false scores
MIN_EW_ALT_FT = 5_000

# Callsign prefixes that confirm EW/ISR tasking
EW_CALLSIGN_PREFIXES = {
    "AWACS", "RIVET", "COBRA", "IRON", "SHADOW", "TACAMO", "RECON", "JANET",
}

NEAR_CLUSTER_NM = 50.0  # tightened from 150 — 150nm is not "near"; at 50nm the
                        # aircraft must be in the same airspace as the formation


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


def _is_in_orbit(hex_id, orbit_hex_set):
    """Returns True if the aircraft hex appears in the confirmed orbit set."""
    return hex_id in orbit_hex_set


def _callsign_matches_ew(flight):
    """Returns True if the callsign starts with a known EW/ISR prefix."""
    if not flight:
        return False
    cs = flight.strip().upper()
    return any(cs.startswith(prefix) for prefix in EW_CALLSIGN_PREFIXES)


# ── Behavioral Scoring ────────────────────────────────────────────────────────

def _behavioral_score(ac, orbit_hex_set, clusters):
    """
    Computes a 0.0–1.0+ behavioral score and a list of human-readable reasons.

    Scoring:
      In confirmed orbit      : +0.35
      Alt > 25 000 ft         : +0.20
      Alt > 55 000 ft         : +0.15 additional
      Speed 250–380 kts       : +0.10
      Near cluster (≤50 nm)   : +0.20

    Altitude gate: aircraft below MIN_EW_ALT_FT return score 0.0 immediately —
    EW/ISR is not conducted at low altitude and approach/departure phases produce
    false orbit and proximity signals.

    Ghost bonus removed: missing identity means less information, not more.
    Speed range tightened from 250–400 to 250–380 to exclude fast transiting jets.

    Returns (score: float, reasons: list[str])
    """
    score = 0.0
    reasons = []

    hex_id = ac.get("hex", "")
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

    # Altitude gate — low-altitude aircraft are not EW/ISR candidates
    if alt_ft is not None and alt_ft < MIN_EW_ALT_FT:
        return 0.0, []

    in_orbit = _is_in_orbit(hex_id, orbit_hex_set)
    if in_orbit:
        score += 0.35
        reasons.append("in confirmed orbit")

    if alt_ft is not None and alt_ft > 25000:
        score += 0.20
        reasons.append(f"high altitude ({alt_ft:.0f} ft)")
        if alt_ft > 55000:
            score += 0.15
            reasons.append("very high altitude (>55 000 ft)")

    if gs is not None and 250 <= gs <= 380:
        score += 0.10
        reasons.append(f"EW-range speed ({gs:.0f} kts)")

    near_dist = _nearest_cluster_dist(lat, lon, clusters)
    if near_dist is not None and near_dist <= NEAR_CLUSTER_NM:
        score += 0.20
        reasons.append(f"near cluster ({near_dist:.0f} nm)")

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
    # Build a set once for O(1) orbit membership lookups
    orbit_hex_set = {o["hex"] for o in orbits}

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

        in_orbit    = _is_in_orbit(hex_id, orbit_hex_set)
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

            if type_upper in _CONFIRMED_TYPES:
                # Dedicated EW/ISR platform on military-only feed → CONFIRMED
                ew_confidence = "CONFIRMED"
                ew_basis = f"Type code {type_upper} confirmed EW/ISR on military feed"

            elif type_upper in _PROBABLE_TYPES:
                # ISR-specific King Air variants — PROBABLE by type, CONFIRMED on evidence
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
                        f"Type match ({type_upper}) — ISR variant on military-only feed"
                    )

            elif type_upper in _POSSIBLE_TYPES:
                # Common transports with rare EW variants (C-130 / EC-130):
                #   - type alone → POSSIBLE
                #   - orbit OR proximity → PROBABLE
                #   - (orbit AND proximity) OR callsign → CONFIRMED
                if cs_match:
                    ew_confidence = "CONFIRMED"
                    ew_basis = f"Type match ({type_upper}) + callsign prefix match"
                elif in_orbit and near_cluster:
                    ew_confidence = "CONFIRMED"
                    ew_basis = (
                        f"Type match ({type_upper}) + in orbit "
                        f"+ near cluster ({near_dist:.0f} nm)"
                    )
                elif in_orbit or near_cluster:
                    ew_confidence = "PROBABLE"
                    evidence = (
                        "in orbit" if in_orbit
                        else f"near cluster ({near_dist:.0f} nm)"
                    )
                    ew_basis = f"Type match ({type_upper}) + {evidence}"
                else:
                    ew_confidence = "POSSIBLE"
                    ew_basis = (
                        f"Type match ({type_upper}) — common transport; "
                        f"EW variant possible on military feed, no corroborating evidence"
                    )

            else:
                # Fallthrough — type is in registry but not in any tier set; shouldn't happen
                ew_confidence = "POSSIBLE"
                ew_basis = f"Type code {type_upper} in EW registry (untiered)"

        else:
            # ── Behavioral classification (no type match) ─────────────────────
            b_score, b_reasons = _behavioral_score(ac, orbit_hex_set, clusters)

            if b_score >= 0.55:
                ew_confidence = "PROBABLE"
                ew_role       = "Unknown EW/ISR (behavioral)"
                ew_basis      = f"Behavioral score {b_score:.2f}: " + ", ".join(b_reasons)
            elif b_score >= 0.40:
                # Raised from 0.35 — orbit alone scores 0.35 which no longer qualifies.
                # A POSSIBLE contact now requires orbit + at least one corroborating
                # signal (altitude, speed, or proximity) to suppress trainer/circuit noise.
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
