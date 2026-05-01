FROM ubuntu:22.04

ENV DEBIAN_FRONTEND=noninteractive
ENV HOME=/home/steam

RUN dpkg --add-architecture i386 && \
    apt-get update && \
    apt-get install -y \
        curl \
        unzip \
        xz-utils \
        ca-certificates \
        lib32gcc-s1 \
        lib32stdc++6 \
    && rm -rf /var/lib/apt/lists/*

RUN useradd -m -s /bin/bash steam

WORKDIR /home/steam

RUN mkdir -p steamcmd && \
    curl -fsSL https://steamcdn-a.akamaihd.net/client/installer/steamcmd_linux.tar.gz \
    | tar -xz -C steamcmd

COPY start.sh /home/steam/start.sh
RUN chmod +x /home/steam/start.sh

EXPOSE 27015/udp

CMD ["/home/steam/start.sh"]
