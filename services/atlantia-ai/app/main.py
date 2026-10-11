from fastapi import FastAPI

from app.api.chat import router as chat_router
from app.core.config import settings
from app.services.ollama_service import ollama_service


app = FastAPI(
    title=settings.APP_NAME,
    version=settings.APP_VERSION,
    description="Servicio local de Luzma, el asistente inteligente de Atlantia Finance",
)

app.include_router(chat_router)


@app.get("/")
async def root():
    return {
        "status": "ok",
        "service": settings.APP_NAME,
        "version": settings.APP_VERSION,
        "environment": settings.APP_ENV,
        "message": "Luzma está funcionando correctamente",
    }


@app.get("/health")
async def health_check():
    try:
        ollama = await ollama_service.health()
    except Exception:
        ollama = {"ollama": False, "model_loaded": False}

    return {
        "status": "healthy" if ollama["model_loaded"] else "degraded",
        "service": settings.APP_NAME,
        "model": settings.AI_MODEL,
        **ollama,
    }
