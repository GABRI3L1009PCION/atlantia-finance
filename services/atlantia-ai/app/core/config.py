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

    AI_TEMPERATURE: float = float(
        os.getenv("AI_TEMPERATURE", "0.2")
    )

    AI_CONTEXT_LENGTH: int = int(
        os.getenv("AI_CONTEXT_LENGTH", "4096")
    )


settings = Settings()
