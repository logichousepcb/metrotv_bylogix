#!/usr/bin/env python3
"""Fetch raw nearby Transit data and write it to a cache JSON file."""

from __future__ import annotations

import argparse
import copy
import json
import math
import os
import sys
import urllib.error
import urllib.parse
import urllib.request


TRANSIT_NEARBY_ROUTES_URL = "https://external.transitapp.com/v3/public/nearby_routes"
METERS_PER_MILE = 1609.344


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Fetch Transit nearby routes data and cache the raw JSON.")
    parser.add_argument("--lat", required=True, type=float, help="Latitude")
    parser.add_argument("--lon", required=True, type=float, help="Longitude")
    parser.add_argument("--api-key", required=True, help="Transit API key")
    parser.add_argument("--radius-miles", required=True, type=float, help="Search radius in miles")
    parser.add_argument("--output", required=True, help="Output JSON cache path")
    return parser.parse_args()


def ensure_parent_dir(file_path: str) -> None:
    parent = os.path.dirname(os.path.abspath(file_path))
    if parent:
        os.makedirs(parent, exist_ok=True)


def slugify(value: str) -> str:
    lowered = value.strip().lower()
    slug_chars = [ch if ch.isalnum() else "-" for ch in lowered]
    slug = "".join(slug_chars)
    while "--" in slug:
        slug = slug.replace("--", "-")
    return slug.strip("-") or "station"


def build_request_url(lat: float, lon: float, radius_miles: float) -> str:
    radius_meters = round(radius_miles * METERS_PER_MILE)
    query = urllib.parse.urlencode(
        {
            "lat": f"{lat:.6f}",
            "lon": f"{lon:.6f}",
            "max_distance": str(int(radius_meters)),
        }
    )
    return f"{TRANSIT_NEARBY_ROUTES_URL}?{query}"


def fetch_payload(url: str, api_key: str) -> object:
    request = urllib.request.Request(
        url,
        headers={
            "Accept": "application/json",
            "apiKey": api_key,
        },
        method="GET",
    )

    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            charset = response.headers.get_content_charset() or "utf-8"
            raw = response.read().decode(charset)
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8", errors="replace").strip()
        raise RuntimeError(body or f"HTTP {exc.code} while fetching Transit data") from exc
    except urllib.error.URLError as exc:
        raise RuntimeError(f"Network error while fetching Transit data: {exc.reason}") from exc

    try:
        return json.loads(raw)
    except json.JSONDecodeError as exc:
        raise RuntimeError("Transit API returned invalid JSON") from exc


def write_payload(output_path: str, payload: object) -> None:
    ensure_parent_dir(output_path)
    temp_path = output_path + ".tmp"
    with open(temp_path, "w", encoding="utf-8", newline="\n") as handle:
        json.dump(payload, handle, indent=2, ensure_ascii=False)
        handle.write("\n")
    os.replace(temp_path, output_path)


def load_json_file(path: str) -> object:
    with open(path, "r", encoding="utf-8") as handle:
        return json.load(handle)


def miles_to_meters(miles: float) -> float:
    return miles * METERS_PER_MILE


def haversine_meters(lat1: float, lon1: float, lat2: float, lon2: float) -> float:
    earth_radius_m = 6371000.0
    lat1_rad = math.radians(lat1)
    lat2_rad = math.radians(lat2)
    delta_lat = math.radians(lat2 - lat1)
    delta_lon = math.radians(lon2 - lon1)

    a = (
        math.sin(delta_lat / 2.0) ** 2
        + math.cos(lat1_rad) * math.cos(lat2_rad) * math.sin(delta_lon / 2.0) ** 2
    )
    c = 2.0 * math.atan2(math.sqrt(a), math.sqrt(1.0 - a))
    return earth_radius_m * c


def station_route_tokens(station: dict) -> set[str]:
    tokens: set[str] = set()
    for route in station.get("routes", []):
        if route is None:
            continue
        tokens.add(str(route).strip().upper())
    return tokens


def route_matches_station(route: dict, station_routes: set[str]) -> bool:
    if not station_routes:
        return True

    candidates = {
        str(route.get("route_short_name", "")).strip().upper(),
        str(route.get("real_time_route_id", "")).strip().upper(),
        str(route.get("route_long_name", "")).strip().upper(),
    }
    candidates.discard("")
    return bool(candidates & station_routes)


def itinerary_within_station_distance(itinerary: dict, station_lat: float, station_lon: float, max_distance_meters: float) -> bool:
    closest_stop = itinerary.get("closest_stop") or {}
    stop_lat = closest_stop.get("stop_lat")
    stop_lon = closest_stop.get("stop_lon")
    if stop_lat is None or stop_lon is None:
        return False

    try:
        distance_meters = haversine_meters(station_lat, station_lon, float(stop_lat), float(stop_lon))
    except (TypeError, ValueError):
        return False

    return distance_meters <= max_distance_meters


def build_station_payload(raw_payload: dict, station: dict) -> dict:
    station_name = str(station.get("name", "")).strip() or "Station"
    station_lat = float(station["latitude"])
    station_lon = float(station["longitude"])
    max_distance_meters = miles_to_meters(float(station.get("distance", 0)))
    station_routes = station_route_tokens(station)

    filtered_routes = []
    for route in raw_payload.get("routes", []):
        if not isinstance(route, dict):
            continue
        if not route_matches_station(route, station_routes):
            continue

        itineraries = []
        for itinerary in route.get("itineraries", []):
            if not isinstance(itinerary, dict):
                continue
            if itinerary_within_station_distance(itinerary, station_lat, station_lon, max_distance_meters):
                itineraries.append(copy.deepcopy(itinerary))

        if itineraries:
            route_copy = copy.deepcopy(route)
            route_copy["itineraries"] = itineraries
            filtered_routes.append(route_copy)

    return {
        "station": {
            "name": station_name,
            "latitude": str(station.get("latitude", "")),
            "longitude": str(station.get("longitude", "")),
            "distance": str(station.get("distance", "")),
            "routes": [str(route) for route in station.get("routes", [])],
        },
        "routes": filtered_routes,
    }


def write_station_files(raw_payload: dict, output_path: str) -> list[str]:
    app_data_dir = os.path.dirname(os.path.abspath(output_path))
    stations_path = os.path.join(app_data_dir, "stations.json")
    if not os.path.isfile(stations_path):
        return []

    stations = load_json_file(stations_path)
    if not isinstance(stations, list):
        raise RuntimeError("stations.json must contain a JSON array")

    written_files: list[str] = []
    for station in stations:
        if not isinstance(station, dict):
            continue
        if "latitude" not in station or "longitude" not in station or "distance" not in station:
            continue

        station_payload = build_station_payload(raw_payload, station)
        station_name = str(station.get("name", "station"))
        station_file = os.path.join(app_data_dir, f"{slugify(station_name)}_transit_data.json")
        write_payload(station_file, station_payload)
        written_files.append(station_file)

    return written_files


def main() -> int:
    args = parse_args()
    if args.radius_miles <= 0:
        print("radius-miles must be greater than zero", file=sys.stderr)
        return 1

    try:
        url = build_request_url(args.lat, args.lon, args.radius_miles)
        payload = fetch_payload(url, args.api_key)
        write_payload(args.output, payload)
        station_files = write_station_files(payload, args.output)
    except Exception as exc:  # pragma: no cover - CLI path
        print(str(exc), file=sys.stderr)
        return 1

    print(f"Saved raw transit data to {args.output}")
    for station_file in station_files:
        print(f"Saved station transit data to {station_file}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())