#!/usr/bin/env bash
#
# Regression test for render_ad_injection.sh
#
# Guards against the FFmpeg sponsor-render bugs fixed earlier:
#   1. acrossfade "Cannot select channel layout" (missing aformat channel_layouts)
#   2. AAC encoded into an .mp3 container (must use libmp3lame for audio output)
#
# Run inside any container that has ffmpeg (php-fpm / worker / liquidsoap):
#   docker compose -f docker-compose.prod.yml exec -T worker bash /var/media-tools/ffmpeg/test_render.sh
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RENDER="$SCRIPT_DIR/render_ad_injection.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

fail() { echo "[FAIL] $1" >&2; exit 1; }
dur()  { ffprobe -v error -show_entries format=duration -of csv=p=0 "$1"; }
acodec() { ffprobe -v error -select_streams a:0 -show_entries stream=codec_name -of csv=p=0 "$1"; }

command -v ffmpeg >/dev/null 2>&1 || fail "ffmpeg bulunamadi"
[ -f "$RENDER" ] || fail "render betigi bulunamadi: $RENDER"

echo "Generating fixtures..."
ffmpeg -nostdin -loglevel error -f lavfi -i "sine=frequency=440:duration=3" -ac 2 -ar 44100 -b:a 128k "$TMP/main.mp3" -y
ffmpeg -nostdin -loglevel error -f lavfi -i "sine=frequency=880:duration=2" -ac 2 -ar 44100 -b:a 128k "$TMP/ad.mp3" -y
main_dur="$(dur "$TMP/main.mp3")"

assert_output() {
  local out="$1" placement="$2"
  [ -f "$out" ] || fail "$placement: cikti dosyasi olusmadi"
  local out_dur; out_dur="$(dur "$out")"
  awk -v o="$out_dur" -v m="$main_dur" 'BEGIN { exit !(o > m) }' \
    || fail "$placement: sure uzamadi (cikti=$out_dur <= ana=$main_dur) -> sponsor enjekte edilmemis"
  local c; c="$(acodec "$out")"
  [ "$c" = "mp3" ] || fail "$placement: ses codec'i mp3 degil ($c) -> konteyner uyumsuzlugu"
  echo "[OK] $placement: $out_dur sn, codec=$c"
}

echo "Testing pre_roll (sunar)..."
bash "$RENDER" "$TMP/main.mp3" "$TMP/ad.mp3" "$TMP/out_pre.mp3" pre_roll || fail "pre_roll render exit-kodu != 0"
assert_output "$TMP/out_pre.mp3" "pre_roll"

echo "Testing post_roll (sundu)..."
bash "$RENDER" "$TMP/main.mp3" "$TMP/ad.mp3" "$TMP/out_post.mp3" post_roll || fail "post_roll render exit-kodu != 0"
assert_output "$TMP/out_post.mp3" "post_roll"

echo "[SUCCESS] render regression test passed"
