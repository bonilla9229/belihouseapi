# Prompt para el equipo de Frontend — Aleph API

Estás desarrollando el frontend de **Aleph**, una plataforma SaaS de administración de propiedades horizontales (PH / condominios). El backend es una API REST en **Laravel 11**. A continuación encontrarás toda la información técnica necesaria para integrarte.

---

## 1. Autenticación y headers obligatorios

Todas las peticiones protegidas requieren dos headers:

```
Authorization: Bearer {token}
X-Tenant-ID: {tenant_id}
```

El token se obtiene en `POST /api/v1/login`. El `tenant_id` identifica al condominio activo.

**Login**
```
POST /api/v1/login
Body: { email, password }
Response: { token, user: { id, nombre, apellido, email, rol } }
```

**Logout**
```
POST /api/v1/logout
```

**Perfil propio**
```
GET /api/v1/me
Response: { data: { id, nombre, apellido, email, rol } }
```

---

## 2. Tenant

```
GET  /api/v1/tenant        → datos del PH activo
PUT  /api/v1/tenant        → actualizar datos del PH
     Body: { nombre?, rif?, direccion?, telefono?, email?, logo_url? }
```

---

## 3. Torres y Unidades

### Torres
```
GET    /api/v1/torres
Response: { data: [ { id, nombre, pisos } ] }

POST   /api/v1/torres
PUT    /api/v1/torres/{id}
Body:  { nombre, pisos }          ← pisos es integer, NO descripcion

DELETE /api/v1/torres/{id}
```

### Unidades
```
GET    /api/v1/unidades             ?torre_id=&buscar=&page=
POST   /api/v1/unidades
PUT    /api/v1/unidades/{id}
DELETE /api/v1/unidades/{id}

Body unidad: { numero, torre_id, piso?, coeficiente?, metraje?, tipo?, activa? }

GET    /api/v1/unidades/{id}        → incluye torre: { id, nombre, pisos }

POST   /api/v1/unidades/{id}/propietario
Body:  { propietario_id }
```

---

## 4. Propietarios

```
GET    /api/v1/propietarios         ?buscar=&page=
POST   /api/v1/propietarios
PUT    /api/v1/propietarios/{id}
DELETE /api/v1/propietarios/{id}

Body:  { nombre, apellido, cedula, telefono?, email?, direccion? }

POST   /api/v1/propietarios/{id}/asignar-unidad
Body:  { unidad_id, fecha_inicio? }
```

---

## 5. Residentes

```
GET    /api/v1/residentes           ?unidad_id=&buscar=&page=
POST   /api/v1/residentes
PUT    /api/v1/residentes/{id}
DELETE /api/v1/residentes/{id}

Body: { nombre, apellido, cedula, telefono?, email?, unidad_id, tipo, activo? }
tipo enum: propietario | inquilino | familiar | otro
```

---

## 6. Vehículos

```
GET    /api/v1/unidades/{id}/vehiculos
POST   /api/v1/unidades/{id}/vehiculos
Body:  { placa, marca?, modelo?, color?, tipo? }
tipo enum: carro | moto | camion | otro

DELETE /api/v1/vehiculos/{id}
```

---

## 7. Conceptos de Cobro

```
GET    /api/v1/conceptos-cobro
POST   /api/v1/conceptos-cobro
PUT    /api/v1/conceptos-cobro/{id}
DELETE /api/v1/conceptos-cobro/{id}

Body: {
  nombre,
  descripcion?,
  monto_base,           ← NO "monto"
  aplica_coeficiente,   ← boolean: si true el monto = monto_base × coeficiente_unidad
  periodicidad,         ← mensual | trimestral | anual | unico
  activo?
}
```

---

## 8. Cuotas

```
GET    /api/v1/cuotas               ?unidad_id=&estado=&mes=&page=
GET    /api/v1/cuotas/{id}
POST   /api/v1/cuotas               (crear cuota manual)
PUT    /api/v1/cuotas/{id}
DELETE /api/v1/cuotas/{id}

POST   /api/v1/cuotas/generar
Body:  { concepto_id, periodo (YYYY-MM), unidades_ids? }

POST   /api/v1/cuotas/recordatorio
Body:  { cuota_ids? }

GET    /api/v1/cuotas/resumen       → stats de cobro del mes

POST   /api/v1/cuotas/{id}/mora     → aplica mora manualmente
```

---

## 9. Pagos

```
GET    /api/v1/pagos                ?unidad_id=&desde=&hasta=&page=
POST   /api/v1/pagos
PUT    /api/v1/pagos/{id}
DELETE /api/v1/pagos/{id}

Body pago: { unidad_id, cuota_ids[], monto_total, metodo_pago, referencia?, fecha_pago?, observaciones? }

POST   /api/v1/pagos/{id}/anular
Body:  { motivo }
```

---

## 10. Tickets de Mantenimiento

