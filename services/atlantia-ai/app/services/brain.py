"""Luzma's own brain: understands the question and writes the answer without any external model.

Everything here is plain Python (standard library only), so it runs on any server at no cost and never depends on an API
key or on Ollama. It does two jobs the language model used to do:

* plan: pick which authorized tool answers the question (TF-IDF over the tool descriptions plus Spanish synonyms).
* answer: write the Resumen / Qué encontré / Por qué importa / Siguiente paso text from the official facts that Laravel
  already computed. The brain never invents a figure: every number in its answer is copied from those facts.
"""

import math
import re
import unicodedata
from collections import Counter

ENGINE_NAME = "luzma-brain"

STOPWORDS = {
    "a", "al", "algo", "ante", "con", "como", "cual", "cuales", "cuanto", "cuanta", "cuantos", "cuantas", "de", "del", "el", "ella",
    "en", "es", "esta", "estan", "este", "esto", "hay", "la", "las", "le", "lo", "los", "me", "mi", "mis", "muy", "no", "nos",
    "para", "pero", "por", "que", "se", "si", "sin", "son", "su", "sus", "te", "tengo", "tiene", "tienen", "tu", "tus", "un",
    "una", "uno", "unos", "unas", "y", "ya", "ver", "dame", "dime", "quiero", "puedes", "favor", "hoy", "esta", "estoy",
}

