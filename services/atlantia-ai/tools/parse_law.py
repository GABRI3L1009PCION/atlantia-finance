"""Splits the extracted text of a Guatemalan law into articles for the Normative Library.

Usage: python tools/parse_law.py <input.txt> <output.json> <code>
Keeps the structure (Libro › Título › Capítulo) because some laws, like the Ley de Actualización
Tributaria, restart article numbering in every book. The JSON is committed and loaded by Laravel.
"""

import json
import re
import sys

HEADING = re.compile(r"^(LIBRO|T[IÍ]TULO|CAP[IÍ]TULO|SECCI[OÓ]N)\s+([IVXL]+|[ÚU]NICO|PRELIMINAR|PRIMERO|SEGUNDO|TERCERO|CUARTO|QUINTO)\b\.?(.{0,80})$")
ARTICLE = re.compile(r"^Art[ií]culo\s+(\d+|[IVXL]{1,6})\s*(bis|ter|qu[aá]ter)?\s*[.:]\s*(.*)$", re.IGNORECASE)
PAGE_NUMBER = re.compile(r"^\d{1,3}$")
ROMAN = {"I": 1, "II": 2, "III": 3, "IV": 4, "V": 5, "VI": 6, "VII": 7, "VIII": 8, "IX": 9, "X": 10}


def heading_of(text: str) -> tuple[str, str]:
    """Title of the article: '(Aplicabilidad).' or 'Objeto.' at the start of its first line."""
    match = re.match(r"^\(([^)]{2,120})\)\.?\s*(.*)$", text)
    if match:
        return match.group(1).strip(), match.group(2).strip()
    match = re.match(r"^([^.]{2,120})\.\s+(.*)$", text)
    if match and len(match.group(1).split()) <= 12:
        return match.group(1).strip(), match.group(2).strip()
    return "", text


def clean(text: str) -> str:
    text = re.sub(r"-\n(\w)", r"\1", text)
    text = re.sub(r"\s+", " ", text)
    return text.strip()


def main(source: str, target: str, code: str) -> None:
    with open(source, encoding="utf-8") as handle:
        pages = handle.read().split("\f")

    path = {"libro": "", "titulo": "", "capitulo": "", "seccion": ""}
    pending_heading = None
    sections = []
    current = None
    seen = {}

    def close() -> None:
        if current is not None:
            current["content"] = clean(current["content"])
            sections.append(current)

    for page_number, page in enumerate(pages, start=1):
        for raw in page.splitlines():
            line = raw.strip()
            if line == "" or PAGE_NUMBER.match(line):
                continue
            heading = HEADING.match(line)
            if heading and len(line) < 120 and not line.endswith(","):
                kind = heading.group(1).upper().replace("Í", "I").replace("Ó", "O")
                label = clean(f"{heading.group(1).capitalize()} {heading.group(2).upper() if heading.group(2).upper() in ROMAN else heading.group(2).capitalize()} {heading.group(3)}")
                if kind == "LIBRO":
                    path.update(libro=label, titulo="", capitulo="", seccion="")
                elif kind == "TITULO":
                    path.update(titulo=label, capitulo="", seccion="")
                elif kind == "CAPITULO":
                    path.update(capitulo=label, seccion="")
                elif kind == "SECCION":
                    path.update(seccion=label)
                pending_heading = kind
                continue
            article = ARTICLE.match(line)
            if article:
                close()
                number = article.group(1)
                suffix = (article.group(2) or "").lower().replace("á", "a")
                title, first = heading_of(article.group(3))
                book = path["libro"].split(" ")[1] if path["libro"].count(" ") else ""
                # Roman-numbered articles are the transitional provisions of the decree, not the code itself.
                prefix = "transitorio-" if not number.isdigit() else (f"libro-{ROMAN.get(book.upper(), book.lower())}-" if book else "")
                key = prefix + f"art-{number.lower()}" + (f"-{suffix}" if suffix else "")
                if not number.isdigit():
                    path["seccion"] = "Disposiciones transitorias del decreto"
                seen[key] = seen.get(key, 0) + 1
                if seen[key] > 1:
                    key = f"{key}-{seen[key]}"
                current = {
                    "key": key,
                    "label": f"Artículo {number}" + (f" {suffix}" if suffix else ""),
                    "heading": title,
                    "path": " · ".join(part for part in path.values() if part),
                    "content": first,
                    "page": page_number,
                }
                pending_heading = None
                continue
            if pending_heading is not None and current is None:
                continue
            if pending_heading is not None and line.isupper() and len(line) < 100:
                # Name of the Libro/Título/Capítulo on the line after it.
                level = {"LIBRO": "libro", "TITULO": "titulo", "CAPITULO": "capitulo", "SECCION": "seccion"}.get(pending_heading)
                if level:
                    path[level] = f"{path[level]} ({clean(line.capitalize())})"
                continue
            if current is not None:
                current["content"] += "\n" + line
    close()

    with open(target, "w", encoding="utf-8") as handle:
        json.dump({"code": code, "sections": sections}, handle, ensure_ascii=False, indent=1)
    print(f"{code}: {len(sections)} artículos")


if __name__ == "__main__":
    main(sys.argv[1], sys.argv[2], sys.argv[3])
