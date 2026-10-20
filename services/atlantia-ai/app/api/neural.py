from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field

from app.services.intents import CORES
from app.services.neural_runtime import runtime

router = APIRouter(prefix="/ai/neural", tags=["Red neuronal"])


class InspectRequest(BaseModel):
    text: str = Field(..., min_length=1, max_length=500)


class LearnItem(BaseModel):
    text: str = Field(..., min_length=1, max_length=300)
    label: str


class TrainRequest(BaseModel):
    epochs: int = Field(14, ge=5, le=80)
    items: list[LearnItem] = []


def _require():
    if not runtime.ready:
        raise HTTPException(status_code=503, detail="La red neuronal aún no está entrenada.")


@router.get("/info")
async def info():
    if not runtime.ready:
        return {"ready": False, "training": runtime.training, "learned": len(runtime.learned())}

    return {"ready": True, "network": runtime.net.summary(), "training": runtime.training, "learned": len(runtime.learned()), "classes": list(CORES.keys())}


@router.post("/inspect")
async def inspect(request: InspectRequest):
    """Runs the network on a text typed by the administrator and returns every activation to draw it."""
    _require()
    outcome = runtime.net.inspect(request.text)
    runtime.record(outcome, "prueba")

    return outcome


@router.get("/recent")
async def recent(after: int = 0):
    """Live feed: what the network decided for the latest real questions. It never contains the question itself."""
    return {"items": [item for item in reversed(list(runtime.recent)) if item["id"] > after], "last_id": runtime.counter}


@router.post("/train")
async def train(request: TrainRequest):
    added = runtime.add_learned([item.model_dump() for item in request.items]) if request.items else 0
    started = runtime.retrain(request.epochs)

    return {"started": started, "added": added, "training": runtime.training}
