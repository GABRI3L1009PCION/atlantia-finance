from fastapi import APIRouter, HTTPException

from app.core.config import settings
from app.schemas.chat import ChatRequest, ChatResponse
from app.services.ollama_service import ollama_service


router = APIRouter(
    prefix="/ai",
    tags=["Atlantia AI"],
)


@router.post(
    "/chat",
    response_model=ChatResponse,
)
async def chat(request: ChatRequest):
    try:
        answer = await ollama_service.chat(request.message)

        return ChatResponse(
            status="ok",
            model=settings.AI_MODEL,
            response=answer,
        )

    except Exception as exc:
        raise HTTPException(
            status_code=503,
            detail=f"No se pudo obtener respuesta del modelo local: {exc}",
        )
