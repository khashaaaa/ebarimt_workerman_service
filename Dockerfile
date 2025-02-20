FROM debian:12

RUN apt-get update && \
    apt-get install -y --no-install-recommends \
    ca-certificates \
    libc6-dev-amd64-cross && \
    update-ca-certificates && \
    ln -s /usr/x86_64-linux-gnu/lib64/ /lib64 && \
    rm -rf /var/lib/apt/lists/*

ENV TZ=Asia/Ulaanbaatar
ENV SSL_CERT_DIR=/etc/ssl/certs

WORKDIR /app
COPY . /app
COPY entrypoint.sh /app

RUN chmod +x /app/entrypoint.sh

RUN for i in $(seq -f "%05g" 1 450); do \
    if [ -f "/app/${i}/PosService" ]; then chmod +x /app/${i}/PosService; fi; \
done

ENTRYPOINT ["/app/entrypoint.sh"]