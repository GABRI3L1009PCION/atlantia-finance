<div align="center">

# Atlantia Finance

**Plataforma SaaS de gestión contable, fiscal y operativa para firmas de contadores y empresas en Guatemala.**

![PHP](https://img.shields.io/badge/PHP-8.3-777BB4?logo=php&logoColor=white)
![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
![React](https://img.shields.io/badge/React-19-61DAFB?logo=react&logoColor=black)
![Inertia](https://img.shields.io/badge/Inertia.js-3-9553E9)
![TypeScript](https://img.shields.io/badge/TypeScript-5-3178C6?logo=typescript&logoColor=white)
![Tailwind CSS](https://img.shields.io/badge/Tailwind_CSS-4-06B6D4?logo=tailwindcss&logoColor=white)
![PostgreSQL](https://img.shields.io/badge/PostgreSQL-18-4169E1?logo=postgresql&logoColor=white)
![Pest](https://img.shields.io/badge/Tests-Pest_4-F472B6)

</div>

---

## Tabla de contenidos

- [Descripción](#descripción)
- [Funcionalidades principales](#funcionalidades-principales)
- [Paneles por rol](#paneles-por-rol)
- [Planes de suscripción](#planes-de-suscripción)
- [Arquitectura](#arquitectura)
- [Stack tecnológico](#stack-tecnológico)
- [Requisitos](#requisitos)
- [Instalación](#instalación)
- [Configuración](#configuración)
- [Desarrollo](#desarrollo)
- [Pruebas y calidad](#pruebas-y-calidad)
- [Seguridad](#seguridad)
- [Despliegue](#despliegue)
- [Estructura del proyecto](#estructura-del-proyecto)
- [Documentación adicional](#documentación-adicional)

---

## Descripción

**Atlantia Finance** es una plataforma multiempresa y multioficina que centraliza la operación de una firma contable: contabilidad general, facturación electrónica (FEL) ante la SAT, obligaciones tributarias, nómina, tesorería, cuentas por cobrar y por pagar, gestión del trabajo del equipo y comunicación con los clientes.

Está diseñada para el contexto guatemalteco (moneda **GTQ**, régimen fiscal de la **SAT**, libros de IVA, retenciones y FEL) y se ofrece como servicio por suscripción, con aislamiento estricto entre organizaciones, permisos verificados en el servidor y trazabilidad completa de cada operación.

La plataforma incluye a **Luzma**, un asistente de inteligencia artificial que se ejecuta de forma local: propone resúmenes, clasificaciones y alertas, pero toda acción sensible requiere la revisión y aprobación de una persona autorizada.

---

## Funcionalidades principales

### Contabilidad
- Catálogo de cuentas, centros de costo y políticas contables versionadas.
- Partida doble automática a partir de ventas, compras, pagos, activos y cheques.
- Estados financieros, notas a los estados financieros y reportes exportables (PDF / Excel).
- Activos fijos con cálculo de depreciación.
- Conciliaciones bancarias y de cuentas.
- Inventario y movimientos de existencias.

### Facturación electrónica y fiscal
- Emisión, certificación, anulación y envío por correo de documentos **FEL**, con proveedores certificadores configurables.
- Lectura de XML de la SAT y registro de documentos recibidos.
- Libros de compras y ventas (IVA), retenciones, ajustes y notas de crédito.
- Calendario y control de obligaciones fiscales, con alertas de vencimiento.
- Biblioteca normativa con la legislación tributaria de referencia.

### Finanzas operativas
- **Cuentas por cobrar:** antigüedad de saldos, cobranza, promesas de pago, estados de cuenta y disputas.
- **Cuentas por pagar:** propuestas de pago, vencimientos, anticipos, detección de duplicados y conciliación.
- **Tesorería:** cuentas bancarias, chequeras, caja, transferencias, aprobaciones y flujo de efectivo.
- **Nómina:** parámetros legales, cálculo, cierre de periodo, pagos y conciliación con la contabilidad.

### Gestión de la firma
- Asignación de empresas cliente, tareas con dependencias, cierres y revisiones.
- Registro de horas, presupuesto de horas y rentabilidad por cliente.
- CRM de prospectos, contratos e interacciones.
- Panel de dirección con indicadores, mapa de riesgos, carga de trabajo, productividad y proyecciones.
- Capacitación interna con cursos, inscripciones y certificados.

### Portal del cliente
- Documentos, solicitudes, aprobaciones, mensajes y calendario de obligaciones.
- Consulta de finanzas, reportes, nómina y facturas FEL de su empresa.
- Avisos de pago y verificación en dos pasos opcional exigida por la oficina.

### Centro de control (plataforma SaaS)
- Administración de organizaciones, planes, versiones de plan, suscripciones y facturación.
- *Feature flags*, límites por plan, uso y créditos.
- Salud de servicios, trabajos en cola, incidentes, tickets de soporte y accesos de soporte auditados.
- Integraciones, webhooks y documentos legales con aceptación registrada.

---

## Paneles por rol

Cada usuario accede a un panel adaptado a su función dentro de la firma:

| Rol | Enfoque |
| --- | --- |
| Socio / Director | Visión global, indicadores, riesgos y crecimiento |
| Administrador de oficina | Equipo, accesos, perfil de la firma y suscripción |
| Gerente / Coordinador contable | Asignaciones, cierres y avance del equipo |
| Contador general / Supervisor | Revisión, observaciones y control de calidad |
| Contador encargado / Auxiliar | Registro diario, documentos y tareas |
| Encargado fiscal | Obligaciones, FEL, libros de IVA y retenciones |
| Tesorería | Pagos, cobros, caja y conciliaciones |
| Cuentas por cobrar / por pagar | Cartera, vencimientos y seguimiento |
| Auditor | Solicitudes y revisión de evidencia |
| Cliente | Portal de autoservicio de su empresa |

---

## Planes de suscripción

| Plan | Perfil |
| --- | --- |
| **Esencial** | Profesional independiente |
| **Profesional** | Firma contable en crecimiento |
| **Multi-oficina** | Propietarios que supervisan varias sedes |
| **Corporativo** | Firmas con operación avanzada |
| **Empresarial** | Organizaciones con todos los módulos |

Los pagos se procesan en quetzales mediante **Recurrente**, con facturación mensual o anual (doce meses al precio de diez).

---

## Arquitectura

```text
┌──────────────────────────┐      ┌──────────────────────────┐
│  React 19 + Inertia v3   │◄────►│   Laravel 13 (PHP 8.3)   │
│  TypeScript · Tailwind 4 │      │  Fortify · Wayfinder     │
└──────────────────────────┘      └────────────┬─────────────┘
                                               │
                 ┌─────────────────────────────┼─────────────────────────────┐
                 ▼                             ▼                             ▼
     ┌──────────────────────┐     ┌──────────────────────┐     ┌──────────────────────┐
     │ PostgreSQL           │     │ Colas y programador  │     │ Atlantia AI (Luzma)  │
     │ Row Level Security   │     │ (jobs, alertas,      │     │ Python · FastAPI     │
     │ por organización     │     │  respaldos)          │     │ ejecución local      │
     └──────────────────────┘     └──────────────────────┘     └──────────────────────┘
                                               │
                         ┌─────────────────────┴─────────────────────┐
                         ▼                                           ▼
              ┌──────────────────────┐                   ┌──────────────────────┐
              │ Certificadores FEL   │                   │ Recurrente (pagos)   │
              │ / Portal SAT         │                   │ webhooks firmados    │
              └──────────────────────┘                   └──────────────────────┘
```

**Modelo de aislamiento multiempresa** (ver [ADR-0001](docs/architecture/adr/0001-multi-tenant-purchases.md)):

```text
usuario → organización activa → oficina activa → empresa asignada → registros de esa empresa
```

La autorización se resuelve siempre en el servidor con denegación por defecto; los recursos fuera de alcance responden `404` y las acciones sin permiso responden `403`.

---

## Stack tecnológico

| Capa | Tecnología |
| --- | --- |
| Backend | PHP 8.3, Laravel 13, Laravel Fortify (2FA y passkeys), Laravel Wayfinder |
| Frontend | React 19, Inertia.js 3, TypeScript 5, Tailwind CSS 4, Radix UI, Lucide |
| Base de datos | PostgreSQL con Row Level Security |
| Documentos | Dompdf (PDF), PhpSpreadsheet (Excel) |
| IA | Servicio Python/FastAPI propio (`services/atlantia-ai`), Ollama opcional |
| Pruebas | Pest 4, PHPUnit |
| Calidad | Laravel Pint, Larastan (PHPStan), ESLint, Prettier |
| Seguridad CI | Semgrep, TruffleHog, auditoría de Composer y pnpm, Dependabot |
| Build | Vite 8, pnpm |

---

## Requisitos

- PHP **8.3** con la extensión `pdo_pgsql`
- Composer 2
- Node.js 22+ y pnpm
- PostgreSQL 16+ (CI usa la versión 18)
- Python 3.11+ (solo si se ejecuta el servicio de IA)

---

## Instalación

```bash
# 1. Clonar el repositorio
git clone https://github.com/gabri3l1009pcion/atlantia-finance.git
cd atlantia-finance

# 2. Instalar dependencias, generar la clave, migrar y compilar
composer setup
```

El script `composer setup` instala las dependencias de PHP y JavaScript, crea `.env` a partir de `.env.example`, genera la clave de la aplicación, ejecuta las migraciones y compila los assets.

Para cargar datos de demostración (solo en entornos no productivos):

```bash
php artisan db:seed
```

---

## Configuración

Copia `.env.example` a `.env` y ajusta, como mínimo:

| Variable | Descripción |
| --- | --- |
| `APP_URL` | URL pública de la aplicación |
| `APP_LOCALE` | Idioma de la interfaz (por defecto `es`) |
| `DB_*` | Conexión a PostgreSQL |
| `FEL_PROVIDER` | Proveedor FEL (`sat_manual` u otro certificador) |
| `FEL_ENVIRONMENT` | `test` o `production` |
| `FEL_API_URL`, `FEL_API_KEY`, `FEL_USERNAME`, `FEL_PASSWORD` | Credenciales del certificador |
| `MAIL_*` | Servidor de correo para notificaciones y envío de FEL |

> Nunca subas el archivo `.env` ni credenciales al repositorio. Para staging usa `.env.staging.example` como referencia.

---

## Desarrollo

Inicia en paralelo el servidor, la cola, el programador de tareas y Vite:

```bash
composer dev
```

La aplicación queda disponible en `http://localhost:8000`.

### Servicio de IA (opcional)

```bash
cd services/atlantia-ai
python -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
```

Consulta [`services/atlantia-ai/README.md`](services/atlantia-ai/README.md) para los motores disponibles (`brain`, `auto`, `ollama`).

---

## Pruebas y calidad

```bash
# Suite completa de pruebas
php artisan test --compact

# Formato de PHP
composer lint

# Análisis estático (PHPStan)
composer types:check

# Verificación completa (lint, formato, tipos y pruebas)
composer ci:check
```

Frontend:

```bash
pnpm lint          # ESLint
pnpm format        # Prettier
pnpm types:check   # TypeScript
```

GitHub Actions ejecuta en cada *push* y *pull request* la suite de pruebas contra PostgreSQL con un rol sin privilegios `BYPASSRLS`, de modo que Row Level Security se aplica igual que en producción.

---

## Seguridad

- Aislamiento entre organizaciones a nivel de aplicación y de base de datos (RLS).
- Autenticación multifactor, passkeys y control de intentos de inicio de sesión.
- Roles y permisos verificados en el servidor en cada ruta.
- Bitácora de auditoría de operaciones sensibles y accesos de soporte.
- Webhooks de pago con verificación de firma.
- Respaldos automáticos con retención configurable y copia a S3 privado.
- Análisis SAST, detección de secretos y auditoría de dependencias en CI.

Documentos relacionados: [matriz de autorización](docs/security/authorization-matrix-purchases.md) y [modelo de amenazas](docs/security/threat-model-purchases.md).

Si encuentras una vulnerabilidad, repórtala de forma privada a [soporte@atlantiasystems.com](mailto:soporte@atlantiasystems.com) en lugar de abrir un *issue* público.

---

## Despliegue

- **Staging / producción:** servidor Linux con Nginx, PHP 8.3 y PostgreSQL. Ver el [runbook de staging](docs/deployment/staging-runbook.md) y la [configuración de Nginx](docs/deployment/nginx-staging.conf.example).
- **Fuera de horario:** Worker de Cloudflare que sirve una página de cierre sin necesidad de mantener el servidor encendido. Ver [docs/deployment/cloudflare](docs/deployment/cloudflare/README.md).

---

## Estructura del proyecto

```text
atlantia-finance/
├── app/
│   ├── Http/Controllers/   # Controladores por módulo (contabilidad, FEL, nómina, etc.)
│   ├── Models/             # Modelos Eloquent
│   ├── Services/           # Lógica de negocio (Accounting, Fel, Payroll, Treasury...)
│   ├── Jobs/ Events/ Mail/ Notifications/
│   └── Support/
├── database/
│   ├── migrations/         # Esquema de la base de datos
│   └── seeders/            # Catálogo de planes y datos de demostración
├── docs/                   # Arquitectura, seguridad, calidad y despliegue
├── resources/js/pages/     # Páginas React por panel y módulo
├── routes/                 # Rutas web, API y comandos de consola
├── services/atlantia-ai/   # Servicio local de IA (Luzma)
└── tests/                  # Pruebas Pest (Feature y Unit)
```

---

## Documentación adicional

| Documento | Contenido |
| --- | --- |
| [ADR-0001](docs/architecture/adr/0001-multi-tenant-purchases.md) | Decisión de aislamiento multiempresa |
| [Matriz de autorización](docs/security/authorization-matrix-purchases.md) | Permisos por rol en el módulo de compras |
| [Modelo de amenazas](docs/security/threat-model-purchases.md) | Análisis STRIDE y controles |
| [Matriz de trazabilidad](docs/quality/purchases-traceability-matrix.md) | Requisito → prueba → evidencia |
| [Aceptación de staging](docs/quality/staging-acceptance.md) | Evidencia de controles técnicos |
| [Runbook de staging](docs/deployment/staging-runbook.md) | Procedimiento de despliegue y respaldo |

---

<div align="center">

Desarrollado por **Atlantia Systems** · Guatemala

</div>
