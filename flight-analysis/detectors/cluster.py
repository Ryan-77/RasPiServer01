"""
Cluster detector — finds groups of 3+ military aircraft in close proximity.
Pure-Python DBSCAN with haversine distance. eps ~30nm.
"""

import math

EARTH_RADIUS_NM = 3440.065
EPS_NM = 30
MIN_SAMPLES = 3


def _haversine_nm(lat1, lon1, lat2, lon2):
    lat1, lon1, lat2, lon2 = map(math.radians, [lat1, lon1, lat2, lon2])
    dlat = lat2 - lat1
    dlon = lon2 - lon1
    a = math.sin(dlat / 2) ** 2 + math.cos(lat1) * math.cos(lat2) * math.sin(dlon / 2) ** 2
    return 2 * EARTH_RADIUS_NM * math.asin(math.sqrt(a))


def _dbscan(points, eps_nm, min_samples):
    """Simple O(n²) DBSCAN. Returns list of cluster label per point (-1 = noise)."""
    n = len(points)
    labels = [-1] * n
    cluster_id = 0

    def neighbors(idx):
        return [
            j for j in range(n)
            if _haversine_nm(points[idx][0], points[idx][1], points[j][0], points[j][1]) <= eps_nm
        ]

    visited = [False] * n
    for i in range(n):
        if visited[i]:
            continue
        visited[i] = True
        nb = neighbors(i)
        if len(nb) < min_samples:
            continue  # noise for now
        labels[i] = cluster_id
        queue = list(nb)
        while queue:
            j = queue.pop()
            if not visited[j]:
                visited[j] = True
                nb2 = neighbors(j)
                if len(nb2) >= min_samples:
                    queue.extend(nb2)
            if labels[j] == -1:
                labels[j] = cluster_id
        cluster_id += 1

    return labels


def detect_clusters(snapshot):
    """
    snapshot: list of dicts from api.adsb.lol
    Returns list of cluster dicts.
    """
    airborne = [
        a for a in snapshot
        if a.get("lat") and a.get("lon")
        and str(a.get("alt_baro", "")).lower() != "ground"
        and a.get("alt_baro") not in (None, "", "0")
    ]

    if len(airborne) < MIN_SAMPLES:
        return []

    points = [(a["lat"], a["lon"]) for a in airborne]
    labels = _dbscan(points, EPS_NM, MIN_SAMPLES)

    clusters = []
    for label in set(labels):
        if label == -1:
            continue

        members = [airborne[i] for i, l in enumerate(labels) if l == label]
        lats = [m["lat"] for m in members]
        lons = [m["lon"] for m in members]
        speeds = [m["gs"] for m in members if m.get("gs")]
        types = list({m.get("t") for m in members if m.get("t")})

        clusters.append({
            "label": label,
            "centroid_lat": sum(lats) / len(lats),
            "centroid_lon": sum(lons) / len(lons),
            "count": len(members),
            "types": types,
            "avg_gs": sum(speeds) / len(speeds) if speeds else None,
            "hex_list": [m["hex"] for m in members],
        })

    return clusters


def cluster_severity(cluster):
    if cluster["count"] >= 5:
        return "HIGH"
    return "MEDIUM"
