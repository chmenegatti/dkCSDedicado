FROM ubuntu:22.04

ENV DEBIAN_FRONTEND=noninteractive

RUN dpkg --add-architecture i386 && \
    apt-get update && \
    apt-get install -y \
        curl \
        ca-certificates \
        lib32gcc-s1 \
        lib32stdc++6 \
    && rm -rf /var/lib/apt/lists/*

RUN useradd -m -s /bin/bash steam

USER steam
WORKDIR /home/steam

RUN mkdir -p steamcmd && \
    curl -fsSL https://steamcdn-a.akamaihd.net/client/installer/steamcmd_linux.tar.gz \
    | tar -xz -C steamcmd

COPY --chown=steam:steam start.sh /home/steam/start.sh
RUN chmod +x /home/steam/start.sh

EXPOSE 27015/udp

CMD ["/home/steam/start.sh"]
