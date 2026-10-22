# Multi-stage minimal build for Go research worker
FROM golang:1.27.3-alpine3.24 AS builder

ENV GOTOOLCHAIN=local

WORKDIR /build

COPY research-go/go.mod research-go/go.sum* ./
RUN go mod download

COPY research-go/ ./
RUN CGO_ENABLED=0 GOOS=linux go build -ldflags="-s -w" -o /research-worker ./cmd/research-worker

# Minimal secure runtime image
FROM alpine:3.24

RUN addgroup -g 10001 -S research && \
    adduser -u 10001 -S research -G research

COPY --from=builder /research-worker /usr/local/bin/research-worker

USER research:research

WORKDIR /app

ENTRYPOINT ["/usr/local/bin/research-worker"]
