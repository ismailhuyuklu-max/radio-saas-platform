FROM savonet/liquidsoap:v2.4.4

USER root

RUN apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg curl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /opt/liquidsoap

COPY docker/liquidsoap/entrypoint.sh /usr/local/bin/radio-liquidsoap-entrypoint
RUN chmod +x /usr/local/bin/radio-liquidsoap-entrypoint

ENTRYPOINT ["/usr/local/bin/radio-liquidsoap-entrypoint"]
