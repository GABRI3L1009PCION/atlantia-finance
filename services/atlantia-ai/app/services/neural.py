"""Luzma's neural network (NumPy): word embeddings followed by two wide dense layers.

    question -> [words and word pairs] -> [embedding, averaged] -> [dense 1, ReLU] -> [dense 2, ReLU] -> [softmax: one neuron per tool]

* Embedding: every word (stem) or pair of consecutive words has a learned vector, so "debo", "adeudo" and "pendiente" end up
  close to each other. The vectors of the words in the question are averaged.
* Dense layers: learn which combinations of those meanings point to which tool (dropout and weight decay keep them honest).
* Output: one probability per tool plus `general` (greetings and anything no tool answers).

The size is chosen from a target number of parameters (about three million). The network only decides WHICH authorized tool
answers: Laravel still runs the tool, checks permissions and supplies every figure, so it can never invent data.
"""

import json
import os
import time
from collections import Counter
from typing import Callable

import numpy as np

from app.services.brain import STOPWORDS, normalize, stem

TARGET_PARAMETERS = 3_000_000
EMBEDDING_DIM = 384
SECOND_LAYER = 1024
DRAWN = (32, 16)  # how many neurons per hidden layer the diagram draws (the real layers are pooled into them)


def featurize_tokens(text: str) -> list[str]:
    """Stems and consecutive pairs of stems, without the stop words."""
    words = [stem(word) for word in normalize(text).split() if word not in STOPWORDS and len(word) > 1]
    pairs = [f"{a}_{b}" for a, b in zip(words, words[1:])]

    return words + pairs


def build_vocab(examples: list[tuple[str, str]], min_pair_count: int = 2) -> list[str]:
    """Every word seen in training and the word pairs that repeat (a pair seen once is noise)."""
    counts: Counter = Counter()
    for text, _ in examples:
        counts.update(set(featurize_tokens(text)))

    return sorted(term for term, count in counts.items() if "_" not in term or count >= min_pair_count)