```
GET    /api/v1/tickets
       ?estado=&prioridad=&categoria_id=&asignado_a=&buscar=&page=

POST   /api/v1/tickets
Body:  { titulo, descripcion?, unidad_id?, categoria_id?, prioridad? }
prioridad enum: baja | media | alta | urgente
estado inicial siempre: abierto

GET    /api/v1/tickets/{id}
PUT    /api/v1/tickets/{id}
Body:  { titulo?, descripcion?, unidad_id?, categoria_id?, prioridad? }
DELETE /api/v1/tickets/{id}

POST   /api/v1/tickets/{id}/comentarios
Body:  { contenido, interno? }          ← interno=true solo visible para admin

PUT    /api/v1/tickets/{id}/estado
Body:  { estado, notas? }
estado enum: abierto | en_progreso | resuelto | en_espera | cerrado
             ↑ OJO: "en_progreso" con g (NO "en_proceso")

POST   /api/v1/tickets/{id}/asignar
Body:  { usuario_id }

GET    /api/v1/categorias-ticket     → lista de categorías disponibles
```

**Respuesta index ticket:**
```json
{
  "id": 1,
  "titulo": "...",
  "prioridad": "alta",
  "estado": "en_progreso",
  "categoria": { "id": 1, "nombre": "Plomería", "color": "#0ea5e9" },
  "unidad": { "id": 5, "numero": "3B" },
  "propietario": "Juan Pérez",
  "asignado_a": { "id": 2, "nombre": "Carlos", "apellido": "Ruiz" },
  "dias_abierto": 3,
  "total_comentarios": 2,
  "created_at": "2026-02-20T10:00:00Z"
}
```

---

## 11. Accesos y Control de Entrada

```
GET  /api/v1/accesos
     ?fecha=YYYY-MM-DD&tipo_visita=&unidad_id=&buscar=&page=

POST /api/v1/accesos
Body: {
  nombre,                       ← nombre del visitante
  cedula?,
  placa?,
  tipo,                         ← visitante | delivery | proveedor | empleado | otro
  unidad_id?,
  motivo?
}

GET  /api/v1/accesos/{id}
DELETE /api/v1/accesos/{id}

POST /api/v1/accesos/{id}/salida   ← registrar hora de salida
Body: { observaciones? }

GET  /api/v1/accesos/buscar-preauth?cedula=&placa=
     → busca preautorizaciones activas para ese visitante

GET  /api/v1/accesos/analitica    → estadísticas de acceso (por tipo, por hora)
```

**Campos en respuesta:**
- `fecha_hora_entrada` / `fecha_hora_salida` — datetime completo (NO hora_entrada)
- `observaciones` — texto libre (NO notas)
- `registrado_por` — nombre del guardia/usuario que registró

---

## 12. Preautorizaciones

```
GET    /api/v1/preautorizaciones    ?unidad_id=&activa=&page=
POST   /api/v1/preautorizaciones
PUT    /api/v1/preautorizaciones/{id}
DELETE /api/v1/preautorizaciones/{id}

Body: {
  unidad_id,
  residente_id,            ← requerido
  nombre_visitante,
  cedula_visitante?,        ← NO "cedula"
  placa_visitante?,         ← NO "placa"
  fecha_desde,
  fecha_hasta?,
  descripcion?,
  activa?
}
```

---

## 13. Reservas de Áreas Comunes

```
GET    /api/v1/reservas             ?area_id=&fecha=&estado=&page=
POST   /api/v1/reservas
PUT    /api/v1/reservas/{id}
DELETE /api/v1/reservas/{id}

Body reserva: { area_comun_id, unidad_id, fecha, hora_inicio, hora_fin, observaciones? }

POST   /api/v1/reservas/{id}/aprobar
POST   /api/v1/reservas/{id}/rechazar
Body:  { motivo? }

GET    /api/v1/areas-comunes
POST   /api/v1/areas-comunes
PUT    /api/v1/areas-comunes/{id}
DELETE /api/v1/areas-comunes/{id}

Body area: {
  nombre, descripcion?, capacidad?,
  requiere_pago, costo?,
  activa?, imagen_url?
}

GET    /api/v1/areas-comunes/{id}/horarios
POST   /api/v1/areas-comunes/{id}/horarios
PUT    /api/v1/horarios/{id}
DELETE /api/v1/horarios/{id}

GET    /api/v1/areas-comunes/{id}/disponibilidad?fecha=YYYY-MM-DD
```

---

## 14. Gastos y Proveedores

```
GET    /api/v1/gastos               ?desde=&hasta=&categoria_id=&page=
GET    /api/v1/gastos/resumen
POST   /api/v1/gastos
PUT    /api/v1/gastos/{id}
DELETE /api/v1/gastos/{id}

Body gasto: { concepto, monto, fecha, categoria_id?, proveedor_id?, comprobante_url?, descripcion? }

GET    /api/v1/categorias-gasto
POST   /api/v1/categorias-gasto
Body:  { nombre, descripcion? }

GET    /api/v1/proveedores          ?buscar=&page=
POST   /api/v1/proveedores
PUT    /api/v1/proveedores/{id}
DELETE /api/v1/proveedores/{id}
Body:  { nombre, rif?, telefono?, email?, servicio?, activo? }
```

