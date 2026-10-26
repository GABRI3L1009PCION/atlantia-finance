"""Training data of Luzma's neural network: what people ask, grouped by the tool that answers it.

Each tool has a list of "cores" (the way people name the topic) that are combined with many sentence frames, so the
network sees the same topic written in many ways. `general` holds greetings, thanks and questions that no tool answers.
The data is deterministic: the same call always produces the same examples.
"""

import random

GENERAL = "general"

# tool -> the ways people name its topic
CORES: dict[str, list[str]] = {
    "get_my_priorities": ["mis prioridades", "lo más urgente", "mis pendientes de hoy", "qué debo hacer hoy", "resumen de mi día", "lo que tengo que atender", "mis asuntos importantes", "qué es lo primero", "por dónde empiezo hoy", "qué es lo más importante ahora", "mi resumen del día", "qué debo atender primero"],
    "get_my_tasks": ["mis tareas", "tareas asignadas a mí", "mi lista de tareas", "las tareas que tengo abiertas", "mis trabajos asignados", "tareas en proceso mías"],
    "get_task_readiness": ["si una tarea está lista para iniciar", "qué tareas ya puedo empezar", "preparación de la tarea", "tareas listas para trabajar", "la tarea está lista para arrancar"],
    "get_task_blockers": ["qué bloquea una tarea", "por qué está bloqueada la tarea", "impedimentos de mis tareas", "tareas detenidas", "qué le falta a la tarea para avanzar", "tareas esperando algo"],
    "get_review_observations": ["observaciones de la revisión", "comentarios del revisor", "correcciones que me pidieron", "observaciones pendientes de corregir", "qué me observaron en la revisión", "devoluciones del supervisor"],
    "get_due_tasks": ["tareas vencidas", "tareas que vencen pronto", "qué vence esta semana", "tareas atrasadas", "fechas límite de las tareas", "qué tareas están por vencer"],
    "get_close_checklist": ["el cierre del mes", "qué falta para cerrar el período", "lista de cierre contable", "cerrar el mes", "checklist del cierre", "pendientes del cierre mensual", "cierre del período contable", "qué falta para el cierre", "podemos cerrar el mes", "pendientes antes de cerrar", "bloqueos del cierre"],
    "get_company_blockers": ["bloqueos de la empresa", "qué impide avanzar a esta empresa", "incidencias de la empresa", "problemas de esta empresa", "qué tiene trabada a la empresa", "impedimentos de la empresa cliente"],
    "get_receivables_summary": ["cuentas por cobrar", "facturas por cobrar", "cartera de clientes", "cxc", "lo que me deben los clientes", "cobros pendientes", "clientes con saldo vencido", "promesas de pago", "quién me debe", "cartera vencida", "saldos de clientes", "cobranza", "cuánto me deben", "clientes morosos", "clientes que no han pagado sus facturas", "facturas de clientes pendientes de cobro", "quién no nos ha pagado", "clientes que todavía no pagan", "facturas emitidas que nadie ha pagado", "clientes con deuda con nosotros", "clientes que nos deben", "clientes que adeudan facturas", "saldo por cobrar de cada cliente", "facturas de venta sin cobrar", "qué clientes están atrasados en sus pagos", "cuánto nos adeudan los clientes", "cobros del mes"],
    "get_receivables_reconciliation": ["cxc contra el mayor", "por qué cuentas por cobrar no coincide con el mayor", "diferencia entre cartera y mayor", "cuadre de cuentas por cobrar", "cxc no cuadra", "conciliación de cuentas por cobrar", "la cartera no coincide con la contabilidad"],
    "get_payables_summary": ["cuentas por pagar", "facturas por pagar", "proveedores", "cxp", "lo que debemos a proveedores", "pagos pendientes", "obligaciones con proveedores", "qué vence primero a proveedores", "anticipos a proveedores", "cuánto debo pagar", "saldos de proveedores", "propuesta de pago", "qué le debemos a los proveedores", "nuestras deudas con terceros", "quiénes son nuestros acreedores", "lo que adeudamos", "proveedores sin pagar", "facturas de proveedores por saldar", "compras pendientes de pago", "deudas que tenemos que pagar", "facturas de compra pendientes", "pagos a proveedores de esta semana", "qué facturas de proveedores vencen", "qué toca pagar esta semana", "obligaciones de pago"],
    "get_payables_reconciliation": ["cxp contra el mayor", "por qué cuentas por pagar no coincide con el mayor", "diferencia entre proveedores y mayor", "cuadre de cuentas por pagar", "cxp no cuadra", "conciliación de cuentas por pagar", "los proveedores no coinciden con la contabilidad"],
    "get_payroll_summary": ["la nómina", "planilla de empleados", "sueldos y salarios", "novedades de la planilla", "igss y prestaciones", "aguinaldo y bono 14", "horas extra del personal", "pago a empleados", "errores de cálculo en la nómina", "períodos de nómina"],
    "get_payroll_reconciliation": ["nómina contra el mayor", "por qué la nómina no coincide con el mayor", "diferencia de sueldos por pagar", "cuadre de la planilla", "la planilla no cuadra con la contabilidad", "conciliación de la nómina"],
    "get_client_summary": ["mi portal de cliente", "qué me solicitó la oficina", "documentos que debo entregar", "qué me falta para completar el mes", "mis autorizaciones pendientes", "reportes publicados para mí", "qué debo a la oficina contable", "lo que me piden mis contadores"],
    "get_audit_summary": ["mis auditorías", "procedimientos de auditoría pendientes", "hallazgos abiertos", "evidencia vencida", "papeles de trabajo de auditoría", "correcciones por verificar", "muestras de auditoría"],
    "get_audit_sensitive_changes": ["cambios sensibles", "períodos reabiertos", "asientos revertidos", "cambios de permisos y roles", "reaperturas de períodos", "qué se modificó de forma delicada", "reversiones y ajustes sensibles"],
    "get_treasury_summary": ["bancos", "el saldo del banco", "tesorería", "conciliación bancaria", "movimientos sin identificar", "cuánto tengo en el banco", "pagos por aprobar", "saldo bancario contra el mayor", "cuentas bancarias", "movimientos del banco", "el dinero que tenemos en bancos", "saldo de las cuentas bancarias", "movimientos pendientes de conciliar", "diferencia entre el banco y el mayor"],
    "get_fiscal_status": ["el iva", "impuestos pendientes", "obligaciones fiscales", "declaraciones de la sat", "isr del período", "retenciones", "documentos fel con error", "cumplimiento fiscal", "vencimientos fiscales", "facturas electrónicas pendientes", "qué debo declarar", "estamos al corriente con los impuestos", "pendientes con la sat", "declaraciones atrasadas", "multas e infracciones fiscales", "cuándo vence la declaración del iva", "qué obligaciones fiscales tengo este mes", "estado de mis impuestos", "facturas fel rechazadas", "declaraciones pendientes de presentar"],
    "get_executive_overview": ["resumen general de la oficina", "cómo vamos como oficina", "empresas atrasadas", "riesgos operativos de la firma", "avance de todas las empresas", "visión ejecutiva", "estado general de la firma", "qué empresas tienen incidencias"],
    "get_workload": ["carga de trabajo del equipo", "quién está sobrecargado", "tareas por persona", "cómo está repartido el trabajo", "qué colaborador tiene más tareas", "disponibilidad del equipo", "equipo sobrecargado", "cómo está la carga del equipo", "quién tiene más trabajo", "personas sobrecargadas"],
    "get_pending_reviews": ["revisiones pendientes", "qué tengo por revisar", "asientos por aprobar", "bandeja de revisión", "documentos esperando mi aprobación", "elementos por revisar del equipo", "qué debo aprobar"],
    "get_account_balance": ["el saldo de una cuenta", "cuánto hay en la cuenta 1101", "balance de la cuenta de caja", "movimientos de una cuenta contable", "por qué cambió esta cuenta", "saldo de la cuenta de ingresos", "cuánto tiene la cuenta de gastos"],
    "search_records": ["buscar una factura", "encuentra el documento de un proveedor", "busca movimientos por monto", "localiza una factura de un cliente", "buscar un registro de Q 1,500", "dónde está el pago de un proveedor", "buscar una transacción por monto"],
    "get_audit_events": ["la bitácora de cambios", "qué pasó hoy en el sistema", "eventos de auditoría", "cambios importantes de hoy", "actividad reciente de los usuarios", "quién cambió algo", "historial de acciones"],
    "search_normative": ["qué dice la ley", "artículo del código de comercio", "decreto 10-2012", "libros contables obligatorios", "norma sobre facturación", "qué exige la ley del isr", "reglamento de la sat", "cómo se prepara el flujo de efectivo", "obligaciones legales de una empresa", "qué estados financieros exige la ley", "normativa vigente", "ley del iva", "qué dice el código de comercio", "artículo de la ley sobre la depreciación", "cuál es la ley que regula esto", "plazos que marca la ley", "qué dice la ley del iva sobre las facturas"],
    "get_fixed_assets_summary": ["mis activos fijos", "el valor en libros de los activos", "depreciación acumulada", "cuánto se deprecia este mes", "vehículos y maquinaria de la empresa", "bienes de la empresa", "equipo de cómputo y mobiliario", "amortización de intangibles", "cuánto vale el camión", "qué activos ya se depreciaron"],
    "get_financial_ratios": ["los indicadores financieros", "el margen de utilidad", "la liquidez de la empresa", "el endeudamiento", "rentabilidad sobre activos", "rentabilidad sobre el patrimonio", "la prueba ácida", "razones financieras del ejercicio", "qué tan rentable es la empresa", "cómo están los márgenes"],
    "get_isr_estimate": ["cuánto pagaré de isr este año", "la renta imponible", "el isr estimado del ejercicio", "los pagos trimestrales de isr", "impuesto sobre la renta del año", "cuánto va a ser el isr", "la utilidad contable contra la renta imponible", "estimación del isr"],
    "get_checks_summary": ["los cheques emitidos", "cheques pendientes de cobro", "la chequera", "cuántos cheques quedan", "el siguiente número de cheque", "cheques anulados", "qué cheques no se han cobrado", "cheques girados"],
    "get_withholdings_summary": ["las retenciones del año", "cuánto hemos retenido de isr", "el iva retenido a proveedores", "constancias de retención", "retenciones a proveedores", "total retenido en el período", "retenciones por mes"],
    "get_cost_centers_result": ["el resultado por centro de costo", "los gastos por área", "cuánto gasta cada centro de costo", "centros de costo de la empresa", "ingresos y gastos por proyecto", "qué centro de costo gasta más", "resultado por departamento"],
    "get_account_reconciliations": ["las conciliaciones de cuentas", "conciliaciones pendientes de revisión", "partidas conciliatorias", "qué cuentas faltan por conciliar", "la conciliación de cuentas de enlace", "conciliaciones en borrador", "diferencias en la conciliación de cuentas"],
    "get_sales_summary": ["las ventas del mes", "cuánto vendí", "cuánto vendimos este mes", "total de ventas", "lo que facturamos", "ventas de octubre", "ventas del año", "cuánto hemos facturado", "ingresos por ventas", "ventas por cliente", "cómo van las ventas", "las ventas contra el mes pasado"],
    "get_purchases_summary": ["las compras del mes", "cuánto compramos", "cuánto compré este mes", "total de compras", "compras a proveedores del mes", "compras de octubre", "lo que compramos este año", "compras por proveedor", "cómo van las compras"],
    "get_vat_summary": ["el iva del mes", "cuánto iva pago", "iva a pagar", "el débito y crédito fiscal", "el remanente de iva", "la liquidación del iva", "cuánto me toca pagar de iva", "iva de octubre", "el crédito fiscal del mes", "cuánto iva voy a pagar este mes"],
    "get_income_statement": ["el estado de resultados", "cuánto ganamos", "la utilidad del año", "estamos ganando o perdiendo", "las pérdidas y ganancias", "la utilidad del mes", "cuánto gané este año", "la ganancia neta", "el resultado del ejercicio"],
    "get_balance_sheet": ["el balance general", "el estado de situación financiera", "los activos y pasivos", "el patrimonio de la empresa", "el capital de trabajo", "cuánto valemos", "cuánto tenemos y cuánto debemos"],
    "get_cash_flow": ["el flujo de efectivo", "el flujo de caja", "las entradas y salidas de dinero", "en qué se fue el dinero", "de dónde salió el dinero", "el efectivo del año"],
    "get_trial_balance": ["la balanza de comprobación", "el balance de comprobación", "la balanza de sumas y saldos", "si cuadra la contabilidad", "la balanza del mes"],
    "get_inventory_summary": ["el inventario", "las existencias", "el stock de productos", "productos bajo el mínimo", "la mercadería en bodega", "productos sin movimiento", "cuánto vale el inventario"],
    "get_top_rankings": ["los principales clientes", "mis mejores clientes", "los mayores proveedores", "el top 10 de clientes", "en qué gastamos más", "los mayores gastos", "quién me compra más", "ranking de clientes y proveedores"],
    "get_fel_summary": ["las facturas electrónicas del mes", "las fel emitidas", "facturas fel anuladas", "fel con error", "los dte certificados", "cuántas facturas electrónicas emitimos"],
    "get_exchange_rate": ["el tipo de cambio", "a cuánto está el dólar", "el precio del dólar hoy", "el tipo de cambio del euro", "la tasa de cambio de banguat", "cuánto está el euro"],
    "get_profit_variance": ["por qué bajó la utilidad", "por qué ganamos menos este mes", "qué movió el resultado", "por qué perdimos dinero", "por qué subió la utilidad", "qué explica la caída de la ganancia", "por qué cayó el resultado"],
    "get_trends": ["la tendencia de ventas y gastos", "cómo hemos ido mes a mes", "la evolución de los últimos meses", "el histórico de ingresos", "la gráfica de resultados por mes", "ventas de cada mes"],
    "get_cash_cycle": ["los días de cobro", "cuánto tardan los clientes en pagar", "los días de pago a proveedores", "el ciclo de efectivo", "la rotación de cartera", "los días de inventario"],
    "get_break_even": ["el punto de equilibrio", "cuánto debo vender para no perder", "cuánto hay que vender al mes", "las ventas mínimas para cubrir gastos"],
    "get_cash_projection": ["la proyección de caja", "cuánto dinero tendré el próximo mes", "nos va a alcanzar el dinero", "el flujo proyectado a 90 días", "la liquidez de los próximos meses", "si me alcanza para pagar"],
    "get_tax_projection": ["los próximos impuestos", "cuánto voy a pagar de impuestos", "la proyección de impuestos", "el próximo pago a la sat", "qué impuestos vienen y cuánto"],
    "find_anomalies": ["algo raro en la contabilidad", "revisa si hay anomalías", "facturas duplicadas", "facturas repetidas de proveedores", "nit inválido de proveedores", "saltos en la numeración de facturas", "asientos sospechosos", "asientos en fin de semana", "gastos que se dispararon", "saldos negativos en caja", "pagos hechos dos veces", "anticipos sin aplicar", "errores en la nómina", "qué está mal en esta empresa", "auditoría automática de la empresa", "cosas fuera de lo normal"],
    "get_bank_match_suggestions": ["sugiere la conciliación bancaria", "a qué factura corresponde este depósito", "empareja los movimientos del banco", "coincidencias entre banco y facturas", "qué movimientos del banco coinciden con facturas"],
    "get_export_links": ["exporta el estado de resultados a pdf", "descargar la balanza en excel", "dame el balance en pdf", "exportar los reportes", "descarga el libro mayor", "el diario en excel"],
    "get_fiscal_calendar": ["el calendario fiscal", "qué debo declarar este mes", "qué impuestos tengo que presentar", "cuándo vence cada impuesto", "los vencimientos de impuestos del mes", "qué declaraciones vienen"],
    "compare_isr_regimes": ["qué régimen de isr me conviene", "comparar el régimen simplificado con utilidades", "cuánto pagaría en el régimen opcional simplificado", "conviene cambiar de régimen", "isr sobre utilidades contra simplificado"],
    "get_iso_estimate": ["el impuesto de solidaridad", "cuánto pago de iso", "el iso trimestral", "la base del iso", "cálculo del iso"],
    "get_small_taxpayer_tax": ["el impuesto de pequeño contribuyente", "cuánto pagaría como pequeño contribuyente", "el 5 por ciento sobre ventas", "la cuota de pequeño contribuyente"],
    "get_companies_at_risk": ["las empresas en riesgo", "qué empresas requieren atención", "clientes con más problemas", "cuáles empresas están peor", "empresas con impuestos vencidos", "prioridad entre mis empresas"],
    "get_client_profitability": ["la rentabilidad por cliente", "cuánto me deja cada cliente", "clientes que no son rentables", "el margen de cada cliente de la firma", "qué cliente me cuesta más de lo que paga", "los honorarios contra las horas"],
    "get_team_productivity": ["la productividad del equipo", "quién ha terminado más tareas", "el rendimiento de los contadores", "cuántas tareas terminó cada persona", "el desempeño del equipo este mes"],
    "get_client_vat": ["cuánto iva voy a pagar", "cuánto pagué de iva", "mi iva del mes", "el iva de mi empresa"],
    "get_client_missing_documents": ["qué documentos me faltan entregar", "qué me falta enviar a la oficina", "documentos pendientes de entregar", "qué me pidió el contador"],
    "get_client_statements": ["explícame mis estados financieros", "cómo le fue a mi empresa", "qué dice mi reporte", "mi estado de resultados publicado", "cuánto ganó mi empresa"],
    GENERAL: ["hola", "buenos días", "buenas tardes", "buenas noches", "gracias", "muchas gracias", "qué puedes hacer", "quién eres", "para qué sirves", "cómo me puedes ayudar", "ayuda", "adiós", "hasta luego", "cuéntame un chiste", "qué hora es", "cómo estás", "el clima de hoy", "la receta de un pastel", "quién ganó el partido", "asdf qwerty", "prueba prueba"],
}