def widths_for(vocab_size: int, classes: int, target: int = TARGET_PARAMETERS) -> tuple[int, int, int]:
    """(embedding, hidden 1, hidden 2) whose total parameters land on the target."""
    dim, second = EMBEDDING_DIM, SECOND_LAYER
    first = max(64, (target - vocab_size * dim - second - classes * (second + 1)) // (dim + 1 + second))

    return dim, int(first // 16 * 16), second


def pool(values: np.ndarray, groups: int) -> list[float]:
    """Mean of consecutive blocks, scaled to 0..1, so a layer of 1,536 neurons can be drawn with 32."""
    blocks = np.array_split(values, groups)
    means = np.array([block.mean() for block in blocks])
    top = float(means.max())

    return [round(float(v / top), 3) if top > 1e-9 else 0.0 for v in means]


class NeuralNet:
    def __init__(self, vocab: list[str], classes: list[str], widths: tuple[int, int, int] | None = None, seed: int = 7):
        self.vocab = vocab
        self.index = {term: i for i, term in enumerate(vocab)}
        self.classes = classes
        self.widths = widths or widths_for(len(vocab), len(classes))
        dim, h1, h2 = self.widths
        rng = np.random.default_rng(seed)
        self.params = {
            "E": rng.normal(0, 0.5, (len(vocab), dim)).astype(np.float32),
            "W1": (rng.normal(0, 1, (dim, h1)) * np.sqrt(2 / dim)).astype(np.float32), "b1": np.zeros(h1, np.float32),
            "W2": (rng.normal(0, 1, (h1, h2)) * np.sqrt(2 / h1)).astype(np.float32), "b2": np.zeros(h2, np.float32),
            "W3": (rng.normal(0, 1, (h2, len(classes))) * np.sqrt(1 / h2)).astype(np.float32), "b3": np.zeros(len(classes), np.float32),
        }
        self.meta: dict = {"trained_at": None, "epochs": 0, "examples": 0, "accuracy": None, "loss": None, "seconds": None}

    # ------------------------------------------------------------ forward

    def bag(self, texts: list[str]) -> np.ndarray:
        matrix = np.zeros((len(texts), len(self.vocab)), np.float32)
        for row, text in enumerate(texts):
            for term in featurize_tokens(text):
                column = self.index.get(term)
                if column is not None:
                    matrix[row, column] += 1.0

        return matrix

    def forward(self, bag: np.ndarray, train: bool = False, rng: np.random.Generator | None = None, dropout: float = 0.0) -> dict:
        p = self.params
        count = np.maximum(bag.sum(axis=1, keepdims=True), 1.0)
        x = bag @ p["E"] / count
        z1 = x @ p["W1"] + p["b1"]
        a1 = np.maximum(z1, 0)
        m1 = m2 = None
        if train and dropout > 0 and rng is not None:
            m1 = (rng.random(a1.shape) >= dropout).astype(np.float32) / (1 - dropout)
            a1 = a1 * m1
        z2 = a1 @ p["W2"] + p["b2"]
        a2 = np.maximum(z2, 0)
        if train and dropout > 0 and rng is not None:
            m2 = (rng.random(a2.shape) >= dropout).astype(np.float32) / (1 - dropout)
            a2 = a2 * m2
        logits = a2 @ p["W3"] + p["b3"]
        logits -= logits.max(axis=1, keepdims=True)
        exps = np.exp(logits)

        return {"x": x, "count": count, "z1": z1, "a1": a1, "z2": z2, "a2": a2, "m1": m1, "m2": m2, "probs": exps / exps.sum(axis=1, keepdims=True)}

    def predict(self, text: str, allowed: set[str] | None = None) -> dict[str, float]:
        """Probability of each class. With `allowed`, only those tools (plus general) compete and the rest is renormalized."""
        probs = self.forward(self.bag([text]))["probs"][0]
        result = {name: float(p) for name, p in zip(self.classes, probs) if allowed is None or name in allowed or name == "general"}
        total = sum(result.values()) or 1.0

        return {name: p / total for name, p in result.items()}

    # ----------------------------------------------------------- training

    def fit(self, examples: list[tuple[str, str]], epochs: int = 40, lr: float = 0.001, batch: int = 64, dropout: float = 0.25,
            smoothing: float = 0.05, weight_decay: float = 1e-4, seed: int = 3, progress: Callable[[int, float, float], None] | None = None) -> None:
        rng = np.random.default_rng(seed)
        known = [(text, self.classes.index(label)) for text, label in examples if label in self.classes]
        bags = self.bag([text for text, _ in known])
        keep = bags.sum(axis=1) > 0
        bags, labels = bags[keep], np.array([label for (_, label), k in zip(known, keep) if k])
        classes = len(self.classes)
        moments = {name: (np.zeros_like(value), np.zeros_like(value)) for name, value in self.params.items()}
        started, step, loss = time.time(), 0, 0.0
        steps_total = epochs * int(np.ceil(len(labels) / batch))
        for epoch in range(epochs):
            order = rng.permutation(len(labels))
            losses = []
            for start in range(0, len(order), batch):
                ids = order[start : start + batch]
                step += 1
                rate = lr * (0.05 + 0.95 * 0.5 * (1 + np.cos(np.pi * step / steps_total)))  # cosine decay
                bag, y = bags[ids], labels[ids]
                out = self.forward(bag, True, rng, dropout)
                target = np.full((len(ids), classes), smoothing / classes, np.float32)
                target[np.arange(len(ids)), y] += 1 - smoothing
                losses.append(float(-(target * np.log(np.maximum(out["probs"], 1e-9))).sum(axis=1).mean()))
                grads = self._backward(bag, out, target)
                for name, grad in grads.items():
                    m, v = moments[name]
                    m *= 0.9
                    m += 0.1 * grad
                    v *= 0.999
                    v += 0.001 * grad * grad
                    update = (m / (1 - 0.9**step)) / (np.sqrt(v / (1 - 0.999**step)) + 1e-8)
                    if name[0] in "EW":
                        self.params[name] *= 1 - rate * weight_decay
                    self.params[name] -= (rate * update).astype(np.float32)
            loss = float(np.mean(losses))
            if progress is not None:
                progress(epoch + 1, loss, float(rate))
        self.meta.update({"trained_at": time.strftime("%Y-%m-%d %H:%M:%S"), "epochs": epochs, "examples": len(labels), "loss": round(loss, 4), "seconds": round(time.time() - started, 1)})

    def _backward(self, bag: np.ndarray, out: dict, target: np.ndarray) -> dict:
        p = self.params
        n = len(bag)
        d_logits = (out["probs"] - target) / n
        d_a2 = d_logits @ p["W3"].T
        if out["m2"] is not None:
            d_a2 = d_a2 * out["m2"]
        d_z2 = d_a2 * (out["z2"] > 0)
        d_a1 = d_z2 @ p["W2"].T
        if out["m1"] is not None:
            d_a1 = d_a1 * out["m1"]
        d_z1 = d_a1 * (out["z1"] > 0)
        d_x = d_z1 @ p["W1"].T

        return {
            "E": bag.T @ (d_x / out["count"]), "W1": out["x"].T @ d_z1, "b1": d_z1.sum(0), "W2": out["a1"].T @ d_z2, "b2": d_z2.sum(0),
            "W3": out["a2"].T @ d_logits, "b3": d_logits.sum(0),
        }

    def accuracy(self, examples: list[tuple[str, str]]) -> float:
        probs = self.forward(self.bag([text for text, _ in examples]))["probs"]
        hits = sum(self.classes[int(row.argmax())] == label for row, (_, label) in zip(probs, examples))

        return hits / max(1, len(examples))

    # ------------------------------------------------------ introspection

    def inspect(self, text: str, allowed: set[str] | None = None) -> dict:
        """Everything needed to draw the network lighting up for one question (activations, the winner, why)."""
        tokens = featurize_tokens(text)
        known = [term for term in dict.fromkeys(tokens) if term in self.index]
        bag = self.bag([text])
        out = self.forward(bag)
        probs = out["probs"][0]
        order = np.argsort(-probs)
        winner = self.classes[int(order[0])]
        win = int(order[0])
        influence = []
        for term in known:
            reduced = bag.copy()
            reduced[0, self.index[term]] = 0.0
            influence.append({"term": term, "weight": round(float(probs[win] - self.forward(reduced)["probs"][0][win]), 4)})
        influence.sort(key=lambda item: item["weight"], reverse=True)

        return {
            "tokens": tokens, "known": known, "unknown": [term for term in dict.fromkeys(tokens) if term not in self.index and "_" not in term],
            "hidden1": pool(out["a1"][0], DRAWN[0]), "hidden2": pool(out["a2"][0], DRAWN[1]),
            "outputs": [{"name": self.classes[int(i)], "probability": round(float(probs[i]), 4)} for i in order[:8]],
            "winner": winner, "confidence": round(float(probs[win]), 4), "influence": influence[:8],
            "allowed_winner": max(self.predict(text, allowed).items(), key=lambda item: item[1])[0] if allowed else winner,
        }

    def parameter_count(self) -> int:
        return int(sum(value.size for value in self.params.values()))

    def summary(self) -> dict:
        dim, h1, h2 = self.widths

        return {"layers": [len(self.vocab), dim, h1, h2, len(self.classes)], "classes": self.classes, "parameters": self.parameter_count(),
                "vocabulary": len(self.vocab), "embedding": dim, "hidden": [h1, h2], **self.meta}

    # --------------------------------------------------------------- io

    def save(self, base: str) -> None:
        """base.json (vocabulary, classes, sizes, metadata) and base.npz (the weights, stored as float16: half the size)."""
        os.makedirs(os.path.dirname(base), exist_ok=True)
        with open(base + ".json", "w", encoding="utf-8") as handle:
            json.dump({"vocab": self.vocab, "classes": self.classes, "widths": list(self.widths), "meta": self.meta}, handle, ensure_ascii=False, separators=(",", ":"))
        np.savez_compressed(base + ".npz", **{name: value.astype(np.float16) for name, value in self.params.items()})

    @classmethod
    def load(cls, base: str) -> "NeuralNet | None":
        try:
            with open(base + ".json", encoding="utf-8") as handle:
                data = json.load(handle)
            weights = np.load(base + ".npz")
            net = cls.__new__(cls)
            net.vocab, net.classes, net.widths, net.meta = data["vocab"], data["classes"], tuple(data["widths"]), data.get("meta", {})
            net.index = {term: i for i, term in enumerate(net.vocab)}
            net.params = {name: weights[name].astype(np.float32) for name in ("E", "W1", "b1", "W2", "b2", "W3", "b3")}

            return net
        except (OSError, ValueError, KeyError):
            return None
