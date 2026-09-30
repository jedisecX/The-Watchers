"""Request schema base. ``extra='forbid'`` rejects unknown fields, so there is no mass assignment (THREAT-MODEL T-2)."""
from __future__ import annotations

from typing import Annotated

from pydantic import BaseModel, ConfigDict, Field, StrictInt


class In(BaseModel):
    model_config = ConfigDict(extra="forbid", str_max_length=10_000)


# Integers must be real JSON integers (no bool/str coercion) and fit Postgres INTEGER, so a hostile
# value can neither be type-confused nor overflow the database into a 500 (finding ADV-15).
Int32 = Annotated[StrictInt, Field(ge=-(2**31), le=2**31 - 1)]
PosInt32 = Annotated[StrictInt, Field(ge=1, le=2**31 - 1)]
