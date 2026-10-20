"""Loads Luzma's neural network once, keeps the live feed of what it decides and retrains it in the background."""

import json
import os
import re
import threading
import time
from collections import deque

from app.services.intents import CORES, HAND_TESTS, test_examples, training_examples
from app.services.neural import NeuralNet, build_vocab

DATA = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "data")
MODEL = os.path.join(DATA, "neural_model")
LEARNED = os.path.join(DATA, "learned.json")


class NeuralRuntime:
    def __init__(self) -> None:
        self.net: NeuralNet | None = NeuralNet.load(MODEL)
        self.recent: deque = deque(maxlen=60)
        self.counter = 0
        self.training: dict = {"running": False, "epoch": 0, "epochs": 0, "loss": None, "message": None}
        self._lock = threading.Lock()

    @property
    def ready(self) -> bool:
        return self.net is not None

    # The live feed never stores the question: only what the network did with it (tool, confidence, activations).
    def record(self, outcome: dict, source: str) -> None:
        self.counter += 1
        self.recent.appendleft({"id": self.counter, "at": time.strftime("%H:%M:%S"), "source": source, "winner": outcome["winner"], "confidence": outcome["confidence"],
                                "known": len(outcome["known"]), "hidden1": outcome["hidden1"], "hidden2": outcome["hidden2"], "outputs": outcome["outputs"][:3]})

    def learned(self) -> list[dict]:
        try:
            with open(LEARNED, encoding="utf-8") as handle:
                return json.load(handle)
        except (OSError, ValueError):
            return []

    def add_learned(self, items: list[dict]) -> int:
        """Approved questions (numbers are masked so no amount, NIT or account reaches the training file)."""
        current = self.learned()
        seen = {(item["text"], item["label"]) for item in current}
        added = 0
        for item in items:
            label = str(item.get("label", ""))
            text = re.sub(r"\d+", "#", str(item.get("text", ""))).strip()[:200]
            if label in CORES and len(text) >= 4 and (text, label) not in seen:
                current.append({"text": text, "label": label})
                seen.add((text, label))
                added += 1
        os.makedirs(DATA, exist_ok=True)
        with open(LEARNED, "w", encoding="utf-8") as handle:
            json.dump(current, handle, ensure_ascii=False)

        return added

    def retrain(self, epochs: int = 14) -> bool:
        with self._lock:
            if self.training["running"]:
                return False
            self.training = {"running": True, "epoch": 0, "epochs": epochs, "loss": None, "message": "Entrenando…"}
        threading.Thread(target=self._train, args=(epochs,), daemon=True).start()

        return True

    def _train(self, epochs: int) -> None:
        try:
            examples = training_examples() + [(item["text"], item["label"]) for item in self.learned()]
            net = NeuralNet(build_vocab(examples), list(CORES.keys()))

            def progress(epoch: int, loss: float, rate: float) -> None:
                self.training.update({"epoch": epoch, "loss": round(loss, 4)})

            net.fit(examples, epochs=epochs, progress=progress)
            held_out = test_examples()
            net.meta["accuracy"] = round(net.accuracy(held_out), 4)
            net.meta["hand_accuracy"] = round(net.accuracy(list(HAND_TESTS.items())), 4)
            net.meta["test_examples"] = len(held_out)
            net.save(MODEL)
            self.net = net
            self.training.update({"running": False, "message": f"Listo: exactitud {net.meta['accuracy']:.1%} (a mano {net.meta['hand_accuracy']:.1%}) con {len(examples)} ejemplos."})
        except Exception as exception:  # the old network keeps serving if training fails
            self.training.update({"running": False, "message": f"El entrenamiento falló: {exception}"})


runtime = NeuralRuntime()
