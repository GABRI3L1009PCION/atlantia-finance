# Atlantia AI

Servicio local de inteligencia artificial para Atlantia Finance.

## Objetivo

Atlantia AI proporcionará capacidades de inteligencia artificial completamente locales para el sistema Atlantia Finance.

El servicio estará encargado de:

- Asistente contable
- Análisis financiero
- Procesamiento de documentos
- Clasificación contable
- Sugerencias de asientos
- Detección de anomalías
- Conciliación bancaria inteligente
- Revisión de períodos contables
- RAG documental
- Predicciones financieras

## Privacidad

Atlantia AI está diseñado para ejecutarse localmente.

Los datos contables, documentos y consultas no serán enviados a servicios externos de inteligencia artificial.

## Tecnologías

- Python
- FastAPI
- Ollama
- Modelos LLM locales
- Base vectorial local
- OCR local

## Estado

Versión inicial: 0.1.0

## Cerebro propio de Luzma

Luzma entiende la pregunta y redacta la respuesta con su propio cerebro (`app/services/brain.py`), solo con la biblioteca
estándar de Python: sin API, sin clave y sin Ollama. Las cifras salen siempre de los datos oficiales que calcula Laravel.

`AI_ENGINE` (en `.env` del servicio):

- `brain` (por defecto): solo el cerebro propio.
- `auto`: Ollama redacta y el cerebro responde si Ollama no está disponible.
- `ollama`: solo Ollama.

Pruebas: `PYTHONPATH=. .venv/Scripts/python.exe -m unittest tests.test_brain`