# sentence frames. {c} is the topic; the frames are intentionally varied, informal and sometimes incomplete.
FRAMES = [
    "{c}", "¿{c}?", "dame {c}", "muéstrame {c}", "quiero ver {c}", "necesito revisar {c}", "revisa {c}", "¿cómo va {c}?", "¿cómo estamos con {c}?",
    "resumen de {c}", "estado de {c}", "¿qué hay de {c}?", "¿qué pasa con {c}?", "dime sobre {c}", "consulta {c}", "ayúdame con {c}", "por favor {c}",
    "¿puedes mostrarme {c}?", "información de {c}", "{c} de este mes", "{c} de la empresa", "{c} hoy", "quisiera saber de {c}", "oye, {c}",
    "¿tengo algo pendiente en {c}?", "¿cuál es la situación de {c}?", "detalle de {c}", "un vistazo a {c}", "{c} por favor", "me interesa {c}",
]

TEST_FRAMES = [
    "¿cuál es el estado de {c} en octubre?", "necesito un análisis de {c}", "a ver, {c}", "me puedes decir algo de {c}", "{c}, ¿qué hay?",
    "¿qué novedades hay sobre {c}?", "reporte rápido de {c}", "¿cómo andamos con {c} esta semana?",
]


def _typo(text: str, rng: random.Random) -> str:
    """Drops accents or one letter, the kind of mistakes people make when they type fast."""
    swaps = [("á", "a"), ("é", "e"), ("í", "i"), ("ó", "o"), ("ú", "u"), ("ñ", "n")]
    roll = rng.random()
    if roll < 0.4:
        for old, new in swaps:
            text = text.replace(old, new)
    elif roll < 0.6 and len(text) > 8:
        position = rng.randrange(2, len(text) - 2)
        if text[position].isalpha():
            text = text[:position] + text[position + 1 :]
    return text


