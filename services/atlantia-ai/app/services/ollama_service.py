import httpx

from app.core.config import settings


SYSTEM_PROMPT = """
Eres Atlantia AI, el asistente privado de inteligencia artificial
integrado en Atlantia Finance.

Tu función es asistir a usuarios de un sistema contable multiempresa.

Debes responder siempre en español, salvo que el usuario solicite
explícitamente otro idioma.

Principios obligatorios:

1. No inventes datos contables, financieros, fiscales ni bancarios.
2. Si no dispones de información suficiente, indícalo claramente.
3. No afirmes haber realizado operaciones que no hayan sido ejecutadas.
4. No generes datos financieros ficticios como si fueran reales.
5. Distingue claramente entre hechos, análisis y recomendaciones.
6. Las operaciones contables sensibles deben ser revisadas y aprobadas
   por un usuario autorizado antes de ejecutarse.
7. Prioriza precisión, trazabilidad y privacidad.
8. Atlantia AI trabaja de manera local y privada dentro de Atlantia Finance.

Actualmente eres el núcleo conversacional de Atlantia AI.
Más adelante recibirás herramientas autorizadas para consultar los
módulos contables del sistema.
"""


class OllamaService:

    async def chat(self, message: str) -> str:

        payload = {
            "model": settings.AI_MODEL,
            "stream": False,
            "messages": [
                {
                    "role": "system",
                    "content": SYSTEM_PROMPT,
                },
                {
                    "role": "user",
                    "content": message,
                },
            ],
            "options": {
                "temperature": settings.AI_TEMPERATURE,
                "num_ctx": settings.AI_CONTEXT_LENGTH,
            },
        }

        async with httpx.AsyncClient(timeout=120.0) as client:
            response = await client.post(
                f"{settings.OLLAMA_URL}/api/chat",
                json=payload,
            )

            response.raise_for_status()

            data = response.json()

            return data["message"]["content"]


ollama_service = OllamaService()
