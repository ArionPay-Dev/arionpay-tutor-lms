"""Build the Tutor LMS plugin zip: copies the ArionPay PHP SDK into the plugin, then zips it.
   python3 build.py  ->  dist/arionpay-for-tutor.zip  (Plugins > Add New > Upload Plugin)"""
import os, shutil, zipfile, pathlib
HERE = pathlib.Path(__file__).resolve().parent
SDK = HERE.parent / "arionpay-php"
PLUGIN = HERE / "arionpay-for-tutor"
lib = PLUGIN / "lib" / "arionpay-php"
if lib.exists():
    shutil.rmtree(lib)
(lib / "src").mkdir(parents=True)
for f in ("autoload.php", "LICENSE"):
    shutil.copy(SDK / f, lib / f)
for f in sorted((SDK / "src").glob("*.php")):
    shutil.copy(f, lib / "src" / f.name)
guard = "<?php\n// Silence is golden.\n"
for d in (PLUGIN / "lib", lib, lib / "src"):
    (d / "index.php").write_text(guard)
(HERE / "dist").mkdir(exist_ok=True)
out = HERE / "dist" / "arionpay-for-tutor.zip"
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
    for root, dirs, files in os.walk(PLUGIN):
        dirs.sort()
        for name in sorted(files):
            p = pathlib.Path(root) / name
            z.write(p, "arionpay-for-tutor/" + str(p.relative_to(PLUGIN)))
print(out, os.path.getsize(out), "bytes")
