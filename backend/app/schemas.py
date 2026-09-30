"""Request schema base. ``extra='forbid'`` rejects unknown fields, so there is no mass assignment (THREAT-MODEL T-2)."""
from __future__ import annotations

from pydantic import BaseModel, ConfigDict


class In(BaseModel):
    model_config = ConfigDict(extra="forbid", str_max_length=10_000)
