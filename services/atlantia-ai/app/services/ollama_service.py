import json
import re

import httpx

from app.core.config import settings


SYSTEM_PROMPT = """
Eres Luzma, el asistente inteligente de Atlantia Finance.
Respondes en español. No inventes datos contables, financieros, fiscales ni bancarios.
Si no tienes información suficiente, dilo. No afirmes haber ejecutado operaciones.
Distingue hechos, análisis y recomendaciones. Trabajas de forma local y privada.
"""

PLAN_SYSTEM = """
Eres el planificador de Luzma. Recibes una pregunta y la lista de herramientas autorizadas.
Elige como máximo UNA herramienta que obtenga los datos oficiales para responder, con sus argumentos.
Si ninguna aplica, usa tool = "" e intent = "general".
Responde solo con el JSON solicitado. Nunca inventes nombres de herramientas.
"""

PLAN_SCHEMA = {
    "type": "object",
    "properties": {
        "intent": {"type": "string", "enum": ["read", "general"]},
        "tool": {"type": "string"},
        "arguments": {"type": "object"},
        "confidence": {"type": "number"},
    },
    "required": ["intent", "tool", "arguments", "confidence"],
}

THINK_BLOCK = re.compile(r"<think>.*?</think>", re.DOTALL)


class OllamaService:
    async def _chat(self, messages: list[dict], response_format: dict | None = None, timeout: float = 120.0) -> str:
        payload = {
            "model": settings.AI_MODEL,
            "stream": False,
            "think": False,
            "keep_alive": settings.AI_KEEP_ALIVE,
            "messages": messages,
            "options": {
                "temperature": settings.AI_TEMPERATURE,
                "num_ctx": settings.AI_CONTEXT_LENGTH,
            },
        }
        if response_format is not None:
            payload["format"] = response_format

        async with httpx.AsyncClient(timeout=timeout) as client:
            response = await client.post(f"{settings.OLLAMA_URL}/api/chat", json=payload)
            response.raise_for_status()
            content = response.json()["message"]["content"]

        return THINK_BLOCK.sub("", content).strip()

    async def chat(self, message: str) -> str:
        return await self._chat([
            {"role": "system", "content": SYSTEM_PROMPT},
            {"role": "user", "content": message},
        ])

    async def answer(self, system: str, messages: list[dict]) -> str:
        return await self._chat([{"role": "system", "content": system}, *messages])

    async def plan(self, question: str, tools: list[dict], context: dict) -> dict:
        catalog = "\n".join(
            f"- {tool['name']}: {tool['description']}"
            + (f" (argumentos: {json.dumps(tool.get('parameters', {}), ensure_ascii=False)})" if tool.get("parameters") else "")
            for tool in tools
        )
        content = await self._chat(
            [
                {"role": "system", "content": PLAN_SYSTEM},
                {
                    "role": "user",
                    "content": (
                        f"Pantalla: {context.get('module')} · empresa: {context.get('company') or 'todas'} · hoy: {context.get('today')}\n"
                        f"Herramientas:\n{catalog}\n\nPregunta: {question}"
                    ),
                },
            ],
            response_format=PLAN_SCHEMA,
            timeout=60.0,
        )
        data = json.loads(content)
        names = {tool["name"] for tool in tools}
        if data.get("tool") not in names:
            data["tool"] = ""
            data["intent"] = "general"

        return data

    async def health(self) -> dict:
        async with httpx.AsyncClient(timeout=4.0) as client:
            response = await client.get(f"{settings.OLLAMA_URL}/api/tags")
            response.raise_for_status()
            models = [model.get("name") for model in response.json().get("models", [])]
            try:
                running = await client.get(f"{settings.OLLAMA_URL}/api/ps")
                resident = settings.AI_MODEL in [model.get("name") for model in running.json().get("models", [])]
            except Exception:
                resident = False

        # model_loaded: the model is installed and can answer; model_resident: it is already in memory (no cold start).
        return {"ollama": True, "model_loaded": settings.AI_MODEL in models, "model_resident": resident}

    async def warmup(self) -> bool:
        """Loads the model into memory and keeps it there, so the next question is answered without a cold start."""
        async with httpx.AsyncClient(timeout=180.0) as client:
            response = await client.post(
                f"{settings.OLLAMA_URL}/api/generate",
                # Same context size the chats use: a different one would make Ollama reload the model on the first question.
                json={
                    "model": settings.AI_MODEL,
                    "keep_alive": settings.AI_KEEP_ALIVE,
                    "options": {"num_ctx": settings.AI_CONTEXT_LENGTH},
                },
            )
            response.raise_for_status()

        return True


ollama_service = OllamaService()