# Spanish synonyms per tool: what people actually type. Tools that are not listed are matched by their description.
ALIASES: dict[str, str] = {
    "get_my_priorities": "prioridades pendientes por hacer tareas mias que debo hacer hoy urgente resumen de mi dia mis pendientes",
    "get_receivables_summary": "cuentas por cobrar facturas por cobrar cartera clientes que me deben cxc cobros pendientes vencido cobrar promesas de pago seguimiento de clientes",
    "get_receivables_reconciliation": "cxc mayor cartera cuadra cobrar no coincide diferencia cuentas por cobrar contra mayor",
    "get_payables_summary": "cuentas por pagar facturas por pagar proveedores cxp obligaciones vencen pagos pendientes anticipos propuesta de pago",
    "get_payables_reconciliation": "cxp mayor pagar cuadra no coincide diferencia cuentas por pagar contra mayor",
    "get_payroll_summary": "nomina planilla empleados sueldos salarios igss aguinaldo bono 14 novedades horas extra prestaciones",
    "get_payroll_reconciliation": "nomina mayor planilla cuadra sueldos por pagar no coincide",
    "get_treasury_summary": "bancos tesoreria saldo bancario conciliacion bancaria movimientos sin identificar cuentas bancarias pagos por aprobar",
    "get_fiscal_status": "impuestos iva isr sat declaraciones obligaciones fiscales vencimientos fiscales fel retenciones cumplimiento fiscal",
    "get_executive_overview": "resumen general oficina empresas atrasadas como vamos riesgos avance de la oficina vision ejecutiva",
    "get_workload": "carga de trabajo equipo sobrecargados quien tiene tareas personal",
    "get_close_checklist": "cierre cerrar periodo que falta para cerrar checklist de cierre mes cierre contable",
    "get_company_blockers": "bloqueos impedimentos que bloquea la empresa incidencias de la empresa",
    "get_account_balance": "saldo de cuenta cuanto hay en la cuenta balance de cuenta mayor de la cuenta",
    "get_pending_reviews": "revisiones pendientes por revisar aprobar asientos por aprobar bandeja de revision",
    "search_normative": "ley articulo norma decreto normativa reglamento codigo de comercio biblioteca normativa que dice la ley obligatorio libros contables",
    "search_records": "buscar documento factura registro encuentra movimiento por monto",
    "get_fixed_assets_summary": "activos fijos depreciacion valor en libros amortizacion vehiculos maquinaria bienes de la empresa cargo de depreciacion",
    "get_financial_ratios": "indicadores financieros margen liquidez endeudamiento rentabilidad roa roe prueba acida razones financieras",
    "get_isr_estimate": "isr estimado impuesto sobre la renta renta imponible pago trimestral cuanto pagare de isr utilidad contable",
    "get_checks_summary": "cheques emitidos chequera cheques pendientes de cobro cheques anulados siguiente numero de cheque",
    "get_withholdings_summary": "retenciones retenido retencion de isr retencion de iva constancia de retencion proveedores",
    "get_cost_centers_result": "centro de costo centros de costo resultado por centro gastos por area proyecto departamento",
    "get_account_reconciliations": "conciliaciones de cuentas conciliacion de cuentas partidas conciliatorias cuenta de enlace pendientes de revision",
    "get_audit_summary": "auditoria procedimientos hallazgos evidencia papel de trabajo muestra correccion",
    "get_audit_events": "bitacora cambios importantes eventos de auditoria que paso hoy actividad",
    "get_audit_sensitive_changes": "cambios sensibles reaperturas reversiones permisos modificados",
    "get_sales_summary": "ventas vendi vendimos facturamos facturado total de ventas ingresos por ventas ventas del mes ventas por cliente",
    "get_purchases_summary": "compras compramos compre total de compras compras del mes compras por proveedor gastos de compras",
    "get_vat_summary": "iva del mes iva a pagar debito fiscal credito fiscal remanente liquidacion del iva cuanto iva pago",
    "get_income_statement": "estado de resultados utilidad perdida ganancia ganamos resultado del ejercicio perdidas y ganancias",
    "get_balance_sheet": "balance general estado de situacion financiera activos pasivos patrimonio capital de trabajo",
    "get_cash_flow": "flujo de efectivo flujo de caja entradas y salidas de dinero",
    "get_trial_balance": "balanza de comprobacion balance de comprobacion sumas y saldos cuadra la contabilidad",
    "get_inventory_summary": "inventario existencias stock productos bajo el minimo bodega mercaderia sin movimiento",
    "get_top_rankings": "principales mejores clientes mayores proveedores top ranking mayores gastos quien compra mas",
    "get_fel_summary": "facturas electronicas fel emitidas anuladas certificadas dte con error",
    "get_exchange_rate": "tipo de cambio dolar euro banguat tasa de cambio a cuanto esta",
    "get_profit_variance": "por que bajo la utilidad por que gane menos que movio el resultado variacion del resultado por que perdimos",
    "get_trends": "tendencia mes a mes evolucion historico ultimos meses grafica",
    "get_cash_cycle": "dias de cobro dias de pago ciclo de efectivo rotacion dias de inventario cuanto tardan en pagar",
    "get_break_even": "punto de equilibrio cuanto debo vender para no perder ventas minimas",
    "get_cash_projection": "proyeccion de caja proyeccion de efectivo flujo proyectado alcanza el dinero proximos meses liquidez futura",
    "get_tax_projection": "proximos impuestos proyeccion de impuestos cuanto voy a pagar de impuestos proximo pago sat",
    "find_anomalies": "anomalias algo raro duplicadas repetidas nit invalido salto numeracion correlativo asientos sospechosos fin de semana monto redondo gasto disparado saldo negativo pago doble anticipo sin aplicar errores",
    "get_bank_match_suggestions": "sugerir conciliacion emparejar movimientos del banco coincidencias deposito corresponde factura",
    "get_export_links": "exportar descargar pdf excel reporte balanza balance estado de resultados libro mayor diario",
    "get_fiscal_calendar": "calendario fiscal que debo declarar vencimientos de impuestos declaraciones que vienen que presentar",
    "compare_isr_regimes": "regimen isr simplificado utilidades conviene cambiar de regimen comparar opcional",
    "get_iso_estimate": "iso impuesto de solidaridad trimestral base del iso",
    "get_small_taxpayer_tax": "pequeno contribuyente cinco por ciento cuota sobre ventas",
    "get_companies_at_risk": "empresas en riesgo requieren atencion problemas peor prioridad impuestos vencidos",
    "get_client_profitability": "rentabilidad por cliente cuanto deja cada cliente margen honorarios horas no rentable",
    "get_team_productivity": "productividad equipo rendimiento tareas terminadas desempeno contadores",
    "get_client_vat": "cuanto iva voy a pagar mi iva pague de iva",
    "get_client_missing_documents": "documentos me faltan entregar enviar oficina pidio contador",
    "get_client_statements": "mis estados financieros como le fue a mi empresa mi reporte cuanto gano",
    "get_client_summary": "portal del cliente que me solicitaron documentos entregados autorizaciones reportes publicados que falta del mes",
}

