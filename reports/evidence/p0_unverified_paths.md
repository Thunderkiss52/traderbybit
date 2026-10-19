# P0 Unverified Paths & Safe Boundaries
- Production database was NOT queried directly (port 5432 is on VPS; local tests run strictly against isolated PostgreSQL 17 `bybit_test` on 127.0.0.1:25432).
- Real exchange orders remain strictly disabled and prohibited.
- ECONOM containers remained untouched.
- Packages P6 and P7 remain deferred and out of scope.
