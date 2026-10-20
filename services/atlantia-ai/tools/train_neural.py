"""Trains Luzma's neural network and writes app/data/neural_model.json.

    cd services/atlantia-ai
    PYTHONPATH=. .venv/Scripts/python.exe tools/train_neural.py

It also reads app/data/learned.json (questions the SaaS administrator approved from real feedback) when it exists.
"""

import json
import os
import sys

from app.services.intents import CORES, HAND_TESTS, test_examples, training_examples
from app.services.neural import NeuralNet, build_vocab

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
MODEL = os.path.join(ROOT, "app", "data", "neural_model")
LEARNED = os.path.join(ROOT, "app", "data", "learned.json")


def learned_examples() -> list[tuple[str, str]]:
    try:
        with open(LEARNED, encoding="utf-8") as handle:
            return [(item["text"], item["label"]) for item in json.load(handle) if item["label"] in CORES]
    except (OSError, ValueError, KeyError):
        return []


def train(epochs: int = 14, verbose: bool = True) -> NeuralNet:
    examples = training_examples() + learned_examples()
    classes = list(CORES.keys())
    net = NeuralNet(build_vocab(examples), classes)

    def show(epoch: int, loss: float, rate: float) -> None:
        if verbose and (epoch == 1 or epoch % 5 == 0):
            print(f"  época {epoch:>3}  pérdida {loss:.4f}  tasa {rate:.4f}", flush=True)

    net.fit(examples, epochs=epochs, progress=show)
    held_out = test_examples()
    net.meta["accuracy"] = round(net.accuracy(held_out), 4)
    net.meta["hand_accuracy"] = round(net.accuracy(list(HAND_TESTS.items())), 4)
    net.meta["test_examples"] = len(held_out)
    net.save(MODEL)
    if verbose:
        print(f"Red entrenada: {net.parameter_count():,} parámetros, capas {net.summary()['layers']}, exactitud en frases nuevas {net.meta['accuracy']:.1%}, en frases escritas a mano {net.meta['hand_accuracy']:.1%}")

    return net


if __name__ == "__main__":
    train(int(sys.argv[1]) if len(sys.argv) > 1 else 14)
