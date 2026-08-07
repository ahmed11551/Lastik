"""
AUTOMETRIA ERP — Local Enterprise AI Pack (AirLLM Engine).

Локальный FastAPI-микросервис для генерации текста без облачных API.
Использует AirLLM для послойной подкачки слоёв (layer-offload):
модель загружается в RAM/SSD и выгружается слой-за-слоем при инференсе,
что позволяет запускать LLM (7B–70B) на обычном железе без VRAM.

Эндпоинты:
  POST /v1/generate   — генерация текста по промпту.
  GET  /health        — проверка доступности.
"""

from __future__ import annotations

import os
import time
from typing import Any, Dict, List, Optional

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field

app = FastAPI(title="AUTOMETRIA AirLLM Engine", version="1.4.0")

# Модель по умолчанию берётся из переменной окружения (локальный путь или HF-id).
# Пример: export AIRLLM_MODEL="Qwen/Qwen2.5-7B-Instruct" или "/models/qwen-7b".
MODEL_PATH = os.environ.get("AIRLLM_MODEL", "Qwen/Qwen2.5-7B-Instruct")

# Ленивая загрузка: модель инициализируется только при первом запросе,
# чтобы сервис быстро стартовал даже на слабом железе.
_MODEL = None


def _get_model():
    """Ленивая инициализация AirLLM с послойной подкачкой (layer-offload).

    AirLLM сам управляет offload-ом слоёв на CPU/диск, поэтому
    обязательный VRAM не требуется.
    """
    global _MODEL
    if _MODEL is None:
        try:
            from airllm import AirLLMModel  # универсальный загрузчик слоёв
        except ImportError as exc:  # pragma: no cover - зависит от окружения
            raise RuntimeError(
                "airllm не установлен. Установите: pip install -r requirements.txt"
            ) from exc

        # batch_size=1 + layer_offload=True → минимальное потребление памяти.
        _MODEL = AirLLMModel(
            MODEL_PATH,
            batch_size=1,
            layer_offload=True,        # послойная подкачка (без VRAM)
            dtype="float16",
        )
    return _MODEL


class GenerateRequest(BaseModel):
    prompt: str = Field(..., min_length=1, max_length=8000)
    max_new_tokens: int = Field(default=512, ge=1, le=4096)
    temperature: float = Field(default=0.7, ge=0.0, le=2.0)
    system_prompt: Optional[str] = Field(default=None)


class GenerateResponse(BaseModel):
    text: str
    model: str
    tokens: int
    elapsed_ms: float


@app.get("/health")
def health() -> Dict[str, Any]:
    return {"status": "ok", "model": MODEL_PATH, "loaded": _MODEL is not None}


@app.post("/v1/generate", response_model=GenerateResponse)
def generate(req: GenerateRequest) -> GenerateResponse:
    try:
        model = _get_model()
    except Exception as exc:  # pragma: no cover
        raise HTTPException(status_code=503, detail=f"model unavailable: {exc}")

    system = req.system_prompt or (
        "Ты — корпоративный AI-ассистент ERP-системы AUTOMETRIA. "
        "Отвечай кратко, по делу, на русском языке, в деловом стиле."
    )
    full_prompt = f"<|system|>\n{system}\n<|user|>\n{req.prompt}\n<|assistant|>\n"

    start = time.time()
    try:
        # AirLLM возвращает список сгенерированных последовательностей.
        output = model.generate(
            full_prompt,
            max_new_tokens=req.max_new_tokens,
            temperature=req.temperature,
        )
    except Exception as exc:  # pragma: no cover
        raise HTTPException(status_code=500, detail=f"generation failed: {exc}")

    text = output[0] if isinstance(output, list) and output else str(output)
    elapsed = (time.time() - start) * 1000.0

    return GenerateResponse(
        text=text.strip(),
        model=MODEL_PATH,
        tokens=len(text.split()),
        elapsed_ms=round(elapsed, 1),
    )


if __name__ == "__main__":
    import uvicorn

    host = os.environ.get("AIRLLM_HOST", "127.0.0.1")
    port = int(os.environ.get("AIRLLM_PORT", "8100"))
    uvicorn.run(app, host=host, port=port)
