#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 4 ]]; then
  echo "Usage: $0 <main_input> <ad_input> <output_file> <pre_roll|post_roll> [crossfade_seconds]" >&2
  exit 64
fi

MAIN_INPUT="$1"
AD_INPUT="$2"
OUTPUT_FILE="$3"
PLACEMENT="$4"
CROSSFADE_SECONDS="${5:-1.0}"
TARGET_LUFS="${TARGET_LUFS:--16}"
TARGET_LRA="${TARGET_LRA:-11}"
TARGET_TP="${TARGET_TP:--1.5}"

main_duration="$(ffprobe -v error -show_entries format=duration -of csv=p=0 "$MAIN_INPUT")"
ad_duration="$(ffprobe -v error -show_entries format=duration -of csv=p=0 "$AD_INPUT")"
main_has_video="$(ffprobe -v error -select_streams v:0 -show_entries stream=index -of csv=p=0 "$MAIN_INPUT" | head -n 1 || true)"
ad_has_video="$(ffprobe -v error -select_streams v:0 -show_entries stream=index -of csv=p=0 "$AD_INPUT" | head -n 1 || true)"
# The xfade/video branch needs a video stream in BOTH inputs. If either input is
# audio-only (e.g. an audio sponsor jingle over a video bulletin), fall back to the
# audio crossfade branch instead of failing on a missing [x:v] stream specifier.
has_video=""
if [[ -n "$main_has_video" && -n "$ad_has_video" ]]; then
  has_video="1"
fi

calc_offset() {
  awk -v duration="$1" -v crossfade="$2" 'BEGIN { offset = duration - crossfade; if (offset < 0) offset = 0; printf "%.3f", offset }'
}

if [[ "$PLACEMENT" == "pre_roll" ]]; then
  FIRST_INPUT="$AD_INPUT"
  SECOND_INPUT="$MAIN_INPUT"
  FIRST_DURATION="$ad_duration"
  SECOND_DURATION="$main_duration"
else
  FIRST_INPUT="$MAIN_INPUT"
  SECOND_INPUT="$AD_INPUT"
  FIRST_DURATION="$main_duration"
  SECOND_DURATION="$ad_duration"
fi

XFADE_OFFSET="$(calc_offset "$FIRST_DURATION" "$CROSSFADE_SECONDS")"

if [[ -n "$has_video" ]]; then
  ffmpeg -y \
    -i "$FIRST_INPUT" \
    -i "$SECOND_INPUT" \
    -filter_complex "
      [0:v]scale=1280:720:force_original_aspect_ratio=decrease,pad=1280:720:(ow-iw)/2:(oh-ih)/2,setsar=1,format=yuv420p[v0];
      [1:v]scale=1280:720:force_original_aspect_ratio=decrease,pad=1280:720:(ow-iw)/2:(oh-ih)/2,setsar=1,format=yuv420p[v1];
      [0:a]loudnorm=I=${TARGET_LUFS}:LRA=${TARGET_LRA}:TP=${TARGET_TP},aformat=sample_fmts=fltp:sample_rates=48000:channel_layouts=stereo[a0];
      [1:a]loudnorm=I=${TARGET_LUFS}:LRA=${TARGET_LRA}:TP=${TARGET_TP},aformat=sample_fmts=fltp:sample_rates=48000:channel_layouts=stereo[a1];
      [v0][v1]xfade=transition=fade:duration=${CROSSFADE_SECONDS}:offset=${XFADE_OFFSET}[v];
      [a0][a1]acrossfade=d=${CROSSFADE_SECONDS}:c1=tri:c2=tri[a]
    " \
    -map "[v]" \
    -map "[a]" \
    -c:v libx264 \
    -preset medium \
    -crf 20 \
    -c:a aac \
    -b:a 192k \
    -movflags +faststart \
    "$OUTPUT_FILE"
else
  ffmpeg -y \
    -i "$FIRST_INPUT" \
    -i "$SECOND_INPUT" \
    -filter_complex "
      [0:a]loudnorm=I=${TARGET_LUFS}:LRA=${TARGET_LRA}:TP=${TARGET_TP},aformat=sample_fmts=fltp:sample_rates=48000:channel_layouts=stereo[a0];
      [1:a]loudnorm=I=${TARGET_LUFS}:LRA=${TARGET_LRA}:TP=${TARGET_TP},aformat=sample_fmts=fltp:sample_rates=48000:channel_layouts=stereo[a1];
      [a0][a1]acrossfade=d=${CROSSFADE_SECONDS}:c1=tri:c2=tri[a]
    " \
    -map "[a]" \
    -c:a libmp3lame \
    -b:a 192k \
    "$OUTPUT_FILE"
fi
