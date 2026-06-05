settings.log.stdout := true
settings.server.telnet := false

# Live playout example. In production you can swap these playlists with
# directory-backed sources or a custom request.queue pipeline.
news   = playlist(mode="random", reload_mode="watch", "/var/media/news")
ads    = playlist(mode="random", reload_mode="watch", "/var/media/ads")
main   = mksafe(fallback(track_sensitive=false, [ads, news]))

output.file(%mp3(bitrate=192), "/var/media/live-output.mp3", main)
