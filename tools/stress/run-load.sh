#!/bin/bash
# usage: tools/stress/run-load.sh <concurrent users> [cache+session driver]   (needs the psed_stress database, see tools/stress/bench.php)
cd "$(dirname "$0")/../.."
D=${2:-database}
export DB_DATABASE=psed_stress CACHE_STORE=$D SESSION_DRIVER=$D
T=$(mktemp -d)
for i in $(seq 1 "$1"); do php tools/stress/load.php 30 > "$T/out.$i.json" & done
wait
cat "$T"/out.*.json | tr -d '[]' | tr ',' '\n' | sort -n | awk -v u="$1" -v d="$D" '{a[NR]=$1; s+=$1} END {printf "%-8s users=%-3d requests=%-4d mean=%7.0f ms  p95=%7.0f ms  max=%7.0f ms\n", d, u, NR, s/NR, a[int(NR*0.95)], a[NR]}'
rm -rf "$T"
