import asyncio
from contextlib import asynccontextmanager

from fastapi import FastAPI, HTTPException

from app.api.chat import router as chat_router
from app.api.neural import router as neural_router
from app.core.config import settings
from app.services.brain import ENGINE_NAME
from app.services.ollama_service import ollama_service


async def _warm_up() -> None:
    # Ollama may still be starting when the gateway comes up: retry for a few minutes.
    for _ in range(20):
        try:
            await ollama_service.warmup()
            return
        except Exception:
            await asyncio.sleep(15)


@asynccontextmanager
async def lifespan(app: FastAPI):
    task = asyncio.create_task(_warm_up() if settings.AI_ENGINE != "brain" else asyncio.sleep(0))
    yield
    task.cancel()


app = FastAPI(
    title=settings.APP_NAME,
    version=settings.APP_VERSION,
    description="Servicio local de Luzma, el asistente inteligente de Atlantia Finance",
    lifespan=lifespan,
)

app.include_router(chat_router)
app.include_router(neural_router)


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
        ollama = {"ollama": False, "model_loaded": False, "model_resident": False}

    # The built-in brain always answers, so Luzma stays available even when Ollama is not installed or is down.
    engine = settings.AI_ENGINE
    if engine == "brain" or (engine == "auto" and not ollama["model_loaded"]):
        ollama = {**ollama, "model_loaded": True, "model_resident": True}
        model = ENGINE_NAME
    else:
        model = settings.AI_MODEL

    return {
        "status": "healthy" if ollama["model_loaded"] else "degraded",
        "service": settings.APP_NAME,
        "model": model,
        "engine": engine,
        **ollama,
    }


@app.post("/ai/warmup")
async def warmup():
    """Called by the Laravel watchdog when the model fell out of memory."""
    if settings.AI_ENGINE == "brain":
        return {"status": "ok", "model": ENGINE_NAME}
    try:
        await ollama_service.warmup()
    except Exception:
        if settings.AI_ENGINE == "auto":
            return {"status": "ok", "model": ENGINE_NAME}
        raise HTTPException(status_code=503, detail="No se pudo cargar el modelo local.")

    return {"status": "ok", "model": settings.AI_MODEL}
