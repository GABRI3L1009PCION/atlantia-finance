from typing import Any

from pydantic import BaseModel, Field


class ChatRequest(BaseModel):
    message: str = Field(..., min_length=1, max_length=10000, description="Mensaje enviado a Luzma")


class ChatResponse(BaseModel):
    status: str
    model: str
    response: str


class LuzmaMessage(BaseModel):
    role: str = Field(..., pattern="^(user|assistant)$")
    content: str = Field(..., min_length=1, max_length=20000)


class LuzmaAnswerRequest(BaseModel):
    system: str = Field(..., min_length=1, max_length=8000)
    messages: list[LuzmaMessage] = Field(..., min_length=1, max_length=20)


class LuzmaTool(BaseModel):
    name: str
    description: str
    parameters: dict[str, str] = {}


class LuzmaPlanRequest(BaseModel):
    question: str = Field(..., min_length=1, max_length=2000)
    tools: list[LuzmaTool]
    context: dict[str, Any] = {}


class LuzmaPlanResponse(BaseModel):
    intent: str
    tool: str
    arguments: dict[str, Any]
    confidence: float
