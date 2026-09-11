# ---------- 构建阶段 ----------
FROM golang:1.23-alpine AS builder

WORKDIR /src
ENV CGO_ENABLED=0 GOOS=linux

COPY go.mod go.sum ./
RUN go env -w GOPROXY=https://goproxy.cn,direct && go mod download

COPY . .
ARG CMD=crawler-worker
RUN go build -trimpath -ldflags "-s -w" -o /out/app ./cmd/${CMD}

# ---------- 运行阶段 ----------
FROM alpine:3.20

RUN apk add --no-cache ca-certificates tzdata && \
    ln -sf /usr/share/zoneinfo/Asia/Shanghai /etc/localtime && \
    adduser -D -u 10001 appuser

WORKDIR /app
COPY --from=builder /out/app /app/app
COPY configs /app/configs

USER appuser
ENTRYPOINT ["/app/app"]
CMD ["-config", "/app/configs/config.yaml"]
