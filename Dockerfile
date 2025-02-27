FROM debian:12

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates libc6-dev-amd64-cross
RUN update-ca-certificates
RUN ln -s /usr/x86_64-linux-gnu/lib64/ /lib64

# This is for mac only
#ENV LD_LIBRARY_PATH="$LD_LIBRARY_PATH:/lib64:/usr/x86_64-linux-gnu/lib"

USER root
WORKDIR /app
ENV TZ=Asia/Ulaanbaatar
ENV SSL_CERT_DIR=/etc/ssl/certs
COPY . /app
COPY entrypoint.sh /app
RUN chmod +x /app/entrypoint.sh && \
    for i in $(seq -f "%05g" 1 450); do chmod +x /app/${i}/PosService; done
ENTRYPOINT ["/app/entrypoint.sh"]