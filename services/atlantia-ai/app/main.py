from fastapi import FastAPI

from app.api.chat import router as chat_router
from app.core.config import settings


app = FastAPI(
    title=settings.APP_NAME,
    version=settings.APP_VERSION,
    description=(
        "Servicio local de inteligencia artificial "
        "de Atlantia Finance"
    ),
)


app.include_router(chat_router)


@app.get("/")
async def root():
    return {
        "status": "ok",
        "service": settings.APP_NAME,
        "version": settings.APP_VERSION,
        "environment": settings.APP_ENV,
        "message": "Atlantia AI está funcionando correctamente",
    }


@app.get("/health")
async def health_check():
    return {
        "status": "healthy",
        "service": settings.APP_NAME,
        "model": settings.AI_MODEL,
        "ollama": settings.OLLAMA_URL,
    }
