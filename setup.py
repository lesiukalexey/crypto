from setuptools import setup
from pythran.dist import PythranBuildExt, PythranExtension

kernel_extension = PythranExtension("pythran_standard", ["pythran_standard.py"])
# Omit build-only debug information while keeping the optimization level used
# by the Pythran performance comparison.
kernel_extension.extra_compile_args = [
    argument for argument in kernel_extension.extra_compile_args if argument != "-g"
] + ["-g0"]

setup(
    name="bitget-simulation-kernels",
    version="1.0.0",
    ext_modules=[kernel_extension],
    cmdclass={"build_ext": PythranBuildExt},
)