GREETING = re.compile(r"^(hola|buenas|buenos dias|buen dia|buenas tardes|buenas noches|hey|gracias|muchas gracias)\b|\bque (puedes|sabes) hacer\b|\bpara que sirves\b|\bquien eres\b")


def normalize(text: str) -> str:
    """Lower case, no accents, single spaces: 'Cuántas FACTURAS?' -> 'cuantas facturas'."""
    text = unicodedata.normalize("NFD", text.lower())
    text = "".join(char for char in text if unicodedata.category(char) != "Mn")
    return re.sub(r"\s+", " ", re.sub(r"[^a-z0-9\s]", " ", text)).strip()


_SUFFIXES = ("amientos", "imientos", "amiento", "imiento", "aciones", "iciones", "acion", "icion", "adores", "adora", "ador", "mente",
             "ables", "able", "ibles", "ible", "idades", "idad", "ando", "iendo", "aron", "ieron", "ados", "adas", "idos", "idas",
             "ado", "ada", "ido", "ida", "ores", "or", "es", "as", "os", "ar", "er", "ir", "a", "o", "s")


def stem(word: str) -> str:
    """Very light Spanish stemmer, enough to match 'facturas' with 'factura' and 'vencidas' with 'vencen'."""
    for suffix in _SUFFIXES:
        if word.endswith(suffix) and len(word) - len(suffix) >= 4:
            return word[: -len(suffix)]
    return word


def fold(text: str) -> str:
    """Lower case without accents, keeping punctuation and digits so amounts such as 'Q 4,500.00' stay readable."""
    return "".join(char for char in unicodedata.normalize("NFD", text.lower()) if unicodedata.category(char) != "Mn")


def tokens(text: str) -> list[str]:
    return [stem(word) for word in normalize(text).split() if word not in STOPWORDS and len(word) > 1]