---

## 15. Comunicados

```
GET    /api/v1/comunicados          ?tipo=&activo=1|0&buscar=&page=
POST   /api/v1/comunicados
PUT    /api/v1/comunicados/{id}     ← solo si publicado=false (borrador)
DELETE /api/v1/comunicados/{id}     ← solo si publicado=false

Body: {
  titulo,
  cuerpo,          ← NO "contenido"
  tipo?            ← general | urgente | informativo
}

GET    /api/v1/comunicados/{id}

POST   /api/v1/comunicados/{id}/publicar    ← solo admin
POST   /api/v1/comunicados/{id}/leer        ← residente marca como leído
GET    /api/v1/comunicados/{id}/estadisticas
```

**Campos en respuesta:**
- `fecha_publicacion` — fecha en que fue publicado (NO `publicado_at`)
- `cuerpo` — contenido del comunicado (NO `contenido`)
- Admin recibe además: `lecturas`, `total_destinatarios`, `porcentaje_lectura`

---

## 16. Asambleas

```
GET    /api/v1/asambleas            ?estado=&page=
POST   /api/v1/asambleas
PUT    /api/v1/asambleas/{id}
DELETE /api/v1/asambleas/{id}

Body: { titulo, fecha, hora?, lugar?, descripcion?, quorum_requerido?, estado? }
estado enum: programada | en_curso | finalizada | cancelada

POST   /api/v1/asambleas/{id}/asistencia
Body:  { propietario_id, asistio }

GET    /api/v1/asambleas/{id}/quorum
```

---

## 17. Votaciones

```
GET    /api/v1/votaciones           ?asamblea_id=&page=
POST   /api/v1/votaciones
PUT    /api/v1/votaciones/{id}
DELETE /api/v1/votaciones/{id}

Body: { titulo, descripcion?, asamblea_id?, fecha_inicio, fecha_fin, opciones: [{ texto }] }

POST   /api/v1/votaciones/{id}/votar
Body:  { opcion_id }

POST   /api/v1/votaciones/{id}/cerrar
GET    /api/v1/votaciones/{id}/resultados
```

---

## 18. Usuarios del sistema

```
GET    /api/v1/usuarios             ?buscar=&rol=&activo=&page=
POST   /api/v1/usuarios
PUT    /api/v1/usuarios/{id}
DELETE /api/v1/usuarios/{id}

Body: { nombre, apellido, email, password?, rol_id?, activo? }
```

---

## 19. Configuración

```
GET /api/v1/configuracion
Response: {
  data: {
    moneda: "USD",
    dia_vencimiento: 10,
    dias_mora_gracia: 5,
    mora: { dias_gracia, tipo_mora, valor_mora, mora_acumulable }
  }
}

PUT /api/v1/configuracion
Body: { moneda?, dia_vencimiento?, dias_mora_gracia? }

GET /api/v1/configuracion/mora
PUT /api/v1/configuracion/mora
Body: { dias_gracia?, tipo_mora?, valor_mora?, mora_acumulable? }
tipo_mora enum: porcentaje | fijo

GET /api/v1/configuracion/notificaciones
PUT /api/v1/configuracion/notificaciones
Body: { email_cuotas?, push_accesos?, push_tickets?, ... }
```

---

## 20. Formato de respuestas

**Éxito colección:**
```json
{
  "data": [ ... ],
  "meta": { "total": 50, "per_page": 20, "current_page": 1, "last_page": 3 }
}
```

**Éxito recurso:**
```json
{ "data": { ... }, "message": "..." }
```

**Error de validación (422):**
```json
{ "message": "...", "errors": { "campo": ["texto del error"] } }
```

**No autorizado (401):** `{ "message": "Unauthenticated." }`
**Prohibido (403):** `{ "message": "..." }`
**No encontrado (404):** `{ "message": "..." }`

---

## 21. Tabla de campos renombrados (referencia rápida)

| Campo antiguo — NO usar        | Campo correcto en la API         |
|-------------------------------|----------------------------------|
| `contenido` (comunicado)      | `cuerpo`                         |
| `publicado_at`                | `fecha_publicacion`              |
| `hora_entrada` / `hora_salida`| `fecha_hora_entrada` / `fecha_hora_salida` |
| `notas` (acceso)              | `observaciones`                  |
| `guardia_id`                  | `autorizado_por`                 |
| `cedula` / `placa` (preauth)  | `cedula_visitante` / `placa_visitante` |
| `monto` (concepto cobro)      | `monto_base`                     |
| `descripcion` (torre)         | `pisos` (integer)                |
| `en_proceso` (ticket estado)  | `en_progreso`                    |
| `creado_por` (ticket)         | `reportado_por`                  |
