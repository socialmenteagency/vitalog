#!/usr/bin/env python3
"""Convierte el export de Apple Health (export.zip) al JSON compacto que
consume el /backend de Vitalog ("Apple Health → Importar").

Uso (en la Mac, con el export.zip descargado desde Salud → perfil → Exportar
y guardado en la carpeta de trabajo ~/Documents/Vitalog/):

    python3 apple_health_to_json.py

Sin argumentos lee export.zip de esa carpeta y deja salud-apple.json al lado.
También acepta rutas explícitas: python3 apple_health_to_json.py origen.zip destino.json

El export.zip pesa cientos de MB; este script lo procesa en streaming y
produce un JSON de unos pocos KB con PROMEDIOS MENSUALES de:
  - weight      HKQuantityTypeIdentifierBodyMass (kg)
  - exercise    HKQuantityTypeIdentifierAppleExerciseTime (minutos/día)
  - resting_hr  HKQuantityTypeIdentifierRestingHeartRate (lpm)
  - steps       HKQuantityTypeIdentifierStepCount (pasos/día)

Nota sobre pasos: iPhone y Apple Watch registran los mismos pasos por
separado; sumar todos los registros los cuenta doble. Como hace la app de
Salud, por cada día se toma la fuente que más registró (máximo por fuente),
no la suma de todas.
"""

import json
import os
import sys
import zipfile
from collections import defaultdict
from xml.etree.ElementTree import iterparse

WORK_DIR = os.path.expanduser("~/Documents/Vitalog")

TYPES = {
    "HKQuantityTypeIdentifierBodyMass": "weight",
    "HKQuantityTypeIdentifierAppleExerciseTime": "exercise",
    "HKQuantityTypeIdentifierRestingHeartRate": "resting_hr",
    "HKQuantityTypeIdentifierStepCount": "steps",
}


def main() -> None:
    if len(sys.argv) == 1:
        src = os.path.join(WORK_DIR, "export.zip")
        dst = os.path.join(WORK_DIR, "salud-apple.json")
    elif len(sys.argv) == 3:
        src, dst = sys.argv[1], sys.argv[2]
    else:
        print(__doc__)
        sys.exit(1)

    # weight/resting_hr: promedio de lecturas del mes.
    # exercise: suma de minutos por día → promedio diario del mes.
    # steps: por día, máximo entre fuentes (evita doble conteo iPhone+Watch)
    #        → promedio diario del mes.
    sums = defaultdict(float)      # (metric, month) -> suma
    counts = defaultdict(int)      # (metric, month) -> n lecturas
    ex_day = defaultdict(float)    # (month, day) -> minutos de ejercicio
    steps_day = defaultdict(lambda: defaultdict(float))  # (month, day) -> {fuente: pasos}

    def handle(record) -> None:
        metric = TYPES.get(record.get("type"))
        if metric is None:
            return
        date = record.get("startDate", "")[:10]      # YYYY-MM-DD hora local
        month = date[:7]
        try:
            value = float(record.get("value"))
        except (TypeError, ValueError):
            return
        if record.get("type") == "HKQuantityTypeIdentifierBodyMass" and record.get("unit") == "lb":
            value *= 0.453592
        if metric == "exercise":
            ex_day[(month, date)] += value
        elif metric == "steps":
            steps_day[(month, date)][record.get("sourceName", "?")] += value
        else:
            sums[(metric, month)] += value
            counts[(metric, month)] += 1

    def parse(stream) -> None:
        for _event, elem in iterparse(stream, events=("end",)):
            if elem.tag == "Record":
                handle(elem)
                elem.clear()

    if src.endswith(".zip"):
        with zipfile.ZipFile(src) as z:
            names = [n for n in z.namelist() if n.endswith("export.xml")]
            if not names:
                sys.exit("No se encontró export.xml dentro del zip.")
            with z.open(names[0]) as f:
                parse(f)
    else:
        with open(src, "rb") as f:
            parse(f)

    months = defaultdict(dict)
    for (metric, month), total in sums.items():
        months[month][metric] = round(total / counts[(metric, month)], 1)
    ex_month_days = defaultdict(list)
    for (month, _day), minutes in ex_day.items():
        ex_month_days[month].append(minutes)
    for month, days in ex_month_days.items():
        months[month]["exercise"] = round(sum(days) / len(days))
    steps_month_days = defaultdict(list)
    for (month, _day), sources in steps_day.items():
        steps_month_days[month].append(max(sources.values()))
    for month, days in steps_month_days.items():
        months[month]["steps"] = round(sum(days) / len(days))
    for month in months:
        if "resting_hr" in months[month]:
            months[month]["resting_hr"] = round(months[month]["resting_hr"])

    out = {"months": [{"month": m, **months[m]} for m in sorted(months)]}
    with open(dst, "w", encoding="utf-8") as f:
        json.dump(out, f, ensure_ascii=False, indent=1)
    print(f"OK: {len(out['months'])} meses → {dst}")
    print("Sube este archivo en el /backend de tu Vitalog → Apple Health → Importar.")


if __name__ == "__main__":
    main()