class Brain:
    """Stateless: every call receives what it needs (the authorized tools, the facts)."""

    # ------------------------------------------------------------------ plan

    def plan(self, question: str, tools: list[dict]) -> dict:
        """The neural network decides first; the word-matching scorer below is the safety net when it is unsure or absent."""
        from app.services.neural_runtime import runtime

        if runtime.ready and tools:
            allowed = {tool["name"] for tool in tools}
            outcome = runtime.net.inspect(question, allowed)
            runtime.record(outcome, "plan")
            probs = runtime.net.predict(question, allowed)
            name, probability = max(probs.items(), key=lambda item: item[1])
            if name == "general":
                if probability >= 0.6:
                    return {"intent": "general", "tool": "", "arguments": {}, "confidence": round(probability, 2), "engine": "neural"}
            elif probability >= 0.55:
                return {"intent": "read", "tool": name, "arguments": self.arguments(name, question, tools), "confidence": round(probability, 2), "engine": "neural"}

        return self._plan_by_words(question, tools)

    @staticmethod
    def arguments(name: str, question: str, tools: list[dict]) -> dict:
        """Values the question carries for the tool's parameters: a free text, an amount or an account code."""
        wanted = next((tool.get("parameters", {}) for tool in tools if tool["name"] == name), {})
        found: dict = {}
        if "text" in wanted:
            found["text"] = question
        if "account" in wanted:
            code = re.search(r"(?<!\d)(\d{3,8})(?!\d)", question)
            found["account"] = code.group(1) if code else ""
        if "amount" in wanted:
            amount = re.search(r"q?\s?(\d[\d,]*(?:\.\d+)?)\s*(mil)?", question.lower())
            if amount:
                found["amount"] = float(amount.group(1).replace(",", "")) * (1000 if amount.group(2) else 1)

        return found

    def _plan_by_words(self, question: str, tools: list[dict]) -> dict:
        normalized = normalize(question)
        if not tools or not normalized or GREETING.search(normalized):
            return {"intent": "general", "tool": "", "arguments": {}, "confidence": 0.0}

        documents = {tool["name"]: tokens(f"{tool['name'].replace('_', ' ')} {tool.get('description', '')} {ALIASES.get(tool['name'], '')}") for tool in tools}
        frequency = Counter(term for terms in documents.values() for term in set(terms))
        total = len(documents)
        idf = {term: math.log((1 + total) / (1 + count)) + 1 for term, count in frequency.items()}

        query = set(tokens(question))
        known = {term for term in query if term in idf}
        if not known:
            return {"intent": "general", "tool": "", "arguments": {}, "confidence": 0.0}
        weight = sum(idf[term] for term in known)
        scores: dict[str, float] = {}
        for name, terms in documents.items():
            # Query coverage: how much of what was asked (weighted by how rare each word is) this tool talks about.
            coverage = sum(idf[term] for term in known if term in set(terms)) / weight
            phrases = sum(1 for phrase in self._phrases(name) if phrase in normalized)
            scores[name] = coverage + min(0.3, 0.15 * phrases)

        ranked = sorted(scores.items(), key=lambda item: item[1], reverse=True)
        best_name, best = ranked[0]
        if best <= 0:
            return {"intent": "general", "tool": "", "arguments": {}, "confidence": 0.0}
        confidence = min(0.95, 0.1 + best * 0.85)
        if len(ranked) > 1 and ranked[1][1] >= best * 0.9:
            confidence -= 0.2
        confidence = max(0.0, round(confidence, 2))
        if confidence < 0.5:
            return {"intent": "general", "tool": "", "arguments": {}, "confidence": confidence}

        return {"intent": "read", "tool": best_name, "arguments": {}, "confidence": confidence}

    @staticmethod
    def _phrases(tool_name: str) -> list[str]:
        """Multi-word expressions of the aliases ('cuentas por cobrar'), which weigh more than loose words."""
        words = ALIASES.get(tool_name, "").split()
        phrases = []
        for size in (3, 2):
            phrases += [" ".join(words[i : i + size]) for i in range(len(words) - size + 1)]
        return phrases

    # ----------------------------------------------------------- verification

    @staticmethod
    def unsupported_figures(answer: str, source: str) -> list[str]:
        """Numbers a written answer contains that the official data does not (small counts and years are allowed).

        A language model that rewrites the answer may be nicer to read, but it must not invent a figure: any answer with a
        number that is not in the facts is thrown away and the brain's own text, built from those facts, is used instead.
        """
        def numbers(text: str) -> dict[float, str]:
            found: dict[float, str] = {}
            for raw in re.findall(r"\d[\d,]*(?:\.\d+)?", text):
                clean = raw.replace(",", "").rstrip(".")
                found[float(clean)] = clean

            return found

        allowed = numbers(source)
        extras = []
        for value, label in numbers(answer).items():
            if value in allowed or (value < 10 and value == int(value)) or 1990 <= value <= 2100:
                continue
            extras.append(label)

        return sorted(extras)

    # ---------------------------------------------------------------- answer

    LABELS = {
        "get_receivables_summary": "cuentas por cobrar", "get_receivables_reconciliation": "la conciliación de CxC con el Mayor",
        "get_payables_summary": "cuentas por pagar", "get_payables_reconciliation": "la conciliación de CxP con el Mayor",
        "get_payroll_summary": "la nómina", "get_payroll_reconciliation": "la conciliación de la nómina", "get_treasury_summary": "bancos y tesorería",
        "get_fiscal_status": "cumplimiento fiscal", "get_my_priorities": "tus prioridades", "get_executive_overview": "la visión ejecutiva",
        "get_workload": "la carga de trabajo", "get_close_checklist": "el cierre del período", "get_pending_reviews": "las revisiones pendientes",
        "search_normative": "la biblioteca normativa", "get_client_summary": "tu portal", "get_audit_summary": "los trabajos de auditoría",
        "get_sales_summary": "las ventas", "get_purchases_summary": "las compras", "get_vat_summary": "el IVA del mes", "get_income_statement": "el estado de resultados",
        "get_balance_sheet": "el balance general", "get_cash_flow": "el flujo de efectivo", "get_trial_balance": "la balanza de comprobación", "get_inventory_summary": "el inventario",
        "get_top_rankings": "los principales clientes, proveedores y gastos", "get_fel_summary": "las facturas electrónicas", "get_exchange_rate": "el tipo de cambio",
        "get_profit_variance": "lo que movió el resultado", "get_trends": "la tendencia mensual", "get_cash_cycle": "los días de cobro y pago", "get_break_even": "el punto de equilibrio",
        "get_cash_projection": "la proyección de efectivo", "get_tax_projection": "los próximos impuestos",
        "find_anomalies": "la revisión automática", "get_bank_match_suggestions": "las coincidencias bancarias", "get_export_links": "los reportes para descargar",
        "get_fiscal_calendar": "el calendario fiscal", "compare_isr_regimes": "la comparación de regímenes de ISR", "get_iso_estimate": "el ISO", "get_small_taxpayer_tax": "el impuesto de pequeño contribuyente",
        "get_companies_at_risk": "las empresas en riesgo", "get_client_profitability": "la rentabilidad por cliente", "get_team_productivity": "la productividad del equipo",
        "get_client_vat": "tu IVA", "get_client_missing_documents": "tus documentos pendientes", "get_client_statements": "tus estados financieros",
    }

    NEXT_STEP = {
        "get_receivables_summary": "Revisa la cartera de clientes y, si hay saldos vencidos, prepara el seguimiento de cobro.",
        "get_receivables_reconciliation": "Revisa las partidas que explican la diferencia entre CxC y el Mayor antes de cerrar el período.",
        "get_payables_summary": "Revisa qué obligaciones vencen primero y, si quieres, pídeme preparar una propuesta de pago.",
        "get_payables_reconciliation": "Revisa las partidas que explican la diferencia entre CxP y el Mayor antes de cerrar el período.",
        "get_payroll_summary": "Revisa las novedades y errores de cálculo pendientes antes de aprobar la nómina.",
        "get_payroll_reconciliation": "Revisa la diferencia entre la nómina y la cuenta de sueldos por pagar.",
        "get_treasury_summary": "Identifica los movimientos bancarios pendientes y concilia la cuenta con el Mayor.",
        "get_fiscal_status": "Atiende primero las obligaciones vencidas o próximas a vencer y los documentos FEL con error.",
        "get_my_priorities": "Empieza por lo vencido; puedo ayudarte a preparar una tarea o un recordatorio.",
        "get_executive_overview": "Entra a las empresas con incidencias y revisa los bloqueos de cada una.",
        "get_workload": "Redistribuye las tareas de quienes tienen sobrecarga si hay vencidas.",
        "get_close_checklist": "Completa los puntos pendientes del cierre; el cierre lo ejecuta una persona autorizada.",
        "get_pending_reviews": "Revisa los elementos en espera; la aprobación la decide el responsable.",
        "search_normative": "Lee el artículo citado completo y confirma su vigencia; la decisión final es del profesional a cargo.",
        "get_fixed_assets_summary": "Genera la depreciación del mes y revisa los activos que ya terminaron su vida útil.",
        "get_financial_ratios": "Compara los márgenes con el período anterior y revisa qué rubro cambió más.",
        "get_isr_estimate": "Revisa la naturaleza fiscal de las cuentas y los ajustes antes de la declaración; la presenta la persona autorizada.",
        "get_checks_summary": "Da seguimiento a los cheques sin cobrar y confirma el siguiente número antes de emitir.",
        "get_withholdings_summary": "Cruza las retenciones con las constancias entregadas a los proveedores.",
        "get_cost_centers_result": "Revisa los centros con mayor gasto y los movimientos sin centro asignado.",
        "get_account_reconciliations": "Cierra las conciliaciones en borrador y pide la revisión de otra persona para las pendientes.",
        "get_client_summary": "Atiende primero lo que la oficina te solicitó para completar el mes.",
        "find_anomalies": "Revisa primero los puntos de prioridad alta; son señales, no errores confirmados.",
        "get_bank_match_suggestions": "Confirma la referencia de cada movimiento antes de aplicarlo en la conciliación.",
        "get_export_links": "Descarga el archivo desde el enlace.",
        "get_fiscal_calendar": "Prepara primero lo que vence antes; la declaración la presenta la persona autorizada.",
        "compare_isr_regimes": "Evalúa el cambio con la proyección completa del año; se solicita a la SAT.",
        "get_iso_estimate": "Verifica la base del ISO con el balance del ejercicio anterior.",
        "get_small_taxpayer_tax": "Confirma el régimen de IVA de la empresa antes de declarar.",
        "get_companies_at_risk": "Atiende primero las empresas con impuestos vencidos.",
        "get_client_profitability": "Revisa los clientes con margen negativo: ajusta honorarios o alcance.",
        "get_team_productivity": "Reparte trabajo de quien tiene vencidas a quien tiene capacidad.",
        "get_client_vat": "Consulta a tu oficina si tienes dudas sobre el monto.",
        "get_client_missing_documents": "Entrega los documentos desde el portal antes de la fecha límite.",
        "get_client_statements": "Descarga el reporte completo desde tu portal.",
        "get_sales_summary": "Revisa a los clientes que más pesan y compara con el período anterior.",
        "get_purchases_summary": "Revisa los proveedores principales y las compras que siguen en borrador.",
        "get_vat_summary": "Revisa que todas las ventas y compras del mes estén registradas antes de cerrar el libro y declarar.",
        "get_income_statement": "Revisa los gastos que más pesan; puedo explicarte qué movió el resultado contra el período anterior.",
        "get_balance_sheet": "Si hay diferencia, revisa los asientos sin cuadrar; si no, vigila el capital de trabajo.",
        "get_cash_flow": "Si la operación consume efectivo, revisa cobros y plazos de pago.",
        "get_trial_balance": "Contabiliza los asientos pendientes y corrige cualquier diferencia antes del cierre.",
        "get_inventory_summary": "Repón los productos bajo el mínimo y revisa los que no se mueven.",
        "get_top_rankings": "Cuida la concentración: si pocos clientes pesan mucho, el riesgo es mayor.",
        "get_fel_summary": "Corrige los documentos con error y registra en la SAT los pendientes.",
        "get_exchange_rate": "Usa el tipo de cambio del día del documento para registrar en moneda extranjera.",
        "get_profit_variance": "Revisa las cuentas que más movieron el resultado y confirma si fue algo puntual.",
        "get_trends": "Busca los meses fuera de lo normal y revisa qué pasó en ellos.",
        "get_cash_cycle": "Si cobras más lento de lo que pagas, negocia plazos o refuerza la cobranza.",
        "get_break_even": "Compara las ventas de cada mes con el punto de equilibrio.",
        "get_cash_projection": "Si el saldo proyectado baja mucho, adelanta cobros o reprograma pagos.",
        "get_tax_projection": "Prepara las declaraciones con anticipación; la presenta la persona autorizada.",
    }

    def answer(self, system: str, messages: list[dict]) -> str:
        data = ""
        question = ""
        for message in reversed(messages):
            if message["role"] == "user":
                data, _, question = message["content"].partition("\n\nPregunta del usuario:")
                question = question.strip()
                break
        normalized = normalize(question)

        if "LA CONSULTA FALLÓ" in data:
            reason = data.split("LA CONSULTA FALLÓ:", 1)[1].split("\n", 1)[0].strip()
            return ("**Resumen:** No pude obtener la información de esta consulta.\n\n"
                    f"**Qué encontré:** {reason or 'La consulta falló.'}\n\n"
                    "**Siguiente paso:** Intenta de nuevo en unos minutos; no tomes esta falla como una respuesta positiva.")

        facts, tool = self._facts(data)
        if not facts:
            return self._general(normalized)

        return self._compose(facts, tool, data)

    @staticmethod
    def _facts(data: str) -> tuple[list[str], str]:
        match = re.search(r"DATOS OFICIALES \(([^)]*)\):\n(.*?)(?:\nDetalle estructurado:|$)", data, re.DOTALL)
        if not match:
            return [], ""
        lines = [line[2:].strip() for line in match.group(2).splitlines() if line.startswith("- ")]
        return [line for line in lines if line], match.group(1)

    def _compose(self, facts: list[str], tool: str, data: str) -> str:
        scope = data.split("\n", 1)[0].removeprefix("Contexto:").strip().rstrip(".")
        flags = self._signals(facts)
        shown = facts[:8]
        extra = len(facts) - len(shown)

        label = self.LABELS.get(tool, "los datos solicitados")
        verdict = "hay puntos que conviene revisar" if flags else "no veo señales de riesgo por ahora"
        summary = f"Consulté los datos oficiales de {label}: {verdict}."
        findings = "\n".join(f"- {fact}" for fact in shown) + (f"\n- Hay {extra} dato(s) más en la pantalla." if extra > 0 else "")

        why = []
        if "overdue" in flags:
            why.append("Hay montos vencidos: afectan la liquidez y pueden generar mora o recargos.")
        if "difference" in flags:
            why.append("Existe una **DIFERENCIA** con el Mayor: conviene revisarla antes de cerrar el período.")
        if "broken" in flags:
            why.append("Hay promesas de pago incumplidas que requieren seguimiento.")
        if "pending" in flags and not why:
            why.append("Hay elementos pendientes que conviene atender para no atrasar el cierre.")
        if not why:
            why.append("Con estos datos no veo señales de riesgo por ahora; es información de seguimiento.")

        next_step = self.NEXT_STEP.get(tool, "Revisa el detalle en la pantalla correspondiente.")

        return (f"**Resumen:** {summary}\n\n**Qué encontré:**\n{findings}\n\n**Por qué importa:** {' '.join(why)}\n\n"
                f"**Siguiente paso:** {next_step}" + (f"\n\nContexto: {scope}." if scope else ""))

    @staticmethod
    def _signals(facts: list[str]) -> set[str]:
        """Risk flags found in the official facts. Only a non-zero amount or count next to the keyword raises them."""
        keywords = (("vencid", "overdue"), ("diferencia", "difference"), ("incumplid", "broken"), ("sin aplicar", "pending"), ("pendient", "pending"))
        number = r"(\d[\d,]*(?:\.\d+)?)"
        flags: set[str] = set()

        def positive(value: str) -> bool:
            return float(value.replace(",", "")) > 0

        for fact in facts:
            text = fold(fact)
            if re.search(r"no coincide|con diferencia", text):
                flags.add("difference")
            for keyword, flag in keywords:
                # "vencido Q 4,500.00" (amount after) or "3 cliente(s) vencido(s)" (count before).
                after = re.findall(keyword + r"\w*(?:\(s\))?[^\d.;]{0,6}" + number, text)
                before = re.findall(number + r"\s+(?:[^\s.;,]+\s+){0,2}" + keyword, text)
                if any(positive(value) for value in after + before):
                    flags.add(flag)

        return flags

    @staticmethod
    def _general(normalized: str) -> str:
        if GREETING.search(normalized):
            return ("¡Hola! Soy Luzma, el asistente de Atlantia Finance. Consulto los datos oficiales del sistema: ventas, compras, IVA, estados financieros, "
                    "cuentas por cobrar y por pagar, bancos, nómina, inventario, impuestos y la biblioteca normativa. Comparo períodos, explico qué cambió, "
                    "detecto cosas raras y proyecto caja e impuestos. También preparo asientos, facturas, cobros, solicitudes, tareas y recordatorios que tú confirmas. "
                    "¿Qué necesitas revisar?")
        return ("**Resumen:** No tengo datos oficiales para responder eso con precisión.\n\n"
                "**Siguiente paso:** Pregúntame por tus prioridades, cuentas por cobrar o por pagar, bancos, obligaciones fiscales, nómina, cierres o "
                "la normativa, y te muestro las cifras reales del sistema.")


brain = Brain()
