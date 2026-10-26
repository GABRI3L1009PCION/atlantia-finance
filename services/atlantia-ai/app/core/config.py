import os

from dotenv import load_dotenv

load_dotenv()


class Settings:
    APP_NAME: str = os.getenv("APP_NAME", "Luzma")
    APP_VERSION: str = os.getenv("APP_VERSION", "0.1.0")
    APP_ENV: str = os.getenv("APP_ENV", "development")

    OLLAMA_URL: str = os.getenv(
        "OLLAMA_URL",
        "http://127.0.0.1:11434",
    )

    AI_MODEL: str = os.getenv(
        "AI_MODEL",
        "qwen3:8b-q4_K_M",
    )

    # auto (default): Ollama writes the answer when it is up and every figure checks out; otherwise the built-in brain answers. brain: only the brain (no model needed);
    # ollama: only Ollama. The brain needs no API, no key and no extra memory.
    AI_ENGINE: str = os.getenv("AI_ENGINE", "auto").lower()

    AI_TEMPERATURE: float = float(
        os.getenv("AI_TEMPERATURE", "0.2")
    )

    AI_CONTEXT_LENGTH: int = int(
        os.getenv("AI_CONTEXT_LENGTH", "4096")
    )

    # Longest answer the model may write (tokens) and how long Ollama may take to write it or to plan. Behind Cloudflare a
    # request is cut at 100 seconds, so planning plus answering must stay below it; when the model is late, the brain answers.
    AI_MAX_TOKENS: int = int(os.getenv("AI_MAX_TOKENS", "450"))
    AI_ANSWER_TIMEOUT: float = float(os.getenv("AI_ANSWER_TIMEOUT", "70"))
    AI_PLAN_TIMEOUT: float = float(os.getenv("AI_PLAN_TIMEOUT", "15"))

    # How long Ollama keeps the model in memory after a request. -1 keeps it loaded for good, so Luzma never
    # needs a cold start (about 50 seconds) after being idle.
    AI_KEEP_ALIVE: int | str = int(os.getenv("AI_KEEP_ALIVE", "-1")) if os.getenv("AI_KEEP_ALIVE", "-1").lstrip("-").isdigit() else os.getenv("AI_KEEP_ALIVE", "-1")


settings = Settings()
