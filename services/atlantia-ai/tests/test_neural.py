import unittest

from app.services.brain import brain
from app.services.intents import CORES, HAND_TESTS, test_examples
from app.services.neural_runtime import runtime

TOOLS = [{"name": name, "description": name} for name in CORES if name != "general"]

class NeuralTest(unittest.TestCase):
    def test_the_trained_network_is_loaded_and_has_about_three_million_parameters(self):
        self.assertTrue(runtime.ready)
        self.assertEqual(runtime.net.summary()["layers"][-1], len(CORES))
        self.assertGreaterEqual(runtime.net.parameter_count(), 2_900_000)
        self.assertLessEqual(runtime.net.parameter_count(), 3_100_000)

    def test_it_understands_phrasings_it_never_saw_in_training(self):
        net = runtime.net
        self.assertGreaterEqual(net.accuracy(test_examples()), 0.95)
        self.assertGreaterEqual(net.accuracy(list(HAND_TESTS.items())), 0.85)

    def test_only_the_authorized_tools_compete(self):
        allowed = {"get_payables_summary", "get_treasury_summary"}
        probs = runtime.net.predict("¿cuánto nos deben los clientes?", allowed)
        self.assertLessEqual(set(probs), allowed | {"general"})
        self.assertAlmostEqual(sum(probs.values()), 1.0, places=5)

    def test_plan_returns_a_listed_tool_or_none(self):
        names = {tool["name"] for tool in TOOLS}
        for question in HAND_TESTS:
            self.assertIn(brain.plan(question, TOOLS)["tool"], names | {""})

    def test_inspect_exposes_the_activations_to_draw_the_network(self):
        out = runtime.net.inspect("¿Qué facturas por cobrar tengo pendientes?")
        self.assertEqual(len(out["hidden1"]), 32)
        self.assertEqual(len(out["hidden2"]), 16)
        self.assertEqual(out["winner"], "get_receivables_summary")
        self.assertTrue(out["influence"])

    def test_the_live_feed_never_stores_the_question(self):
        brain.plan("¿Qué facturas por cobrar tengo pendientes?", TOOLS)
        feed = str(list(runtime.recent)[0])
        self.assertNotIn("facturas", feed)

    def test_learned_questions_mask_numbers(self):
        before = runtime.learned()
        try:
            added = runtime.add_learned([{"text": "saldo de la cuenta 110145 de NIT 1234567", "label": "get_account_balance"}])
            self.assertEqual(added, 1)
            self.assertTrue(all(not any(c.isdigit() for c in item["text"]) for item in runtime.learned()[len(before):]))
        finally:
            import json
            from app.services.neural_runtime import LEARNED
            with open(LEARNED, "w", encoding="utf-8") as handle:
                json.dump(before, handle)


if __name__ == "__main__":
    unittest.main()
