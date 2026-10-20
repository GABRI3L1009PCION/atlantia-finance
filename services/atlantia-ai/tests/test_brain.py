import unittest

from app.services.brain import brain

TOOLS = [
    {"name": "get_my_priorities", "description": "Prioridades y tareas pendientes del usuario"},
    {"name": "get_receivables_summary", "description": "Cartera de cuentas por cobrar: vencido, clientes a dar seguimiento, promesas y cobros sin aplicar"},
    {"name": "get_payables_summary", "description": "Obligaciones con proveedores: vencimientos, propuestas, anticipos y pagos sin aplicar"},
    {"name": "get_fiscal_status", "description": "Obligaciones fiscales pendientes, próximas a vencer o vencidas y documentos FEL con error"},
    {"name": "get_treasury_summary", "description": "Bancos: saldo banco vs Mayor, movimientos sin identificar, conciliaciones y pagos por aprobar"},
    {"name": "get_payroll_summary", "description": "Nóminas de las empresas: períodos en curso, novedades pendientes, errores de cálculo"},
    {"name": "search_normative", "description": "Busca en la Biblioteca Normativa leyes, decretos y artículos"},
]


def data_message(facts, tool="get_receivables_summary"):
    body = "\n".join(f"- {fact}" for fact in facts)
    return {"role": "user", "content": f"Contexto: Transportes Río Dulce, S.A. · pantalla accounting.\nDATOS OFICIALES ({tool}):\n{body}\nDetalle estructurado: {{}}\n\nPregunta del usuario: ¿cómo va?"}


class PlanTest(unittest.TestCase):
    def tool_for(self, question):
        return brain.plan(question, TOOLS)["tool"]

    def test_invoices_to_collect_are_not_confused_with_priorities(self):
        self.assertEqual(self.tool_for("¿Qué facturas por cobrar tiene pendientes Transportes Río Dulce y cuánto es el total?"), "get_receivables_summary")

    def test_invoices_to_pay(self):
        self.assertEqual(self.tool_for("¿Qué facturas por pagar tengo pendientes?"), "get_payables_summary")

    def test_taxes_banks_payroll_and_law(self):
        self.assertEqual(self.tool_for("¿Cuándo vence el IVA de este mes?"), "get_fiscal_status")
        self.assertEqual(self.tool_for("¿Cuánto tengo en el banco y qué movimientos no están identificados?"), "get_treasury_summary")
        self.assertEqual(self.tool_for("¿Qué novedades de planilla faltan?"), "get_payroll_summary")
        self.assertEqual(self.tool_for("¿Qué dice la ley sobre los libros contables obligatorios?"), "search_normative")

    def test_greetings_and_nonsense_use_no_tool(self):
        self.assertEqual(self.tool_for("Hola, buenas tardes"), "")
        self.assertEqual(self.tool_for("xyz qwerty"), "")

    def test_an_unlisted_tool_is_never_returned(self):
        names = {tool["name"] for tool in TOOLS}
        for question in ("¿Cuánto me deben mis clientes?", "prioridades de hoy", "cerrar el mes"):
            self.assertIn(brain.plan(question, TOOLS)["tool"], names | {""})


class AnswerTest(unittest.TestCase):
    def test_every_figure_comes_from_the_official_facts(self):
        facts = ["Transportes Río Dulce, S.A.: cartera Q 6,200.00, vencido Q 0.00, 0 cliente(s) vencido(s), 0 cobro(s) sin aplicar; CxC vs Mayor: Con diferencia.",
                 "0 promesa(s) de pago incumplida(s) en los últimos 30 días."]
        text = brain.answer("system", [data_message(facts)])
        self.assertIn("Q 6,200.00", text)
        self.assertIn("DIFERENCIA", text)
        self.assertNotIn("mora", text)
        self.assertIn("Siguiente paso", text)

    def test_overdue_amounts_raise_the_risk_flag(self):
        text = brain.answer("system", [data_message(["Cartera Q 9,000.00, vencido Q 4,500.00."])])
        self.assertIn("vencidos", text)

    def test_zero_figures_do_not_raise_alarms(self):
        text = brain.answer("system", [data_message(["Cartera Q 100.00, vencido Q 0.00."])])
        self.assertIn("no veo señales de riesgo", text)

    def test_a_failed_query_is_never_reported_as_good_news(self):
        content = "Contexto: x\nLA CONSULTA FALLÓ: Tiempo agotado.\nInforma que no pudiste obtener la información."
        text = brain.answer("system", [{"role": "user", "content": content + "\n\nPregunta del usuario: ¿cómo va?"}])
        self.assertIn("No pude obtener", text)
        self.assertIn("Tiempo agotado", text)

    def test_without_data_it_does_not_invent_figures(self):
        content = "Contexto: x\nNo se consultó ninguna herramienta: no tienes datos oficiales.\n\nPregunta del usuario: ¿Cuánto vendimos?"
        text = brain.answer("system", [{"role": "user", "content": content}])
        self.assertNotRegex(text, r"Q\s?\d")
        self.assertIn("No tengo datos oficiales", text)

    def test_it_introduces_itself(self):
        content = "Contexto: x\nNo se consultó ninguna herramienta.\n\nPregunta del usuario: Hola, ¿qué puedes hacer?"
        self.assertIn("Soy Luzma", brain.answer("system", [{"role": "user", "content": content}]))


class VerificationTest(unittest.TestCase):
    SOURCE = "Contexto: x. DATOS OFICIALES (get_receivables_summary): cartera Q 6,200.00, vencido Q 0.00, 3 cliente(s)."

    def test_an_answer_that_repeats_the_figures_is_accepted(self):
        self.assertEqual(brain.unsupported_figures("Tu cartera es de Q 6,200.00 y no hay vencido (Q 0.00).", self.SOURCE), [])

    def test_an_invented_figure_is_rejected(self):
        self.assertEqual(brain.unsupported_figures("Tu cartera es de Q 7,350.00.", self.SOURCE), ["7350.00"])

    def test_small_counts_and_years_are_allowed(self):
        self.assertEqual(brain.unsupported_figures("En 2026 hay 2 pasos y 5 revisiones.", self.SOURCE), [])


if __name__ == "__main__":
    unittest.main()
