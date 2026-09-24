---
paths:
  - app/Services/DashboardStatsService.php
---

# Services

## Semántica anual del dashboard
El filtro anual de causas usa exclusivamente causas.fecha_ingreso. Los movimientos financieros y las actuaciones se filtran por su propia columna fecha. Mantener estas fechas separadas para evitar mezclar períodos operacionales.