def training_examples(seed: int = 7, per_core: int = 14) -> list[tuple[str, str]]:
    """(text, label) pairs: every topic is written with several frames, mistakes and dropped words."""
    rng = random.Random(seed)
    examples: list[tuple[str, str]] = []
    for label, cores in CORES.items():
        for core in cores:
            for frame in rng.sample(FRAMES, min(per_core, len(FRAMES))):
                text = frame.format(c=core)
                examples.append((text, label))
                if rng.random() < 0.4:
                    examples.append((_typo(text, rng), label))
                if rng.random() < 0.35:
                    words = text.split()
                    if len(words) > 3:
                        del words[rng.randrange(len(words))]
                        examples.append((" ".join(words), label))
    rng.shuffle(examples)

    return examples


# Phrasings written by hand, unlike any frame above: the honest measure of understanding.
HAND_TESTS: dict[str, str] = {
    "¿Cuánto nos deben los clientes este mes?": "get_receivables_summary",
    "¿Qué clientes tienen facturas sin pagar?": "get_receivables_summary",
    "¿Tengo facturas de proveedores sin pagar?": "get_payables_summary",
    "¿A quién le debemos dinero?": "get_payables_summary",
    "¿Cuándo hay que presentar el IVA?": "get_fiscal_status",
    "¿Estamos al día con la SAT?": "get_fiscal_status",
    "¿Cuánto dinero hay en el banco ahora?": "get_treasury_summary",
    "¿Qué movimientos del banco no están conciliados?": "get_treasury_summary",
    "¿Qué falta para cerrar septiembre?": "get_close_checklist",
    "¿Qué dice la ley sobre la depreciación?": "search_normative",
    "¿Qué artículo habla de los libros contables?": "search_normative",
    "¿Quién del equipo está saturado de trabajo?": "get_workload",
    "¿Ya puedo aprobar los asientos del equipo?": "get_pending_reviews",
    "¿Cómo va la planilla de octubre?": "get_payroll_summary",
    "¿Qué tareas se me vencieron?": "get_due_tasks",
    "¿Qué es lo más urgente que tengo hoy?": "get_my_priorities",
    "¿Qué cambios importantes hubo en el sistema hoy?": "get_audit_events",
    "¿Cuánto vendimos en septiembre?": "get_sales_summary",
    "¿Cuánto IVA me toca pagar este mes?": "get_vat_summary",
    "¿Estamos ganando dinero este año?": "get_income_statement",
    "¿A cuánto está el dólar hoy?": "get_exchange_rate",
    "¿Por qué bajó la utilidad este mes?": "get_profit_variance",
    "¿Nos alcanza el dinero para los próximos meses?": "get_cash_projection",
    "¿Hay algo raro en la contabilidad de esta empresa?": "find_anomalies",
    "¿Qué impuestos tengo que presentar este mes?": "get_fiscal_calendar",
    "¿Qué empresas necesitan atención primero?": "get_companies_at_risk",
    "Gracias por la ayuda": "general",
    "buenas, ¿cómo estás?": "general",
    "cuéntame un chiste": "general",
}


def test_examples(seed: int = 11) -> list[tuple[str, str]]:
    """Held-out phrasings the network never saw while training: they measure real understanding."""
    rng = random.Random(seed)
    examples = []
    for label, cores in CORES.items():
        for core in cores:
            examples.append((rng.choice(TEST_FRAMES).format(c=core), label))

    return examples
