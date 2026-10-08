"""Runtime facade for the compiled Pythran simulation kernels."""

from __future__ import annotations

from importlib.machinery import EXTENSION_SUFFIXES

import pythran_standard as _standard

if not all(
    any(module.__file__.endswith(suffix) for suffix in EXTENSION_SUFFIXES)
    for module in (_standard,)
):
    raise ImportError(
        "The Pythran simulation extension is not built. "
        "Install requirements.txt, then run: python setup.py build_ext --inplace"
    )

simulate_standard = _standard.simulate_standard
