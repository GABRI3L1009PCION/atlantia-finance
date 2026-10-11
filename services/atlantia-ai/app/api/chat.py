from fastapi import APIRouter, HTTPException

from app.core.config import settings
from app.schemas.chat import (
    ChatRequest,
    ChatResponse,
    LuzmaAnswerRequest,
    LuzmaPlanRequest,
    LuzmaPlanResponse,
)
from app.services.ollama_service import ollama_service


router = APIRouter(prefix="/ai", tags=["Luzma"])


@router.post("/chat", response_model=ChatResponse)
async def chat(request: ChatRequest):
    """Compatibility endpoint of the Atlantia AI era."""
    try:
        answer = await ollama_service.chat(request.message)
        return ChatResponse(status="ok", model=settings.AI_MODEL, response=answer)
    except Exception:
        raise HTTPException(status_code=503, detail="No se pudo obtener respuesta del modelo local.")


@router.post("/luzma/answer", response_model=ChatResponse)
async def luzma_answer(request: LuzmaAnswerRequest):
    """Explains data already computed and authorized by Laravel. This service never reads the database."""
    try:
        answer = await ollama_service.answer(request.system, [message.model_dump() for message in request.messages])
        return ChatResponse(status="ok", model=settings.AI_MODEL, response=answer)
    except Exception:
        raise HTTPException(status_code=503, detail="No se pudo obtener respuesta del modelo local.")


@router.post("/luzma/plan", response_model=LuzmaPlanResponse)
async def luzma_plan(request: LuzmaPlanRequest):
    """Structured intent: which of the authorized tools answers the question. Laravel re-validates the choice."""
    try:
        data = await ollama_service.plan(request.question, [tool.model_dump() for tool in request.tools], request.context)
        return LuzmaPlanResponse(
            intent=str(data.get("intent", "general")),
            tool=str(data.get("tool", "")),
            arguments=data.get("arguments") or {},
            confidence=float(data.get("confidence", 0)),
        )
    except Exception:
        raise HTTPException(status_code=503, detail="No se pudo planificar la consulta.")
