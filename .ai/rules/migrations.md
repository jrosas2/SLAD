---
paths:
  - 'app/**,resources/views/pages/causas/**,database/migrations/**'
---

# Migrations

## Responsables de causas son usuarios
causas.responsable_id referencia users.id; no reintroducir el modelo ni catálogo Responsable. Solo ADMINISTRADOR puede asignar, cambiar o quitar responsable mediante CausaPolicy::assignResponsible. Las opciones asignables son usuarios activos ADMINISTRADOR o ABOGADO; abogados crean causas sin responsable.
