from fastapi import APIRouter, HTTPException

from app.core.config import settings
from app.schemas.chat import (
    ChatRequest,
    ChatResponse,
    LuzmaAnswerRequest,
    LuzmaPlanRequest,
    LuzmaPlanResponse,
)
from app.services.brain import ENGINE_NAME, brain
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
    messages = [message.model_dump() for message in request.messages]
    if settings.AI_ENGINE != "brain":
        try:
            answer = await ollama_service.answer(request.system, messages)
            # The model only writes: every figure in its text must come from the official data, or the brain's text is used.
            source = " ".join(message["content"] for message in messages)
            if not brain.unsupported_figures(answer, source) or settings.AI_ENGINE == "ollama":
                return ChatResponse(status="ok", model=settings.AI_MODEL, response=answer)
        except Exception:
            if settings.AI_ENGINE == "ollama":
                raise HTTPException(status_code=503, detail="No se pudo obtener respuesta del modelo local.")

    return ChatResponse(status="ok", model=ENGINE_NAME, response=brain.answer(request.system, messages))


@router.post("/luzma/plan", response_model=LuzmaPlanResponse)
async def luzma_plan(request: LuzmaPlanRequest):
    """Structured intent: which of the authorized tools answers the question. Laravel re-validates the choice."""
    tools = [tool.model_dump() for tool in request.tools]
    # The brain is deterministic and instant: when it is sure, the model is not even asked.
    own = brain.plan(request.question, tools)
    if settings.AI_ENGINE == "brain" or own["confidence"] >= 0.7:
        return LuzmaPlanResponse(**own)
    try:
        data = await ollama_service.plan(request.question, tools, request.context)
        return LuzmaPlanResponse(
            intent=str(data.get("intent", "general")),
            tool=str(data.get("tool", "")),
            arguments=data.get("arguments") or {},
            confidence=float(data.get("confidence", 0)),
        )
    except Exception:
        if settings.AI_ENGINE == "ollama":
            raise HTTPException(status_code=503, detail="No se pudo planificar la consulta.")

        return LuzmaPlanResponse(**own)
